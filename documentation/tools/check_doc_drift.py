#!/usr/bin/env python3
"""Documentation drift checker for the IT faculty scheduling system.

Reports places where the *current* documentation no longer matches the code, the test
suites, or the sources the documents are generated from. It never rewrites anything —
it prints what disagrees and exits, so a developer can review and fix it.

    python documentation/tools/check_doc_drift.py                # fast, read-only checks
    python documentation/tools/check_doc_drift.py --run-suites    # also run both suites and
                                                                 # verify counts *and* assertions
    python documentation/tools/check_doc_drift.py --json          # machine-readable output
    python -m unittest discover -s documentation/tools -p "test_*.py"   # the checker's own tests

Exit status: 0 when every check passes, 1 when actionable drift was found, 2 when the
checker itself could not run its configuration.

What it checks
--------------
1. Test-count and assertion claims in current documents, against the numbers the
   repository actually has (counted from the test sources; with --run-suites, from a real
   run of both suites).
2. Claims about how many scheduling-protection layers exist, against the layers found in
   the code (each layer is anchored to the file that implements it, so the check fails if
   a mechanism is removed rather than merely renamed in prose).
3. Outdated descriptions of scheduling-conflict behaviour: drafts that are claimed not to
   count, plans that are claimed to reach the write without a conflict check, archiving
   that is claimed to happen before the engine is called, or a failed run that is claimed
   to cost the admin the existing draft.
4. Generated DOCX files that have drifted from the Markdown they are built from.
5. Diagram instructions that no longer match the maintained source: a missing source or
   image, an image that is not a PNG, or an explainer that still points at an uncommitted
   scratch file as the editable source.

Historical documents are not drift
----------------------------------
Documents that describe a state at a point in time (progress reports, bug logs, dated
manuscript briefs, superseded guides) are listed in HISTORICAL_FILES with a reason and are
skipped. Lines that frame a number as historical ("as of", "at the time", "has since
grown", "superseded", "outdated"…) are skipped inside files that are otherwise current,
so a document can honestly describe an older state without tripping the checker.
"""

from __future__ import annotations

import argparse
import json
import re
import struct
import subprocess
import sys
from dataclasses import dataclass, field
from pathlib import Path

# --------------------------------------------------------------------------------------
# Configuration — kept explicit and documented rather than spread through the checks.
# --------------------------------------------------------------------------------------

#: Documents that intentionally describe an earlier state. They are skipped by every
#: content check; the reason is printed with --list-config so the list stays honest.
HISTORICAL_FILES: dict[str, str] = {
    "documentation/Bug-Fix-Log.md": "chronological bug log for sessions 1–2 (2026-08-01/02)",
    "documentation/Progress-report.md": "progress report dated 2026-09-27 covering July–September",
    "documentation/Manuscript_Update_Brief.md": "manuscript brief 'as of 2026-09-09' for commits f04f0e8→eacdd66",
    "documentation/Manuscript_Update_Brief.docx": "generated from the historical brief above",
    "documentation/latestdoc.md": "development log snapshot, kept as a dated record",
    "documentation/System-Defense-Guide.docx": "legacy hand-formatted defence guide committed 2026-08-27 with no Markdown source; superseded by the reproducible System_Defense_Guide.* pair",
}

#: DOCX files and the Markdown each one is generated from. A pair is only checked when
#: both files exist; historical files are skipped via HISTORICAL_FILES.
DOCX_PAIRS: list[tuple[str, str]] = [
    ("documentation/System_Defense_Guide.md", "documentation/System_Defense_Guide.docx"),
    ("documentation/System_Complete_Guide.md", "documentation/System_Complete_Guide.docx"),
    ("documentation/Manuscript_Update_Brief.md", "documentation/Manuscript_Update_Brief.docx"),
]

#: Generated figures and the documents that explain them. `command_tokens` must all appear
#: on the render-command line the explainer documents.
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

#: Directories never scanned for documentation.
SKIP_DIRS = {".git", ".tmp-run", "node_modules", "vendor", ".venv", "__pycache__", "dist", ".idea", ".vscode"}

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

#: A DOCX must cover at least this share of the Markdown's words to count as in sync.
DOCX_COVERAGE_THRESHOLD = 0.97


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
    notes: list[str] = field(default_factory=list)


