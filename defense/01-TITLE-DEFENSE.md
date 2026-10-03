# TITLE DEFENSE — Script & Prep
**Event type:** Combined panel (title + oral + design hearing share sessions)
**Slot:** ~10 minutes speaking + panel questions
**Team:** 4–5 members — Speaker A (intro/problem), Speaker B (objectives/scope), Speaker C (methodology), Speaker D (expected output/plan), Speaker E (optional: closing/handoff)

> ⚠️ **Read `defense/00-FACT-SHEET.md` first.** Every number below comes from it. At title defense, panels judge *clarity and feasibility* — not implementation depth. Keep it crisp.

---

## Part 1 — Opening & Problem Statement (Speaker A) — ~2 min

> "Good morning/afternoon, panel. We are presenting our capstone project: the **AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System** for the IT Department.

> "Every semester, the Department Head builds the class schedule by hand. That process has four concrete problems:
> **One — it's slow.** Hours of manual cross-checking across faculty, rooms, and sections.
> **Two — it's error-prone.** Faculty get double-booked. Lab classes end up in lecture rooms. Teaching loads get exceeded — and these are discovered only after the schedule is posted.
> **Three — conflicts are invisible across sections.** Checking one section's schedule tells you nothing about another section using the same room or teacher.
> **Four — there's no audit trail.** Nothing records who generated a schedule, when, or why it changed.

> "Our project replaces that manual process with a system that *generates* conflict-free schedules automatically, *defends* them with three layers of validation, and keeps the Department Head in full control."

*(Transition to B: "My teammate will now present our objectives.")*

---

## Part 2 — Objectives / Statement of the Problem (Speaker B) — ~2.5 min

> "Our study is guided by six specific objectives — each one maps to something the system verifiably does today:
>
> **SOP 1 — Automatically generate schedules.** The system formulates scheduling as a constraint-programming problem and solves it with Google OR-Tools CP-SAT — an industrial-grade solver that guarantees constraint satisfaction.
>
> **SOP 2 — Prioritize students.** Each section declares preferred days and a time window; the solver enforces them directly, and room capacity protects students from overcrowded rooms.
>
> **SOP 3 — Detect and prevent conflicts.** Eight hard constraint categories — qualifications, availability, room type, room capacity, faculty double-booking, room double-booking, maximum teaching load, and cross-section conflicts — are enforced mathematically, not by trial and error.
>
> **SOP 4 — Optimize resource use.** Rooms are matched by type and capacity; reports track room utilization and faculty workload.
>
> **SOP 5 — Keep the Department Head in control.** The AI proposes a draft; the admin reviews, edits, approves, and publishes through a conflict gate. Publishing is never automatic — *the AI proposes, the Admin decides.*
>
> **SOP 6 — Ensure acceptability and reliability.** The system ships with 136 automated tests across the solver and backend, plus a full audit trail of every generation attempt."

*(Transition to C: "To achieve these objectives, here is our methodology.")*

---

## Part 3 — Methodology Overview (Speaker C) — ~2.5 min

> "We followed an incremental, prototype-driven approach across four layers:
>
> **Data layer:** PostgreSQL with 11 domain tables — faculty, subjects, rooms, sections, and the pivot and schedule tables that link them — managed by Laravel migrations as the single source of schema truth.
>
> **AI layer:** Python FastAPI hosting the OR-Tools CP-SAT solver. We validated the constraint model *standalone first* — on a fabricated dataset including stress cases: part-time faculty with restricted days, and a deliberately broken dataset to prove the solver fails *cleanly with a human-readable explanation* instead of crashing. This de-risked the hardest requirement before any integration work.
>
> **Backend layer:** Laravel 13 with Sanctum authentication — admin-only by design — exposing the REST API and enforcing business rules before the AI engine is ever called.
>
> **Frontend layer:** React with TypeScript — dashboards for managing data, generating schedules, reviewing, approving, publishing, and printing."

*(Transition to D: "Here is our scope and expected output.")*

---

## Part 4 — Scope & Expected Output (Speaker D) — ~2 min

