# DEMO RUNBOOK — Defense Day
**Goal:** a smooth, scripted 8–10 minute live demo with zero surprises. Read this the morning of. Every step lists the **expected result** — if reality differs, use the fallbacks at the bottom.

---

## ⏰ T-minus checklist (do BEFORE the panel arrives)

### 1. Start the three services (in this order)
```bash
# Terminal 1 — Laravel API (port 8000)
cd backend && php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 — AI engine (port 8001)
cd ai-engine && .venv\Scripts\python.exe -m uvicorn api.app:app --host 127.0.0.1 --port 8001

# Terminal 3 — Frontend (port 5173)
cd frontend && npm run dev
```
Or detached (PowerShell, survives terminal closure):
```powershell
$P='C:\Users\Emily PC\IT-faculty-scheduling-system'
Start-Process 'C:\php85\php.exe' 'artisan','serve','--host=127.0.0.1','--port=8000' -WorkingDirectory "$P\backend" -WindowStyle Hidden
Start-Process "$P\ai-engine\.venv\Scripts\python.exe" '-m','uvicorn','api.app:app','--host','127.0.0.1','--port','8001' -WorkingDirectory "$P\ai-engine" -WindowStyle Hidden
Start-Process 'npm.cmd' 'run','dev' -WorkingDirectory "$P\frontend" -WindowStyle Hidden
```

### 2. Verify all three (30 seconds)
```powershell
netstat -ano | findstr ":8000 :8001 :5173" | findstr LISTENING
```
Then in the browser: open `http://localhost:5173` → the login page must render.

### 3. The warm-up generation (⚠️ CRITICAL)
Log in and generate **BSIT 1A once before the panel arrives**. Why: proves all three services + DB work *right now*, and warms every cache. If it says **OPTIMAL — 7 sessions**, delete that draft (🗑 button) so the demo starts clean — or keep it; regenerating during the demo also works (old drafts auto-archive, which is itself a feature to show).

### 4. Clean demo state (optional but polished)
- Delete leftover draft schedules except one good BSIT 1A draft
- Confirm the dashboard shows **7 faculty, 11 subjects, 5 rooms, 9 sections** (BSIT 1A–1D, 2A, 2B, 3A–3C)
- Close extra tabs. Set browser zoom ~110–125% for the projector. Enter fullscreen (F11).

### 5. Credentials
- **Login:** `admin@example.com` / `password`
- Back these up in a phone note. If the password was changed, fix it the night before, not the morning of.

---

## 🎬 The Scripted Demo (8–10 min)

**Narrator talks throughout — the operator just clicks.** Expected result noted after each step.

### Step 1 — Login (0:30)
Log in with the credentials above.
> **Narrate:** "Admin-only by design — the Department Head is the sole system user. Faculty are records, not accounts — a deliberate security-scope decision."
**Expected:** Admin Dashboard renders with Generate Schedule, Review & Approval, and Faculty tables.

### Step 2 — Show the data (1:00)
Click through 🎓 Faculty → show a couple of records (e.g. Lorena Murillo with TPC 311 / Major 1, and Queenie Edicto with GE 1 / GE 2) and their availability days **plus time windows**. Back to dashboard.
> **Narrate:** "Everything the solver needs lives here: qualifications, availability days, room types, capacities, and each section's preferred window."
**Expected:** faculty list shows names, types, max loads, subject links.

### Step 3 — Generate a schedule — THE MAIN EVENT (1:30)
On the dashboard, BSIT 1A selected → click **Generate Schedule for BSIT 1A**.
> **Narrate:** "One click. Laravel validates the data, calls the AI engine, which models this as a constraint problem — eight hard constraint categories — and Google OR-Tools CP-SAT solves it in about a second. Note the status."
**Expected:** ✅ **"Status: OPTIMAL — 7 sessions created."** and a draft schedule table appears with the section's subjects (e.g. TPC 311 lecture + lab, Major 1 lecture + lab, GE subjects) — each with day, time, room, faculty.

