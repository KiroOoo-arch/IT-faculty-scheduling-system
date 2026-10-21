#!/usr/bin/env python3
"""Build the project's Word documents from their authoritative Markdown sources.

Every ``.docx`` under ``documentation/`` that has a Markdown source is registered
in :data:`ARTIFACTS` below, so the source of each Word file and the command that
regenerates it are both in the repository rather than in someone's memory.

Run from the repository root::

    python documentation/tools/build_docx.py --list          # sources and outputs
    python documentation/tools/build_docx.py --all           # rebuild every artifact
    python documentation/tools/build_docx.py System_Defense_Guide
    python documentation/tools/build_docx.py documentation/System_Defense_Guide.md \
        --out .tmp-run/defense-guide.docx                    # test build elsewhere

Requires ``python-docx`` (``pip install python-docx``). Nothing else is needed, and
nothing outside the named output file is written.

What the converter keeps from the Markdown: heading levels, ordered and nested
bullet lists, block quotes, GitHub-style tables, fenced code blocks (monospace,
``mermaid`` blocks included), horizontal rules, and inline bold, italic and code
spans. Links are rendered as ``text (target)`` for absolute URLs and as plain
text for repository-relative paths, because a Word file has no repository to
resolve them against.
"""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]

PROVENANCE_TEMPLATE = (
    "Generated from {source} by documentation/tools/build_docx.py "
    "\u2014 edit the Markdown source, not this file."
)


@dataclass(frozen=True)
class Artifact:
    """A Word document and the Markdown it is generated from."""

    source: str
    output: str
    title: str
    toc: bool = False
    provenance: bool = False
    notes: str = ""


ARTIFACTS: dict[str, Artifact] = {
    "System_Defense_Guide": Artifact(
        source="documentation/System_Defense_Guide.md",
        output="documentation/System_Defense_Guide.docx",
        title="IT Faculty Scheduling System \u2014 Design Defense Guide",
        toc=True,
        provenance=True,
        notes="Defense guide; rebuilt from the Markdown source of record.",
    ),
    "System_Complete_Guide": Artifact(
        source="documentation/System_Complete_Guide.md",
        output="documentation/System_Complete_Guide.docx",
        title="IT Faculty Scheduling System \u2014 Complete Guide",
        notes="Legacy layout kept as committed; the Markdown is the source of record.",
    ),
    "Manuscript_Update_Brief": Artifact(
        source="documentation/Manuscript_Update_Brief.md",
        output="documentation/Manuscript_Update_Brief.docx",
        title="Manuscript Update Brief \u2014 Faculty Scheduling System",
        notes="Dated handoff brief; its counts are historical by design.",
    ),
}

INLINE_RE = re.compile(r"(\*\*.+?\*\*|`[^`]+`|\*[^*`\s][^*`]*?\*)")
LINK_RE = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")
HTML_COMMENT_RE = re.compile(r"<!--.*?-->")
HEADING_RE = re.compile(r"^(#{1,6})\s+(.*)$")
ORDERED_RE = re.compile(r"^(\s*)(\d+)[.)]\s+(.*)$")
BULLET_RE = re.compile(r"^(\s*)[-*+]\s+(.*)$")
FENCE_RE = re.compile(r"^```(.*)$")
RULE_RE = re.compile(r"^\s*(-{3,}|\*{3,}|_{3,})\s*$")
TABLE_SEP_RE = re.compile(r"^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?$")