@dataclass
class Issue:
    check: str
    path: str
    message: str
    line: int | None = None

    def __str__(self) -> str:
        where = f"{self.path}:{self.line}" if self.line else self.path
        return f"[{self.check}] {where}\n    {self.message}"


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

    A static count is not good enough: data providers make one method several tests, so
    only the framework's own listing agrees with what `php artisan test` reports.
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

    The backend suite and the cross-section conflict suite are run separately: the
    documentation quotes their sizes independently, so each needs its own ground truth.
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


#: Straight and curly quoted spans. A claim inside one is being *cited* — the "old claim"
#: column of a corrections table, for instance — not asserted by the current document.
QUOTED_SPAN = re.compile(r'"[^"]*"|\u201c[^\u201d]*\u201d')


def is_cited(line: str, start: int, end: int) -> bool:
    """True when the matched text sits inside a quoted span on that line."""
    return any(span.start() <= start and end <= span.end() for span in QUOTED_SPAN.finditer(line))


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
        for number, line in stripped_lines(path):
            if has_historical_marker(line) or not SUITE_CONTEXT.search(line):
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
        ))
    expected = len(PROTECTION_LAYERS)
    for path in doc_files(root):
        rel = path.relative_to(root).as_posix()
        if is_historical(rel):
            continue
        for number, line in stripped_lines(path):
            lowered = line.lower()
            # Only *protection* layers are counted here. "four layers" in an architecture
            # or methodology sentence is a different claim and must not be flagged.
            if has_historical_marker(line) or "protection" not in lowered:
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
        for number, block in paragraph_blocks(path):
            lowered = block.lower()
            if has_historical_marker(block):
                continue
            if not re.search(r"draft|schedule|generat", lowered):
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
                ))
                break
    return issues


def _normalize_words(text: str) -> list[str]:
    """Words with Markdown punctuation and emphasis removed, for DOCX comparison."""
    text = re.sub(r"```[a-z]*", " ", text)
    text = re.sub(r"[*_`>#|]+", " ", text)
    # Ordered/bulleted list markers are rendering instructions, not content: Word re-creates
    # the numbering from the paragraph style, so the literal "1." never reaches the DOCX text.
    # Heading hashes are removed first, so "## 1. Overview" is treated like "1. Overview".
    text = re.sub(r"^[ \t]*(?:[-*+]|\d+[.)])[ \t]+", " ", text, flags=re.M)
    text = re.sub(r"<br\s*/?>", " ", text)
    text = re.sub(r"\s+", " ", text)
    return re.findall(r"[a-z0-9][a-z0-9'./:{}_-]*", text.lower())


def docx_text(path: Path) -> str:
    """All text in a DOCX: paragraphs plus every table cell."""
    from docx import Document  # imported lazily so the fast checks need no python-docx

    document = Document(str(path))
    parts = [p.text for p in document.paragraphs]
    for table in document.tables:
        for row in table.rows:
            for cell in row.cells:
                parts.append(cell.text)
    return "\n".join(parts)


def check_docx_sync(root: Path, facts: Facts) -> list[Issue]:
    """Generated DOCX files that no longer carry the Markdown they are built from."""
    issues: list[Issue] = []
    for md_rel, docx_rel in DOCX_PAIRS:
        if is_historical(md_rel) or is_historical(docx_rel):
            continue
        md_path, docx_path = root / md_rel, root / docx_rel
        if not md_path.exists() or not docx_path.exists():
            continue
        try:
            rendered = docx_text(docx_path)
        except Exception as exc:  # a corrupt or unreadable DOCX is a finding, not a crash
            issues.append(Issue("docx-sync", docx_rel, f"could not be read: {exc}"))
            continue
        md_words = _normalize_words(md_path.read_text(encoding="utf-8", errors="replace"))
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
                f"(threshold {DOCX_COVERAGE_THRESHOLD:.0%}); missing e.g. {examples}\n"
                f"      regenerate it from the Markdown and re-check",
            ))
        # headings are the structural contract: every Markdown heading must be present
        headings = [m.group(1).strip() for m in re.finditer(r"^#{1,4}\s+(.+)$",
                                                            md_path.read_text(encoding="utf-8", errors="replace"),
                                                            re.M)]
        missing_headings = [h for h in headings if _normalize_words(h) and
                            not all(w in docx_words for w in _normalize_words(h))]
        if missing_headings:
            issues.append(Issue(
                "docx-sync", docx_rel,
                f"{len(missing_headings)} heading(s) from {md_rel} are absent, e.g. "
                + "; ".join(missing_headings[:3]),
            ))
    return issues


