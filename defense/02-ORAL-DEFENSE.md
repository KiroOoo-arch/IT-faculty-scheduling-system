# ORAL DEFENSE — Slide-by-Slide Script
**Slot:** ~15–20 minutes speaking, split across 4–5 speakers (suggested: A=intro/problem, B=objectives+methodology, C=architecture+AI engine, D=testing+results+conclusion)
**Format note:** Built for the combined-event setup — if oral and design hearing merge into one session, this script still stands alone; the panel's deeper questions are covered in `03-DESIGN-HEARING.md` and `05-QA-BANK.md`.

> 📊 **Slide content is provided per slide** — you can build the deck directly from this document. Speaker notes are in blockquotes. Keep slides to ≤6 bullets; the script carries the detail.

---

## SLIDE 1 — Title (Speaker A) — 0:30

**Slide content:**
- AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System
- Team members + section
- Capstone project, [school name], 2026

> "Good morning. We present our capstone: an AI-assisted, constraint-based scheduling system for the IT Department — built to replace manual, error-prone schedule construction with mathematically guaranteed, conflict-free schedules."

---

## SLIDE 2 — The Problem (Speaker A) — 1:30

**Slide content:**
- Manual scheduling: hours per semester, one person
- Double-booking discovered after posting
- Labs in wrong rooms; loads exceeded
- Cross-section conflicts invisible
- No audit trail

> "Today's process is manual — hours of cross-checking by the Department Head. The errors it produces are concrete: the same professor in two rooms at once, laboratory classes assigned to lecture rooms, teaching loads exceeded. Because sections are scheduled one at a time, conflicts *between* sections are invisible until the schedule is posted. And nothing records who generated what, or why a schedule changed. We address all five points."

---

## SLIDE 3 — Proposed Solution (Speaker A) — 1:00

**Slide content:**
- Web system: manage data → generate → review → approve → publish → print
- AI proposes; the Admin decides
- 8 hard constraints enforced by CP-SAT solver
- Explanations, not just errors

> "Our solution is a three-tier web system. The Department Head manages all scheduling data, clicks generate, and receives a candidate schedule from a constraint solver — in seconds. Nothing publishes automatically: review, approval, and a final conflict gate sit between the AI and the posted schedule. And when the solver can't place a session, it returns a *reason*, not a shrug."

*(Handoff: "My teammate will present the objectives.")*

---

## SLIDE 4 — Objectives (SOP 1–6) (Speaker B) — 2:00

**Slide content:**
- SOP1: Auto-generate schedules (CP-SAT)
- SOP2: Student-centered (section preferences + capacity)
- SOP3: Detect/prevent conflicts (8 constraints)
- SOP4: Optimize resources (reports)
- SOP5: Department Head control (workflow)
- SOP6: Acceptability (136 automated tests)

> "Six objectives, each verifiably met. SOP1 — automatic generation via Google OR-Tools CP-SAT. SOP2 — student-centered: each section's preferred days and time window are enforced *by the solver*, and room capacity is checked against student count. SOP3 — conflict prevention through eight hard constraint categories. SOP4 — resource optimization with workload and utilization reports. SOP5 — the Department Head controls review, approval, and publication. SOP6 — acceptability, demonstrated by 136 passing automated tests across the solver and the backend."

---

## SLIDE 5 — Scope & Limitations (Speaker B) — 1:30

**Slide content:**
- In scope: one IT department, per-semester, admin-only login
- Faculty/students = records and recipients (print/PDF distribution)
- Out of scope (future work): university-wide, multi-semester, notifications, soft preferences

> "Scope: one department, used per semester. Only the Admin logs in — a deliberate security decision; faculty are records whose data feeds the solver, and published schedules go out as printed or PDF copies. We're explicit about what's out of scope: university-wide scheduling, multi-semester planning, and soft preferences like lunch breaks. The architecture supports extending to these — independent services can scale independently."

---

## SLIDE 6 — Methodology (Speaker B) — 1:30

**Slide content:**
1. Standalone solver validation (incl. stress tests)
2. Database design — 11 domain tables
3. Backend API + auth (Laravel + Sanctum)
4. Frontend (React + TS)
5. Integration + automated tests

> "Our methodology de-risked the hardest part first: we validated the constraint model as a standalone prototype on a fabricated dataset — including stress cases — before any integration. Then: database design with 11 domain tables, the Laravel API with admin-only authentication, the React frontend, and finally integration with an automated test suite."

*(Handoff: "For the technical architecture, my teammate takes over.")*

---

## SLIDE 7 — System Architecture (Speaker C) — 2:00

**Slide content:**
```
React+TS (5173) → Laravel API (8000) → FastAPI AI (8001)
                         ↘ PostgreSQL (5432) ↙ (read via psycopg2)
```
- HTTP/JSON + Sanctum Bearer tokens
- One database, one source of truth
- Three services, independently maintainable

> "Three services, one database. The React frontend talks to Laravel over HTTP with Sanctum token authentication. Laravel handles business logic and persistence, and calls the Python AI engine — FastAPI hosting the OR-Tools CP-SAT solver — over an internal HTTP call. The AI engine reads scheduling data directly from PostgreSQL with parameterized queries and returns the candidate schedule; Laravel persists it. One database means one source of truth — no synchronization problem. Separation of concerns means each layer uses the best tool for its job: PHP for web, Python for optimization."

---

## SLIDE 8 — The AI Engine (Speaker C) — 2:00

