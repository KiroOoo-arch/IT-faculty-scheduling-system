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
  constants/      system.ts (org + system name), roomTypes.ts (canonical room/lab vocabulary)
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

### Printable output

Both printables render through `PrintLetterhead` and carry print-specific CSS. The section printable
defaults to a subject-list layout and can switch to the weekly grid via `&layout=grid`. Published
schedules only.

## Lint debt

`npm run lint` currently reports **11 errors / 9 warnings**, all `react-hooks/set-state-in-effect`
from the `useEffect(() => { fetchX() }, [])` pattern used across the admin pages. These are
pre-existing and do not affect runtime behaviour; `npm run build` passes cleanly.
