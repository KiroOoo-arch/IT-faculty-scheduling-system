#!/usr/bin/env python3
"""Documentation drift checker for the IT faculty scheduling system.

This is a *merged* checker. It combines the two implementations that grew on
separate branches:

* the measured-ground-truth half (from ``feature/multi-session-editor``) — test
  counts obtained from PHPUnit's own listing, protection layers anchored to the
  code that implements them, paragraph-aware and code-fence-aware scanning, DOCX
  coverage comparison, and diagram provenance/dimension checks;
* the operator-experience half (from the laptop toolset) — severity on every
  finding, an actionable ``fix`` hint, inline ``doc-drift:allow`` /
  ``doc-drift:allow-next`` annotations, broken local Markdown link detection,
  dependency-light DOCX text extraction via ``zipfile``, registry reuse from
  ``build_docx.ARTIFACTS`` and ``render_mmd.FIGURES``, and ``--verbose``.

It reports places where the *current* documentation no longer matches the code,
the test suites, or the sources the documents are generated from. It never
rewrites anything — it prints what disagrees and exits, so a developer can
review and fix it.

    python documentation/tools/check_doc_drift.py               # fast, read-only checks
    python documentation/tools/check_doc_drift.py --verbose     # also print suppressed matches
    python documentation/tools/check_doc_drift.py --json        # machine-readable output
    python documentation/tools/check_doc_drift.py --list-rules  # rules, registries and allowlist
    python documentation/tools/check_doc_drift.py --run-suites  # DATABASE-TOUCHING (see below)
    python -m unittest discover -s documentation/tools -p "test_*.py"   # the checker's own tests

Exit status
-----------
``0``  nothing actionable — no findings, or warnings only.
``1``  at least one error-severity finding.
``2``  the checker could not establish ground truth (configuration/environment).

Severity
--------
Errors are drift a reader would be misled by. Warnings are hygiene (a figure no
current document cites, a source newer than its render): they are printed, but a
warning on its own does not fail the run.

Backend test counts are *measured*, never hard-coded. PHPUnit is asked for its own
test listing; when that is unavailable the check reports itself as SKIPPED rather
than guessing, because a static scan cannot count this suite (its cases are
declared with ``#[Test]`` attributes and ``@test`` annotations, not ``test*``
method names).

``--run-suites`` executes ``php artisan test`` and the engine's unittest suite. On
this project that touches a database, so it must only be used where the
environment is known to be a throwaway one. The default mode never runs a suite.
"""

from __future__ import annotations

import argparse
import html
import json
import re
import struct
import subprocess
import sys
import zipfile
from dataclasses import dataclass, field
from pathlib import Path

TOOLS_DIR = Path(__file__).resolve().parent
REPO_ROOT = TOOLS_DIR.parents[1]
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

TOOLS_README = "documentation/tools/README.md"

# The generators are the registries of record: a Word file or a figure is
# "registered" when it is named there, so the checker cannot drift from them.
try:  # pragma: no cover - exercised by import, not by a branch
    import build_docx  # noqa: E402

    HAVE_BUILD_DOCX = True
except Exception:  # the checker still works standalone, on its fallback lists
    build_docx = None  # type: ignore[assignment]
    HAVE_BUILD_DOCX = False

try:  # pragma: no cover
    import render_mmd  # noqa: E402

    HAVE_RENDER_MMD = True
except Exception:
    render_mmd = None  # type: ignore[assignment]
    HAVE_RENDER_MMD = False


# --------------------------------------------------------------------------------------
# Configuration — kept explicit and documented rather than spread through the checks.
# --------------------------------------------------------------------------------------

#: Documents that intentionally describe an earlier state. They are skipped by every
#: content check; the reason is printed with --list-rules so the list stays honest.
HISTORICAL_FILES: dict[str, str] = {
    "documentation/Bug-Fix-Log.md": "chronological bug log for sessions 1–2 (2026-08-01/02)",
    "documentation/Progress-report.md": "progress report dated 2026-09-27 covering July–September",
    "documentation/Manuscript_Update_Brief.md": "manuscript brief 'as of 2026-09-09' for commits f04f0e8→eacdd66",
    "documentation/Manuscript_Update_Brief.docx": "generated from the historical brief above",
    "documentation/latestdoc.md": "development log snapshot, kept as a dated record",
    "documentation/System-Defense-Guide.docx": (
        "legacy hand-formatted defence guide committed 2026-08-27 with no Markdown source; "
        "superseded by the reproducible System_Defense_Guide.* pair"
    ),
}

#: Fallback DOCX pairs, used only when build_docx.py is not importable. When the
#: generator is present its ARTIFACTS registry is authoritative.
DOCX_PAIRS_FALLBACK: list[tuple[str, str]] = [
    ("documentation/System_Defense_Guide.md", "documentation/System_Defense_Guide.docx"),
    ("documentation/System_Complete_Guide.md", "documentation/System_Complete_Guide.docx"),
    ("documentation/Manuscript_Update_Brief.md", "documentation/Manuscript_Update_Brief.docx"),
]

#: Generated figures and the documents that explain them. ``command_tokens`` must all
#: appear on the render-command line the explainer documents. ``doc``, ``command_tokens``
#: and ``size`` are optional: an entry contributed by render_mmd.FIGURES carries only the
#: source/image pair, and still gets the traceability checks that need no explainer.
DIAGRAMS: list[dict[str, object]] = [
    {
        "source": "documentation/screenshots/system-flow.mmd",
        "image": "documentation/screenshots/16-system-flow.png",
        "doc": "documentation/Explainer-System-Flow.md",
        "command_tokens": ["mermaid-cli", "system-flow.mmd", "16-system-flow.png"],
        # The size the explainer documents for the committed rendering; a figure rendered at a
        # different size than the document claims (or re-rendered from another source) shows up here.
        "size": (2531, 6600),
    },
    {
        "source": "documentation/screenshots/program-flow.mmd",
        "image": "documentation/screenshots/11-program-flow.png",
        "doc": "documentation/Program-Flow.md",
        "command_tokens": ["mermaid-cli", "program-flow.mmd", "11-program-flow.png"],
    },
]

