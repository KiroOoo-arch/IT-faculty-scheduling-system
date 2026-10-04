# Explainer — System Flow

**Figure:** [`screenshots/16-system-flow.png`](screenshots/16-system-flow.png) — 1900 × 3264 px, 514 KB
**Editable source:** `.tmp-run/diagram/system-flow-v2.html` (not committed — a scratch file)
**Companion documents:** [`Program-Flow.md`](Program-Flow.md), [`API.md`](API.md), [`Explainer-Architecture.md`](Explainer-Architecture.md)

*This file explains the system flow figure so you can present it and defend it. Steps, status codes, and every failure branch were traced through the code and exercised against the running stack.*

---

## 1. What this figure is

A picture of the system's **runtime behaviour** — the ordered sequence of interactions that happens when the admin triggers something, the decisions taken at each step, the data that moves, and the exact branch every failure takes.

Where the architecture figure shows *parts*, this one shows **movement**. Every step is numbered in execution order and tagged with the component that performs it, so you can trace a request from the browser to the database and back.

![System Flow](screenshots/16-system-flow.png)

---

## 2. The question it answers

> **"What happens, in what order, when someone uses this system — and what happens when it fails?"**

**It answers:**

- The sequence of steps for the system's core operation (generating a schedule)
- Which component performs each step
- What data moves at each hand-off
- Every way the flow can end early, and what the system returns
- How authentication works end to end
- How a draft becomes a published, printed schedule
- The schedule's state transitions

**It does not answer:**

- What the system is made of, statically (that is the **System Architecture** figure)
- Which file or function implements a step (that is the **Program Flow** figure)
- What the human sees on screen (that is the **User Flow** figure)

---

## 3. How to read it — section by section

### Section 1 — Primary Flow: Generating a Draft Schedule (17 steps)

This is the heart of the figure. The steps are colour-coded by actor, which lets you show the hand-offs at a glance:

| Actor | Steps | What it does |
|---|---|---|
| **Browser** (blue) | 1, 2, 17 | Starts the request; renders the result |
| **Laravel** (purple) | 3, 4, 5, 6, 15, 16 | Guards, validates, archives, calls the engine, persists |
| **PostgreSQL** (green) | 7–12 | Supplies six separate read sets to the engine |
| **FastAPI** (amber) | 13, 14 | Builds the model, solves it, returns the result |

The shape to narrate: **Laravel hands off, the engine reads for itself, and the result comes back to Laravel.** Notice that steps 7–12 are labelled *PostgreSQL* rather than *FastAPI* — this is deliberate, because the engine is performing those reads itself rather than receiving the data from Laravel. That distinction is the figure's most important structural claim.

Step 4 is the pre-flight integrity gate, and step 6 is where the 30-second HTTP timeout lives.

### Section 2 — Decision Points and Failure Branches (17 rows)

The most defensible part of the figure. Each row names a condition, the component that detects it, the response code, and the side effect.

The rows worth knowing by heart:

| Situation | Who detects it | Result |
|---|---|---|
| Wrong email or password | Laravel | **401** — an authentication failure, **not** a 422 |
| Valid credentials, non-admin role | Laravel | **403**, no token issued |
| Missing or expired token | `auth:sanctum` | **401** → client clears session → `/login` |
| Subject doesn't match the section | Laravel gate | **422** — engine never called, no draft, no log |
| Engine not running | Laravel HTTP client | **502** + a failure log written |
| Engine places nothing (INFEASIBLE) | Solver | **422** + a failure log **is still written** |
| Engine places some (PARTIAL) | Solver | **200** — a draft **is** saved, the rest reported |
| Engine places all but hits 15 s (FEASIBLE) | Solver | **200** — treated exactly like OPTIMAL |

If you only present one panel from this figure, present this one. It shows that failures were designed for rather than discovered.

### Section 3 — Authentication Flow

Login, token issuance, and the 401 path. Three points to make:

1. **Login is the only public route.** Everything else sits behind `auth:sanctum`.
2. **A failed login is a 401, not a 422.** A wrong password and an unknown email both answer **401**;
   a valid non-admin account answers **403**. A 422 from this route means only a malformed body.
   Getting this right is a good sign you actually tested the system.
3. **A 401 anywhere is handled globally.** A single interceptor clears the stored session and redirects to the login page — so an expired token never leaves a page silently showing empty data.

