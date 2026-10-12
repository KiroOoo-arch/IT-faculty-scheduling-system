"""Self-tests and fixtures for check_doc_drift.py (merged suite).

Every test builds a throwaway repository in a temporary directory, so the suite never
depends on the real documentation and never writes to the working tree. Run it with:

    python -m unittest discover -s documentation/tools -p "test_*.py"
    # or simply
    python documentation/tools/test_check_doc_drift.py

This file is the single canonical suite for the merged checker. It keeps the
check-level fixtures from the feature-branch implementation (mocked protection-layer
and diagram inventories, DOCX coverage, PNG dimensions) and adds the rule-level
fixtures from the laptop toolset (inline allow annotations, broken local links,
severity, registry reuse). There is deliberately no second suite in a `tests/`
subdirectory: two competing suites testing one module is how coverage silently
diverges.

The DOCX fixtures are written straight into the OOXML zip container, so the suite
runs on a machine that has never installed python-docx — the same dependency-light
property as the checker's own DOCX reader.

Covered cases:
  * a passing documentation set                  -> no findings, exit 0
  * a stale current test count                   -> finding, exit 1
  * an outdated current scheduling claim         -> finding
  * an intentionally historical document         -> no finding
  * a quoted/annotated old claim                 -> no finding, listed when --verbose
  * a DOCX that no longer matches its Markdown   -> finding
  * a missing diagram source / wrong dimensions  -> finding
  * a broken local Markdown link                 -> finding
  * a warning-only result                        -> reported, still exit 0
"""
from __future__ import annotations

import contextlib
import html
import io
import os
import struct
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_doc_drift as drift  # noqa: E402  (path set up above)


def write_docx(path: Path, paragraphs: list[str]) -> Path:
    """A minimal but real .docx holding one paragraph per line.

    Written with the standard library only, so no test skips itself just because
    python-docx is not installed.
    """
    body = "".join(
        '<w:p><w:r><w:t xml:space="preserve">%s</w:t></w:r></w:p>' % html.escape(text)
        for text in paragraphs
    )
    document = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        f"<w:body>{body}</w:body></w:document>"
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(path, "w") as archive:
        archive.writestr("word/document.xml", document)
    return path


class FixtureRepo:
    """A minimal repository tree that the checks can be pointed at."""

    def __init__(self) -> None:
        self._tmp = tempfile.TemporaryDirectory(prefix="docdrift-")
        self.root = Path(self._tmp.name)
        # A fixture-safe protection-layer inventory, so a test that only cares about some
        # other check is not failed by anchors pointing at the real repository. Tests that
        # assert on layer counting override this with layers().
        self.write("fixture_anchor.py", "def generate_schedule():\n    pass\n")
        self.layers([("fixture solver layer", "fixture_anchor.py", ["def generate_schedule"])])

    # -- writing -----------------------------------------------------------
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
        return write_docx(self.root / relpath, paragraphs)

    # -- patching module inventories --------------------------------------
    def _patch(self, patch: mock._patch) -> None:
        patch.start()
        self._patches = getattr(self, "_patches", [])
        self._patches.append(patch)

    def mark_historical(self, relpath: str, reason: str = "fixture: dated record") -> None:
        self._patch(mock.patch.dict(drift.HISTORICAL_FILES, {relpath: reason}))

    def layers(self, inventory: list[tuple[str, str, list[str]]]) -> None:
        """Temporarily replace the protection-layer inventory with fixture anchors."""
        self._patch(mock.patch.object(drift, "PROTECTION_LAYERS", list(inventory)))

    def layer(self, relpath: str, text: str, name: str = "fixture layer") -> None:
        self.layers([(name, relpath, [text])])

    def diagrams(self, entries: list[dict]) -> None:
        """Replace the diagram inventory, and blank the renderer registry too.

        The checker extends DIAGRAMS with anything registered in render_mmd.FIGURES,
        so a fixture that only patches DIAGRAMS would still pick up the real figures.
        """
        self._patch(mock.patch.object(drift, "DIAGRAMS", list(entries)))
        if drift.HAVE_RENDER_MMD:
            self._patch(mock.patch.object(drift.render_mmd, "FIGURES", {}))

    def docx_pairs(self, pairs: list[tuple[str, str]]) -> None:
        """Force the fallback pair list, so the fixture does not need the real registry."""
        self._patch(mock.patch.object(drift, "HAVE_BUILD_DOCX", False))
        self._patch(mock.patch.object(drift, "DOCX_PAIRS_FALLBACK", list(pairs)))

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

    def test_missing_ground_truth_is_not_a_pass_or_a_finding(self) -> None:
        """No backend listing means "cannot judge", so nothing is claimed either way."""
        self.repo.write("documentation/Status.md",
                        "The backend suite has 99 tests.\n")
        issues = drift.check_test_counts(self.repo.root, drift.Facts())
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


