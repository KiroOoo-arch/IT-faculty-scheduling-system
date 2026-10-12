"""
Readiness and liveness coverage for ai-engine/api/app.py.

The engine cannot serve a request against a database that lacks the classification
schema — departments, the access table, and the ownership and classification
columns — and it must not advertise itself as ready when nothing has been
reviewed: every subject would then be unclassified and refused, so readiness would
be promising a service the engine cannot provide. `/ready` is the gate for that;
`/health` stays a liveness probe so a missing migration cannot send an
orchestrator into a restart loop.

Both endpoints are unauthenticated, so neither may return exception text, SQL or
connection detail. Several tests below assert the absence of a secret string that
was deliberately embedded in the simulated failure.

No database is opened: `get_connection` is patched for the duration of each test.
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

import psycopg2

API_DIR = Path(__file__).resolve().parent.parent / "api"
if str(API_DIR) not in sys.path:
    sys.path.append(str(API_DIR))

TESTS_DIR = Path(__file__).resolve().parent
if str(TESTS_DIR) not in sys.path:
    sys.path.append(str(TESTS_DIR))

import app as engine  # noqa: E402

from _asgi_support import ScriptedConnection, ScriptedCursor, perform_asgi_request  # noqa: E402


# A connection string fragment of the kind psycopg2 puts in its error text. If any
# response contains these, the endpoint is leaking.
LEAKY_ERROR_TEXT = "password=supersecret host=db.internal"

ALL_COLUMNS = (
    ("subjects", "department_id"),
    ("subjects", "classification"),
    ("sections", "department_id"),
)


def readiness_cursor(
    departments_relation="departments",
    access_relation="subject_department_access",
    columns=ALL_COLUMNS,
    department_count=1,
    classified_subject_count=30,
    fail_on=None,
):
    """
    A scripted cursor for the readiness queries.

    A `None` relation simulates a missing table (`to_regclass` returns NULL rather
    than raising), `columns` selects which ownership columns the metadata query
    reports, `department_count` decides whether the seeder has run, and
    `classified_subject_count` whether any subject carries a review outcome.
    `fail_on` is a (SQL fragment, exception) pair raised when that query runs.
    """
    responses = [
        ("to_regclass('public.departments')", [{"relation": departments_relation}]),
        ("to_regclass('public.subject_department_access')", [{"relation": access_relation}]),
        ("information_schema.columns",
         [{"table_name": t, "column_name": c} for t, c in columns]),
        ("count(*) AS total FROM departments", [{"total": department_count}]),
        ("count(*) AS total FROM subjects", [{"total": classified_subject_count}]),
    ]

    if fail_on is not None:
        responses.insert(0, fail_on)

    return ScriptedCursor(responses)


def call_asgi(path, method="GET"):
    """Drive this engine's own ASGI app. See ai-engine/tests/_asgi_support.py."""
    return perform_asgi_request(engine.app, path, method)


class ReadinessTestCase(unittest.TestCase):
    """Shared harness: point `get_connection` at a scripted result."""

    def connect_to(self, cursor):
        """Patch the connection helper to hand back a scripted cursor."""
        patcher = mock.patch.object(
            engine, "get_connection", return_value=ScriptedConnection(cursor)
        )
        patcher.start()
        self.addCleanup(patcher.stop)

    def fail_to_connect(self, error):
        """Patch the connection helper to raise, as an unreachable database does."""
        patcher = mock.patch.object(engine, "get_connection", side_effect=error)
        patcher.start()
        self.addCleanup(patcher.stop)

    def get_ready(self):
        return call_asgi("/ready")

    def get_health(self):
        return call_asgi("/health")

    def assert_no_leak(self, response):
        """Neither endpoint may echo what the database said."""
        self.assertNotIn("supersecret", response.text)
        self.assertNotIn("db.internal", response.text)