class MarkdownToDocx:
    """Minimal, dependency-light Markdown to Word conversion."""

    def __init__(self, docx_module, *, toc: bool = False, provenance: str | None = None):
        self.docx = docx_module
        self.shared = docx_module.shared
        self.doc = docx_module.Document()
        self.doc.styles["Normal"].font.size = self.shared.Pt(11)
        self.toc = toc
        self.provenance = provenance

    # -- helpers ---------------------------------------------------------
    def _plain(self, text: str) -> str:
        text = LINK_RE.sub(lambda m: m.group(1), text)
        text = INLINE_RE.sub(lambda m: m.group(0).strip("*`"), text)
        return text.replace("**", "").replace("`", "").strip()

    def _add_paragraph(self, text: str, style: str | None = None):
        paragraph = self.doc.add_paragraph(style=style)
        self._add_inline(paragraph, text)
        return paragraph

    def _add_inline(self, paragraph, text: str) -> None:
        text = LINK_RE.sub(
            lambda m: m.group(1)
            if not m.group(2).startswith(("http://", "https://"))
            else f"{m.group(1)} ({m.group(2)})",
            text,
        )
        for part in INLINE_RE.split(text):
            if not part:
                continue
            if part.startswith("**") and part.endswith("**") and len(part) > 4:
                paragraph.add_run(part[2:-2]).bold = True
            elif part.startswith("`") and part.endswith("`") and len(part) > 2:
                run = paragraph.add_run(part[1:-1])
                run.font.name = "Consolas"
                run.font.size = self.shared.Pt(9.5)
            elif part.startswith("*") and part.endswith("*") and len(part) > 2:
                paragraph.add_run(part[1:-1]).italic = True
            else:
                paragraph.add_run(part)

    def _add_table(self, rows: list[str]) -> None:
        cells = [[c.strip() for c in row.strip().strip("|").split("|")] for row in rows]
        cells = [[c for c in row] for row in cells if not TABLE_SEP_RE.match("|".join(row))]
        if not cells or not cells[0]:
            return
        body = [row for row in cells[1:]]
        table = self.doc.add_table(rows=len(cells), cols=len(cells[0]))
        table.style = "Light Grid Accent 1"
        for i, row in enumerate([cells[0], *body]):
            for j, value in enumerate(row):
                if j >= len(table.rows[i].cells):
                    continue
                cell = table.rows[i].cells[j]
                cell.text = ""
                paragraph = cell.paragraphs[0]
                self._add_inline(paragraph, value)
                for run in paragraph.runs:
                    run.font.size = self.shared.Pt(10)
                    if i == 0:
                        run.font.bold = True

    def _add_code(self, code: list[str]) -> None:
        run = self.doc.add_paragraph().add_run("\n".join(code))
        run.font.name = "Consolas"
        run.font.size = self.shared.Pt(9)

    def _add_quote(self, lines: list[str]) -> None:
        for line in lines:
            self.doc.add_paragraph(self._plain(line), style="Quote")

    # -- blocks ----------------------------------------------------------
    def _parse(self, lines: list[str]):
        """Yield (kind, payload) blocks, skipping frontmatter and HTML comments."""
        index = 0
        if lines and lines[0].strip() == "---":
            for end in range(1, len(lines)):
                if lines[end].strip() in ("---", "..."):
                    index = end + 1
                    break
        while index < len(lines):
            raw = lines[index]
            line = HTML_COMMENT_RE.sub("", raw)
            stripped = line.strip()
            if not stripped:
                index += 1
                continue
            fence = FENCE_RE.match(stripped)
            if fence:
                index += 1
                code: list[str] = []
                while index < len(lines) and not FENCE_RE.match(lines[index].strip()):
                    code.append(lines[index])
                    index += 1
                index += 1
                yield ("code", (fence.group(1).strip(), code))
                continue
            if RULE_RE.match(stripped) and not stripped.startswith("|"):
                index += 1
                continue
            heading = HEADING_RE.match(stripped)
            if heading:
                index += 1
                yield ("heading", (len(heading.group(1)), heading.group(2).strip()))
                continue
            if stripped.startswith("|"):
                rows: list[str] = []
                while index < len(lines) and lines[index].strip().startswith("|"):
                    rows.append(lines[index].strip())
                    index += 1
                yield ("table", rows)
                continue
            if stripped.startswith(">"):
                quote: list[str] = []
                while index < len(lines) and lines[index].strip().startswith(">"):
                    quote.append(lines[index].strip().lstrip(">").strip())
                    index += 1
                yield ("quote", quote)
                continue
            ordered = ORDERED_RE.match(line)
            if ordered:
                index += 1
                yield ("ordered", (ordered.group(1), ordered.group(3).strip()))
                continue
            bullet = BULLET_RE.match(line)
            if bullet:
                index += 1
                yield ("bullet", (bullet.group(1), bullet.group(2).strip()))
                continue
            index += 1
            yield ("paragraph", stripped)

    def render(self, markdown: str) -> None:
        blocks = list(self._parse(markdown.splitlines()))
        if self.toc:
            self._add_toc(blocks)
        for kind, payload in blocks:
            if kind == "heading":
                level, text = payload
                self.doc.add_heading(self._plain(text), level=min(level, 4) - 1)
                if self.provenance and level == 1:
                    paragraph = self.doc.add_paragraph()
                    run = paragraph.add_run(self.provenance)
                    run.italic = True
                    run.font.size = self.shared.Pt(9)
            elif kind == "code":
                _language, code = payload
                self._add_code(code)
            elif kind == "table":
                self._add_table(payload)
            elif kind == "quote":
                self._add_quote(payload)
            elif kind == "ordered":
                indent, text = payload
                number_level = min(len(indent) // 2 + 1, 3)
                style = "List Number" if number_level == 1 else f"List Number {number_level}"
                self._add_paragraph(text, style=style)
            elif kind == "bullet":
                indent, text = payload
                level = min(len(indent) // 2 + 1, 3)
                style = "List Bullet" if level == 1 else f"List Bullet {level}"
                self._add_paragraph(text, style=style)
            else:
                self._add_paragraph(payload)

    def _add_toc(self, blocks) -> None:
        headings = [(h[0], h[1]) for kind, h in blocks if kind == "heading" and h[0] <= 3]
        if not headings:
            return
        self.doc.add_heading("Contents", level=0)
        counters = [0, 0, 0]
        for level, text in headings:
            if level == 1:
                continue
            counters[level - 2] += 1
            for deeper in range(level - 1, 3):
                counters[deeper] = 0
            number = ".".join(str(c) for c in counters[: level - 1])
            paragraph = self.doc.add_paragraph()
            run = paragraph.add_run(f"{number}  {self._plain(text)}")
            run.font.size = self.shared.Pt(11 if level == 2 else 10)
            if level == 2:
                run.bold = True

    def save(self, output: Path) -> None:
        output.parent.mkdir(parents=True, exist_ok=True)
        self.doc.save(output)


def _docx_module():
    try:
        import docx  # noqa: F401
    except ImportError:  # pragma: no cover - environment guidance
        sys.exit(
            "python-docx is required to build the Word documents.\n"
            "Install it in a virtual environment: python -m pip install python-docx"
        )
    return docx


def resolve(name: str) -> Artifact:
    if name in ARTIFACTS:
        return ARTIFACTS[name]
    candidate = Path(name)
    if candidate.suffix == ".md":
        for artifact in ARTIFACTS.values():
            if Path(artifact.source) == candidate:
                return artifact
    raise SystemExit(f"unknown artifact {name!r}; try --list")


def build(artifact: Artifact, *, output: Path | None = None) -> Path:
    docx = _docx_module()
    source = REPO_ROOT / artifact.source
    if not source.is_file():
        raise SystemExit(f"missing Markdown source: {artifact.source}")
    target = output or (REPO_ROOT / artifact.output)
    provenance = (
        PROVENANCE_TEMPLATE.format(source=artifact.source) if artifact.provenance else None
    )
    builder = MarkdownToDocx(docx, toc=artifact.toc, provenance=provenance)
    builder.render(source.read_text(encoding="utf-8"))
    builder.save(target)
    return target


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Build the project's Word documents from their Markdown sources.",
        epilog="Run from the repository root. No file other than the named output is written.",
    )
    parser.add_argument("artifact", nargs="?", help="artifact name, e.g. System_Defense_Guide")
    parser.add_argument("--all", action="store_true", help="rebuild every registered artifact")
    parser.add_argument("--list", action="store_true", help="list sources, outputs and commands")
    parser.add_argument("--out", help="write the document here instead of the registered output")
    args = parser.parse_args(argv)

    if args.list or (not args.artifact and not args.all):
        for name, artifact in ARTIFACTS.items():
            print(f"{name}")
            print(f"  source: {artifact.source}")
            print(f"  output: {artifact.output}")
            print(f"  build : python documentation/tools/build_docx.py {name}")
            if artifact.notes:
                print(f"  note  : {artifact.notes}")
        return 0

    targets = list(ARTIFACTS.values()) if args.all else [resolve(args.artifact)]
    for artifact in targets:
        if args.out and len(targets) > 1:
            raise SystemExit("--out can only be used with a single artifact")
        written = build(artifact, output=Path(args.out) if args.out else None)
        print(f"built {written.relative_to(REPO_ROOT) if written.is_absolute() else written}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