class InlineAnnotationTest(DriftTestCase):
    """Fixture 5 (ported from the laptop suite): annotated quotations are not claims."""

    def test_inline_allow_annotation_exempts_the_line(self) -> None:
        # `allow` is for a claim that sits on the annotated line itself.
        self.repo.write("documentation/Status.md", (
            "# Status\n\n"
            "The older brief said 99 backend tests. "
            "<!-- doc-drift:allow: quoting the old claim on purpose -->\n"
        ))
        issues = drift.check_test_counts(self.repo.root, drift.Facts(backend_tests=127))
        self.assertEqual([], [str(issue) for issue in issues])

    def test_allow_next_annotation_exempts_the_following_block(self) -> None:
        self.repo.write("documentation/Status.md", (
            "# Status\n\n"
            "<!-- doc-drift:allow-next: a quoted before/after table -->\n"
            "| Old | New |\n"
            "| 99 backend tests | 127 backend tests |\n"
        ))
        issues = drift.check_test_counts(self.repo.root, drift.Facts(backend_tests=127))
        self.assertEqual([], [str(issue) for issue in issues])

    def test_annotation_outside_its_block_does_not_exempt_everything(self) -> None:
        """allow-next covers exactly one paragraph, not the rest of the file."""
        self.repo.write("documentation/Status.md", (
            "# Status\n\n"
            "<!-- doc-drift:allow-next: only the next paragraph -->\n"
            "The older brief said 99 backend tests.\n\n"
            "The suite now has 99 backend tests.\n"
        ))
        issues = drift.check_test_counts(self.repo.root, drift.Facts(backend_tests=127))
        self.assertEqual(1, len(issues), [str(i) for i in issues])
        self.assertEqual(6, issues[0].line)

    def test_suppressed_matches_are_listed_by_verbose(self) -> None:
        self.repo.write("documentation/Status.md", (
            "# Status\n\n"
            "The older brief said 99 backend tests. <!-- doc-drift:allow: quoting -->\n"
        ))
        self.repo.diagrams([])  # keep the real figure registry out of this fixture
        _code, quiet = self.run_main()
        self.assertIn("suppressed", quiet)
        self.assertNotIn("inline doc-drift:allow", quiet)
        _code, loud = self.run_main("--verbose")
        self.assertIn("inline doc-drift:allow", loud)


class BrokenLocalLinkTest(DriftTestCase):
    """Fixture 6 (ported from the laptop suite): repository links must resolve."""

    def test_broken_local_link_is_reported(self) -> None:
        self.repo.write("documentation/Status.md",
                        "![flow](screenshots/missing-flow.png)\n")
        issues = drift.check_local_links(self.repo.root, drift.Facts())
        self.assertEqual(1, len(issues), [str(i) for i in issues])
        self.assertEqual("broken-links", issues[0].check)
        self.assertIn("missing-flow.png", issues[0].message)
        self.assertTrue(issues[0].remediation, "every finding carries a fix hint")

    def test_existing_local_link_is_clean(self) -> None:
        self.repo.write_bytes("documentation/screenshots/ok.png", b"\x89PNG\r\n\x1a\n")
        self.repo.write("documentation/Status.md", "![flow](screenshots/ok.png)\n")
        self.assertEqual([], [str(i) for i in drift.check_local_links(self.repo.root, drift.Facts())])

    def test_external_and_anchor_links_are_ignored(self) -> None:
        self.repo.write("documentation/Status.md", (
            "[web](https://example.com/x) [mail](mailto:a@b.c) [anchor](#top)\n"
        ))
        self.assertEqual([], [str(i) for i in drift.check_local_links(self.repo.root, drift.Facts())])


class SeverityAndRemediationTest(DriftTestCase):
    """Fixture 7 (ported from the laptop suite): severity, and fixes on every finding."""

    def test_warning_only_result_does_not_fail_the_run(self) -> None:
        self.repo.write("documentation/Status.md", "# Status\n\nNothing to see.\n")
        self.repo.write_bytes("documentation/screenshots/lonely.png", b"\x89PNG\r\n\x1a\n")
        self.repo.diagrams([{
            "source": "documentation/screenshots/lonely.mmd",
            "image": "documentation/screenshots/lonely.png",
            "doc": None,
            "command_tokens": [],
            "size": None,
        }])
        self.repo.write("documentation/screenshots/lonely.mmd", "flowchart TD\n  A --> B\n")
        issues = drift.check_diagrams(self.repo.root, drift.Facts())
        warnings = [i for i in issues if i.severity == "warning"]
        self.assertTrue(warnings, [str(i) for i in issues])
        self.assertTrue(any("uncited" in w.check for w in warnings), [str(w) for w in warnings])
        code, output = self.run_main()
        self.assertEqual(0, code, output)
        self.assertIn("warning", output)

    def test_error_findings_carry_severity_and_remediation(self) -> None:
        self.repo.write("documentation/Status.md",
                        "The generator archives old drafts first, before calling the engine.\n")
        issues = drift.check_scheduling_claims(self.repo.root, drift.Facts())
        self.assertTrue(issues)
        self.assertEqual("error", issues[0].severity)
        self.assertTrue(issues[0].remediation)
        self.assertIn("fix  :", str(issues[0]))


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


