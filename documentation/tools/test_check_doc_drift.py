"""Self-tests and fixtures for check_doc_drift.py.

Every test builds a throwaway repository in a temporary directory, so the suite never
depends on the real documentation and never writes to the working tree. Run it with:

    python -m unittest discover -s documentation/tools -p "test_*.py"
    # or simply
    python documentation/tools/test_check_doc_drift.py

Covered cases (the fixtures named in the tool's requirements):
  * a passing documentation set            -> no findings, exit 0
  * a stale current test count             -> finding, exit 1
  * an outdated current scheduling claim   -> finding
  * an intentionally historical document   -> no finding
  * a DOCX that no longer matches its Markdown, a missing diagram source, and a
    protection-layer count that disagrees with the code
"""
from __future__ import annotations

import contextlib
import io
import struct
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_doc_drift as drift  # noqa: E402  (path set up above)

try:
    from docx import Document as _DocxDocument
    HAVE_DOCX = True
except Exception:  # pragma: no cover - python-docx is a declared generator dependency
    HAVE_DOCX = False


class FixtureRepo:
    """A minimal repository tree that the checks can be pointed at."""

    def __init__(self) -> None:
        self._tmp = tempfile.TemporaryDirectory(prefix="docdrift-")
        self.root = Path(self._tmp.name)

    def write(self, relpath: str, text: str) -> Path:
        path = self.root / relpath
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
        return path

    def write_bytes(self, relpath: str, data: bytes) -> Path:
        path = self.root / relpath
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return path

    def write_docx(self, relpath: str, paragraphs: list[str]) -> Path:
        document = _DocxDocument()
        for text in paragraphs:
            document.add_paragraph(text)
        path = self.root / relpath
        path.parent.mkdir(parents=True, exist_ok=True)
        document.save(str(path))
        return path

    def mark_historical(self, relpath: str, reason: str = "fixture: dated record") -> None:
        patch = mock.patch.dict(drift.HISTORICAL_FILES, {relpath: reason})
        patch.start()
        self._patches = getattr(self, "_patches", [])
        self._patches.append(patch)

    def layer(self, relpath: str, text: str, name: str = "fixture layer") -> None:
        """Temporarily replace the protection-layer inventory with fixture anchors."""
        self.layers([(name, relpath, [text])])

    def diagrams(self, entries: list[dict]) -> None:
        """Temporarily replace the diagram inventory with fixture entries."""
        patch = mock.patch.object(drift, "DIAGRAMS", list(entries))
        patch.start()
        self._patches = getattr(self, "_patches", [])
        self._patches.append(patch)

    def layers(self, inventory: list[tuple[str, str, list[str]]]) -> None:
        patch = mock.patch.object(drift, "PROTECTION_LAYERS", list(inventory))
        patch.start()
        self._patches = getattr(self, "_patches", [])
        self._patches.append(patch)

    def cleanup(self) -> None:
        for patch in getattr(self, "_patches", []):
            patch.stop()
        self._tmp.cleanup()


class DriftTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self.repo = FixtureRepo()
        self.addCleanup(self.repo.cleanup)

    def run_main(self, *extra: str) -> tuple[int, str]:
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = drift.main(["--root", str(self.repo.root), *extra])
        return code, out.getvalue()


class PassingDocumentationTest(DriftTestCase):
    """Fixture 1: a documentation set that matches the code."""

    def test_clean_documentation_reports_nothing_and_exits_zero(self) -> None:
        # The protection layers are anchored to code, so the fixture supplies that code.
        self.repo.write("code/guard.py", "def generate_schedule():\n    pass\n")
        self.repo.write("code/gate.py", "def find_publish_conflicts():\n    pass\n")
        self.repo.layers([
            ("solver constraints at generation time", "code/guard.py", ["def generate_schedule"]),
            ("publish conflict gate", "code/gate.py", ["def find_publish_conflicts"]),
        ])
        # Likewise the figure: a source, its rendering, and the command that produces it.
        self.repo.write("documentation/screenshots/demo-flow.mmd", "flowchart TD\n  A --> B\n")
        self.repo.write_bytes("documentation/screenshots/9-demo-flow.png",
                              b"\x89PNG\r\n\x1a\n" + b"\x00" * 32)
        self.repo.write("documentation/Explainer-Demo.md", (
            "Source: `documentation/screenshots/demo-flow.mmd`\n\n"
            "```\nmmdc -i documentation/screenshots/demo-flow.mmd "
            "-o documentation/screenshots/9-demo-flow.png\n```\n"
        ))
        self.repo.diagrams([{
            "source": "documentation/screenshots/demo-flow.mmd",
            "image": "documentation/screenshots/9-demo-flow.png",
            "doc": "documentation/Explainer-Demo.md",
            "command_tokens": ["mmdc", "demo-flow.mmd", "9-demo-flow.png"],
        }])
        self.repo.write("README.md", (
            "# Demo\n\n"
            "The backend suite has 127 tests and the engine suite has 118 tests.\n"
            "Schedule generation takes a term-scoped lock, checks cross-section conflicts\n"
            "against draft, approved and published sessions in the same term, and replaces\n"
            "the previous draft inside one transaction.\n"
            "The system has two protection layers.\n"
        ))
        facts = drift.Facts(engine_tests=118, backend_tests=127,
                            backend_assertions=537, conflict_tests=25)
        issues = drift.run_checks(self.repo.root, facts)
        self.assertEqual([], [str(issue) for issue in issues])

        code, output = self.run_main()
        self.assertEqual(0, code, output)
        self.assertIn("No drift found.", output)


