# Frontend — React Admin UI

The Department Head's interface for the IT Faculty Scheduling System.

**Stack:** React 19, TypeScript, Tailwind CSS 4, Vite. Runs on **port 5173**.

## Scripts

| Script | Command | Notes |
|---|---|---|
| `npm run dev` | `vite` | dev server on 5173 |
| `npm run build` | `tsc -b && vite build` | typecheck + production build |
| `npm run lint` | `eslint .` | see "Lint debt" below |
| `npm run preview` | `vite preview` | preview the production build |

## API

All requests go to the Laravel API at a hardcoded base URL in `src/context/AuthContext.tsx`:

```ts
const API_BASE_URL = 'http://127.0.0.1:8000/api'
```

Authentication is a Bearer token kept in `localStorage`. `AuthContext` re-validates the cached
profile against `GET /me` on mount, so a renamed account shows correctly without a re-login.

## Routes

| Route | Page |
|---|---|
| `/login` | `LoginPage` |
| `/dashboard` | `AdminDashboard` — generate card + schedule review/approval |
| `/admin/users` | `UsersPage` |
| `/admin/faculty` | `FacultyPage` |
| `/admin/subjects` | `SubjectsPage` |
| `/admin/rooms` | `RoomsPage` |
| `/admin/sections` | `SectionsPage` |
| `/admin/reports` | `ReportsPage` — Overview / Faculty Load / Room Usage / Sections / Generation Logs |
| `/print-schedule?schedule={id}` | `PrintableSchedule` — `&layout=list\|grid` |
| `/print-faculty-schedule?faculty={id}` | `PrintableFacultySchedule` |

## Structure

```
src/
  components/     PrintLetterhead (official letterhead), ScheduleListTable (subject-list template)
  constants/      system.ts (org + system name), roomTypes.ts (canonical room/lab vocabulary),
                  subjectClassification.ts (major | minor | unclassified)
  context/        AuthContext (token, user, API_BASE_URL)
  pages/          page components; pages/admin/ holds the master-data pages
  utils/          time formatting
```

### Shared vocabulary

`constants/roomTypes.ts` is the single frontend source for room and lab types
(`lecture`, `computer_lab`, `science_lab`, `electronics_lab`). It mirrors
`RoomController::ROOM_TYPES` and `SubjectController::LAB_ROOM_TYPES` deliberately — the solver
matches a session to a room with an exact string comparison, so a free-text room type would
silently make lab sessions unschedulable.

`constants/subjectClassification.ts` is the frontend source for the review outcomes
(`major`, `minor`, `unclassified`) and mirrors `App\Models\Subject`'s constants. The Subjects page
sends `classification` explicitly on every create and update — it is never inferred from whether an
owner is set, because the API reads a missing classification as `unclassified` and not as a shared
subject. `classificationOf()` maps anything unrecognised (including a missing column) to
`unclassified`, the outcome that grants nothing, so an unreviewed subject is never displayed as
schedulable.

### Subject classification and section scope

The Subjects page is the only place the review outcome is decided:

| Classification | The form asks for | The API stores |
|---|---|---|
| **Major** | one owning department, plus any other departments authorized for it | `department_id` and one `subject_department_access` row per authorized department (the owner always included) |
| **Minor / shared** | nothing — it is open to every department | no owner, no grants |
| **Unclassified** | nothing; a new subject starts here | no owner, no grants; schedulable and generatable nowhere |

The owner's authorization row is shown as a locked, always-checked entry, so it cannot be left out of
the list. Changing an existing subject's classification or owner asks for confirmation, and the API
refuses the change outright if a section still holds the subject and could no longer schedule it —
the screen shows that refusal verbatim, naming the sections to fix first. Moving a subject to
Minor / shared is allowed without that check, since every department may take a minor.

### Reviewing what nobody has classified

Unclassified subjects are collected in a **Subjects Needing Review** card on the Subjects page, above
the full list, showing the count and each subject's code, title, year, semester and current
classification. Two actions sit on every row:

- **Classify as Minor / Shared** saves immediately. It is the one outcome that needs no other decision
  — no owner, no grants, and the API clears any grant the subject held — so it is a single deliberate
  click rather than a trip through the form. The result is reported beside the queue.
- **Classify as Major…** opens the subject in the form with Major already selected and scrolls to it,
  because the owning department and the departments authorized for it are decisions only an
  administrator can make. The owner is required, extra departments are ticked explicitly, and the
  saved row is checked by the API exactly as a hand-edited one is.

### Working through the queue in bulk

A checkbox on every row and a **Select all** box in the header turn the queue into a selection; the bar
above the table shows how many subjects are ticked and offers **Classify Selected as Minor / Shared**,
**Review Selected as Major…** and **Clear selection**. Both bulk actions ask for confirmation first,
naming the subjects they will change.

There is no bulk endpoint. The screen sends the same PUT the form sends, once per ticked subject, in
queue order, so the classification rules, the lab consistency rule and the stranded-section check are
still evaluated for each subject on its own — and a refusal belongs to one subject rather than to the
run. That is also why a run that half succeeds reports as much: the count that was classified, then one
line per subject carrying the server's own reason for the ones that were not. The refused subjects stay
ticked and stay in the queue, so the next attempt is exactly the work that is left; only the subjects
that were ticked are written to at all.

**Review Selected as Major…** cannot be a single click — a major needs an owner and the departments
authorized for it — so the selection becomes a run through the form, one subject at a time (*Reviewing
2 of 3*), with **Skip this subject** and **Stop this run** beside it. Nothing is saved until Update is
pressed for that subject, a skipped subject stays unclassified in the queue, and updating a subject
that is not part of the run ends the run.

The queue only ever holds `unclassified` subjects: nothing is auto-classified, and a subject stays
unschedulable — refused by section assignment and by generation, not merely hidden — until someone
saves an outcome for it.

### Where subject choices come from

The Section editor asks the API for the subjects a department may take
(`GET /api/subjects?department_id=N`) instead of downloading every subject and filtering in the
browser. The endpoint applies the authorization rule itself, so a major of another department with no
grant, and every unclassified subject, is never in the response; a section with no department asks with
an empty value and is offered minor / shared subjects only. The form still narrows the answer to the
section's year and semester, and still shows an assigned subject the current selection no longer covers
in an amber “fix or remove” block rather than dropping it silently. Loading, empty and failed states
are all shown, the failure with a **Try again** button. The server remains the authority: it validates
every assignment it is sent, whatever the picker offered.

### Department display names

Departments are shown by name, never by code: **Industrial Technology** (`BIT`) and **BindTech
Electronics** (`ELEC`). The code is the stable handle the API, the section name prefixes and the
classification mapping key on; the name is what the dropdowns, chips (`shared from BindTech
Electronics`), tables and ownership sentences print. The frontend hardcodes neither — it renders
`department.name` from the API — so a rename is a seeder edit, not a frontend change.

### Printable output

Both printables render through `PrintLetterhead` and carry print-specific CSS. The section printable
defaults to a subject-list layout and can switch to the weekly grid via `&layout=grid`. Published
schedules only.

## Lint debt

`npm run lint` currently reports **11 errors / 10 warnings**, all the `react-hooks/set-state-in-effect`
pattern from the `useEffect(() => { fetchX() }, [])` mount effects used across the admin pages, plus the
matching `exhaustive-deps` warnings for the `fetchX` helpers those effects call. These are pre-existing
and do not affect runtime behaviour; `npm run build` passes cleanly. The Section editor's department
scoped fetch is deliberately written to avoid adding another of them: it defers the call by a microtask,
which also drops a superseded response instead of letting it overwrite a newer list.
