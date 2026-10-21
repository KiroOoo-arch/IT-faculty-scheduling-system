# User Flow — IT Faculty Scheduling System

*Standalone companion to `System-Architecture.md` § 3 (User Types and User Flows). Content is identical and verified against the implemented system (commit 38274e3; the published-reference delete guard added in commit 524e5ab is documented in § 2.1 below and in `System-Architecture.md` § 2.10).*

---

## 1. System Users (Complete List)

| User Type | System Access | Role in the System |
|---|---|---|
| **Administrator / Department Head** | **Login account — the only system user** (one combined role; the Department Head logs in with the Administrator account) | Full operational control: manage faculty/subject/room/section records, generate schedules, review/edit, approve, publish, unpublish, print/download, view reports, manage admin accounts |
| **Faculty** | **No login — not a system user** | Maintained as scheduling records (name, employment type, qualifications, availability, max load). Their data feeds the scheduling engine; they receive published schedules as **printed/PDF copies** |
| **Students** | **No login — not a system user** | Recipients of published schedules via printed/PDF copies posted or distributed by the department |

> **Role-model note:** The SRS lists *Administrator* and *Department Head* as two user classes; the implemented system issues a single **admin** login role that covers both (the Department Head uses the Administrator account). There is no faculty login, no faculty portal, and no student account. Faculty and students exist only as data records / recipients.

---

## 2. User Flow — Administrator / Department Head

```mermaid
flowchart TD
    A[Login<br/>email + password] --> B{Sanctum auth<br/>role == admin?}
    B -- no / non-admin --> X[Rejected:<br/>Only administrator accounts can access this system]
    B -- yes --> C[Admin Dashboard]
    C --> D[Manage Scheduling Data<br/>Faculty records · Subjects · Rooms · Sections]
    D --> DEL["Delete a master-data record<br/>Faculty · Subject · Room · Section"]
    DEL -- "still used by a published schedule" --> DEL1[HTTP 409<br/>total sessions at risk + published count<br/>· nothing is mutated]
    DEL1 -. admin declines .-> C
    DEL1 -- admin confirms --> DEL2[Retry with ?force=1<br/>delete proceeds]
    DEL2 --> C
    DEL -- "no published reference" --> DEL3[Deleted]
    DEL3 --> C
    D --> D1[Create/Edit Subject<br/>lab_hours > 0 requires computer_lab /<br/>science_lab / electronics_lab · else HTTP 422]
    D1 --> E[Create/Edit Section<br/>assign subjects]
    E --> E1{Subjects match section<br/>year level + semester?}
    E1 -- no --> E2[HTTP 422<br/>offending subject codes named<br/>· assignment rejected]
    E2 --> E
    E1 -- yes --> F[Generate Schedule]
    F --> F0{"Section already has a schedule?<br/>show a confirmation prompt<br/>naming what will be replaced"}
    F0 -- "confirmed / none exists" --> F1{Laravel validation gate:<br/>all assigned subjects match<br/>year level + semester?}
    F0 -. cancel — nothing is sent .-> C
    F1 -- no --> F2[HTTP 422<br/>AI engine never called]
    F2 --> E
    F1 -- yes --> F3[FastAPI → OR-Tools CP-SAT<br/>candidate schedule]
    F3 --> G{Result}
    G -- OPTIMAL / FEASIBLE / PARTIAL --> H[DRAFT: Review / Manual Edit<br/>server-side conflict detection]
    G -- INFEASIBLE --> I[Reasons reported<br/>fix data and retry]
    I --> E
    H --> J[Approve]
    J --> K{Publish conflict gate:<br/>cross-section conflicts?}
    K -- conflict --> L[Publish rejected 422]
    L --> H
    K -- none --> M[PUBLISHED]
    M --> N[Print / Download<br/>print-friendly weekly grid<br/>→ browser print dialog → Save as PDF]
    N --> O[Distribute hard copies<br/>to faculty and students]
    M -. changes needed .-> P[Unpublish<br/>PUBLISHED → DRAFT]
    P --> H
```

Text flow (same steps):