class StaleTestCountTest(DriftTestCase):
    """Fixture 2: a current document carrying an out-of-date suite size."""

    def test_stale_backend_count_is_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Verified: the backend suite has 99 tests and all pass.\n")
        facts = drift.Facts(engine_tests=118, backend_tests=127)
        issues = drift.check_test_counts(self.repo.root, facts)
        self.assertEqual(1, len(issues))
        self.assertEqual("test-counts", issues[0].check)
        self.assertIn("claims 99 tests", issues[0].message)
        self.assertIn("127", issues[0].message)
        self.assertEqual("documentation/Status.md", issues[0].path)

    def test_correct_count_is_not_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Verified: the backend suite has 127 tests and all pass.\n")
        facts = drift.Facts(backend_tests=127)
        self.assertEqual([], drift.check_test_counts(self.repo.root, facts))

    def test_cited_count_in_a_correction_table_is_not_reported(self) -> None:
        """A quoted number is someone else's claim being corrected, not an assertion."""
        self.repo.write("documentation/Status.md", (
            "| Old claim | Correct now |\n"
            "|---|---|\n"
            '| "6/6 backend tests" | 127 backend tests |\n'
        ))
        facts = drift.Facts(backend_tests=127)
        issues = drift.check_test_counts(self.repo.root, facts)
        self.assertEqual([], [str(issue) for issue in issues])


class StaleSchedulingClaimTest(DriftTestCase):
    """Fixture 3: a current document describing behaviour the code no longer has."""

    def test_outdated_draft_claim_is_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Conflict checks ignore drafts, so only published schedules count.\n")
        issues = drift.check_scheduling_claims(self.repo.root, drift.Facts())
        self.assertTrue(issues, "an outdated draft claim must be reported")
        self.assertEqual("scheduling-claims", issues[0].check)
        self.assertIn("now count as live bookings", issues[0].message)

    def test_excluded_draft_claim_is_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Generation excludes drafts when it looks for conflicts.\n")
        issues = drift.check_scheduling_claims(self.repo.root, drift.Facts())
        self.assertEqual(1, len(issues), [str(i) for i in issues])

    def test_negated_claim_is_not_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Generation does not ignore drafts when it looks for conflicts.\n")
        self.assertEqual([], [str(i) for i in drift.check_scheduling_claims(self.repo.root, drift.Facts())])

    def test_current_description_is_not_reported(self) -> None:
        self.repo.write("documentation/Status.md", (
            "Conflict checks consider draft, approved and published sessions in the same\n"
            "academic year and semester, and the previous draft is archived inside the\n"
            "transaction that writes its replacement.\n"
        ))
        issues = drift.check_scheduling_claims(self.repo.root, drift.Facts())
        self.assertEqual([], [str(issue) for issue in issues])

    def test_end_to_end_exit_code_is_nonzero_on_drift(self) -> None:
        self.repo.write("documentation/Status.md",
                        "The generator archives old drafts first, before calling the engine.\n")
        code, output = self.run_main()
        self.assertEqual(1, code, output)
        self.assertIn("drift finding", output)
        self.assertIn("documentation/Status.md", output)


