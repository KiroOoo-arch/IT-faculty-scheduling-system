"""
Regression coverage for the subject classification guard in ai-engine/api/app.py.

Every subject carries a review outcome:

* MINOR — open to every department, section or no section.
* MAJOR — owned by one department and schedulable only for sections whose
  department holds a `subject_department_access` grant for it. The owner's own
  grant is mandatory; a second department may hold one too (ELEC201, owned by
  Electronics Engineering, is granted to BIT), which is the whole reason access is
  a table rather than "owner == section department".
* UNCLASSIFIED — not reviewed yet, so schedulable nowhere. A subject row that does
  not carry a classification at all is read as unclassified: the guard fails
  closed rather than treating it as shared.

The guard sits at the top of the /generate-schedule route, before the solver is
reached, so a bypassed assignment fails loudly instead of quietly returning a
timetable that is missing its classes.

Two harnesses, same fixtures:

* the route function is called directly, which is the shortest path to the guard;
* `call_asgi` drives the real ASGI app, which adds Starlette routing, the
  middleware stack, FastAPI's exception handlers and JSON serialization.

Neither opens a database: `get_connection` is patched for the duration of each
test, and the solver is stubbed because these tests are about the guard in front
of it rather than the timetable it produces.
"""

import sys
import unittest
from pathlib import Path
from unittest import mock

API_DIR = Path(__file__).resolve().parent.parent / "api"
if str(API_DIR) not in sys.path:
    sys.path.append(str(API_DIR))

TESTS_DIR = Path(__file__).resolve().parent
if str(TESTS_DIR) not in sys.path:
    sys.path.append(str(TESTS_DIR))

import app as engine  # noqa: E402

from _asgi_support import (  # noqa: E402
    ScriptedConnection,
    ScriptedCursor,
    perform_asgi_request,
)


SECTION_ID = 42

IT_DEPARTMENT = {"id": 10, "name": "Industrial Technology"}
ELECTRONICS_DEPARTMENT = {"id": 20, "name": "BindTech Electronics"}
THIRD_DEPARTMENT = {"id": 30, "name": "Department of General Education"}

FACULTY = {"id": 7, "name": "Prof. Verify"}


def section_row(department=None):
    """A `sections` row as the route selects it, with its department LEFT JOINed."""
    return {
        "id": SECTION_ID,
        "name": "VERIFY-SECTION",
        "preferred_days": [1, 2, 3],
        "preferred_start_time": "07:00",
        "preferred_end_time": "18:00",
        "student_count": 30,
        "department_id": department["id"] if department else None,
        "department_name": department["name"] if department else None,
        "academic_year": "2026-2027",
        "semester_name": "1st Semester",
    }


def subject_row(subject_id, code, classification, owner=None, access=(), include_classification=True):
    """
    A `subjects` row as the route selects it, plus the access grants it holds.

    `access` is the list of departments authorized to schedule it, as the
    `subject_department_access` rows hold them. `include_classification=False`
    drops the column entirely, which is what a database without the
    classification migration returns.
    """
    row = {
        "id": subject_id,
        "code": code,
        "lecture_hours": 2,
        "lab_hours": 0,
        "lab_room_type": None,
        "classification": classification,
        "department_id": owner["id"] if owner else None,
        "department_name": owner["name"] if owner else None,
    }

    if not include_classification:
        row.pop("classification")

    row["access"] = list(access)

    return row


def minor_row(subject_id, code):
    """A reviewed minor / shared subject: open to every department, no grants."""
    return subject_row(subject_id, code, "minor")


def major_row(subject_id, code, owner, extra_access=()):
    """A major whose owner always holds its own grant, plus any extra grants."""
    return subject_row(
        subject_id,
        code,
        "major",
        owner=owner,
        access=[owner, *extra_access],
    )


def unclassified_row(subject_id, code):
    """A subject nobody has reviewed: schedulable nowhere, no grants."""
    return subject_row(subject_id, code, "unclassified")


def call_asgi(path, method="POST"):
    """Drive this engine's own ASGI app. See ai-engine/tests/_asgi_support.py."""
    return perform_asgi_request(engine.app, path, method)


