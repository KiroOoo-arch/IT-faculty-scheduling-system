# Documentation tooling

Small, dependency-light tools that keep the documents honest. Everything here runs
from the repository root with the system Python (3.9+); nothing here touches the
application, the database, or the test suites.

| Tool | What it does |
|---|---|
| [`build_docx.py`](build_docx.py) | Builds each Word document from its Markdown source of record. Needs `python-docx`. |
| [`render_mmd.py`](render_mmd.py) | Renders a committed Mermaid source (`.mmd`) to the PNG the documents cite, through the Mermaid CLI. Needs Node.js + a Chromium browser. |
| [`check_doc_drift.py`](check_doc_drift.py) | Reports documentation drift: stale counts, obsolete behavior claims, artifact and link problems. |
| [`mermaid-render-config.json`](mermaid-render-config.json) | The Mermaid config used for every render (message wrapping, margins). |
| [`test_check_doc_drift.py`](test_check_doc_drift.py) | Fixture-based self-tests for the checker — the single canonical suite. |

## Word documents

Every Word file in `documentation/` is generated. The Markdown is the source of
record; edit that, never the `.docx`.

| Output | Source | Command |
|---|---|---|
| `documentation/System_Defense_Guide.docx` | `documentation/System_Defense_Guide.md` | `python documentation/tools/build_docx.py System_Defense_Guide` |
| `documentation/System_Complete_Guide.docx` | `documentation/System_Complete_Guide.md` | `python documentation/tools/build_docx.py System_Complete_Guide` |
| `documentation/Manuscript_Update_Brief.docx` | `documentation/Manuscript_Update_Brief.md` | `python documentation/tools/build_docx.py Manuscript_Update_Brief` |

```bash
# one-time, in a throwaway virtual environment (the tool itself adds no project dependency)
python -m venv .tmp-run/docx-venv
.tmp-run/docx-venv/Scripts/python.exe -m pip install python-docx   # POSIX: .tmp-run/docx-venv/bin/python

python documentation/tools/build_docx.py --list       # sources, outputs, commands
python documentation/tools/build_docx.py --all        # rebuild every registered document
python documentation/tools/build_docx.py System_Defense_Guide --out .tmp-run/check.docx   # test build
```

`System_Defense_Guide.docx` carries a generated-from line naming its source, and a
numbered contents list; the other two keep the layout they were committed with.
The registry in `build_docx.py` is what `check_doc_drift.py` reads, so a document
can never lose its source silently: the checker takes its Word pairs from
`build_docx.ARTIFACTS` and its figures from `render_mmd.FIGURES` instead of keeping
private copies, and it excludes scratch directories (`.tmp-run/`, `.tmp-docwork/`)
from the document scan.

### One builder, not four

`documentation/tools/build_docx.py` is the **single supported builder** for every Word
file: it registers all three artifacts above and is the registry the drift checker
reads. The older per-document scripts that remain in `documentation/`
(`build_defense_guide.py`, `build_complete_guide.py`, `build_manuscript_brief.py`) are
kept only as history — they are superseded, nothing references them, and new work must
not extend them. To rebuild a Word file use `python documentation/tools/build_docx.py
<name>` (or `--all`); when a source or output changes, update `build_docx.ARTIFACTS`
and nothing else. Likewise `documentation/tools/render_mmd.py` with
`documentation/tools/mermaid-render-config.json` is the supported renderer, and
`render_mmd.FIGURES` is the figure registry the checker reads.

### Word files with no source (documented exception)

| File | Status |
|---|---|
| `documentation/System-Defense-Guide.docx` | **Legacy, no source exists.** It is a Word-only variant added in commit `2e4e41e` ("System Flow and Defense Guide", 246 lines: architecture, auth flow, data-setup flow, generation flow, post-generation flow, approval workflow, session editing, reports, schema, constraints, Q&A). No Markdown or other source was ever committed for it, and none exists in the branch history, so it cannot be regenerated without authoring a new source. It is *not* a duplicate of `System_Defense_Guide.docx`: the section lists and the Q&A differ, and it still describes the pre-fix generation order. Decide its fate explicitly — either write a Markdown source for it and register it under a name in `build_docx.ARTIFACTS`, or retire the file. Until then `check_doc_drift.py` reports it as an undocumented artifact if this row disappears. |

## Figures