**Slide content:**
- Variables: day, start time, room, faculty — per session
- Domains: from rooms/faculty/availability data
- 8 hard constraints + section window + self-overlap
- Result: OPTIMAL / FEASIBLE / PARTIAL / INFEASIBLE — with reasons
- 15-second solver budget; verified ~1–2 s on our data

> "The heart of the system. Scheduling is modeled as constraint programming: for every session, variables for day, start time, room, and faculty; domains drawn from the actual data; and eight hard constraint categories — qualifications, availability, room type, capacity, faculty and room double-booking, teaching load, and cross-section conflicts — plus the section's preferred window and self-overlap prevention. The solver *maximizes placed sessions* and returns OPTIMAL when all are placed and proven best — FEASIBLE when all are placed but the 15-second budget is hit first — PARTIAL when some can't fit — each with a plain-language reason — or INFEASIBLE when the data makes scheduling impossible. On our data scale it proves OPTIMAL in about one to two seconds, within a 15-second budget."

---

## SLIDE 9 — Three Protection Layers (Speaker C) — 1:30

**Slide content:**
1. Generation constraints (CP-SAT)
2. Manual-edit conflict detection (server-side, 422 on conflict)
3. Publish conflict gate (cross-section, final)
- Plus: pre-flight data validation before the AI is ever called

> "Conflict prevention doesn't rely on one mechanism — there are three layers. First, the solver's constraints make the *generated* schedule valid by construction. Second, every manual edit of a session is re-validated server-side and rejected with a specific conflict message. Third, before a schedule goes live, a publish gate re-checks for cross-section conflicts. Before any of this, Laravel validates the data itself — mismatched subject assignments are rejected with a 422 naming the offending subjects, and the AI engine is never called with bad data."

---

## SLIDE 10 — Workflow & Admin Control (Speaker D) — 1:30

**Slide content:**
```
Generate → DRAFT → Review/Edit → APPROVED → Publish Gate → PUBLISHED → Print/PDF
                                        Unpublish ↓ returns to DRAFT
```
- Full audit trail: schedule_generation_logs
- Old drafts auto-archive

> "The lifecycle: a generated schedule is a draft; the admin reviews and can edit sessions — with conflict checking; approval moves it forward; the publish gate runs its final cross-section check; and the published schedule becomes a print-ready weekly grid for PDF export and hard-copy distribution. Unpublishing returns it to draft for correction. Every generation attempt — success or failure — is logged, giving the department a complete audit trail."

*(Handoff: "How do we know it all works? My teammate presents testing.")*

---

## SLIDE 11 — Testing & Results (Speaker D) — 2:00

**Slide content:**
- 90 Laravel tests (380 assertions) across 12 files
- 46 solver unit tests (Python)
- Live-verified: BSIT 1A generates OPTIMAL (7 sessions); full workflow passes
- tsc typecheck + production build clean

> "Two automated suites: 90 backend feature tests with 380 assertions — covering generation correctness, section/subject year-and-semester validation, and lab-hour consistency rules — and 46 solver unit tests in Python covering every constraint category, including infeasible cases and part-time faculty availability windows. Beyond the suites, we live-verified the full workflow end to end: the live sections generate OPTIMAL, the draft-approve-publish-print flow runs clean, and the validation gates fire correctly with named error messages."

---

## SLIDE 12 — Conclusion & Future Work (Speaker D) — 1:00

**Slide content:**
- Manual hours → seconds; errors → mathematical guarantees
- AI proposes, three layers defend, the Admin decides
- Future: soft constraints, CSV export, university scale, notifications

> "The system turns hours of error-prone manual work into seconds of provably valid scheduling — with explanations, accountability, and the Department Head in control. Future work: soft constraints like lunch breaks and seniority preference, spreadsheet export, university-wide scaling, and notifications. Thank you — we welcome your questions."

---

## Timing Map (~18:00 of 20:00 budget)

| Time | Slide | Speaker |
|---|---|---|
| 0:00–0:30 | 1 Title | A |
| 0:30–2:00 | 2 Problem | A |
| 2:00–3:00 | 3 Solution | A |
| 3:00–5:00 | 4 Objectives | B |
| 5:00–6:30 | 5 Scope | B |
| 6:30–8:00 | 6 Methodology | B |
| 8:00–10:00 | 7 Architecture | C |
| 10:00–12:00 | 8 AI Engine | C |
| 12:00–13:30 | 9 Protection Layers | C |
| 13:30–15:00 | 10 Workflow | D |
| 15:00–17:00 | 11 Testing | D |
| 17:00–18:00 | 12 Conclusion | D |

*(5 speakers? Give Speaker E slides 9–10 and shift C to 7–8, D to 11–12.)*

---

## Slide 13 (hidden/backup) — Live Demo Transition

**Slide content:** "LIVE DEMO" + the system's login screen URL

> Only use if the panel wants a demo during the oral defense itself. Follow `defense/04-DEMO-RUNBOOK.md` — 8–10 minutes, scripted, with expected results at each step and fallbacks ready.

---

## Delivery notes for the whole team

1. **Handoffs:** each speaker's last line names the next topic — use them; they prevent dead air and show coordination.
2. **If a panelist interrupts with a question mid-slide:** answer briefly from the fact sheet, then "Happy to go deeper in the Q&A — continuing."
3. **Numbers freeze:** 90 backend tests / 380 assertions / 46 solver tests / 136 total / 8 constraints / 4 room types / 4 statuses / 11 tables / 15 s solver cap / under 1 s actual. Nothing else.
4. **Never say:** ML, neural network, "always works", "faculty login". Say: constraint programming, CP-SAT, "proposes and explains", "records, not users", "availability is a day + a declared time window".