class DepartmentOwnershipTestCase(unittest.TestCase):
    """Shared harness: run the route against a scripted database and a stub solver."""

    def install_scripted_environment(self, section, subjects, faculty=()):
        """
        Patch the database and the solver for one scripted run.

        Access rows are derived from each subject fixture's `access` list, so a test
        states the authorization it wants in one place instead of repeating it as a
        second fixture.

        Leaves the solver mock on `self.solver` so tests can assert whether the
        solver was reached. Cleanup is registered on the test case, so nothing
        leaks between tests.
        """
        access_rows = [
            {"subject_id": subject["id"], "department_id": department["id"]}
            for subject in subjects
            for department in subject.get("access", [])
        ]

        cursor = ScriptedCursor([
            # Each marker matches exactly one query the route issues.
            ("FROM sections s", [section] if section else []),
            ("FROM subjects s", list(subjects)),
            ("FROM subject_department_access", access_rows),
            ("SELECT DISTINCT f.id, f.name", list(faculty)),
            ("SELECT subject_id FROM faculty_subjects",
             [{"subject_id": s["id"]} for s in subjects]),
            ("FROM faculty_availabilities", []),
            ("SELECT max_teaching_load", [{"max_teaching_load": 24}]),
            ("COALESCE(SUM(", [{"total_hours": 0}]),
            ("FROM rooms", [{"id": 3, "name": "R101", "type": "lecture", "capacity": 40}]),
            ("to_char(ss.start_time", []),
        ])

        connection = ScriptedConnection(cursor)
        self.solver = mock.Mock(
            return_value={"status": "OPTIMAL", "message": None, "sessions": []}
        )

        get_connection = mock.patch.object(engine, "get_connection", return_value=connection)
        get_connection.start()
        self.addCleanup(get_connection.stop)

        generate = mock.patch.object(engine, "generate_schedule", self.solver)
        generate.start()
        self.addCleanup(generate.stop)

    def run_route(self, section, subjects, faculty=()):
        """Invoke the real route function directly. Returns its result."""
        self.install_scripted_environment(section, subjects, faculty)
        return engine.generate_schedule_for_section(SECTION_ID)

    def run_route_via_asgi(self, section, subjects, faculty=()):
        """Perform the same request through the ASGI app. Returns the HTTP response."""
        self.install_scripted_environment(section, subjects, faculty)
        return call_asgi(f"/generate-schedule/{SECTION_ID}")

    def subjects_passed_to_solver(self):
        """The subject list the route handed the solver — fails if it never got there."""
        self.solver.assert_called_once()
        return self.solver.call_args.args[1]

    def assert_rejected(self, section, subjects, *expected_fragments):
        """The guard refuses the run, and nothing reached the solver."""
        response = self.run_route_via_asgi(section, subjects, faculty=[FACULTY])

        self.assertEqual(422, response.status_code)
        detail = response.json()["detail"]

        for fragment in expected_fragments:
            self.assertIn(fragment, detail)

        self.solver.assert_not_called()

        return detail


