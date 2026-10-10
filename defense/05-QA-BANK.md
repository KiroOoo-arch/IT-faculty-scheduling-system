# Q&A BANK — ~40 Questions with Verified Answers
**Rule of engagement:** answer from the fact sheet. Short answer first, then one supporting detail. If you don't know: *"That's a great question — our design decision there was X, and I'd want to verify the exact behavior before confirming"* beats guessing. Never invent numbers.

Organized by theme. ⭐ = highest-probability questions.

---

## A. Architecture & Stack

**Q1. ⭐ Why three separate services instead of one application?**
> Technological fit: Python hosts OR-Tools (the solver isn't natively available in PHP), Laravel handles web concerns it's excellent at, React gives the admin a real UI. One PostgreSQL database keeps a single source of truth. Each service is independently maintainable and, if needed, independently scalable.

**Q2. Why Laravel and not CodeIgniter/Symfony/Django?**
> First-party Sanctum auth, mature validation/migrations/Eloquent, rapid development through conventions. Django was considered, but the team's PHP strength plus Laravel's auth tooling made it the productive choice; the AI layer covers the Python side.

**Q3. Why React and not Vue/Angular?**
> Component model fits our nine CRUD/dashboard pages; TypeScript gives build-time safety on API contracts; largest ecosystem; team familiarity.

**Q4. Why PostgreSQL and not MySQL/SQLite?**
> ACID guarantees, JSONB for genuinely flexible fields (preferred_days, unscheduled explanations), strict FK constraints with cascades, first-class Laravel support.

**Q5. How do Laravel and FastAPI communicate? What if one is down?**
> Synchronous HTTP POST to `/generate-schedule/{id}`; FastAPI returns the solver result; Laravel persists. If FastAPI is down, generation returns an error and *nothing is partially written* — persistence only happens on a successful response. CRUD and reports are unaffected.

**Q6. Why does the AI engine read the database directly?**
> The solver needs many tables (sections, subjects, faculty, qualifications, availability, rooms); passing them all through the HTTP payload would duplicate Laravel's serialization and bloat the contract. Parameterized psycopg2 queries keep it safe.

---

## B. AI / Solver (the deepest theme)

**Q7. ⭐ Explain the AI in 30 seconds.**
> Scheduling is modeled as constraint programming: for every needed session, variables for day, time, room, and faculty; domains from the real data; eight hard constraint categories enforced as model constraints; the objective maximizes the number of successfully placed sessions. Google OR-Tools CP-SAT solves it and proves the result. Statuses: OPTIMAL / PARTIAL (with per-session reasons) / INFEASIBLE.

**Q8. ⭐ Why not machine learning?**
> No training data exists; scheduling validity is a hard requirement — one conflict invalidates the schedule, and probabilities don't guarantee anything. CP-SAT gives mathematical satisfaction + optimality proof + explanations. This is an optimization/modeling problem, not a prediction problem. (Bonus: "we chose the right tool — using ML here would have been using AI for its own sake.")

**Q9. What exactly are the 8 constraints?**
> (1) faculty qualification, (2) faculty availability (day + declared time window), (3) room type matching, (4) room capacity vs section size, (5) faculty no double-booking, (6) room no double-booking, (7) max teaching load, (8) cross-section conflicts vs the **same academic year and semester's** draft/approved/published schedules. Plus the section's preferred window and self-overlap prevention modeled directly.

**Q10. ⭐ Is faculty availability enforced by hour or by day?**
> **By day *and* by hour.** Each faculty member declares availability as a day plus a start/end window, and the solver only places a session when it fits entirely inside one declared window on that day. A faculty member with no declared availability at all falls back to the section's preferred days. The section's own preferred window also applies as an outer bound.

**Q11. What happens when it can't schedule everything?**
> Best-effort: the objective maximizes placed sessions. PARTIAL returns what was placed plus a plain-language reason per unscheduled session (no qualified faculty, no suitable room, window too short, conflicts). INFEASIBLE means the data makes placement impossible. The admin fixes data and regenerates — the system *explains* instead of producing a bad schedule.

**Q12. How do you know the schedule is actually optimal?**
> CP-SAT proves optimality within the 15-second budget — on our data it returns OPTIMAL in about 1–2 seconds. If the budget is hit with a valid solution, it reports FEASIBLE instead of claiming optimality.

**Q13. Why maximize sessions as the objective? Why not fairness or compactness?**
> Correctness first: place as much as possible without violating any hard constraint. Additional objectives (compact schedules, balanced loads, preferred gap placement) are additive soft objectives — documented future work. The midday break itself is already enforced as a hard constraint.

**Q14. What was your hardest technical bug?** ⭐ (authenticity gold — tell it well)
> A constraint-modeling one. Time-disjointness is a *disjunction* — session A is before B or after B. Our first implementation reified it one-directionally (¬overlap → must start after the external session), which silently forced an ordering. When an approved schedule had evening sessions outside a morning section's window, every placement became impossible → instant INFEASIBLE on clearly schedulable data. We added solver-status logging, diagnosed the reification, rewrote the constraints as explicit before/after booleans for both external and internal conflicts, and added a 46-test solver suite covering exactly these cases. It taught us: in CP, *how you encode a rule* is as important as the rule.

**Q15. How long does generation take? Does it scale?**
> ~1 s per section on our dataset (9 sections, 11 subjects, 7 faculty), 15 s hard budget. Complexity grows with sessions per day (pairwise overlap constraints, O(n²)); CP-SAT handles department scale comfortably. University scale → partition per department/college; the engine is stateless so it can be replicated/queued without redesign.

**Q16. Why "AI-assisted" and not fully automatic?**
> Deliberate human oversight: the solver proposes a candidate; the admin reviews, edits, approves, publishes. Publishing is never automatic. Accountability and a final human error-check belong to the Department Head.

---

## C. Database & Data Integrity

**Q17. How many tables and what are they?**
> 11 domain tables: users, faculties, faculty_availabilities, subjects, faculty_subjects, sections, section_subjects, rooms, schedules, schedule_sessions, schedule_generation_logs.

**Q18. ⭐ Faculty don't log in? Why?**
> Instructor-directed design decision (September 2026), justified on three grounds: the system is used per semester by one office; distribution is hard-copy/print-PDF culture; and removing accounts shrinks the attack surface — no faculty credentials to leak or escalate. Faculty are records: name, type, qualifications, availability, load. `faculties.user_id` is a nullable legacy column from before the change — not a login.

**Q19. How do you handle deletions?**
> Cascade rules at the DB level: faculty → their availabilities/qualifications/session links; room → its sessions; schedule → its sessions; section → schedules + logs. Referential integrity is automatic; no orphans. Destructive deletes are also protected: if the record is still referenced by a **published** schedule, the API returns **409** and requires an explicit `?force=1` confirmation before cascading.

**Q20. Why pivot tables for faculty_subjects and section_subjects?**
> Clean many-to-many: one faculty teaches many subjects and vice versa; one section takes many subjects and vice versa. Normalized, queryable, update-anomaly-free.

**Q21. How do you prevent two live published schedules for one section?**
> Regeneration auto-archives old drafts; publishing archives older approved/published schedules of the same section; the lifecycle allows exactly one current published schedule per section at a time.

**Q22. What's the audit trail?**
> `schedule_generation_logs` records every generation: who requested, when, status (optimal/partial/failure), the message, and unscheduled-session details as JSON. Combined with schedule status history (approved_by, approved_at).

---

## D. Security

**Q23. ⭐ How does authentication work?**
> Laravel Sanctum token auth: `POST /api/login` → bearer token → included in the Authorization header → revoked at logout. Passwords are bcrypt-hashed by Laravel's framework defaults.

**Q24. What stops a faculty member logging in?**
> Two layers: the login endpoint itself rejects non-admin roles (defense-in-depth — no token is issued), and every management route sits behind `auth:sanctum` + `admin` middleware. Faculty accounts don't exist to begin with.

**Q25. SQL injection / XSS / CSRF?**
> Eloquent ORM parameterizes all queries; psycopg2 uses parameterized queries too; React escapes output by default (no raw HTML injection); Laravel validation whitelists every write; the API uses token auth rather than cookie sessions.

**Q26. What if the only admin gets deleted?**
> A last-admin deletion guard refuses the operation — the system can't be locked out.

**Q27. Where is sensitive data documented?**
> `documentation/Data-Privacy-and-Security.md` — privacy notice, security controls, retention/disposal, data-subject rights. The system holds faculty scheduling data, not grades or personal finance; retention follows the department's semester cycle.

---

## E. Validation & Conflict Prevention

**Q28. ⭐ You mention "five protection layers" — what are they?**
> (1) CP-SAT constraints during generation — every other section's draft/approved/published session in the same academic year and semester is a fixed booking; (2) the generation-time gate: one run per term behind a cache lock (409 if contended), and the finished plan is cross-checked before anything is written (422 on a clash, the section's existing draft untouched, replacement in one transaction); (3) server-side conflict detection on every manual session edit (422 with specifics, drafts included); (4) the publish conflict gate — a final cross-section check against approved/published schedules before going live; (5) the published-data delete guard (409 + `?force=1`). Plus a pre-flight data gate in Laravel (subject/section year-semester matching, lab-hour consistency) that rejects bad data with a 422 *before the AI is ever called*.