class TestReadinessFailsClosed(ReadinessTestCase):
    def test_a_database_that_cannot_be_reached_is_not_ready(self):
        self.fail_to_connect(psycopg2.OperationalError(LEAKY_ERROR_TEXT))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        self.assertEqual("not_ready", response.json()["status"])
        self.assertEqual("database_unavailable", response.json()["reason"])
        self.assert_no_leak(response)

    def test_a_missing_departments_table_is_not_ready(self):
        self.connect_to(readiness_cursor(departments_relation=None))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        body = response.json()
        self.assertEqual("schema_incompatible", body["reason"])
        self.assertIn("departments table", body["missing"])
        # The columns are present in this fixture, so only the table is reported.
        self.assertNotIn("subjects.department_id column", body["missing"])

    def test_a_missing_access_table_is_not_ready(self):
        """
        Without subject_department_access nothing can be authorized for a major but
        its owner, so a shared major such as ELEC201 could not be scheduled at all.
        """
        self.connect_to(readiness_cursor(access_relation=None))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        body = response.json()
        self.assertEqual("schema_incompatible", body["reason"])
        self.assertIn("subject_department_access table", body["missing"])
        self.assertNotIn("departments table", body["missing"])

    def test_a_missing_subjects_department_column_is_not_ready(self):
        self.connect_to(readiness_cursor(columns=[
            ("subjects", "classification"),
            ("sections", "department_id"),
        ]))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        body = response.json()
        self.assertEqual("schema_incompatible", body["reason"])
        self.assertIn("subjects.department_id column", body["missing"])
        self.assertNotIn("subjects.classification column", body["missing"])
        self.assertNotIn("sections.department_id column", body["missing"])
        self.assertNotIn("departments table", body["missing"])

    def test_a_missing_classification_column_is_not_ready(self):
        """
        Without the column the engine cannot tell a shared subject from an
        unreviewed one, and it fails closed on every subject. That is a database
        the engine must not be handed, so it is a readiness failure.
        """
        self.connect_to(readiness_cursor(columns=[
            ("subjects", "department_id"),
            ("sections", "department_id"),
        ]))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        body = response.json()
        self.assertEqual("schema_incompatible", body["reason"])
        self.assertIn("subjects.classification column", body["missing"])
        self.assertNotIn("subjects.department_id column", body["missing"])

    def test_a_missing_sections_department_column_is_not_ready(self):
        self.connect_to(readiness_cursor(columns=[
            ("subjects", "department_id"),
            ("subjects", "classification"),
        ]))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        body = response.json()
        self.assertEqual("schema_incompatible", body["reason"])
        self.assertIn("sections.department_id column", body["missing"])
        self.assertNotIn("subjects.department_id column", body["missing"])

    def test_every_missing_piece_is_reported_together(self):
        self.connect_to(readiness_cursor(
            departments_relation=None, access_relation=None, columns=[],
        ))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        missing = response.json()["missing"]
        self.assertIn("departments table", missing)
        self.assertIn("subject_department_access table", missing)
        self.assertIn("subjects.department_id column", missing)
        self.assertIn("subjects.classification column", missing)
        self.assertIn("sections.department_id column", missing)

    def test_migrated_but_unseeded_is_not_ready(self):
        """
        The schema is applied but no department is recorded, so no ownership can be
        enforced and generation would run unconstrained. This is the silent state
        the seeder exists to prevent, so readiness must refuse it.
        """
        self.connect_to(readiness_cursor(department_count=0))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        self.assertEqual("ownership_not_seeded", response.json()["reason"])

    def test_departments_without_a_single_reviewed_subject_are_not_ready(self):
        """
        The half-finished activation: departments exist but every subject is still
        unclassified, so the guard refuses every subject and the engine could serve
        no generation request. Advertising readiness would be a promise it cannot
        keep.
        """
        self.connect_to(readiness_cursor(department_count=2, classified_subject_count=0))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        self.assertEqual("ownership_not_seeded", response.json()["reason"])

    def test_a_database_error_reports_the_fact_not_the_message(self):
        self.connect_to(readiness_cursor(
            fail_on=(
                "to_regclass('public.departments')",
                psycopg2.Error(f'relation "x" does not exist; {LEAKY_ERROR_TEXT}'),
            ),
        ))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        self.assertEqual("database_error", response.json()["reason"])
        self.assert_no_leak(response)
        self.assertNotIn("does not exist", response.text)

    def test_readiness_never_returns_a_5xx_other_than_503_or_a_200_when_broken(self):
        """A failing readiness probe must be usable as a boolean by an orchestrator."""
        self.fail_to_connect(psycopg2.OperationalError(LEAKY_ERROR_TEXT))

        response = self.get_ready()

        self.assertEqual(503, response.status_code)
        self.assertNotEqual(200, response.status_code)


class TestReadinessSucceeds(ReadinessTestCase):
    def test_an_applied_schema_with_reviewed_subjects_is_ready(self):
        self.connect_to(readiness_cursor(department_count=2, classified_subject_count=30))

        response = self.get_ready()

        self.assertEqual(200, response.status_code)
        body = response.json()
        self.assertEqual("ready", body["status"])
        self.assertEqual("application/json", response.headers.get("content-type"))

    def test_readiness_does_not_require_a_reviewed_subject_to_be_a_major(self):
        """One minor subject is a reviewed database: the guard has a rule to apply."""
        self.connect_to(readiness_cursor(department_count=1, classified_subject_count=1))

        response = self.get_ready()

        self.assertEqual(200, response.status_code)
        self.assertEqual("ready", response.json()["status"])

    def test_readiness_does_not_leak_on_success_either(self):
        self.connect_to(readiness_cursor())

        response = self.get_ready()

        self.assertEqual(200, response.status_code)
        self.assert_no_leak(response)


class TestLivenessStaysSeparate(ReadinessTestCase):
    """
    `/health` answers "is the process up and can it reach a database at all". It
    must not fail because a migration has not run, or an orchestrator would restart
    a healthy process forever.
    """

    def test_health_reports_ok_when_the_database_answers(self):
        self.connect_to(ScriptedCursor([]))

        response = self.get_health()

        self.assertEqual(200, response.status_code)
        self.assertEqual({"status": "ok", "database": "connected"}, response.json())

    def test_health_stays_alive_and_does_not_leak_when_the_database_is_down(self):
        self.fail_to_connect(psycopg2.OperationalError(LEAKY_ERROR_TEXT))

        response = self.get_health()

        # Still 200: the process is alive. That is what a liveness probe asks.
        self.assertEqual(200, response.status_code)
        self.assertEqual({"status": "error", "database": "unavailable"}, response.json())
        self.assert_no_leak(response)

    def test_health_is_unaffected_by_a_missing_department_schema(self):
        """
        Health never inspects the schema, so an unmigrated database cannot send it
        red. Only /ready carries that verdict.
        """
        self.connect_to(ScriptedCursor([]))

        response = self.get_health()

        self.assertEqual(200, response.status_code)
        self.assertEqual({"status": "ok", "database": "connected"}, response.json())


if __name__ == "__main__":
    unittest.main()