### Step 4 — Prove the constraints are real (1:30)
Point at the generated rows:
- A **laboratory** session → **Room 2** (a computer_lab) — *"room type matching"*
- No faculty appears twice at overlapping times — *"no double-booking"*
- Everything inside the section's preferred window — *"student-centered: the section's own preferred days and times are enforced by the solver"*
> **Bonus move:** click **Edit** on a session and try to move it onto a conflicting slot → **422 conflict message appears, edit rejected.** "That's protection layer three — every manual edit is re-checked server-side, against other sections' drafts in the same term too." (Cancel the edit.)
> **Bonus move 2 — the published-data guard:** with the schedule still `published`, open **Rooms** and try to delete a room the published timetable uses → a **409** confirmation appears naming the affected schedule and how many sessions are at risk; cancel it. "The AI defends the generated schedule, and this guard defends the *published* one — master-data edits can't silently break a live timetable."

### Step 5 — The validation gate (1:00, optional but powerful)
Select **BSIT 3A** in the dropdown. Then say:
> "And before the AI is ever called, Laravel validates the data itself. If we assigned a Year 1 subject to this Year 3 section, generation is blocked — watch the error name the offending subject."
(Only demonstrate the 422 if you deliberately keep a mismatched assignment on a *spare* section — don't break BSIT 3A for the demo.)

### Step 6 — Approve → Publish → Print (2:00)
Click **Approve** on the new draft → status becomes `approved`. Click **Publish** → status becomes `published`. Click **🖨 Print / PDF**.
> **Narrate:** "Publish runs the fourth protection layer — a cross-section conflict gate that blocks double-booking against other published schedules. Then the published schedule opens as a print-friendly weekly grid — save as PDF or print. This replaces any faculty portal: hard-copy distribution is how the department actually works."
**Expected:** print view renders a clean weekly grid (A4 landscape). Close the tab, return to the dashboard.

### Step 7 — Reports (1:00)
Click 📈 Reports → click through tabs: **Faculty Load** (hours per teacher), **Room Usage**, **Schedule Status**.
> **Narrate:** "Five report views give the department oversight — workload, utilization, conflicts, status, and section summaries."

### Step 8 — Close (0:30)
Return to the dashboard.
> **Close:** "That's the full lifecycle — data in, mathematically guaranteed schedule out, human-approved, published, and printed. The AI proposes; five layers defend; the Admin decides. Thank you — questions welcome."

---

## 🚨 Fallback Plans

### "Status" shows ❌ / error on generate
1. **Read the error aloud and use it:** "Notice it explains *why* — that's a feature." If it's the validation gate message, it's actually a demonstration of SOP3.
2. If solver-related: check AI engine is up (`netstat`), restart it, regenerate.
3. **Ultimate fallback:** switch to the screenshot pack (below) and narrate: "Here's the live generation we ran this morning."

### AI engine down (nothing on :8001)
```powershell
cd ai-engine
.venv\Scripts\python.exe -m uvicorn api.app:app --host 127.0.0.1 --port 8001
```
Verify: `curl http://127.0.0.1:8001/health` → `{"status":"ok","database":"connected"}`

### Frontend down (nothing on :5173)
```powershell
cd frontend && npm run dev
```

### Backend down (nothing on :8000)
```powershell
cd backend && php artisan serve --host=127.0.0.1 --port=8000
```
If "port in use": `netstat -ano | findstr :8000` → `taskkill /PID <pid> /F` → serve again.

### Projector/VM fails entirely
**Screenshot pack:** take these screenshots the night before, into `defense/screenshots/`:
1. Login page · 2. Dashboard with data · 3. OPTIMAL success banner · 4. Generated schedule table · 5. Edit-conflict 422 message · 6. Approved status · 7. Published + Print view weekly grid · 8. Reports (Faculty Load)
Narrate the same script over the screenshots. A fluent narrated slideshow beats a frozen live demo.

### Panel asks to see something unplanned
- "Show me a section generating INFEASIBLE" → say: *"INFEASIBLE needs broken data; we can break it live: assign a Year 1 subject to BSIT 3A and generate — the gate blocks it with the subject named."* (Do it only if you're comfortable reverting.)
- "Show the code" → `ai-engine/solver/scheduler.py` (constraints, ~line 130–210) and `backend/routes/api.php` (admin-only routing). Have both files open in the editor beforehand, scrolled.

---

## The Night Before

- [ ] `git status` clean or intentionally uncommitted (know which!) — the solver and published-reference guard are committed on `ai-engine`; the `defense/` packet is intentionally untracked. If the demo machine resets, copy the `defense/` folder.
- [ ] Screenshot pack captured
- [ ] All three services verified + warm-up generation done
- [ ] This runbook printed or on a phone
- [ ] Faculties/sections in demo-clean state
- [ ] Browser zoom + fullscreen tested on the actual projector
