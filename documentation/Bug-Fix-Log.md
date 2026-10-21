# Bug Fix Log — Session 2 (08/01–08/02)

## Bug #9: 302 Redirect / HTML Welcome Page Instead of JSON
- **Symptom:** API calls returned the Laravel welcome page (200 HTML) or 302 redirects instead of JSON.
- **Root Cause:** Two issues: (1) missing `Accept: application/json` header, (2) `redirectGuestsTo` in `bootstrap/app.php` was configured to return a **Response object** instead of a **URL string**, breaking unauthenticated request handling.
- **Fix:** Removed the broken `redirectGuestsTo`; always send `Accept: application/json` in frontend fetch calls.
- **Files:** `bootstrap/app.php`, `AdminDashboard.tsx` (headers() helper)

## Bug #10: Admin Middleware Fatal Error
- **Symptom:** 500 error when hitting admin routes.
- **Root Cause:** `EnsureUserIsAdmin.php` was missing the `class EnsureUserIsAdmin` declaration (file had no class body), and `admin` middleware alias wasn't registered.
- **Fix:** Restored the class declaration and registered the alias in `bootstrap/app.php`.
- **Files:** `app/Http/Middleware/EnsureUserIsAdmin.php`, `bootstrap/app.php`

## Bug #11: Schedule Generation 500 — Missing `$fillable`
- **Symptom:** `POST /api/schedules/generate/{section}` returned 500.
- **Root Cause:** `ScheduleSession.php` had the fillable array floating without `protected $fillable = [...]`, causing a fatal PHP error on `ScheduleSession::create()`.
- **Fix:** Added the `$fillable` property declaration.
- **File:** `app/Models/ScheduleSession.php`

## Bug #12: 405 Method Not Allowed on Session URL
- **Symptom:** Opening `api/schedules/sessions/{id}` in a browser shows 405 "GET not supported, supported methods: PUT".
- **Root Cause:** Not a real bug — the route is PUT-only and browsers always send GET when opening a URL in a tab.
- **Fix:** None needed. Use DevTools Network → Preview to inspect responses instead of opening URLs in a new tab.

## Bug #13: Session Edit False "Conflict" (CRITICAL)
- **Symptom:** Saving a session edit **without changing anything** returned `422 This change would create a conflict` for **every** session.
- **Root Cause (two layers):**
  1. **Availability mismatch:** all faculty availability records ended at **15:00**, but the AI engine generated sessions running until **18:00**. The edit conflict check correctly rejected any session ending after 15:00 → every session failed.
  2. **Boundary string comparison:** PHP compares `"16:00"` vs `"16:00:00"` as strings — the shorter prefix `"16:00"` is "less", so `start_time >= availability_start` fails at exact boundaries.
- **Fix:**
  1. Extended all `faculty_availabilities.end_time` to `19:00:00` (data now matches real class hours).
  2. Guarded the availability check with `->isNotEmpty()` (skip when no availability declared, matching the AI engine's fallback).
  3. Normalized both sides with `substr($time, 0, 5)` for consistent `HH:MM` comparison.
- **Files:** `app/Http/Controllers/ScheduleSessionController.php`, `faculty_availabilities` table (data update)

## Bug #14: New User Not Appearing in Faculty Management
- **Symptom:** Creating a user with role `faculty` via **Users Management** didn't create a `faculties` record → person invisible in **Faculty Management** and ineligible for scheduling.
- **Root Cause:** `UserController::store()` only created the `users` row; no `Faculty` record. Only the Faculty Management page (`createFaculty`) created both.
- **Fix:** Added `app/Observers/UserObserver.php` — auto-creates a `Faculty` record whenever a user with `role = 'faculty'` is created (works from UI, API, tinker, or seeders). Registered in `AppServiceProvider::boot()`.
- **Files:** `app/Observers/UserObserver.php` (new), `app/Providers/AppServiceProvider.php`

---

## All-Time Bug Summary (Sessions 1 & 2)

| # | Bug | Fix |
|---|-----|-----|
| 1 | 302 redirect on CRUD forms | Add `Accept: application/json` header |
| 2 | Time format "07:30 am" rejected | Strip AM/PM before sending |
| 3 | Subject not in generated schedule | Assign subject to section + faculty |
| 4 | FOLA never scheduled | AI fallback: section preferred days when availability empty |
| 5 | Python crash (tuple vs int) | `(f["id"])` → `(f["id"],)` |
| 6 | Duplicate faculty schedules | Auto-archive old published on publish |
| 7 | Wrong section ID on generate | Sync dropdown state with useEffect |
| 8 | Controller file corrupted | Restored correct class name |
| 9 | HTML welcome page instead of JSON | Remove broken `redirectGuestsTo`, send Accept header |
| 10 | Admin middleware fatal error | Restore class declaration + register alias |
| 11 | 500 on generate — missing `$fillable` | Add `$fillable` to ScheduleSession |
| 12 | 405 on session URL | Not a bug (PUT-only route opened in browser) |
| 13 | Session edit false conflict | Extend availability to 19:00 + `isNotEmpty()` + normalize time strings |
| 14 | New user missing in Faculty Management | Add `UserObserver` to auto-create Faculty |
