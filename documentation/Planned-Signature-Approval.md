# Planned Update — Signature Before Approval

**Status:** agreed design, not yet implemented
**Reference:** acknowledgement footer supplied by the user (screenshot) — a signature written over a
printed name, with a title and date, laid out side by side (the example showed
`Date enrolled | Registrar | Total Units | Subject Acknowledged`).

This note records the reference and the decisions taken, so the feature can be built later without
re-litigating the design.

---

## 1. Where the feature sits today

The schedule workflow already has the right shape, so a signature is an extension of an existing
concept rather than a new one.

State machine, from `backend/app/Http/Controllers/ScheduleApprovalController.php`:

```
draft --approve--> approved --publish--> published --unpublish--> draft
  |                    |
  +---- reject --> rejected
```

| Route | Handler | Behaviour today |
|---|---|---|
| `PATCH /api/schedules/{schedule}/approve` | `approve()` | `draft` → `approved`; stamps `approved_by` + `approved_at` |
| `PATCH /api/schedules/{schedule}/publish` | `publish()` | `approved` → `published`; runs `findPublishConflicts()` first |
| `PATCH /api/schedules/{schedule}/unpublish` | `unpublish()` | `published` → `draft`; clears `approved_by` + `approved_at` |
| `PATCH /api/schedules/{schedule}/reject` | `reject()` | `draft` → `rejected` |

Relevant facts established by inspection:

- `schedules` already carries `approved_by` (nullable FK to `users`) and `approved_at` — the natural
  home for signature evidence. **No signature/signatory code exists anywhere** in backend,
  database, or frontend today.
- `approve()` and `publish()` are gated by `authorizeAdmin()`, which requires `role === 'admin'`
  (the Department Head). Copy currently reads *"Only the Department Head can approve, publish, or
  reject schedules."*
- The UI drives these from `frontend/src/pages/AdminDashboard.tsx` — `handleApprove()` posts to
  `/schedules/{id}/approve`, `handlePublish()` to `/schedules/{id}/publish`; the buttons are shown
  for `draft` and `approved` rows.
- The printables (`frontend/src/pages/PrintableSchedule.tsx`, `PrintableFacultySchedule.tsx`) render
  through `frontend/src/components/PrintLetterhead.tsx` and have **no signature footer block at
  all** today.

## 2. Decisions taken

1. **Signing IS the approval.** A single Department Head action records the signature *and* moves
   the schedule to `approved`. Publishing stays a separate, conflict-checked distribution step —
   it is not folded into signing.
2. **Typed name + timestamp.** No drawn canvas signature and no stored image. The signer's typed
   name is stamped together with the server time.
3. **Single signatory — the Department Head only.** This matches the existing admin-only rule; no
   registrar account or extra role is introduced.

## 3. Proposed change set

Deliberately small — the flow already exists, so this is an added field plus its evidence.

**Database**
- One migration: add nullable `signature_name` (string) to `schedules`.
- Reuse the existing `approved_by` / `approved_at` columns as the signature's identity and
  timestamp rather than adding a parallel `signed_by` / `signed_at` pair — with "signing IS the
  approval" they are the same event, and duplicated columns would be able to drift apart.
- No `schedule_signatures` table under this decision. If a second signatory (e.g. Registrar) is
  ever needed, that is the point to introduce it — adding a row-per-signatory table then is
  cheaper than widening `schedules` twice.

**Backend**
- `Schedule::approve()` validates `signature_name` as `required|string|max:120`, stores it
  trimmed, and rejects with 422 when blank.
- `unpublish()` clears `signature_name` alongside `approved_by` / `approved_at`, so a reverted
  draft cannot carry a stale signature.
- `type` the schedule JSON response so the frontend can rely on `signature_name` being present.

**Frontend**
- Approve becomes a two-field act: the existing button opens a small inline confirm showing the
  typed name and the date, and stays disabled until the name is non-empty.
- Surface the signer on `approved` / `published` rows ("Signed by <name> · <date>").

**Printable**
- Add a footer signature block to the section and faculty printables, rendered **only when the
  schedule is `published`**: a signature line, the typed name set in the signature style, the title
  `Department Head`, and the approval date — mirroring the reference footer.

**Tests**
- Blank / missing `signature_name` is rejected with 422 and leaves the schedule in `draft`.
- A successful approve persists `signature_name`, `approved_by`, `approved_at`.
- `publish` still refuses anything not `approved`, and still runs the conflict check.
- `unpublish` clears the signature.

## 4. Explicitly out of scope

Two fields visible in the reference footer cannot be produced by this system as it stands, and are
**not** part of this update:

- **Total Units** — there is no `units` column anywhere; subjects carry `lecture_hours` and
  `lab_hours` only. Reproducing that column needs a schema addition plus form, model, and report
  changes.
- **Date enrolled** — registrar/enrollment data the scheduler does not track.

The reference's *two-column* acknowledgement layout (Registrar **and** Subject Acknowledged) is also
not reproduced under decision 3; only the Department Head signs.