### Section 4 — Review, Edit, Approve, Publish, Print

What happens to a draft between generation and paper. The manual-edit subsection matters most, because it describes **four sequential conflict checks** and notes that the change is merged onto the session's existing values and the *whole resulting state* is re-validated — not just the fields that were sent. That is why a partial edit cannot smuggle in an invalid combination.

The print subsection states plainly that PDF is produced by the **browser's own print dialog** — there is no PDF library and no external service.

### Section 5 — Schedule Lifecycle

State chips with the real triggering events. Two things here are more accurate than a typical student diagram:

- **`ARCHIVED` is not the step after `PUBLISHED`.** Archiving happens two ways: regenerating a section archives its old drafts, and publishing a *new* schedule archives the previously published one for that section. A schedule does not archive itself.
- The figure explicitly records that **`rejected` is a dead end**. More on that in §7.

### Section 6 — Faculty Availability Flow and Concurrency

How availability is authored, and what happens when two things run at once. Key facts:

- Saving availability **replaces** the whole set — old rows are deleted, new ones inserted. It is not a merge.
- A row only means anything when **both** start and end times are present; a row with a missing time is ignored by the engine, so an empty window silently imposes no restriction.
- A faculty member with **no** declared availability at all falls back to the section's preferred days; one **with** declared windows is restricted to exactly those.
- Generation is **synchronous**. There is no queue, no worker, and no retry. The admin waits.
- A run only affects other sections once it is **approved or published** — drafts never influence anyone else.

### Timing strip

**15 s** solver budget inside a **30 s** HTTP timeout (deliberately double, so a slow solve is not cut off mid-search), **one writer**, and **zero retries**.

---

## 4. Where it goes

| Destination | How to use it |
|---|---|
| [`API.md`](API.md) | The endpoint-level reference. This figure is the behaviour those endpoints produce. |
| [`Program-Flow.md`](Program-Flow.md) | The prose companion — the same behaviour described file by file. Reference this figure as the visual summary. |
| Manuscript | The chapter on **system operation / methodology**. This is the figure that shows the pipeline actually working. |
| [`System_Defense_Guide.md`](System_Defense_Guide.md) | Present it **second**, immediately after the architecture figure. Architecture establishes the parts; this shows them moving. |
| Hearing order | **Second.** Walk steps 1–17 along the top, then drop to the failure panel and pick two or three branches to highlight. |

> **Superseded figure:** `screenshots/12-system-flow.png` (1860 × 1829) is an earlier rendering of the same view in a different style. This figure is the print-quality one. `12` is not cited by any markdown document, so there is no broken reference either way — but avoid using both in the same document.

---

## 5. Sixty-second spoken script

> "This is what happens when the admin generates a schedule.
>
> It starts at the browser — step 1 — where the admin picks a section and clicks generate. The request goes to Laravel, which first checks the token, then the role. Step 4 is a data-integrity gate: every subject assigned to that section must match the section's year level and semester. If not, Laravel answers 422 and **never calls the AI engine at all** — so bad data can't waste a solve.
>
> Step 5 archives the section's previous drafts. Step 6 Laravel calls the engine. Notice steps 7 to 12 — those reads are the engine reading PostgreSQL **itself**, server-to-server. Then it solves, and returns the result to Laravel, which saves the draft — Laravel is the only writer in the system.
>
> The middle panel is the one I'd draw your attention to. Every failure path is enumerated with its status code and its side effect. An unreachable engine is a 502 with a failure log. A solver that can't place everything is a **partial success** — we save the draft and report the sessions it couldn't place, rather than throwing the whole run away.
>
> The bottom panels cover authentication, the review-and-publish path, the schedule lifecycle, and how faculty availability is authored and enforced."

---

## 6. Likely panelist questions

**"What happens if the AI engine crashes mid-request?"**
Laravel catches the connection failure, writes a failure row to the generation log with the connection error, and returns HTTP 502. No draft is created, so the admin never sees a partial schedule.

**"What if the solver can't find a complete schedule?"**
It never fails silently. It maximises the number of sessions it can place, returns `PARTIAL`, and supplies a **plain-language reason for every session it could not place** — for example, "no room of type 'computer_lab' exists for this laboratory session." That is saved as a draft and shown to the admin, who fixes the data and regenerates.

