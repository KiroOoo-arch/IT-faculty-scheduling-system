"""
Mutation sensitivity for the department ownership guard.

`test_department_ownership.py` pins what the guard does. This module pins the
*pinning*: for each decision the guard makes, it rewrites that one decision in an
in-memory copy of `api/app.py`, re-runs the ownership suite against the mutant, and
asserts the suite fails. A test that keeps passing while the rule it protects is
inverted is not protecting anything, and a regression that weakens the guard would
otherwise be invisible in a green suite.

Six mutations, one per decision the classification rule consists of. Each names
the failure it simulates and the permanent tests that are supposed to catch it:

  accepts-everything
      The minor allowance becomes `if True:`, so nothing is ever refused — a
      bypassed guard. Caught by the rejection tests, by their
      solver-was-never-reached assertions, and by the ASGI 422 assertions.
  rejects-everything
      The minor allowance becomes `if False:`, so even a minor subject is refused.
      Caught by the acceptance tests: a shared subject, or the section's own
      major, is incorrectly rejected.
  unclassified-accepted
      The unclassified refusal becomes `if False:`, so an unreviewed subject is
      evaluated as if it were a major: the rejection then blames a missing owner
      instead of a missing decision. Caught by the tests that assert the wording,
      because a wrong reason is a wrong verdict for the operator.
  access-grant-ignored
      The grant lookup becomes `if True:`, so any major is accepted everywhere and
      a department with no grant can schedule another department's major — the
      ELEC201 rule inverted. Caught by the cross-department rejection tests.
  guard-loop-never-runs
      The review loop is emptied, so a rejected major reaches the solver. Caught by
      the same tests as accepts-everything, and specifically by
      `test_a_rejected_major_never_reaches_the_solver`.
  unresolvable-section-claims-no-department
      A section whose department row is missing is described as having no
      department at all, so a rejection blames the wrong side of the pair. Caught
      by `test_a_section_with_an_unresolvable_department_does_not_claim_to_have_none`.

Each mutation also declares the minimum number of tests that must catch it. The
floor is what turns "something noticed" into "the suite depends on this rule": it
trips when coverage collapses — say a rejection test is weakened and the rule is
left resting on one incidental assertion. The floors sit at the measured breadth of
the suite as it stands, so ordinary edits leave them alone; only thinning does not.
The section-wording mutation is the honest exception at a floor of one, because
only a section with an id and no resolvable name distinguishes it from the correct
branch, and a second test would have to duplicate that fixture to say anything new.

Everything happens in memory: `api/app.py` on disk is never written, and no test
here opens a database, starts a server or needs a dependency the engine did not
already have. The source is read once so the mutation anchors can be checked
against the guard as it is written today — when the guard is refactored and an
anchor disappears, the check fails and names the mutation to update, instead of
quietly testing nothing.
"""

import contextlib
import io
import sys
import types
import unittest
from pathlib import Path

API_DIR = Path(__file__).resolve().parent.parent / "api"
if str(API_DIR) not in sys.path:
    sys.path.append(str(API_DIR))

TESTS_DIR = Path(__file__).resolve().parent
if str(TESTS_DIR) not in sys.path:
    sys.path.append(str(TESTS_DIR))

import test_department_ownership as ownership  # noqa: E402

API_SOURCE_PATH = API_DIR / "app.py"
SOURCE = API_SOURCE_PATH.read_text(encoding="utf-8")

# The exact text of each decision, as the guard writes it today.
MINOR_ALLOWANCE = '            if classification == "minor":'

UNCLASSIFIED_REFUSAL = '            if classification != "major":'

ACCESS_GRANT_CHECK = '                if int(section_department_id) in authorized:'

SECTION_HAS_NO_DEPARTMENT = '            if section_department_id is None:'

REVIEW_LOOP = '        for candidate in subjects:'

# (name, anchor, replacement, minimum tests that must catch it)
MUTATIONS = (
    ("accepts-everything", MINOR_ALLOWANCE, "            if True:", 3),
    ("rejects-everything", MINOR_ALLOWANCE, "            if False:", 2),
    ("unclassified-accepted", UNCLASSIFIED_REFUSAL, "            if False:", 2),
    ("access-grant-ignored", ACCESS_GRANT_CHECK, "                if True:", 2),
    ("guard-loop-never-runs", REVIEW_LOOP, "        for candidate in []:", 3),
    (
        "unresolvable-section-claims-no-department",
        SECTION_HAS_NO_DEPARTMENT,
        '            if not section.get("department_name"):',
        1,
    ),
)


