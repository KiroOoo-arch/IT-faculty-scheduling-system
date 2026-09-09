# Manuscript Update Brief — Faculty Scheduling System

*For the manuscript revision team. Reflects commits f04f0e8 → eacdd66 on branch ai-engine, as of September 9, 2026. All changes verified against the actual source code and live-tested.*

---

## 1. MAJOR DESIGN CHANGE: Faculty are NOT system users

- Faculty no longer log in. No Faculty Portal, no Faculty Dashboard, no "My Schedule," no /api/my-schedule. Only the Admin/Department Head authenticates.
- Faculty are scheduling records: name, employment type, availability (days/times), qualifications/subject assignments, max teaching load. faculties.user_id is only a nullable legacy field — never describe it as a login.
- Delete from the manuscript: Faculty Portal chapter, faculty login use cases, "faculty views schedule online" flows, faculty email/password account creation, screenshots of the Faculty Dashboard.
- Replace with the print/PDF distribution model (section 3).
- Update: actor lists, use case diagrams (Admin is the sole actor; Faculty/Students are only recipients of printed schedules), ERD (users = admin accounts only; faculty name stored directly on the record), DFDs and sequence diagrams (no faculty API calls).

## 2. The Correct Architecture (use exactly this)

```
Admin / Department Head
        |
React + TypeScript Frontend
        |  (HTTP/JSON + Bearer token)
Laravel Backend
        |                       |
PostgreSQL            FastAPI AI Engine
 (Eloquent ORM;        (reads scheduling data via psycopg2)
  central store)               |
        |                OR-Tools CP-SAT Solver
        <------------- Candidate Schedule
        |
DRAFT > Admin Review / Manual Edit > APPROVED
        > Publish Conflict Gate
          conflict > REJECT
          no conflict > PUBLISHED
        > Print / Download PDF > Faculty + Students
```

- Updated diagrams/Architecture.drawio, DFD.drawio, Sequence.drawio, UseCase.drawio, Activity.drawio — all now valid draw.io files matching this; re-export images from them for the manuscript.
- Key phrase for the defense: "AI proposes; the Admin decides." Publishing is never automatic.

## 3. Workflow

Generate > Draft > Review/Edit > Approve > Publish Conflict Gate > Published > Print/Download PDF > Distribution

- Print/Download is a new feature (FR-017): the Admin opens a print-friendly weekly grid of a published schedule, prints or saves as PDF, and distributes hard copies. This replaces the Faculty Portal — cite the design justification: per-semester use, hard-copy distribution, reduced security surface (instructor-directed decision).
- Unpublish is also new: Published > Draft > edit > re-approve > re-publish. It returns the current schedule to draft — it does not restore any earlier state.
- On publish, older approved/published schedules of the same section may be auto-archived.

## 4. AI/Solver terminology — correct these in the manuscript

- WRONG: "AI solves with 8 constraints" — CORRECT: "The scheduler models eight main constraint categories, with the section's preferred scheduling window also directly enforced by the solver" (plus section self-overlap prevention).
- The 8 categories: (1) faculty qualification, (2) faculty availability, (3) room type matching, (4) room capacity, (5) faculty no double-booking, (6) room no double-booking, (7) maximum teaching load, (8) cross-section conflicts.
- IMPORTANT — faculty availability wording: the solver restricts faculty by their available DAYS; the section's preferred start/end window is the enforced time window. Do not write "no scheduling outside declared hours" — the code does not enforce hourly availability.
- IMPORTANT — max teaching load IS enforced (old drafts claimed it was not; verified in ai-engine/solver/scheduler.py).
- Results: OPTIMAL (all placed, optimality proven) / FEASIBLE (valid, optimality unproven) / PARTIAL (some placed, with reasons: no suitable room, no qualified faculty, window too short, capacity/type limits, other conflicts) / INFEASIBLE (none placed).
- Never claim "all sections generate OPTIMAL with zero unscheduled sessions." Correct: "Best-effort: maximizes the number of successfully scheduled sessions and reports reasons for the rest."
- Call it "AI-assisted constraint-based scheduling" — not machine learning.

## 5. Room & Laboratory section

- Room eligibility = room type AND capacity >= section student count AND room status available.
- Room types are now four: lecture, computer_lab, science_lab, electronics_lab (unified dropdown list shared by Subjects and Rooms pages).
- Subjects have save-blocking validation: lab hours > 0 requires a lab room type; lab hours = 0 requires type None. Mention this as a data-integrity feature.

## 6. THREE protection layers (new section — strong defense material)

1. CP-SAT generation constraints — constraints enforced in the solver model
2. Manual-edit validation — every admin session edit (day/time/room/faculty) is re-checked server-side and rejected on conflict
3. Publish conflict gate — final cross-section validation before going live

## 7. Reports — update endpoint names

Current (use these): /api/reports/faculty-workload, /api/reports/room-utilization, /api/reports/conflicts, /api/reports/schedule-status, /api/reports/section-summary.
Remove obsolete: overview, workload, sections, generation-logs.

## 8. Testing chapter — rewrite

- Replace any "18/18 manual tests" claim with the automated suite: 6/6 backend feature tests (30 assertions) including section/year-level generation tests (schedule attributed to correct section, year level, semester; other sections untouched; regeneration archives only the target's drafts), run on an isolated scheduling_system_testing database.
- Add the live end-to-end integration test (September 2026): admin login > AI generated BIT-3A's schedule OPTIMAL with all constraints verified (IAS lab correctly placed in a computer lab) > approve > publish through the gate > unpublish > all 5 reports — all passed.
- Frontend TypeScript typecheck + production build pass.

## 9. Security section (already implemented — safe to demo)

- Admin-only login; login rejects non-admin roles (defense-in-depth); all management routes behind admin middleware; last-admin deletion guard; Users page creates admin accounts only.
- New document: documentation/Data-Privacy-and-Security.md (privacy notice, security controls, retention, data-subject rights) — maps to the instructor's checklist.

## 10. Documentation to pull from (do not re-derive)

| File | Use for |
|---|---|
| documentation/System-Architecture.md | Authoritative architecture, flows, ERD |
| documentation/Constraints.md | Correct constraint list and solver behavior |
| documentation/System_Complete_Guide.docx | Overall narrative (regenerated Sept 9) |
| documentation/Progress-report.md | Dated development timeline, July 18 > September 9 (ready-made methodology chapter) |
| documentation/Data-Privacy-and-Security.md | Privacy/security chapter |
| diagrams/*.drawio | Re-export all 5 diagrams for the manuscript |

## 11. DELETE checklist for the old manuscript

- Faculty Portal chapter and screenshots
- "Faculty logs in / My Schedule" flows
- Faculty email/password forms
- "Role: Admin or Faculty" user creation
- UserObserver / automatic faculty account creation
- "18/18 tests" claims
- "Always OPTIMAL" claims
- "Not enforced" max-load claims
- Old report endpoint names (overview, workload, sections, generation-logs)
- Two-type room list (lecture/computer_lab only)

---

The two biggest rewrites are section 1 (faculty-as-records — touches actors, use cases, ERD, DFDs) and section 4 (solver terminology — now exactly matches the code).