```text
Login (admin-only) → Manage Data → Create/Edit Subjects (lab consistency validated)
  → Create/Edit Sections + Assign Subjects (year/semester validated, 422 on mismatch)
  → Generate Schedule (if the section already has a schedule, confirm before replacing it — cancelling sends nothing)
  → Laravel validation gate (422 blocks legacy mismatches; AI never called)
  → OR-Tools CP-SAT candidate → DRAFT → Review / Manual Edit (conflict-checked)
  → Approve → Publish conflict gate → PUBLISHED → Print/Download PDF → Distribute
  → (Unpublish returns to DRAFT for re-editing)
  → (Deleting master data a published schedule depends on: 409 with the exact scope,
     then an explicit ?force=1 to proceed)
```

Every validation step above is enforced server-side (SectionController, SubjectController, ScheduleController) and mirrored in the UI — the subject checklist on the Sections page only lists subjects matching the section's year level and semester, with legacy mismatches flagged in place.

---

## 2.1 Deleting Master Data That a Published Schedule Uses

The admin can delete a faculty record, subject, room or section at any time — master data does change. The one operation the system refuses to do *silently* is destroying part of a timetable that has already been distributed.

```text
Admin clicks Delete on a faculty / subject / room / section record
  → Client asks "Delete this record? Its schedule sessions will also be removed."
  → DELETE /api/rooms/{id}
  → Server counts every session that references the record,
    and which of those schedules are published

  CASE no published reference:
      delete proceeds → 200

  CASE a published schedule still uses it:
      → 409 with the exact scope, e.g.:
        "This record is still used by published schedules #69, #102. Deleting it would
         remove 31 sessions in total, 3 of them from the published timetable."
        (plus requires_confirmation, published_schedule_ids, sessions_at_risk,
         published_sessions_at_risk)
      → NOTHING is mutated. The record is still readable.
      → Client shows a SECOND confirmation quoting that message verbatim
        · Admin declines → nothing is sent at all
        · Admin confirms → DELETE /api/rooms/{id}?force=1 → delete proceeds → 200
```

**Why the counts are split.** The message leads with the *total* number of sessions that would disappear and then states how many of those are in the published timetable, because the two numbers are very different. In the verified run, deleting one room removed **31** sessions of which only **3** were published — reporting the published subset alone would have understated the loss by an order of magnitude.

**What confirming actually costs.** On `?force=1` the record is deleted and every session that referenced it is removed — drafts, archives, *and* published. Three consequences follow, and all three are deliberate:

| After a forced delete | Behaviour |
|---|---|
| The affected published schedule | Keeps its **`published`** status. Nothing downgrades, archives or flags it — it is simply short of sessions |
| Audit trail | **None.** No generation log, no tombstone; the removal is not recorded |
| Recovery | Manual: unpublish, correct the master data, regenerate, re-approve, re-publish |

> **The honest framing.** The 409 is an intention checkpoint, not a transaction. Its purpose is that destroying a live timetable must be a *deliberate act* rather than a side effect of tidying up master data — so the admin is shown the exact scope and has to confirm twice. What it does **not** do is make the operation reversible or repair the published schedule afterwards. Stated as a known gap: a forced delete can leave a published schedule incomplete without the system flagging it, and recovery depends on the admin noticing and regenerating.

---

## 3. User Flow — Faculty (Non-User)

Faculty never log in and never interact with the system directly:

```text
Admin enters faculty data (name, type, qualifications, availability, max load)
  → faculty records feed the scheduling engine
  → Admin publishes the schedule
  → Admin prints / exports PDF
  → faculty member receives the printed/PDF copy of their schedule
```

---

## 4. User Flow — Students (Non-User)

Students never log in and never interact with the system directly:

```text
Admin publishes the section schedule
  → Admin prints / exports PDF
  → copies are posted or distributed to the class
```

---

## 5. Schedule Lifecycle (status transitions)

```text
DRAFT → APPROVED → PUBLISHED → ARCHIVED
                     ↓ (Unpublish)
                  DRAFT (for re-editing)
```

- **DRAFT** — AI-generated candidate; can be reviewed and manually edited (every edit conflict-checked server-side)
- **APPROVED** — accepted by the Admin/Department Head; still not visible to faculty/students
- **PUBLISHED** — passed the cross-section publish conflict gate; printed/PDF copies are produced for distribution
- **ARCHIVED** — superseded by a newer generation for the same section