**"Why is the solver limit 15 seconds but the HTTP timeout 30?"**
Deliberate headroom. The solver stops itself at 15 seconds and returns the best answer it found. The 30-second HTTP timeout means the outer request will not time out before the inner solve has had a chance to finish and report.

**"How do you stop two people scheduling the same room?"**
Three layers. At generation time the solver treats other sections' approved and published sessions as hard constraints. A manual edit is re-checked by the conflict validator. And publishing runs a cross-section conflict gate that refuses with 422 if the new schedule clashes with another section's live schedule.

**"Is a draft visible to faculty?"**
No. Only `published` schedules are printed and distributed. Drafts and approved schedules are internal.

**"What happens if the engine takes too long?"**
Laravel's 30-second client timeout fires, which is treated exactly like an unreachable engine: a failure log is written and a 502 is returned.

**"Can a schedule be edited after publishing?"**
Not directly. A published schedule must be **unpublished** first, which returns it to draft and clears the approval stamp; then it can be edited, approved, and published again. That two-step is intentional — it prevents silent changes to a schedule that has already been distributed.

---

## 7. Accuracy notes and honest caveats

1. **`rejected` is a dead-end status.** `reject` sets a draft's status to `rejected`, but the delete rule accepts only `draft` and `archived`, and approve/publish require their own prior states. So a rejected schedule **cannot be approved, published, or deleted through the API** — it can only be cleared by editing the database. This is recorded on the figure rather than hidden. If asked, say it is a known gap and that the fix is to accept `rejected` in the delete path.

2. **The pre-flight gate writes no generation log.** Every engine-related failure writes a failure row, but a generation blocked by the subject/section mismatch writes **nothing**. That means a blocked generation is visible in the HTTP response but does **not** appear in Reports → Generation Logs. This asymmetry is called out on the figure.

3. **Login failures are 401 or 403, not 422.** Expect a panelist to test this assumption. It is
   correct: `AuthController::login` answers **401** for a wrong password or an unknown email, and
   **403** for a valid non-admin account. Only a malformed body is still **422**.

4. **The engine's direct database read is a genuine dependency.** It appears in this figure as steps 7–12 and in the architecture figure as boundary B3. Be ready to defend it, or to concede it if the panel's requirement forbids any direct database access by the AI service.

5. **Generation is one section per request, run sequentially.** Generating for many sections means many sequential calls, each with its own 30-second ceiling. There is no batch endpoint and no queue.

6. **`FEASIBLE` is a success, not a failure.** This looks counter-intuitive on a status list, so state it explicitly: the solver hit its 15-second budget but placed every session. It is accepted and stored identically to `OPTIMAL`.

---

## 8. Regenerating the figure

```bash
# 1. Edit .tmp-run/diagram/system-flow-v2.html
# 2. Open it in a browser and read document.body.scrollWidth / scrollHeight
# 3. Screenshot at exactly that size:
"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
  --headless=new --disable-gpu --no-first-run --hide-scrollbars \
  --user-data-dir="$(mktemp -d)" \
  --window-size=1900,3264 \
  --screenshot="documentation/screenshots/16-system-flow.png" \
  "file:///<repo>/.tmp-run/diagram/system-flow-v2.html"
```

`--window-size` must equal the measured page size or the capture clips. The current figure is 1900 × 3264.

---

## 9. How this view relates to the other three

| View | Figure | Answers |
|---|---|---|
| Architecture | [`15-system-architecture.png`](screenshots/15-system-architecture.png) · [explainer](Explainer-Architecture.md) | What is the system made of? |
| **System Flow** | [`16-system-flow.png`](screenshots/16-system-flow.png) ← *you are here* | What happens between components when it runs? |
| Program Flow | [`17-program-flow.png`](screenshots/17-program-flow.png) · [explainer](Explainer-Program-Flow.md) | What code runs, and where is each rule enforced? |
| User Flow | [`14-user-flow-modern.png`](screenshots/14-user-flow-modern.png) · [explainer](Explainer-User-Flow.md) | What does the admin do, step by step? |

Quick test for which view you are looking at: if an arrow could be labelled with a **URL or a port**, it is a flow view. If a box could be labelled with a **filename or function name**, it is program flow. If boxes are **components and tables**, it is architecture.