class HistoricalDocumentTest(DriftTestCase):
    """Fixture 4: a document that is allowed to describe an older state."""

    def test_allowlisted_historical_document_is_skipped(self) -> None:
        rel = "documentation/Progress-report.md"
        self.repo.write(rel, "As of that milestone the backend suite had 99 tests.\n")
        self.repo.mark_historical(rel)
        facts = drift.Facts(backend_tests=127)
        self.assertEqual([], drift.check_test_counts(self.repo.root, facts))
        # A historical marker in a current document is framing too, so the line is skipped.
        self.repo.write("documentation/Status.md",
                        "As of that milestone the backend suite had 99 tests.\n")
        self.assertEqual([], drift.check_test_counts(self.repo.root, facts),
                         "a line with a historical marker is framing, not a claim")

    def test_historical_marker_suppresses_a_claim_without_an_allowlist_entry(self) -> None:
        self.repo.write("documentation/Status.md",
                        "Previously the backend suite had 99 tests; it has since grown.\n")
        facts = drift.Facts(backend_tests=127)
        self.assertEqual([], drift.check_test_counts(self.repo.root, facts))

    def test_allowlist_is_documented(self) -> None:
        self.repo.mark_historical("documentation/Progress-report.md", "fixture reason")
        self.assertTrue(all(reason for reason in drift.HISTORICAL_FILES.values()),
                        "every historical entry needs a written reason")
        code, output = self.run_main("--list-config")
        self.assertEqual(0, code)
        self.assertIn("Historical documents", output)


class ProtectionLayerTest(DriftTestCase):
    def test_wrong_layer_count_is_reported(self) -> None:
        self.repo.write("code/guard.py", "def generate_schedule():\n    pass\n")
        self.repo.layer("code/guard.py", "def generate_schedule")
        self.repo.write("documentation/Status.md", "The design has three protection layers.\n")
        issues = drift.check_protection_layers(self.repo.root, drift.Facts())
        self.assertEqual(1, len(issues), [str(issue) for issue in issues])
        self.assertIn("three protection layers", issues[0].message)

    def test_matching_layer_count_is_clean(self) -> None:
        self.repo.write("code/guard.py", "def generate_schedule():\n    pass\n")
        self.repo.layer("code/guard.py", "def generate_schedule")
        self.repo.write("documentation/Status.md",
                        "The design has one protection layer.\n"
                        "The architecture is described in four layers of components.\n")
        self.assertEqual([], [str(i) for i in drift.check_protection_layers(self.repo.root, drift.Facts())])

    def test_missing_code_anchor_is_reported(self) -> None:
        self.repo.layer("code/guard.py", "def generate_schedule")
        issues = drift.check_protection_layers(self.repo.root, drift.Facts())
        self.assertTrue(any("no longer matches the code" in i.message for i in issues))


@unittest.skipUnless(HAVE_DOCX, "python-docx is required to build DOCX fixtures")
class DocxSyncTest(DriftTestCase):
    def _pair(self) -> tuple[str, str]:
        md = "documentation/Guide.md"
        docx = "documentation/Guide.docx"
        self.repo.write(md, "# Guide\n\n## 1. Overview\n\nThe lock is term-scoped.\n")
        return md, docx

    def test_matching_docx_is_not_reported(self) -> None:
        md, docx = self._pair()
        self.repo.write_docx(docx, ["Guide", "1. Overview", "The lock is term-scoped."])
        with mock.patch.object(drift, "DOCX_PAIRS", [(md, docx)]):
            self.assertEqual([], [str(i) for i in drift.check_docx_sync(self.repo.root, drift.Facts())])

    def test_docx_missing_a_section_is_reported(self) -> None:
        md, docx = self._pair()
        self.repo.write_docx(docx, ["Guide", "The lock is term-scoped."])
        with mock.patch.object(drift, "DOCX_PAIRS", [(md, docx)]):
            issues = drift.check_docx_sync(self.repo.root, drift.Facts())
        messages = " ".join(str(i) for i in issues)
        self.assertIn("heading(s) from", messages)

    def test_ordered_list_markers_are_not_content_drift(self) -> None:
        """Word recreates numbering from the paragraph style, so "1." never reaches the text."""
        md, docx = self._pair()
        self.repo.write(md, "# Guide\n\n1. Acquire the lock\n2. Call the engine\n")
        self.repo.write_docx(docx, ["Guide", "Acquire the lock", "Call the engine"])
        with mock.patch.object(drift, "DOCX_PAIRS", [(md, docx)]):
            self.assertEqual([], [str(i) for i in drift.check_docx_sync(self.repo.root, drift.Facts())])

    def test_unreadable_docx_is_a_finding_not_a_crash(self) -> None:
        md, docx = self._pair()
        self.repo.write(docx, "not a zip archive")
        with mock.patch.object(drift, "DOCX_PAIRS", [(md, docx)]):
            issues = drift.check_docx_sync(self.repo.root, drift.Facts())
        self.assertTrue(any("could not be read" in i.message for i in issues), [str(i) for i in issues])