def check_diagrams(root: Path, facts: Facts) -> list[Issue]:
    """Diagram sources, rendered images, and the instructions that point at them."""
    issues: list[Issue] = []
    for entry in DIAGRAMS:
        source = root / str(entry["source"])
        image = root / str(entry["image"])
        doc = root / str(entry["doc"])
        if not source.exists() or source.stat().st_size == 0:
            issues.append(Issue("diagram-source", str(entry["source"]),
                                "the maintained diagram source is missing or empty"))
            continue
        if not image.exists() or image.stat().st_size == 0:
            issues.append(Issue("diagram-source", str(entry["image"]),
                                f"the rendered image is missing or empty; render it from {entry['source']}"))
        else:
            data = image.read_bytes()
            if data[:8] != b"\x89PNG\r\n\x1a\n":
                issues.append(Issue("diagram-source", str(entry["image"]), "is not a PNG file"))
            elif entry.get("size"):
                actual = struct.unpack(">II", data[16:24])
                if tuple(actual) != tuple(entry["size"]):
                    issues.append(Issue(
                        "diagram-source", str(entry["image"]),
                        f"is {actual[0]} × {actual[1]} px, but the document claims "
                        f"{entry['size'][0]} × {entry['size'][1]}; it was rendered from a different "
                        f"source (or at a different size) than {entry['source']}",
                    ))
        if not doc.exists():
            issues.append(Issue("diagram-source", str(entry["doc"]), "the explainer is missing"))
            continue
        text = doc.read_text(encoding="utf-8", errors="replace")
        if source.name not in text:
            issues.append(Issue(
                "diagram-source", str(entry["doc"]),
                f"never names the maintained source {source.name}; the figure cannot be traced "
                f"back to what generated it",
            ))
        # A documented command may be wrapped across lines with trailing backslashes, so
        # unwrap continuations before looking for one statement that names both files.
        unwrapped = re.sub(r"\\\s*\n\s*", " ", text)
        command_lines = [line for line in unwrapped.splitlines()
                         if all(token in line for token in entry["command_tokens"])]
        if not command_lines:
            issues.append(Issue(
                "diagram-source", str(entry["doc"]),
                "documents no render command that names both "
                f"{entry['source']} and {entry['image']}",
            ))
        for number, line in enumerate(text.splitlines(), 1):
            if re.search(r"\b(editable source|figure source)\b", line, re.IGNORECASE) and re.search(
                    r"\.tmp-run|/tmp|scratch", line, re.IGNORECASE):
                issues.append(Issue(
                    "diagram-source", str(entry["doc"]),
                    f"points at an uncommitted scratch file as the editable source: {line.strip()[:120]}",
                    number,
                ))
    return issues


CHECKS = (check_test_counts, check_protection_layers, check_scheduling_claims, check_docx_sync, check_diagrams)
#: `check_test_counts` needs a real suite size; a missing ground truth means "cannot judge",
#: which the checker reports as a skipped check rather than as a pass.
SKIPPABLE = {"test-counts"}


def run_checks(root: Path, facts: Facts) -> list[Issue]:
    issues: list[Issue] = []
    for check in CHECKS:
        issues.extend(check(root, facts))
    return issues


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
                        help="also run both test suites so counts and assertions are verified against a real run")
    parser.add_argument("--json", action="store_true", help="emit machine-readable JSON")
    parser.add_argument("--list-config", action="store_true", help="print the historical allowlist and exit")
    args = parser.parse_args(argv)

    root = Path(args.root).resolve()
    if args.list_config:
        print("Historical documents (skipped by every content check):")
        for rel, reason in sorted(HISTORICAL_FILES.items()):
            print(f"  {rel}: {reason}")
        return 0

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
            "skipped": skipped,
            "notes": facts.notes,
            "issues": [issue.__dict__ for issue in issues],
        }, indent=2))
        return 1 if issues else 0

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

    if not issues:
        print("No drift found.")
        return 0

    print(f"{len(issues)} drift finding(s):\n")
    for issue in issues:
        print(issue)
        print()
    print("Review each finding and correct the documentation. This checker never edits files.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