> "**Scope — the system covers one IT Department, per semester:** admin-only access by deliberate design decision; faculty and students are *records and recipients*, not system users — published schedules are distributed as printed or PDF copies. The system manages faculty records, subject qualifications and availability, rooms and labs, sections, and the full schedule lifecycle from draft to published.
>
> **What it does not cover** — and we say this openly: university-wide multi-department scheduling, multi-semester planning, notifications, and soft preferences like lunch breaks or seniority priority. These are documented future work; the architecture supports them.
>
> **Expected output:** a working web system where the Department Head logs in, manages scheduling data, clicks generate, and receives — in about two seconds on our data scale — a conflict-free schedule that respects all eight constraints; every unschedulable session comes back with a plain-language reason. The schedule then moves through draft, approval, and publication, and ends as a printable PDF for distribution."

*(Speaker E or D closes:)*

> "In short: manual scheduling took hours and hid its errors. Ours takes seconds, *proves* its schedules valid, and explains itself when it can't. Thank you — we welcome your questions."

---

## Timing Map (10:00 total)

| Time | Speaker | Content |
|---|---|---|
| 0:00–2:00 | A | Opening + 4 problems |
| 2:00–4:30 | B | SOP 1–6 objectives |
| 4:30–7:00 | C | Methodology (4 layers) |
| 7:00–9:00 | D | Scope + expected output |
| 9:00–10:00 | D/E | Closing statement |

---

## Title-Defense Q&A — The 10 Most Likely Questions

**Q1: Why is "AI-Assisted" in the title and not "AI-Based"?**
> Because the AI generates the *candidate*; the Department Head reviews, edits, approves, and publishes. Human oversight is a deliberate design principle — "the AI proposes, the Admin decides."

**Q2: What AI technique do you use — is it machine learning?**
> No, and that's deliberate. We use **constraint programming** (Google OR-Tools CP-SAT). ML needs training data and gives probabilistic outputs — unacceptable for scheduling, where one conflict invalidates the whole schedule. CP-SAT gives mathematically *guaranteed* valid solutions and proves optimality.

**Q3: What does "Student-Centered" actually mean in your system?**
> Three concrete mechanisms: each section's preferred days and time window are enforced by the solver; room capacity is checked against the section's student count; and every session belongs to a section — the schedule is built around sections' needs, not around room convenience.

**Q4: Is your system already working?**
> Yes — a working end-to-end prototype exists and is verified: the live dataset generates OPTIMAL (all sessions placed), the full draft → approve → publish → print workflow runs, and 136 automated tests pass.

**Q5: Why does the admin have to approve? Can't it just publish?**
> Two reasons: (1) accountability — a human must own the published schedule; (2) safety — the publish step runs one more cross-section conflict check, the third and final protection layer.

**Q6: What if the AI cannot schedule everything?**
> It reports PARTIAL with a plain-language reason per unscheduled session — e.g., "no qualified faculty available" — and INFEASIBLE when nothing can be placed. The admin fixes the data and regenerates. Best-effort with explanations beats a silently broken schedule.

**Q7: Who are the users of the system?**
> The Admin/Department Head is the only login. Faculty are scheduling records — their qualifications and availability feed the solver — and students receive the published schedule as a printed/PDF copy. This was a deliberate design decision to reduce the security surface and match how the department distributes schedules.

**Q8: How is this different from existing scheduling software?**
> Three things: (1) mathematically guaranteed conflict-freedom via CP-SAT, not heuristic matching; (2) explanations — every failure is explained, not just displayed as an error; (3) three protection layers — generation constraints, edit-time validation, and a publish gate.

**Q9: What are your deliverables?**
> The working system (React frontend, Laravel API, FastAPI AI engine, PostgreSQL database), complete documentation (SRS, requirements, architecture, ERD, user manual, data privacy statement), 136 automated tests, and this defense.

**Q10: What is your timeline / what's left to do?**
> The system is functionally complete and verified. Remaining work is preparation: polished demo data, this defense, and optional enhancements (CSV export, soft constraints) documented as future work.

---

## Do / Don't for the title defense

| ✅ Do | ❌ Don't |
|---|---|
| Quote numbers from the fact sheet | Improvise numbers ("I think we had like 20 tests…") |
| Say "constraint programming / CP-SAT" | Say "machine learning" or "neural network" |
| Say "the AI proposes, the Admin decides" | Say "it publishes automatically" |
| Say "best-effort with explanations" | Say "it always finds a schedule" |
| Volunteer limitations confidently | Hide limitations and get caught |
| Hand the baton smoothly ("My teammate will…") | Dead air between speakers |