Every rendered figure has a committed editable source next to it, and the source is
always newer-or-equal than the render.

| Figure | Editable source | Command |
|---|---|---|
| `documentation/screenshots/16-system-flow.png` | `documentation/screenshots/system-flow.mmd` | `python documentation/tools/render_mmd.py documentation/screenshots/system-flow.mmd documentation/screenshots/16-system-flow.png` |
| `documentation/screenshots/11-program-flow.png` | `documentation/screenshots/program-flow.mmd` | `python documentation/tools/render_mmd.py documentation/screenshots/program-flow.mmd documentation/screenshots/11-program-flow.png` |

```bash
python documentation/tools/render_mmd.py --check                      # render every registered figure to a temp dir
python documentation/tools/render_mmd.py --browser /path/to/chrome …  # pick the browser explicitly
```

`render_mmd.py` runs `npx -y @mermaid-js/mermaid-cli@12.0.0` and points Puppeteer
at a Chromium browser that is already installed (`MERMAID_BROWSER`, `--browser`, or
auto-detected), so no dependency is added to the project. First run needs network
access for `npx` to fetch the CLI. The committed PNGs were rendered at
`--scale 2` on a white background, which is the default. Code fixtures
(`erd.mmd`, `user-flow-admin.mmd`) are kept for reference only; the figures that
cite them predate this tooling and are not re-rendered by it. The Mermaid Live
editor (`https://mermaid.live`) remains a fallback: paste the `.mmd`, export a PNG
at the same scale.

## Drift checker

```bash
python documentation/tools/check_doc_drift.py              # exit 0 = clean, 1 = actionable drift
python documentation/tools/check_doc_drift.py --verbose    # also list suppressed matches
python documentation/tools/check_doc_drift.py --json
python documentation/tools/check_doc_drift.py --list-rules # every rule, the registries and the historical allowlist
python -m unittest discover -s documentation/tools -p "test_*.py"   # the checker's own tests
```

What it holds the documents to:

* **measured counts** — backend test and assertion claims are compared with PHPUnit's
  own `--list-tests` listing, and engine counts with the engine suite's test methods.
  No count is hard-coded anywhere: this suite declares its cases with `#[Test]`
  attributes and `@test` annotations rather than `test*` method names, so a static
  count cannot work. When the listing is unavailable the check reports itself as
  SKIPPED instead of guessing, and `--run-suites` is the only way to verify assertion
  counts against a real run.
* **behavior** — cross-section scope (draft + approved + published in the same
  academic year and semester), drafts counting as live bookings, the archive
  happening only after a usable plan is accepted, five protection layers in every
  document, the removed faculty portal, the implemented lunch break, and eight
  constraint categories.
* **artifacts** — every registered Word file has an existing source and output and
  carries the current figures; every `documentation/*.docx` is either registered or
  listed as a legacy exception here; every rendered figure has a committed `.mmd`
  source that is not newer than its PNG; every local markdown link resolves.

Two documented ways to mark a legitimate exception:

1. **Inline annotation** — `<!-- doc-drift:allow: reason -->` on the line, or
   `<!-- doc-drift:allow-next: reason -->` for that line and the paragraph after it.
   Used where a document *quotes* an older claim to explain it.
2. **File-level exemption** — a dated document listed in `HISTORICAL_FILES` in
   `check_doc_drift.py` (currently `Bug-Fix-Log.md`, `Progress-report.md`,
   `latestdoc.md`, `Manuscript_Update_Brief.md`/`.docx` and
   `System-Defense-Guide.docx`), with the reason recorded there and printed by
   `--list-rules`.

Sentences that mark themselves as historical ("superseded", "recorded", "at the
time", "outdated", "as it then stood", …) are suppressed too, and `--verbose`
prints every suppression with its reason, so nothing is dropped silently.

Findings carry a **severity** and a **fix**. Errors are drift a reader would be
misled by, and make the checker exit `1`; warnings are hygiene (a figure no current
document cites, a source newer than its render) and are printed without failing the
run. Exit `2` means the checker could not establish ground truth at all.

`--run-suites` additionally executes `php artisan test` and the engine suite. That
touches a database, so it is deliberately *not* part of day-to-day validation: use it
only against a disposable environment.

The checker never edits a document. When it reports drift, the fix is an edit to the
Markdown source followed by a rebuild of the affected Word file or figure.