#: The five protection layers, each anchored to the code that implements it. The layer
#: count in the documentation must equal this list's length, and every pattern must still
#: match its file.
PROTECTION_LAYERS: list[tuple[str, str, list[str]]] = [
    ("solver constraints at generation time", "ai-engine/solver/scheduler.py", ["def generate_schedule", "cp_model.CpModel", "AddBoolOr"]),
    ("term lock and pre-write conflict gate", "backend/app/Http/Controllers/ScheduleController.php", ["Cache::lock(", "findGeneratedConflicts"]),
    ("manual-edit validation", "backend/app/Http/Controllers/ScheduleSessionController.php", ["findConflicts"]),
    ("publish conflict gate", "backend/app/Http/Controllers/ScheduleApprovalController.php", ["findPublishConflicts"]),
    ("published-reference delete guard", "backend/app/Http/Controllers/Concerns/GuardsPublishedReferences.php", ["published"]),
]

#: Directories never scanned for documentation. The scratch trees (``.tmp-run``,
#: ``.tmp-docwork``) are excluded so local experiments and virtualenvs cannot pollute a
#: document scan; ``.freebuff`` is local agent metadata.
SKIP_DIRS = {
    ".git",
    ".tmp-run",
    ".tmp-docwork",
    ".freebuff",
    "node_modules",
    "vendor",
    ".venv",
    "__pycache__",
    "dist",
    ".idea",
    ".vscode",
}

#: Lines carrying one of these markers are treated as historical framing and skipped —
#: a current document is allowed to say what an older revision recorded.
HISTORICAL_MARKERS = (
    "as of", "at the time", "then stood", "has since grown", "since grown", "superseded",
    "previously", "recorded", "outdated", "re-baseline", "rebaseline", "earlier revision",
    "used to", "before the fix", "was re-run", "historical", "old count", "in the past",
)

#: Outdated scheduling-conflict claims, with the behaviour that replaced each one.
STALE_SCHEDULING_CLAIMS: list[tuple[str, str]] = [
    (r"drafts?\s+(?:do(?:es)?\s+not|don't|doesn't)\s+count",
     "drafts in the same academic year and semester now count as live bookings"),
    (r"drafts?\s+(?:are|is)\s+(?:not\s+counted|excluded|ignored)",
     "drafts in the same term are no longer excluded"),
    (r"(?<!not )(?<!never )(?:ignor\w+|skip\w*|exclud\w+)\s+(?:all\s+|any\s+|other\s+)*drafts?\b",
     "draft sessions in the same term now count as live bookings"),
    (r"only\s+(?:avoids?|counts?|considers?|sees?)\s+[^.]{0,60}(?:approved|published)",
     "a draft now avoids every other section's draft, approved and published sessions in the term"),
    (r"approved\s*/\s*published\s+(?:schedules?\s+)?(?:only|alone)",
     "approved/published are no longer the only statuses that count"),
    (r"(?:batch|set)\s+of\s+drafts?\s+(?:can|may)\s+overlap",
     "runs for a term are serialized, so drafts from one batch cannot overlap"),
    (r"drafts?\s+(?:can|may)\s+overlap\s+(?:each\s+other|one\s+another)",
     "runs for a term are serialized, so sibling drafts cannot overlap"),
    (r"archiv\w+[^.]{0,40}(?:old|previous|existing)\s+drafts?[^.]{0,20}(?:first|up\s*front|before)",
     "the previous draft is archived inside the transaction that writes its replacement"),
    (r"archive\s+old\s+drafts",
     "archiving happens after the engine returns and after the conflict check, not before"),
    (r"before\s+(?:calling\s+|the\s+)?(?:engine|AI\s+engine)[^.]{0,60}archiv",
     "the engine is now called before anything is archived"),
]

