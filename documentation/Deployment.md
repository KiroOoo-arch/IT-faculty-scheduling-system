# Deployment Guide & Remaining Work

## AI-Assisted Student-Centered Constraint-Based Faculty, Classroom, and Laboratory Scheduling System

*Status: **not yet deployable as-is.** The application is fully working locally and demo-ready; what
remains is configuration and packaging, not features. This document is the ordered list of what to do
first.*

Companion docs: `Implementation_Status.md` (what exists), `System-Architecture.md` (how it fits
together), `Data-Privacy-and-Security.md` (data handling).

---

## 0. Where we actually are

| Check | Result |
|---|---|
| App works end-to-end locally | ✅ Yes — Laravel :8000, React :5173, FastAPI solver :8001, PostgreSQL :5432 |
| Backend tests | ✅ 143 passed / 604 assertions |
| Engine tests | ✅ 118 passed |
| Frontend build | ✅ clean (`tsc -b && vite build`) |
| Frontend lint | ❌ exit 1 — 11 errors / 9 warnings (8 `set-state-in-effect`, 2 `no-explicit-any`, 1 `react-refresh`) |
| `Dockerfile` / `docker-compose.yml` | ❌ none exists |
| CI (`.github/workflows`) | ❌ none exists |
| `Procfile` / `render.yaml` / `vercel.json` | ❌ none exists |
| `ai-engine/.env.example` | ❌ missing (only the real, untracked `.env`) |
| Production config (env-driven URLs, real CORS) | ❌ hardcoded to `127.0.0.1` |
| Production credentials | ❌ seeded admin password is literally `password` |

**Three hard blockers before anything is publicly reachable:**

1. The seeded admin password (`password`) — a published login is an open door.
2. Hardcoded `127.0.0.1` URLs + localhost-only CORS — deployed as-is, the frontend cannot reach the API
   and the API cannot reach the solver.
3. No packaging — there is nothing to deploy *onto* yet.

---

## 1. First: make the app configurable (code/config changes only, ~1–2 hours)

Do this on a branch before touching any hosting. Every item is small and independently testable.

### 1.1 Frontend API base → build-time env var
- **Where:** `frontend/src/context/AuthContext.tsx:10`
- **Now:** `const API_BASE_URL = 'http://127.0.0.1:8000/api'`
- **Change to:** `import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000/api'`
- It is exported from that one module and imported by every page, so this is a single-line change.
- Add `VITE_API_BASE_URL=https://<domain>/api` to the frontend build environment.

### 1.2 Solver URL → backend env var
- **Where:** `backend/app/Http/Controllers/ScheduleController.php:60`
- **Now:** `"http://127.0.0.1:8001/generate-schedule/{$section->id}"`
- **Change to:** `env('AI_ENGINE_URL', 'http://127.0.0.1:8001') . "/generate-schedule/{$section->id}"`
- Add `AI_ENGINE_URL=http://engine:8001` to the backend `.env` (the Compose service name).
- Also add it to `backend/.env.example` — it is currently absent.

### 1.3 CORS → the real domain
- **Where:** `backend/config/cors.php:9-13`
- **Now:** `FRONTEND_URL` env (default `http://localhost:5173`), plus `localhost:5173` and `localhost:3000`.
- Set `FRONTEND_URL=https://<domain>`. Keep or drop the localhost entries depending on whether dev
  machines will talk to the deployed API.
- **Gotcha already hit once:** `127.0.0.1` is *not* in this list. Never serve the frontend from
  `127.0.0.1` or you get an opaque "Failed to fetch".

### 1.4 Secrets out of tracked files + new admin password
- `.env` files are gitignored (root `.gitignore:19/27/28/60`, `backend/.gitignore`), so nothing has
  leaked — but the *values* are dev values: `DB_PASSWORD=Panuan2006`, and the admin login
  `admin@example.com` / `password`.
- **Change the admin password before first deploy.** Either flip it through the Users page, or run a
  one-off `php artisan tinker` hash. Do not seed demo credentials into a public instance.
- Generate fresh `APP_KEY`, DB password, and (if used) any token secrets per environment.

### 1.5 Add `ai-engine/.env.example`
- The engine reads its own `.env` from the `ai-engine/` root (`api/app.py:20`) and pulls
  `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` via `os.getenv`.
- Ship an example with those five keys so a fresh clone is self-documenting.
- The engine has **no CORS middleware** and is never called by a browser — keep it that way and keep it
  off the public internet (see 3.3).

### 1.6 Clear the frontend lint debt
- `npm run lint` exits 1 with 11 errors / 9 warnings: 8 × `react-hooks/set-state-in-effect` (the
  `useEffect(() => { fetchX() }, [])` pattern, e.g. `frontend/src/pages/admin/UsersPage.tsx:34`),
  2 × `@typescript-eslint/no-explicit-any` (both in `ReportsPage.tsx`), and 1 ×
  `react-refresh/only-export-components` in `AuthContext.tsx` (it exports `API_BASE_URL` next to the
  provider — which is also why the API-base change in 1.1 touches that file).
- Pre-existing and no runtime impact, but a red lint makes CI meaningless once you add it. Fix first,
  then add the workflow.

### 1.7 Verify nothing regressed
```bash
cd backend && php artisan test                 # expect 143 passed
cd ai-engine && ./.venv/Scripts/python.exe -m unittest discover -s tests   # expect 118 OK
cd frontend && npm run build && npm run lint   # expect clean build, lint 0 problems
```

---

## 2. Second: package it (Dockerfiles + Compose)