**Q29. What conflicts can a manual edit trigger?**
> Faculty time overlap, room time overlap, section self-overlap, room type mismatch, capacity — each returned specifically so the admin knows exactly what to fix.

**Q30. What does the publish gate check that the solver didn't already?**
> Two things. Generation already treats every other section's draft/approved/published session in the same academic year and semester as a live booking — serialized by the term lock, with a pre-write conflict check that answers 422 — but time still passes between generation and publication, and other sections' schedules may be approved or published in between. So the gate re-checks faculty/room/time conflicts against the *current* set of approved/published schedules, not the state as of generation time.

**Q31. Tell me about the validation 422s.**
> Two gates: SectionController validates subject-year/semester matching on save (naming offending subjects); SubjectController enforces lab consistency (lab hours > 0 requires a lab room type; zero lab hours requires none). ScheduleController re-checks before calling the AI. Every 422 names the offending data — errors are explanations.

---

## F. Testing

**Q32. ⭐ How did you test the system?**
> Three levels: (1) **118 Python unit tests** on the solver (five modules) — every constraint category, part-time faculty restrictions, infeasible cases, the disjointness reification; (2) **143 Laravel feature tests (604 assertions)** — generation correctness (right section/year/semester), cross-section conflict protection (term lock 409, pre-write gate 422, atomic draft replacement, draft-aware manual edits), subject-section matching gate, lab consistency rules, regeneration archiving; (3) **live end-to-end verification** — the live sections generating OPTIMAL, the full approve→publish→print workflow, validation gates firing, zero console/network errors. Frontend TypeScript compile + production build pass.