#: A number is only treated as a claim when the line it sits on is about a test suite.
SUITE_CONTEXT = re.compile(
    r"(backend|laravel|php artisan test|feature test|solver|engine|unit test|unittest|test suite|assertion)",
    re.IGNORECASE,
)
TEST_COUNT_CLAIM = re.compile(r"\b(\d{1,4})\s*(?:\+\s*)?(?:[A-Za-z][\w/-]*\s+){0,3}tests?\b", re.IGNORECASE)
ASSERTION_CLAIM = re.compile(r"\b(\d{1,5})\s*assertions?\b", re.IGNORECASE)
LAYER_CLAIM = re.compile(
    r"\b(one|two|three|four|five|six|seven)\b[\s-]*(?:protection\s+)?layers?\b", re.IGNORECASE
)
WORDS_TO_NUMBERS = {"one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6, "seven": 7}

#: Markdown links that point into the repository must resolve.
LOCAL_LINK_RE = re.compile(r"!?\[[^\]]*\]\(([^)\s]+)")
MARKDOWN_LINK_SKIP = ("http://", "https://", "mailto:", "#", "tel:", "data:")

#: A DOCX must cover at least this share of the Markdown's words to count as in sync.
DOCX_COVERAGE_THRESHOLD = 0.97

#: Inline annotations that mark a match as a deliberate quotation rather than a claim.
ALLOW_MARKER = "doc-drift:allow"
ALLOW_NEXT_MARKER = "doc-drift:allow-next"


@dataclass
class Facts:
    """The numbers the repository actually has, as far as the checker could establish."""

    engine_tests: int | None = None
    backend_tests: int | None = None
    backend_assertions: int | None = None
    conflict_tests: int | None = None
    conflict_assertions: int | None = None
    engine_source: str = "counted from ai-engine/tests/test_*.py"
    backend_source: str = "counted from backend/tests/**/*.php"
    conflict_source: str = "phpunit on backend/tests/Feature/*ConflictTest.php"
    protection_layers: int = len(PROTECTION_LAYERS)
    #: Matches dropped because a line was annotated or framed as historical; printed by
    #: --verbose so nothing is suppressed silently.
    suppressed: list[str] = field(default_factory=list)
    notes: list[str] = field(default_factory=list)


@dataclass
class Issue:
    check: str
    path: str
    message: str
    line: int | None = None
    severity: str = "error"
    remediation: str = ""

    def __str__(self) -> str:
        where = f"{self.path}:{self.line}" if self.line else self.path
        marker = "drift" if self.severity == "error" else "warning"
        text = f"[{self.check}] {marker} {where}\n    {self.message}"
        if self.remediation:
            text += f"\n    fix  : {self.remediation}"
        return text


# --------------------------------------------------------------------------------------
# Ground truth
# --------------------------------------------------------------------------------------


def count_engine_tests(root: Path) -> int | None:
    """Number of test methods in the engine suite (matches a real unittest run)."""
    total = 0
    files = sorted((root / "ai-engine" / "tests").glob("test_*.py"))
    if not files:
        return None
    for path in files:
        total += len(re.findall(r"^\s+def test_\w+", path.read_text(encoding="utf-8", errors="replace"), re.M))
    return total


ANSI_ESCAPE = re.compile(r"\x1b\[[0-9;]*m")


def strip_ansi(text: str) -> str:
    """Remove colour codes: a suite summary is still a summary when it is coloured."""
    return ANSI_ESCAPE.sub("", text)


def phpunit_list_tests(root: Path) -> str | None:
    """PHPUnit's own `--list-tests` output, or None when it cannot be produced.

    The vendor script is run through the `php` interpreter: on Windows it has no shebang
    that the operating system can execute, so invoking it directly raises OSError even
    though the tool is installed and working.

    This lists tests; it does not run them, and it does not touch a database.
    """
    backend = root / "backend"
    phpunit = backend / "vendor" / "bin" / "phpunit"
    if not phpunit.exists():
        return None
    for command in (["php", str(phpunit), "--list-tests"], [str(phpunit), "--list-tests"]):
        try:
            out = subprocess.run(command, cwd=backend, capture_output=True, text=True, timeout=300)
        except (OSError, subprocess.SubprocessError):
            continue
        if out.returncode == 0 and out.stdout.strip():
            return out.stdout
    return None


def count_backend_tests(root: Path) -> int | None:
    """Number of backend tests, from PHPUnit's own test listing when available.

    A static count is not good enough: this suite declares its cases with ``#[Test]``
    attributes and ``@test`` annotations rather than ``test*`` method names, and data
    providers make one method several tests, so only the framework's own listing agrees
    with what ``php artisan test`` reports.
    """
    listing = phpunit_list_tests(root)
    if listing is None:
        return None
    return sum(1 for line in listing.splitlines() if re.match(r"^\s+-\s", line))


def parse_suite_summary(text: str) -> tuple[int, int] | None:
    """(tests, assertions) from a PHPUnit/Laravel summary, colour codes and all."""
    combined = strip_ansi(text)
    match = (re.search(r"Tests:\s+(\d+)(?:\s+\w+)?\s+passed\s*\((\d+)\s+assertions\)", combined)
             or re.search(r"OK \((\d+) tests?, (\d+) assertions?\)", combined))
    if not match:
        return None
    return int(match.group(1)), int(match.group(2))


def run_suites(root: Path) -> tuple[dict[str, int | None], list[str]]:
    """Run the suites and parse their summaries. Returns (numbers, notes).

    DATABASE-TOUCHING. Only reached with --run-suites, and only where the environment is
    disposable. The backend suite and the cross-section conflict suite are run separately:
    the documentation quotes their sizes independently, so each needs its own ground truth.
    """
    numbers: dict[str, int | None] = {}
    notes: list[str] = []

    backend = root / "backend"
    if (backend / "artisan").exists():
        try:
            out = subprocess.run(["php", "artisan", "test"], cwd=backend, capture_output=True,
                                 text=True, timeout=1800)
            summary = parse_suite_summary(out.stdout + out.stderr)
            if summary:
                numbers["backend_tests"], numbers["backend_assertions"] = summary
            else:
                notes.append("backend suite ran but its summary could not be parsed")
        except (OSError, subprocess.SubprocessError) as exc:
            notes.append(f"could not run the backend suite: {exc}")
    else:
        notes.append("backend suite skipped: backend/artisan is missing")

    phpunit = backend / "vendor" / "bin" / "phpunit"
    conflict_files = [str(p) for p in sorted((backend / "tests" / "Feature").glob("*ConflictTest.php"))]
    if conflict_files and phpunit.exists():
        try:
            out = subprocess.run(["php", str(phpunit), *conflict_files], cwd=backend,
                                 capture_output=True, text=True, timeout=1800)
            summary = parse_suite_summary(out.stdout + out.stderr)
            if summary:
                numbers["conflict_tests"], numbers["conflict_assertions"] = summary
            else:
                notes.append("conflict suite ran but its summary could not be parsed")
        except (OSError, subprocess.SubprocessError) as exc:
            notes.append(f"could not run the conflict suite: {exc}")
    elif not phpunit.exists():
        notes.append("conflict suite skipped: phpunit is not installed")

    engine_python = root / "ai-engine" / ".venv" / "Scripts" / "python.exe"
    if not engine_python.exists():
        engine_python = root / "ai-engine" / ".venv" / "bin" / "python"
    if engine_python.exists():
        try:
            out = subprocess.run([str(engine_python), "-m", "unittest", "discover", "-s", "tests"],
                                 cwd=root / "ai-engine", capture_output=True, text=True, timeout=1800)
            match = re.search(r"Ran (\d+) tests?", strip_ansi(out.stderr + out.stdout))
            if match:
                numbers["engine_tests"] = int(match.group(1))
            else:
                notes.append("engine suite ran but its summary could not be parsed")
        except (OSError, subprocess.SubprocessError) as exc:
            notes.append(f"could not run the engine suite: {exc}")
    else:
        notes.append("engine suite skipped: no virtualenv interpreter found")

    return numbers, notes


def count_conflict_tests(root: Path) -> int | None:
    """Size of the cross-section conflict suite, from PHPUnit's listing."""
    listing = phpunit_list_tests(root)
    if listing is None:
        return None
    return sum(1 for line in listing.splitlines()
               if re.match(r"^\s+-\s", line) and re.search(r"(ScheduleGenerationConflictTest|ScheduleSessionConflictTest)", line))


def collect_facts(root: Path, run_suites_flag: bool = False) -> Facts:
    facts = Facts()
    facts.engine_tests = count_engine_tests(root)
    facts.engine_source = "counted from ai-engine/tests/test_*.py (equals a unittest run)"
    backend = count_backend_tests(root)
    if backend is not None:
        facts.backend_tests = backend
        facts.backend_source = "phpunit --list-tests"
    facts.conflict_tests = count_conflict_tests(root)
    if run_suites_flag:
        numbers, notes = run_suites(root)
        facts.notes.extend(notes)
        for key, value in numbers.items():
            if value is not None:
                setattr(facts, key, value)
        if "backend_tests" in numbers:
            facts.backend_source = "php artisan test"
    return facts


# --------------------------------------------------------------------------------------
# Registries (single source of truth)
# --------------------------------------------------------------------------------------


def docx_pairs(root: Path) -> list[tuple[str, str]]:
    """Markdown→DOCX pairs, from build_docx.ARTIFACTS when the generator is present."""
    if HAVE_BUILD_DOCX:
        return [(artifact.source, artifact.output) for artifact in build_docx.ARTIFACTS.values()]  # type: ignore[union-attr]
    return list(DOCX_PAIRS_FALLBACK)


def diagram_entries(root: Path) -> list[dict[str, object]]:
    """Figure entries, extended with anything registered in render_mmd.FIGURES.

    A figure registered with the renderer but not described here still gets the
    source/image traceability checks; only the explainer-specific expectations are absent.
    """
    entries = [dict(entry) for entry in DIAGRAMS]
    if HAVE_RENDER_MMD:
        known = {str(entry.get("image")) for entry in entries}
        for image, source in sorted(render_mmd.FIGURES.items()):  # type: ignore[union-attr]
            if image not in known:
                entries.append({
                    "source": source,
                    "image": image,
                    "doc": None,
                    "command_tokens": [],
                    "size": None,
                    "origin": "render_mmd.FIGURES",
                })
    return entries


# --------------------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------------------


def doc_files(root: Path) -> list[Path]:
    """Every Markdown document in the repository, minus the checker's own directory."""
    found: list[Path] = []
    for path in sorted(root.rglob("*.md")):
        parts = set(path.relative_to(root).parts)
        if parts & SKIP_DIRS:
            continue
        if path.relative_to(root).as_posix().startswith("documentation/tools/"):
            continue  # the checker's own instructions quote example numbers
        found.append(path)
    return found


def is_historical(relpath: str) -> bool:
    return relpath in HISTORICAL_FILES


def stripped_lines(path: Path) -> list[tuple[int, str]]:
    """Content lines with fenced code blocks removed, so code is never read as a claim."""
    lines: list[tuple[int, str]] = []
    in_fence = False
    for number, raw in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
        if raw.lstrip().startswith("```"):
            in_fence = not in_fence
            continue
        if not in_fence:
            lines.append((number, raw))
    return lines


def paragraph_blocks(path: Path) -> list[tuple[int, str]]:
    """Content lines grouped into paragraphs: (first line number, text joined by spaces).

    Prose wraps, so a claim can straddle two lines — "…committed to" / "*approved/published*
    schedules" — and a line-at-a-time search would miss it. Code fences are already gone.
    """
    blocks: list[tuple[int, str]] = []
    current: list[str] = []
    start = 0
    for number, line in stripped_lines(path):
        if line.strip():
            if not current:
                start = number
            current.append(line.strip())
        elif current:
            blocks.append((start, " ".join(current)))
            current = []
    if current:
        blocks.append((start, " ".join(current)))
    return blocks


def has_historical_marker(text: str) -> bool:
    lowered = text.lower()
    return any(marker in lowered for marker in HISTORICAL_MARKERS)


def allowed_lines(path: Path) -> set[int]:
    """1-based line numbers exempted by an inline annotation.

    ``<!-- doc-drift:allow: reason -->`` exempts its own line; the block form
    ``<!-- doc-drift:allow-next: reason -->`` exempts its own line and the paragraph
    that follows it, so a quoted before/after table can be annotated once.
    """
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    exempt: set[int] = set()
    index = 0
    while index < len(lines):
        lowered = lines[index].lower()
        if ALLOW_NEXT_MARKER in lowered:
            exempt.add(index + 1)
            cursor = index + 1
            while cursor < len(lines) and lines[cursor].strip():
                exempt.add(cursor + 1)
                cursor += 1
            index = cursor
        elif ALLOW_MARKER in lowered:
            exempt.add(index + 1)
        index += 1
    return exempt


#: Straight and curly quoted spans. A claim inside one is being *cited* — the "old claim"
#: column of a corrections table, for instance — not asserted by the current document.
QUOTED_SPAN = re.compile(r'"[^"]*"|\u201c[^\u201d]*\u201d')


def is_cited(line: str, start: int, end: int) -> bool:
    """True when the matched text sits inside a quoted span on that line."""
    return any(span.start() <= start and end <= span.end() for span in QUOTED_SPAN.finditer(line))


def _annotated(facts: Facts, rel: str, number: int, exempt: set[int], check: str) -> bool:
    """Record (and report) a match dropped because its line carries an annotation."""
    if number in exempt:
        facts.suppressed.append(f"{rel}:{number}: [{check}] inline doc-drift:allow annotation")
        return True
    return False


# --------------------------------------------------------------------------------------
# Checks
# --------------------------------------------------------------------------------------


def check_test_counts(root: Path, facts: Facts) -> list[Issue]:
    """Test-count and assertion claims in current documents vs the repository."""
    issues: list[Issue] = []
    expectations = [
        ("engine", facts.engine_tests, "the engine suite"),
        ("backend", facts.backend_tests, "the backend suite"),
    ]
    for path in doc_files(root):
        rel = path.relative_to(root).as_posix()
        if is_historical(rel):
            continue
        exempt = allowed_lines(path)
        for number, line in stripped_lines(path):
            if has_historical_marker(line) or not SUITE_CONTEXT.search(line):
                continue
            if _annotated(facts, rel, number, exempt, "test-counts"):
                continue
            for match in TEST_COUNT_CLAIM.finditer(line):
                claimed = int(match.group(1))
                if claimed < 4 or is_cited(line, match.start(), match.end()):
                    continue
                context = line[max(0, match.start() - 40):match.end() + 40]
                expected = _which_suite(context, facts)
                if expected is None:
                    continue
                actual, suite_name = expected
                if claimed != actual:
                    issues.append(Issue(
                        "test-counts", rel,
                        f"claims {claimed} tests for {suite_name}, but the repository has {actual}"
                        f" ({facts.engine_source if suite_name == 'the engine suite' else facts.backend_source}); "
                        f"line reads: {line.strip()[:120]}",
                        number,
                        remediation="cite the measured number, or mark the sentence as a historical record",
                    ))
            for match in ASSERTION_CLAIM.finditer(line):
                claimed = int(match.group(1))
                if is_cited(line, match.start(), match.end()):
                    continue
                context = line[max(0, match.start() - 60):match.end() + 20]
                # The cross-section conflict suite is quoted separately from the whole
                # backend suite, so its assertions are compared against its own count.
                if re.search(r"conflict", context, re.IGNORECASE):
                    if facts.conflict_assertions is None or claimed == facts.conflict_assertions:
                        continue
                    issues.append(Issue(
                        "test-counts", rel,
                        f"claims {claimed} assertions for the cross-section conflict suite, but "
                        f"that suite reports {facts.conflict_assertions} ({facts.conflict_source}); "
                        f"line reads: {line.strip()[:120]}",
                        number,
                        remediation="run the checker with a suite listing to re-measure, then cite that",
                    ))
                    continue
                if facts.backend_assertions is None:
                    continue
                expected = _which_suite(context, facts)
                if expected is None or expected[1] != "the backend suite":
                    continue
                if claimed != facts.backend_assertions:
                    issues.append(Issue(
                        "test-counts", rel,
                        f"claims {claimed} assertions, but the backend suite reports "
                        f"{facts.backend_assertions} ({facts.backend_source})",
                        number,
                        remediation="run the checker with a suite listing to re-measure, then cite that",
                    ))
    return issues


def _which_suite(context: str, facts: Facts) -> tuple[int, str] | None:
    """Decide which suite a phrase is talking about, and return (count, name)."""
    lowered = context.lower()
    engine_words = re.search(r"engine|solver|unittest|python", lowered)
    backend_words = re.search(r"backend|laravel|php artisan|feature", lowered)
    if engine_words and not backend_words:
        return (facts.engine_tests, "the engine suite") if facts.engine_tests is not None else None
    if backend_words and not engine_words:
        return (facts.backend_tests, "the backend suite") if facts.backend_tests is not None else None
    return None


def check_protection_layers(root: Path, facts: Facts) -> list[Issue]:
    """The documented number of scheduling-protection layers, and the layers in the code."""
    issues: list[Issue] = []
    missing: list[str] = []
    for name, relpath, patterns in PROTECTION_LAYERS:
        path = root / relpath
        text = path.read_text(encoding="utf-8", errors="replace") if path.exists() else ""
        for pattern in patterns:
            if pattern not in text:
                missing.append(f"layer '{name}': {relpath} no longer contains {pattern!r}")
    if missing:
        issues.append(Issue(
            "protection-layers", "documentation/tools/check_doc_drift.py",
            "the protection-layer inventory no longer matches the code:\n      "
            + "\n      ".join(missing),
            remediation="re-anchor PROTECTION_LAYERS to the code that now implements each layer",
        ))
    expected = len(PROTECTION_LAYERS)
    for path in doc_files(root):
        rel = path.relative_to(root).as_posix()
        if is_historical(rel):
            continue
        exempt = allowed_lines(path)
        for number, line in stripped_lines(path):
            lowered = line.lower()
            # Only *protection* layers are counted here. "four layers" in an architecture
            # or methodology sentence is a different claim and must not be flagged.
            if has_historical_marker(line) or "protection" not in lowered:
                continue
            if _annotated(facts, rel, number, exempt, "protection-layers"):
                continue
            for match in LAYER_CLAIM.finditer(line):
                if is_cited(line, match.start(), match.end()):
                    continue
                word = match.group(1).lower()
                claimed = WORDS_TO_NUMBERS.get(word)
                if claimed is None and word.isdigit():
                    claimed = int(word)
                if claimed is None or claimed == expected:
                    continue
                issues.append(Issue(
                    "protection-layers", rel,
                    f"describes {word} protection layers, but the code implements {expected} "
                    f"({', '.join(name for name, _, _ in PROTECTION_LAYERS)})",
                    number,
                    remediation="document the protection-layer model consistently with the code inventory",
                ))
    return issues


def check_scheduling_claims(root: Path, facts: Facts) -> list[Issue]:
    """Outdated statements about generation, conflict checking and draft replacement."""
    issues: list[Issue] = []
    compiled = [(re.compile(pattern, re.IGNORECASE), why) for pattern, why in STALE_SCHEDULING_CLAIMS]
    for path in doc_files(root):
        rel = path.relative_to(root).as_posix()
        if is_historical(rel):
            continue
        exempt = allowed_lines(path)
        for number, block in paragraph_blocks(path):
            lowered = block.lower()
            if has_historical_marker(block):
                continue
            if not re.search(r"draft|schedule|generat", lowered):
                continue
            if _annotated(facts, rel, number, exempt, "scheduling-claims"):
                continue
            for pattern, why in compiled:
                match = pattern.search(block)
                if not match or is_cited(block, match.start(), match.end()):
                    continue
                snippet = block[max(0, match.start() - 60):match.end() + 80].strip()
                issues.append(Issue(
                    "scheduling-claims", rel,
                    f"outdated description of scheduling behaviour: …{snippet}…\n"
                    f"      the code does this instead: {why}",
                    number,
                    remediation="describe the behaviour the code has now, or mark the sentence as historical",
                ))
                break
    return issues


def _normalize_words(text: str) -> list[str]:
    """Words with Markdown punctuation and emphasis removed, for DOCX comparison."""
    text = re.sub(r"```[a-z]*", " ", text)
    text = re.sub(r"[*_`>#|]+\s*", " ", text)
    # Ordered/bulleted list markers are rendering instructions, not content: Word re-creates
    # the numbering from the paragraph style, so the literal "1." never reaches the DOCX text.
    # Heading hashes are removed first, so "## 1. Overview" is treated like "1. Overview".
    text = re.sub(r"^[ \t]*(?:[-*+]|\d+[.)])[ \t]+", " ", text, flags=re.M)
    text = re.sub(r"<br\s*/?>", " ", text)
    text = re.sub(r"\s+", " ", text)
    return re.findall(r"[a-z0-9][a-z0-9'./:{}_-]*", text.lower())


def docx_text(path: Path) -> str:
    """All text in a DOCX: paragraphs plus every table cell.

    Read straight out of the OOXML archive, so the check needs no third-party
    dependency and still runs on a machine that has never installed python-docx.
    """
    try:
        with zipfile.ZipFile(path) as archive:
            xml = archive.read("word/document.xml").decode("utf-8", "replace")
    except (zipfile.BadZipFile, KeyError, OSError) as exc:
        raise RuntimeError(f"could not be read: {exc}") from exc
    xml = re.sub(r"</w:p>", "\n", xml)
    xml = re.sub(r"<w:(?:tab|br)\s*/>", " ", xml)
    return html.unescape(re.sub(r"<[^>]+>", "", xml))


def check_docx_sync(root: Path, facts: Facts) -> list[Issue]:
    """Generated DOCX files that no longer carry the Markdown they are built from."""
    issues: list[Issue] = []
    for md_rel, docx_rel in docx_pairs(root):
        if is_historical(md_rel) or is_historical(docx_rel):
            continue
        md_path, docx_path = root / md_rel, root / docx_rel
        if not md_path.exists() or not docx_path.exists():
            continue
        text = md_path.read_text(encoding="utf-8", errors="replace")
        try:
            rendered = docx_text(docx_path)
        except RuntimeError as exc:  # a corrupt or unreadable DOCX is a finding, not a crash
            issues.append(Issue(
                "docx-sync", docx_rel, f"{exc}",
                remediation=f"rebuild it: python documentation/tools/build_docx.py {docx_path.stem}",
            ))
            continue
        md_words = _normalize_words(text)
        docx_words = set(_normalize_words(rendered))
        if not md_words:
            continue
        missing = [word for word in set(md_words) if word not in docx_words]
        coverage = 1.0 - (len(missing) / len(set(md_words)))
        if coverage < DOCX_COVERAGE_THRESHOLD:
            examples = ", ".join(sorted(missing)[:12])
            issues.append(Issue(
                "docx-sync", docx_rel,
                f"covers only {coverage:.1%} of {md_rel}'s vocabulary "
                f"(threshold {DOCX_COVERAGE_THRESHOLD:.0%}); missing e.g. {examples}",
                remediation=f"rebuild it from the Markdown: python documentation/tools/build_docx.py {docx_path.stem}",
            ))
        # headings are the structural contract: every Markdown heading must be present
        headings = [m.group(1).strip() for m in re.finditer(r"^#{1,4}\s+(.+)$", text, re.M)]
        missing_headings = [h for h in headings if _normalize_words(h) and
                            not all(w in docx_words for w in _normalize_words(h))]
        if missing_headings:
            issues.append(Issue(
                "docx-sync", docx_rel,
                f"{len(missing_headings)} heading(s) from {md_rel} are absent, e.g. "
                + "; ".join(missing_headings[:3]),
                remediation=f"rebuild it from the Markdown: python documentation/tools/build_docx.py {docx_path.stem}",
            ))
    return issues


def check_local_links(root: Path, facts: Facts) -> list[Issue]:
    """Every Markdown link that points into the repository must resolve."""
    issues: list[Issue] = []
    for path in doc_files(root):
        rel = path.relative_to(root).as_posix()
        if is_historical(rel):
            continue
        exempt = allowed_lines(path)
        for number, line in stripped_lines(path):
            targets = [t for t in LOCAL_LINK_RE.findall(line)
                       if not t.startswith(MARKDOWN_LINK_SKIP) and "://" not in t]
            if not targets:
                continue
            if _annotated(facts, rel, number, exempt, "broken-links"):
                continue
            for target in targets:
                clean = target.split("#", 1)[0]
                if not clean:
                    continue
                base = root if clean.startswith("/") else path.parent
                if not (base / clean.lstrip("/")).exists():
                    issues.append(Issue(
                        "broken-links", rel, f"link target does not exist: {target}", number,
                        remediation="point the link at a committed file, or remove it "
                                    "(uncommitted scratch files cannot be cited)",
                    ))
    return issues


def check_diagrams(root: Path, facts: Facts) -> list[Issue]:
    """Diagram sources, rendered images, and the instructions that point at them."""
    issues: list[Issue] = []
    for entry in diagram_entries(root):
        source = root / str(entry["source"])
        image = root / str(entry["image"])
        if not source.exists() or source.stat().st_size == 0:
            issues.append(Issue(
                "diagram-source", str(entry["source"]),
                "the maintained diagram source is missing or empty",
                remediation="restore the committed .mmd and re-render the figure from it",
            ))
            continue
        if not image.exists() or image.stat().st_size == 0:
            issues.append(Issue(
                "diagram-source", str(entry["image"]),
                f"the rendered image is missing or empty; render it from {entry['source']}",
                remediation=f"python documentation/tools/render_mmd.py {entry['source']} {entry['image']}",
            ))
        else:
            data = image.read_bytes()
            if data[:8] != b"\x89PNG\r\n\x1a\n":
                issues.append(Issue(
                    "diagram-source", str(entry["image"]), "is not a PNG file",
                    remediation="re-render the figure as a PNG from the committed .mmd",
                ))
            elif entry.get("size"):
                actual = struct.unpack(">II", data[16:24])
                if tuple(actual) != tuple(entry["size"]):  # type: ignore[arg-type]
                    issues.append(Issue(
                        "diagram-source", str(entry["image"]),
                        f"is {actual[0]} × {actual[1]} px, but the document claims "
                        f"{entry['size'][0]} × {entry['size'][1]}; it was rendered from a different "  # type: ignore[index]
                        f"source (or at a different size) than {entry['source']}",
                        remediation="re-render at the documented size, or correct the documented size",
                    ))
            # Hygiene, not drift: the editable source should not be newer than its render.
            if image.exists() and source.stat().st_mtime > image.stat().st_mtime + 1:
                issues.append(Issue(
                    "diagram-stale-render", str(entry["image"]),
                    f"the editable source {entry['source']} is newer than this render",
                    severity="warning",
                    remediation=f"python documentation/tools/render_mmd.py {entry['source']} {entry['image']}",
                ))
            # Hygiene: a figure no current document cites is dead weight.
            cited = False
            for doc in doc_files(root):
                if doc.relative_to(root).as_posix() in HISTORICAL_FILES:
                    continue
                if doc == image:
                    continue
                text = doc.read_text(encoding="utf-8", errors="replace")
                if str(entry["image"]) in text or image.name in text:
                    cited = True
                    break
            if not cited:
                issues.append(Issue(
                    "diagram-uncited", str(entry["image"]),
                    "no current document cites this figure",
                    severity="warning",
                    remediation="cite it from the document it illustrates, or retire it",
                ))
        doc_rel = entry.get("doc")
        if not doc_rel:
            continue
        doc = root / str(doc_rel)
        if not doc.exists():
            issues.append(Issue(
                "diagram-source", str(doc_rel), "the explainer is missing",
                remediation="restore the explainer that documents this figure",
            ))
            continue
        text = doc.read_text(encoding="utf-8", errors="replace")
        if source.name not in text:
            issues.append(Issue(
                "diagram-source", str(doc_rel),
                f"never names the maintained source {source.name}; the figure cannot be traced "
                f"back to what generated it",
                remediation=f"name {entry['source']} as the editable source in the explainer",
            ))
        # A documented command may be wrapped across lines with trailing backslashes, so
        # unwrap continuations before looking for one statement that names both files.
        unwrapped = re.sub(r"\\\s*\n\s*", " ", text)
        command_lines = [line for line in unwrapped.splitlines()
                         if all(token in line for token in entry.get("command_tokens", []))]
        if entry.get("command_tokens") and not command_lines:
            issues.append(Issue(
                "diagram-source", str(doc_rel),
                "documents no render command that names both "
                f"{entry['source']} and {entry['image']}",
                remediation="document the exact render command next to the figure",
            ))
        for number, line in enumerate(text.splitlines(), 1):
            if re.search(r"\b(editable source|figure source)\b", line, re.IGNORECASE) and re.search(
                    r"\.tmp-run|\.tmp-docwork|/tmp|scratch", line, re.IGNORECASE):
                issues.append(Issue(
                    "diagram-source", str(doc_rel),
                    f"points at an uncommitted scratch file as the editable source: {line.strip()[:120]}",
                    number,
                    remediation="point at the committed .mmd instead",
                ))
    return issues


CHECKS = (check_test_counts, check_protection_layers, check_scheduling_claims,
          check_docx_sync, check_local_links, check_diagrams)
#: `check_test_counts` needs a real suite size; a missing ground truth means "cannot judge",
#: which the checker reports as a skipped check rather than as a pass.
SKIPPABLE = {"test-counts"}


def run_checks(root: Path, facts: Facts) -> list[Issue]:
    issues: list[Issue] = []
    for check in CHECKS:
        issues.extend(check(root, facts))
    return issues


def _print_suppressed(facts: Facts, verbose: bool) -> None:
    """Summarise the matches dropped by annotations or historical framing.

    Printed even when nothing else was found, so a suppressed file never looks like a
    file the checker simply did not read.
    """
    if not facts.suppressed:
        return
    print(f"{len(facts.suppressed)} match(es) suppressed as historical or annotated"
          + (":" if verbose else " (run with --verbose to list them)"))
    if verbose:
        for note in facts.suppressed:
            print(f"  suppressed: {note}")
    print()


def main(argv: list[str] | None = None) -> int:
    # Findings quote the documentation verbatim, and the documentation contains
    # em dashes, arrows and tick marks. On a console whose encoding cannot
    # represent them (cp1252 on Windows), printing a finding used to raise
    # UnicodeEncodeError and abort the run half way through the list — the
    # remaining findings were never shown, which is the opposite of the point.
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure is not None:
            try:
                reconfigure(encoding="utf-8", errors="replace")
            except (ValueError, OSError):  # a stream that cannot be reconfigured
                pass

    parser = argparse.ArgumentParser(description="Report documentation drift; never rewrites files.")
    parser.add_argument("--root", default=".", help="repository root (default: the working directory)")
    parser.add_argument("--run-suites", action="store_true",
                        help="DATABASE-TOUCHING: also run both suites so counts and assertions are verified "
                             "against a real run; avoid unless the environment is disposable")
    parser.add_argument("--json", action="store_true", help="emit machine-readable JSON")
    parser.add_argument("--verbose", action="store_true", help="also list suppressed and annotated matches")
    parser.add_argument("--list-rules", action="store_true", help="print rules, registries and the historical allowlist")
    parser.add_argument("--list-config", action="store_true", help="alias for --list-rules (historical allowlist)")
    args = parser.parse_args(argv)

    if args.list_rules or args.list_config:
        print("Checks:")
        for check in CHECKS:
            print(f"  {check.__name__.removeprefix('check_').replace('_', '-')}")
        print()
        print("Test-count and assertion claims are measured, never hard-coded:")
        print("  * engine tests   : counted from ai-engine/tests/test_*.py")
        print("  * backend tests  : phpunit --list-tests (declared with #[Test]/@test, so a static count cannot work)")
        print("  * assertions     : only with --run-suites, which is DATABASE-TOUCHING")
        print()
        print("Protection layers are anchored to the code that implements them:")
        for name, relpath, _patterns in PROTECTION_LAYERS:
            print(f"  * {name}: {relpath}")
        print()
        print("Registries:")
        print(f"  * DOCX pairs : {'build_docx.ARTIFACTS' if HAVE_BUILD_DOCX else 'fallback list (build_docx.py not importable)'}")
        print(f"  * figures    : {'DIAGRAMS + render_mmd.FIGURES' if HAVE_RENDER_MMD else 'DIAGRAMS fallback list'}")
        print()
        print("Inline annotations that suppress a match (reported by --verbose):")
        print("  * <!-- doc-drift:allow: reason -->        exempts its own line")
        print("  * <!-- doc-drift:allow-next: reason -->   exempts its line and the paragraph after it")
        print()
        print("Historical documents (skipped by every content check):")
        for rel, reason in sorted(HISTORICAL_FILES.items()):
            print(f"  {rel}: {reason}")
        return 0

    root = Path(args.root).resolve()
    try:
        facts = collect_facts(root, run_suites_flag=args.run_suites)
    except Exception as exc:  # configuration or environment failure, not documentation drift
        print(f"check_doc_drift: could not establish ground truth: {exc}", file=sys.stderr)
        return 2

    issues = run_checks(root, facts)

    skipped: list[str] = []
    if facts.backend_tests is None:
        skipped.append("backend test count (phpunit vendor/bin/phpunit --list-tests unavailable)")
    if facts.backend_assertions is None:
        skipped.append("assertion counts (run with --run-suites to verify them)")
    if facts.conflict_tests is None:
        skipped.append("conflict-suite size (phpunit unavailable)")
    if facts.conflict_assertions is None:
        skipped.append("conflict-suite assertions (run with --run-suites to verify them)")

    errors = [issue for issue in issues if issue.severity == "error"]
    warnings = [issue for issue in issues if issue.severity != "error"]

    if args.json:
        print(json.dumps({
            "facts": {
                "engine_tests": facts.engine_tests,
                "backend_tests": facts.backend_tests,
                "backend_assertions": facts.backend_assertions,
                "conflict_tests": facts.conflict_tests,
                "conflict_assertions": facts.conflict_assertions,
                "protection_layers": facts.protection_layers,
            },
            "sources": {"engine": facts.engine_source, "backend": facts.backend_source},
            "registries": {
                "docx_pairs": "build_docx.ARTIFACTS" if HAVE_BUILD_DOCX else "fallback",
                "figures": "render_mmd.FIGURES" if HAVE_RENDER_MMD else "fallback",
            },
            "skipped": skipped,
            "notes": facts.notes,
            "suppressed": facts.suppressed if args.verbose else len(facts.suppressed),
            "errors": len(errors),
            "warnings": len(warnings),
            "issues": [issue.__dict__ for issue in issues],
        }, indent=2))
        return 1 if errors else 0

    print("check_doc_drift — documentation vs code")
    print(f"  engine tests      : {facts.engine_tests}  ({facts.engine_source})")
    print(f"  backend tests     : {facts.backend_tests}  ({facts.backend_source})")
    print(f"  backend assertions: {facts.backend_assertions}")
    print(f"  conflict suite    : {facts.conflict_tests}")
    print(f"  conflict asserts  : {facts.conflict_assertions}")
    print(f"  protection layers : {facts.protection_layers}")
    for note in facts.notes:
        print(f"  note: {note}")
    for item in skipped:
        print(f"  SKIPPED: {item}")
    print()

    if not errors and not warnings:
        _print_suppressed(facts, args.verbose)
        print("No drift found.")
        return 0

    if errors:
        print(f"{len(errors)} drift finding(s):\n")
        for issue in errors:
            print(issue)
            print()
    if warnings:
        print(f"{len(warnings)} warning(s):\n")
        for issue in warnings:
            print(issue)
            print()
    _print_suppressed(facts, args.verbose)
    if errors:
        print("Review each finding and correct the documentation. This checker never edits files.")
    else:
        print("No drift found (warnings above are hygiene, not drift).")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