class DiagramCheckTest(DriftTestCase):
    PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 32

    def _entry(self) -> dict:
        return {
            "source": "documentation/screenshots/x-flow.mmd",
            "image": "documentation/screenshots/9-x-flow.png",
            "doc": "documentation/Explainer-X.md",
            "command_tokens": ["mmdc", "x-flow.mmd", "9-x-flow.png"],
        }

    def test_missing_source_and_image_are_reported(self) -> None:
        self.repo.write("documentation/Explainer-X.md", "The figure shows the flow.\n")
        with mock.patch.object(drift, "DIAGRAMS", [self._entry()]):
            issues = drift.check_diagrams(self.repo.root, drift.Facts())
        messages = " ".join(str(i) for i in issues)
        self.assertIn("maintained diagram source is missing", messages)

    def test_doc_without_a_traceable_source_is_reported(self) -> None:
        self.repo.write("documentation/screenshots/x-flow.mmd", "flowchart TD\n  A --> B\n")
        self.repo.write_bytes("documentation/screenshots/9-x-flow.png", self.PNG)
        self.repo.write("documentation/Explainer-X.md", "The figure shows the flow.\n")
        with mock.patch.object(drift, "DIAGRAMS", [self._entry()]):
            issues = drift.check_diagrams(self.repo.root, drift.Facts())
        messages = " ".join(str(i) for i in issues)
        self.assertIn("never names the maintained source", messages)
        self.assertIn("documents no render command", messages)

    def test_matching_source_image_and_command_are_clean(self) -> None:
        self.repo.write("documentation/screenshots/x-flow.mmd", "flowchart TD\n  A --> B\n")
        self.repo.write_bytes("documentation/screenshots/9-x-flow.png", self.PNG)
        self.repo.write("documentation/Explainer-X.md", (
            "Source: `documentation/screenshots/x-flow.mmd`\n\n"
            "Regenerate with:\n\n```\nmmdc -i documentation/screenshots/x-flow.mmd \\\n"
            "  -o documentation/screenshots/9-x-flow.png\n```\n"
        ))
        with mock.patch.object(drift, "DIAGRAMS", [self._entry()]):
            issues = drift.check_diagrams(self.repo.root, drift.Facts())
        self.assertEqual([], [str(i) for i in issues])

    def test_image_rendered_at_the_wrong_size_is_reported(self) -> None:
        """A figure rendered at another size (or from another source) must not pass silently."""
        self.repo.write("documentation/screenshots/x-flow.mmd", "flowchart TD\n  A --> B\n")
        # 10 x 4 px, while the entry (and so the document) claims 800 x 600.
        png = (b"\x89PNG\r\n\x1a\n" + b"\x00\x00\x00\rIHDR" + struct.pack(">II", 10, 4)
               + b"\x08\x06\x00\x00\x00" + b"\x00" * 16)
        self.repo.write_bytes("documentation/screenshots/9-x-flow.png", png)
        self.repo.write("documentation/Explainer-X.md", (
            "Source: `documentation/screenshots/x-flow.mmd`\n\n"
            "```\nmmdc -i documentation/screenshots/x-flow.mmd -o "
            "documentation/screenshots/9-x-flow.png\n```\n"
        ))
        entry = self._entry() | {"size": (800, 600)}
        with mock.patch.object(drift, "DIAGRAMS", [entry]):
            issues = drift.check_diagrams(self.repo.root, drift.Facts())
        self.assertTrue(any("is 10 × 4 px" in i.message for i in issues), [str(i) for i in issues])

    def test_wrapped_command_line_is_recognised(self) -> None:
        """A documented command may be wrapped with a trailing backslash."""
        self.repo.write("documentation/screenshots/x-flow.mmd", "flowchart TD\n  A --> B\n")
        self.repo.write_bytes("documentation/screenshots/9-x-flow.png", self.PNG)
        doc = self.repo.write("documentation/Explainer-X.md", (
            "Source: `documentation/screenshots/x-flow.mmd`\n\n"
            "```\nmmdc -i documentation/screenshots/x-flow.mmd -o "
            "documentation/screenshots/9-x-flow.png\n```\n"
        ))
        with mock.patch.object(drift, "DIAGRAMS", [self._entry()]):
            issues = drift.check_diagrams(self.repo.root, drift.Facts())
        self.assertEqual([], [str(i) for i in issues], doc.read_text(encoding="utf-8"))


class NeverWritesTest(DriftTestCase):
    """The checker must report drift, never repair it."""

    def test_checker_leaves_the_documentation_untouched(self) -> None:
        path = self.repo.write("documentation/Status.md",
                               "The generator archives old drafts before calling the engine.\n")
        before = path.read_bytes()
        code, _ = self.run_main()
        self.assertEqual(1, code)
        self.assertEqual(before, path.read_bytes())


if __name__ == "__main__":
    unittest.main(verbosity=2)