**Q33. Do tests touch real data?**
> No — the backend suite runs on an isolated `scheduling_system_testing` database; dev data is never touched.

**Q34. What couldn't you test automatically?**
> Visual/print layout and full-browser flows — covered by scripted manual end-to-end passes instead. Automated UI testing (Playwright) is future work.

---

## G. Design Decisions & Honest Limits

**Q35. Why does the department need this? Quantify the benefit.**
> Manual scheduling: hours of cross-checking per semester, errors found after posting. Ours: seconds, with conflicts mathematically excluded before posting, plus reports and an audit trail. Time saved is real but modest; the *error class eliminated* (double-bookings, wrong rooms, overloads) is the core value.

**Q36. What would you build next?**
> Soft preferences (seniority priority, gap minimization), CSV/Excel export, availability declared per calendar date, notifications on schedule changes, university-scale partitioning, automated UI tests.

**Q37. ⭐ What are the system's limitations?** (volunteer these!)
> Department-scale only; availability declared per day-of-week rather than per calendar date; no soft preferences yet; synchronous generation call; small real-world dataset so far. Each has a documented path forward — and none affects correctness of what IS implemented.

**Q38. What if the department grows to 10× sections?**
> The solver budget absorbs it at department scale; beyond that, partition by program/college (cross-section conflicts only matter within shared faculty/rooms anyway), and move generation behind a queue. The stateless engine makes both straightforward.

**Q39. Why is this a capstone-worthy project? What's technically novel?**
> The integration depth: a formally-verified constraint model (with stress-tested failure behavior) wired into a full administrative workflow with three defense layers and explanations at every failure point. Constraint solvers exist; *making one explainable, validated, and embedded in a governance workflow* is the engineering contribution.

**Q40. If a panelist breaks your demo data live — what happens?**
> The system tells them *why* it can't proceed: a 422 naming the offending subject, or a PARTIAL result naming the unplaceable session and reason. That's the best possible response to adversarial input — the system defends itself with explanations. (Which is exactly what we'd say while fixing it.)