class DocxSyncTest(DriftTestCase):
    """DOCX coverage fixtures. No python-docx needed, by design."""

    def _pair(self) -> tuple[str, str]:
        md = "documentation/Guide.md"
        docx = "documentation/Guide.docx"
        self.repo.write(md, "# Guide\n\n## 1. Overview\n\nThe lock is term-scoped.\n")
        self.repo.docx_pairs([(md, docx)])
        return md, docx

    def test_docx_reader_needs_no_third_party_dependency(self) -> None:
        self.repo.write_docx("documentation/Raw.docx", ["Alpha", "Beta"])
        self.assertIn("Alpha", drift.docx_text(self.repo.root / "documentation/Raw.docx"))

    def test_docx_reader_includes_table_text(self) -> None:
        path = self.repo.root / "documentation/Table.docx"
        path.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(path, "w") as archive:
            archive.writestr(
                "word/document.xml",
                '<?xml version="1.0"?><w:document '
                'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                "<w:body><w:tbl><w:tr><w:tc><w:p><w:r><w:t>CellValue</w:t></w:r></w:p>"
                "</w:tc></w:tr></w:tbl></w:body></w:document>",
            )
        self.assertIn("CellValue", drift.docx_text(path))

    def test_matching_docx_is_not_reported(self) -> None:
        md, docx = self._pair()
        self.repo.write_docx(docx, ["Guide", "1. Overview", "The lock is term-scoped."])
        self.assertEqual([], [str(i) for i in drift.check_docx_sync(self.repo.root, drift.Facts())])

    def test_docx_missing_a_section_is_reported(self) -> None:
        md, docx = self._pair()
        self.repo.write_docx(docx, ["Guide", "The lock is term-scoped."])
        issues = drift.check_docx_sync(self.repo.root, drift.Facts())
        messages = " ".join(str(i) for i in issues)
        self.assertIn("heading(s) from", messages)

    def test_ordered_list_markers_are_not_content_drift(self) -> None:
        """Word recreates numbering from the paragraph style, so "1." never reaches the text."""
        md, docx = self._pair()
        self.repo.write(md, "# Guide\n\n1. Acquire the lock\n2. Call the engine\n")
        self.repo.write_docx(docx, ["Guide", "Acquire the lock", "Call the engine"])
        self.assertEqual([], [str(i) for i in drift.check_docx_sync(self.repo.root, drift.Facts())])

    def test_unreadable_docx_is_a_finding_not_a_crash(self) -> None:
        md, docx = self._pair()
        self.repo.write(docx, "not a zip archive")
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
        self.repo.diagrams([self._entry()])
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
        self.repo.diagrams([self._entry()])
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
        self.repo.diagrams([entry])
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
        self.repo.diagrams([self._entry()])
        issues = drift.check_diagrams(self.repo.root, drift.Facts())
        self.assertEqual([], [str(i) for i in issues], doc.read_text(encoding="utf-8"))


class RegistryReuseTest(DriftTestCase):
    """Fixture 8 (ported from the laptop suite): one registry, not two copies."""

    def test_docx_pairs_come_from_the_generator_registry(self) -> None:
        pairs = drift.docx_pairs(self.repo.root)
        self.assertTrue(pairs, "the generator registry must produce pairs")
        if drift.HAVE_BUILD_DOCX:
            expected = [(a.source, a.output) for a in drift.build_docx.ARTIFACTS.values()]
            self.assertEqual(expected, pairs)

    def test_diagram_entries_include_the_renderer_registry(self) -> None:
        entries = drift.diagram_entries(self.repo.root)
        self.assertTrue(entries)
        if drift.HAVE_RENDER_MMD:
            sources = {str(e["source"]) for e in entries}
            for image, source in drift.render_mmd.FIGURES.items():
                self.assertIn(source, sources, f"{image} registered with the renderer but not checked")

    def test_a_registry_figure_without_an_explainer_is_still_traceable(self) -> None:
        """A figure only known to the renderer still gets source/image checks."""
        self.repo.write("documentation/screenshots/n.mmd", "flowchart TD\n  A --> B\n")
        self.repo.write_bytes("documentation/screenshots/n.png", DiagramCheckTest.PNG)
        self._patch_registry = mock.patch.object(drift, "DIAGRAMS", [])
        self._patch_registry.start()
        self.addCleanup(self._patch_registry.stop)
        if drift.HAVE_RENDER_MMD:
            # patch.object replaces the registry outright; patch.dict would *add* the
            # fixture entry to the real figures and re-introduce them as findings.
            registry = mock.patch.object(drift.render_mmd, "FIGURES",
                                         {"documentation/screenshots/n.png":
                                          "documentation/screenshots/n.mmd"})
            registry.start()
            self.addCleanup(registry.stop)
        issues = drift.check_diagrams(self.repo.root, drift.Facts())
        errors = [i for i in issues if i.severity == "error"]
        self.assertEqual([], [str(i) for i in errors])


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