class TestMinorSubjectsAreOpenToEveryDepartment(DepartmentOwnershipTestCase):
    def test_a_minor_is_accepted_by_another_department(self):
        subjects = [minor_row(1, "MATH1")]

        result = self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_a_minor_is_accepted_by_a_section_with_no_department(self):
        subjects = [minor_row(1, "MATH1")]

        result = self.run_route(section_row(None), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_a_minor_never_needs_an_access_row(self):
        """Being open to everyone is the classification, not a list of departments."""
        subjects = [minor_row(1, "MATH1")]
        self.assertNotIn(THIRD_DEPARTMENT["id"], [d["id"] for d in subjects[0]["access"]])

        result = self.run_route(section_row(THIRD_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])


class TestMajorOfTheSectionsOwnDepartment(DepartmentOwnershipTestCase):
    def test_a_major_granted_to_its_own_department_is_accepted(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        result = self.run_route(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])

        # Accepted means the major reached the solver, not that it was dropped.
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_a_major_of_another_department_is_rejected_with_422(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        with self.assertRaises(engine.HTTPException) as caught:
            self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(422, caught.exception.status_code)

        detail = caught.exception.detail
        # Identifies the subject, its owning department, and the target section
        # with the department it does belong to.
        self.assertTrue(detail.startswith("PROG1 is a major subject of"), detail)
        self.assertIn(IT_DEPARTMENT["name"], detail)
        self.assertIn(ELECTRONICS_DEPARTMENT["name"], detail)
        self.assertIn("VERIFY-SECTION", detail)

    def test_a_rejected_major_never_reaches_the_solver(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        with self.assertRaises(engine.HTTPException):
            self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        # No partial run: the guard refuses before the solver is handed anything.
        self.solver.assert_not_called()

    def test_a_major_with_no_owner_holding_a_grant_is_rejected(self):
        """
        The owner's grant is what authorizes the owner. A major row whose access
        is missing is malformed data, and the guard fails closed rather than
        inferring permission from `department_id` alone — the preflight is what
        reports the missing row.
        """
        subjects = [subject_row(1, "PROG1", "major", owner=IT_DEPARTMENT, access=[])]

        with self.assertRaises(engine.HTTPException) as caught:
            self.run_route(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(422, caught.exception.status_code)
        self.assertIn("PROG1", caught.exception.detail)
        self.solver.assert_not_called()

    def test_a_major_with_no_owning_department_is_rejected(self):
        """A major with no owner can be authorized nowhere, whatever the grants say."""
        subjects = [subject_row(1, "PROG1", "major", owner=None, access=[IT_DEPARTMENT])]

        self.assert_rejected(
            section_row(IT_DEPARTMENT),
            subjects,
            "PROG1",
            "no department (the major has no owner)",
        )

    def test_only_the_foreign_major_is_reviewed_not_the_whole_section(self):
        """A minor alongside a foreign major must not mask the rejection."""
        subjects = [
            minor_row(1, "MATH1"),
            major_row(2, "PROG1", owner=IT_DEPARTMENT),
        ]

        with self.assertRaises(engine.HTTPException) as caught:
            self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(422, caught.exception.status_code)
        self.assertIn("PROG1", caught.exception.detail)
        self.solver.assert_not_called()


class TestCrossDepartmentAccessGrants(DepartmentOwnershipTestCase):
    """
    The ELEC201 shape: a major owned by one department and granted to a second.
    The grant — not the owner id — decides who may schedule it, so the second
    department is accepted and a third one still is not.
    """

    def test_a_major_granted_to_a_second_department_is_accepted_there(self):
        subjects = [major_row(1, "ELEC201", owner=ELECTRONICS_DEPARTMENT, extra_access=[IT_DEPARTMENT])]

        result = self.run_route(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_the_owner_still_schedules_a_shared_major(self):
        subjects = [major_row(1, "ELEC201", owner=ELECTRONICS_DEPARTMENT, extra_access=[IT_DEPARTMENT])]

        result = self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])

    def test_a_department_without_a_grant_is_still_rejected(self):
        subjects = [major_row(1, "ELEC201", owner=ELECTRONICS_DEPARTMENT, extra_access=[IT_DEPARTMENT])]

        detail = self.assert_rejected(
            section_row(THIRD_DEPARTMENT),
            subjects,
            "ELEC201",
            ELECTRONICS_DEPARTMENT["name"],
            THIRD_DEPARTMENT["name"],
        )

        # The rejection names the owner, not the department that was granted.
        self.assertNotIn(IT_DEPARTMENT["name"], detail)


class TestUnclassifiedSubjectsAreSchedulableNowhere(DepartmentOwnershipTestCase):
    def test_an_unclassified_subject_is_rejected_and_named(self):
        subjects = [unclassified_row(1, "MYSTERY1")]

        with self.assertRaises(engine.HTTPException) as caught:
            self.run_route(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(422, caught.exception.status_code)

        detail = caught.exception.detail
        self.assertIn("MYSTERY1", detail)
        self.assertIn("has not been classified yet", detail)
        self.assertIn("minor", detail)
        self.solver.assert_not_called()

    def test_an_unclassified_subject_is_rejected_through_the_endpoint(self):
        subjects = [unclassified_row(1, "MYSTERY1")]

        self.assert_rejected(section_row(IT_DEPARTMENT), subjects, "MYSTERY1")

    def test_a_subject_row_without_the_classification_column_is_refused(self):
        """
        A database that predates the classification migration has no review
        outcome recorded for anything, so every subject is unknown. The guard fails
        closed instead of reading "no data" as "shared" — readiness is what stops
        such a database being served at all.
        """
        subjects = [subject_row(1, "PROG1", "minor", include_classification=False)]

        self.assert_rejected(section_row(IT_DEPARTMENT), subjects, "PROG1", "has not been classified yet")

    def test_a_minor_alongside_an_unclassified_subject_does_not_mask_it(self):
        subjects = [minor_row(1, "MATH1"), unclassified_row(2, "MYSTERY1")]

        self.assert_rejected(section_row(IT_DEPARTMENT), subjects, "MYSTERY1")

    def test_an_unclassified_subject_is_refused_for_a_section_with_no_department_too(self):
        subjects = [unclassified_row(1, "MYSTERY1")]

        self.assert_rejected(section_row(None), subjects, "MYSTERY1")


class TestSectionWithNoDepartment(DepartmentOwnershipTestCase):
    """
    A section with no department of its own has no claim on anyone's majors, so
    only minor subjects are open to it. That is the policy the guard implements
    and the backend mirrors.
    """

    def test_a_major_is_rejected_for_a_section_with_no_department(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        with self.assertRaises(engine.HTTPException) as caught:
            self.run_route(section_row(None), subjects, faculty=[FACULTY])

        self.assertEqual(422, caught.exception.status_code)

        detail = caught.exception.detail
        self.assertIn("PROG1", detail)
        self.assertIn(IT_DEPARTMENT["name"], detail)
        self.assertIn("has no department", detail)
        self.solver.assert_not_called()

    def test_a_major_the_section_cannot_claim_is_rejected_even_with_a_grant_elsewhere(self):
        subjects = [major_row(1, "ELEC201", owner=ELECTRONICS_DEPARTMENT, extra_access=[IT_DEPARTMENT])]

        self.assert_rejected(section_row(None), subjects, "ELEC201", "has no department")


class TestGuardIsRepeatable(DepartmentOwnershipTestCase):
    """The same fixtures in either order give the same verdict, and no state leaks."""

    def test_repeated_runs_do_not_change_the_verdict(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        for _ in range(3):
            with self.assertRaises(engine.HTTPException) as caught:
                self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])
            self.assertEqual(422, caught.exception.status_code)

    def test_the_accepting_case_still_passes_after_a_rejection(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        with self.assertRaises(engine.HTTPException):
            self.run_route(section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY])

        result = self.run_route(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual("OPTIMAL", result["status"])


class TestOwnershipThroughTheAsgiEndpoint(DepartmentOwnershipTestCase):
    """
    The same rule through the HTTP surface a caller actually hits: real routing,
    FastAPI's HTTPException handler, and JSON serialization of the response.
    """

    def test_routing_and_serialization_are_really_exercised(self):
        """
        Control for this class. If the ASGI harness silently did nothing, every
        other assertion here would pass for the wrong reason, so prove the router
        and the exception handler are genuinely in the path.
        """
        unknown = call_asgi("/no-such-route")
        self.assertEqual(404, unknown.status_code)
        self.assertEqual({"detail": "Not Found"}, unknown.json())

        # A known path with no section proves the endpoint ran and FastAPI
        # serialized the HTTPException it raised.
        self.install_scripted_environment(None, [])
        missing = call_asgi(f"/generate-schedule/{SECTION_ID}")
        self.assertEqual(404, missing.status_code)
        self.assertEqual("Section 42 not found.", missing.json()["detail"])

    def test_a_foreign_department_major_returns_422(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        response = self.run_route_via_asgi(
            section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY]
        )

        self.assertEqual(422, response.status_code)
        self.assertEqual("application/json", response.headers.get("content-type"))
        self.solver.assert_not_called()

    def test_the_422_json_names_the_subject_and_both_departments(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        response = self.run_route_via_asgi(
            section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY]
        )

        payload = response.json()
        self.assertIn("detail", payload)

        detail = payload["detail"]
        self.assertIn("PROG1", detail)                          # the subject
        self.assertIn(IT_DEPARTMENT["name"], detail)            # its owning department
        self.assertIn(ELECTRONICS_DEPARTMENT["name"], detail)   # the section's department
        self.assertIn("VERIFY-SECTION", detail)                 # the section

    def test_the_422_json_serializes_an_unclassified_rejection(self):
        subjects = [unclassified_row(1, "MYSTERY1")]

        response = self.run_route_via_asgi(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(422, response.status_code)
        self.assertIn("MYSTERY1", response.json()["detail"])

    def test_a_same_department_major_is_not_rejected(self):
        subjects = [major_row(1, "PROG1", owner=IT_DEPARTMENT)]

        response = self.run_route_via_asgi(section_row(IT_DEPARTMENT), subjects, faculty=[FACULTY])

        self.assertEqual(200, response.status_code)
        self.assertEqual("OPTIMAL", response.json()["status"])
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_minor_subjects_remain_accepted_across_departments(self):
        subjects = [minor_row(1, "MATH1")]

        response = self.run_route_via_asgi(
            section_row(ELECTRONICS_DEPARTMENT), subjects, faculty=[FACULTY]
        )

        self.assertEqual(200, response.status_code)
        self.assertEqual("OPTIMAL", response.json()["status"])
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_minor_subjects_are_accepted_by_a_section_with_no_department(self):
        subjects = [minor_row(1, "MATH1")]

        response = self.run_route_via_asgi(section_row(None), subjects, faculty=[FACULTY])

        self.assertEqual(200, response.status_code)
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])


class TestEmptyDepartmentRelationships(DepartmentOwnershipTestCase):
    """
    The LEFT JOIN yields NULL whenever a department row cannot be resolved, which
    covers two very different situations: nothing was ever recorded (unseeded), and
    a grant was recorded but its row is gone (dangling). An unresolvable *name* is
    never read as "no ownership": the ids decide.

    Both are exercised through the ASGI endpoint, so the verdict is the one a
    caller would receive.
    """

    def test_a_database_with_no_reviewed_subjects_runs_nothing(self):
        """
        departments exists but holds no grants and every subject is unclassified:
        nothing was reviewed and therefore nothing may be scheduled. This is the
        documented fail-closed policy, not a silent bypass.
        """
        subjects = [
            unclassified_row(1, "PROG1"),
            unclassified_row(2, "MATH1"),
        ]

        response = self.run_route_via_asgi(section_row(None), subjects, faculty=[FACULTY])

        self.assertEqual(422, response.status_code)
        self.assertIn("has not been classified yet", response.json()["detail"])
        self.solver.assert_not_called()

    def test_a_foreign_major_is_still_rejected_when_its_department_row_is_missing(self):
        # subjects.department_id is set but its departments row is gone, so the
        # join supplies no name. The guard reads the id and the grants, not the
        # join name, so this must NOT be treated as a minor subject.
        orphan = major_row(1, "PROG1", owner=IT_DEPARTMENT)
        orphan["department_name"] = None

        response = self.run_route_via_asgi(
            section_row(ELECTRONICS_DEPARTMENT), [orphan], faculty=[FACULTY]
        )

        self.assertEqual(422, response.status_code)
        detail = response.json()["detail"]
        self.assertIn("PROG1", detail)
        self.assertIn(f"department #{IT_DEPARTMENT['id']}", detail)
        self.solver.assert_not_called()

    def test_a_section_whose_department_row_is_missing_still_matches_its_own_major(self):
        # The join could not resolve the section's department name, but its id is
        # intact and the grant is there, so its own major is still its own.
        section = section_row(IT_DEPARTMENT)
        section["department_name"] = None
        own_major = major_row(1, "PROG1", owner=IT_DEPARTMENT)

        response = self.run_route_via_asgi(section, [own_major], faculty=[FACULTY])

        self.assertEqual(200, response.status_code)
        self.assertEqual([1], [s["id"] for s in self.subjects_passed_to_solver()])

    def test_a_section_with_an_unresolvable_department_does_not_claim_to_have_none(self):
        section = section_row(IT_DEPARTMENT)
        section["department_name"] = None
        foreign = major_row(1, "PROG1", owner=ELECTRONICS_DEPARTMENT)

        response = self.run_route_via_asgi(section, [foreign], faculty=[FACULTY])

        self.assertEqual(422, response.status_code)
        detail = response.json()["detail"]

        # The section does have a department; only its name is unavailable.
        self.assertNotIn("which has no department", detail)
        self.assertIn(f"department #{IT_DEPARTMENT['id']}", detail)
        self.solver.assert_not_called()

    def test_a_dangling_owner_against_a_section_with_none_is_still_rejected(self):
        # Both sides unresolvable by name, and the section genuinely has no
        # department, so the decision comes down to the ids.
        orphan = major_row(1, "PROG1", owner=IT_DEPARTMENT)
        orphan["department_name"] = None

        response = self.run_route_via_asgi(section_row(None), [orphan], faculty=[FACULTY])

        self.assertEqual(422, response.status_code)
        self.assertIn("has no department", response.json()["detail"])
        self.solver.assert_not_called()


if __name__ == "__main__":
    unittest.main()