def load_mutant(anchor, replacement):
    """
    Exec a copy of the engine with one decision rewritten.

    A fresh module object is used rather than an edit to the imported one, so the
    pristine engine other tests hold stays untouched and `api/app.py` on disk is
    never written.
    """
    mutated = SOURCE.replace(anchor, replacement)
    if mutated == SOURCE:
        raise AssertionError(
            "mutation did not apply - the anchor text is stale:\n" + anchor
        )

    module = types.ModuleType("app_mutant")
    module.__file__ = str(API_SOURCE_PATH)
    exec(compile(mutated, str(API_SOURCE_PATH), "exec"), module.__dict__)
    return module


@contextlib.contextmanager
def ownership_suite_running_against(module):
    """
    Point the ownership suite's `engine` global at `module` for the duration.

    Its tests resolve `engine` by name at call time, so rebinding that global is
    enough to run every one of them against the mutant — including the ASGI ones,
    which take their app object from the same name. The original is restored on the
    way out, so discovery order cannot matter.
    """
    original = ownership.engine
    ownership.engine = module
    try:
        yield
    finally:
        ownership.engine = original


def run_ownership_suite():
    """Run the whole ownership suite in-process and return its result."""
    suite = unittest.TestLoader().loadTestsFromModule(ownership)
    # verbosity 0 still writes a summary line; keep it out of this run's output.
    return unittest.TextTestRunner(stream=io.StringIO(), verbosity=0).run(suite)


def catching_tests(result):
    """The names of the tests that failed or errored under a mutation."""
    return sorted(
        case.id().rsplit(".", 1)[-1] for case, _ in result.failures + result.errors
    )


class GuardSensitivityTest(unittest.TestCase):
    def test_the_ownership_suite_is_green_before_any_mutation(self):
        """
        Control for everything below. If the suite were already failing for an
        unrelated reason, "the suite fails under the mutation" would prove nothing.
        """
        result = run_ownership_suite()

        self.assertTrue(
            result.wasSuccessful(),
            "the ownership suite must be green for this check to mean anything: "
            f"{catching_tests(result)}",
        )
        self.assertGreaterEqual(result.testsRun, 30)

    def test_every_mutation_still_applies_to_the_guard(self):
        """
        A mutation whose anchor no longer exists would compare the pristine engine
        with itself, and the sensitivity check would pass while testing nothing.
        Fail loudly instead, and name the mutation to update.
        """
        stale = [
            name
            for name, anchor, _replacement, _floor in MUTATIONS
            if anchor not in SOURCE
        ]

        self.assertEqual(
            [],
            stale,
            f"these guard mutation anchors are stale - update MUTATIONS in {Path(__file__).name} "
            "to the guard's current text, or the ownership rule stops being covered",
        )

    def test_the_ownership_suite_catches_every_guard_mutation(self):
        """
        The load-bearing check: invert one decision at a time and require the
        permanent suite to notice. No mutation may survive undetected.
        """
        undetected = []
        detection = {}

        for name, anchor, replacement, _floor in MUTATIONS:
            mutant = load_mutant(anchor, replacement)

            with ownership_suite_running_against(mutant):
                result = run_ownership_suite()

            detection[name] = catching_tests(result)

            if result.wasSuccessful():
                undetected.append(name)

        self.assertEqual(
            [],
            undetected,
            "these guard mutations were not detected by the ownership suite, so the "
            f"tests do not protect the rule they claim to: {undetected}",
        )

        thin = [
            f"{name}: caught by {len(detection[name])} test(s), floor is {floor} "
            f"({detection[name]})"
            for name, _anchor, _replacement, floor in MUTATIONS
            if len(detection[name]) < floor
        ]

        self.assertEqual(
            [],
            thin,
            "coverage for these guard rules has thinned below its floor, so the rule "
            f"now rests on less than it used to: {thin}",
        )