Nothing exists yet — no `Dockerfile`, no `docker-compose.yml`, no CI. Build four artifacts:

| Service | Image / artifact | Notes |
|---|---|---|
| `db` | `postgres:17` | Named volume; Laravel migrations are the source of truth |
| `engine` | Python image + `ai-engine/requirements.txt` | OR-Tools CP-SAT is heavy — 2 GB RAM minimum |
| `api` | PHP 8.3+ (FPM or `artisan serve` behind Caddy) | `composer.json` requires PHP `^8.3` |
| `web` | Static `vite build` output | Served by Caddy as plain files |

Compose rules that matter here:

- Put `db` and `engine` on an internal-only network. Only `web` and `api` are exposed.
- Persist Postgres on a named volume — the solver reads live data every run and has no cache.
- Run the engine with `uvicorn`, and **give the container ≥2 GB** (OR-Tools + pandas + Postgres
  connections). The solver's own budget is 15 s per section (`ai-engine/solver/scheduler.py:378`).
- On `api` start: `php artisan migrate --force`, then `config:cache` / `route:cache`, and
  `APP_DEBUG=false`, `APP_ENV=production`.

---

## 3. Third: pick a host and deploy

### 3.1 Recommendation: a single small VPS running Docker Compose

**Ranking: VPS (Docker Compose) → Railway → Render (demo only) → Vercel (frontend only).**

Why the VPS wins for this project:

- **≥2 GB RAM** for the solver, PHP, and Postgres together. Hetzner from ~€5/mo, DigitalOcean from $4/mo.
- All four services live in one private network, so the Laravel → solver hop stays internal.
- No cold starts, no expiration, no private-networking restrictions.
- One `docker compose up -d` is the whole deploy; Caddy gives automatic TLS from a domain name.

Why the alternatives lose:

- **Render free tier** — 750 instance-hours/month, spins down after 15 minutes idle (~1 min wake),
  free Postgres **expires 30 days after creation** (deleted after a 14-day grace), the docs say
  "do not use for production", and **free web services cannot receive private network traffic** — which
  kills the Laravel → solver hop. Fine as a throwaway demo, not as the deployment.
- **Railway** — ~$5/mo Hobby, no real free tier, but multi-service with working private networking.
  Good middle option if you would rather not touch a server.
- **Fly.io** — cheapest raw compute, but CLI-first and unmanaged Postgres by default.
- **Vercel** — can host the React build and nothing else. Useful only for the frontend half.

### 3.2 Provision
1. Create the VPS (2 GB RAM / 1–2 vCPU, Ubuntu LTS).
2. Point an A record at it.
3. Install Docker + the Compose plugin.
4. Clone the repo, create the three `.env` files from the examples in step 1.
5. `docker compose up -d --build`.

### 3.3 Network shape
- Public: Caddy on 80/443 only (terminates TLS, serves the built frontend, proxies `/api` to PHP).
- Internal: Postgres 5432 and the solver 8001 — **published to no host port at all**.
- The solver is read-only against the DB and is never called from a browser, so it needs no CORS and no
  public listener.

---

## 4. Fourth: load the data

The app has no production seeder for real institutional data. Two options:

- **Fresh start:** `php artisan migrate --force`, create one admin via tinker, then enter the real
  sections / subjects / rooms / faculty through the UI. Cleanest for a real term.
- **Promote the working dataset:** `pg_dump` the local `scheduling_system` database and restore it
  into the container. Fastest for a defense demo — but remember to change the admin password
  immediately afterwards (step 1.4).

For a defense demo, promote the dataset **after** verifying the fresh instance migrates cleanly, so a
broken migration cannot surface during the presentation.

---

## 5. Fifth: verify the deployment (do not skip)

Run these against the deployed instance, not locally:

1. `https://<domain>/login` loads; login with the **new** admin password succeeds.
2. Dashboard → pick a real section → **Generate Schedule** → engine is reached and a draft appears
   (`POST /generate-schedule/{id}` must not 502).
3. Reports → Generation Logs renders the human-readable unscheduled reasons.
4. Approve → Publish, including the cross-section conflict gate rejecting a real clash.
5. Print both timetables and confirm the letterhead renders.
6. Confirm the solver port and Postgres port are **not** reachable from outside.
7. Confirm no `127.0.0.1` string remains in any production build output.

---

## 6. Later (not deploy blockers)

- **Signature-before-approval** — designed and agreed, not built: `Planned-Signature-Approval.md`.
  The open fork is whether a sub-Head tier's approval is a recommendation the Head then signs to
  publish, or whether signing itself is the approval.
- **Rename `/api/reports/conflicts`** — it returns generation logs, not conflicts. Left alone because
  renaming changes the public API.
- **Login rate limiting** — harden `POST /api/login` against brute force.
- **Role tiers** — `users.role` currently permits only `admin` and `faculty`; the `faculty` role is
  effectively dead (removed by
  `2026_09_08_000001_detach_faculty_users_and_add_name_to_faculties_table.php:38`), the whole API sits
  behind one `admin` group (`backend/routes/api.php:25`), and the frontend does no role gating. Any
  real multi-role rollout needs this designed first.
- **CI** — add the workflow *after* the lint debt is cleared, so green means something.

---

## 7. The short answer to "what do we do first?"

**Step 1.1–1.6 — make the app configurable and change the admin password.** No hosting decision, no
containers, no money required, and every item is verifiable locally with the existing test suites. Do
that first, re-run the three test commands, and only then buy a VPS. If you deploy before step 1, the
result is a public instance that cannot talk to its own API and whose login is `password`.
