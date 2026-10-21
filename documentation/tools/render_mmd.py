#!/usr/bin/env python3
"""Render a committed Mermaid source file to the PNG that the documents cite.

The repository keeps the editable source of every Mermaid figure next to the
rendered image (``documentation/screenshots/*.mmd``), and this script is the
single documented way to turn one into the other. It runs the Mermaid CLI
through ``npx`` and points Puppeteer at a Chromium browser that is already
installed on the machine, so nothing is added to the project's dependencies.

Run from the repository root::

    python documentation/tools/render_mmd.py documentation/screenshots/system-flow.mmd \
        documentation/screenshots/16-system-flow.png

    python documentation/tools/render_mmd.py --check          # render every figure to a temp dir

Options:
    --scale N        device pixel ratio (default 2, i.e. retina-grade output)
    --background C   canvas colour (default white, as the documents expect)
    --browser PATH   Chromium executable; otherwise auto-detected, or taken
                     from the MERMAID_BROWSER environment variable
    --keep           keep the generated Puppeteer config for inspection

If the browser or ``npx`` cannot be found the script stops with an explanation
and renders nothing, rather than writing a broken or empty PNG.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
TOOLS_DIR = Path(__file__).resolve().parent
MERMAID_CLI = "@mermaid-js/mermaid-cli@12.0.0"
MERMAID_CONFIG = TOOLS_DIR / "mermaid-render-config.json"

#: Figures the documents cite, mapped to their committed editable source.
FIGURES: dict[str, str] = {
    "documentation/screenshots/16-system-flow.png": "documentation/screenshots/system-flow.mmd",
    "documentation/screenshots/11-program-flow.png": "documentation/screenshots/program-flow.mmd",
}

BROWSER_CANDIDATES = (
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
    "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
    "/usr/bin/google-chrome",
    "/usr/bin/chromium",
    "/usr/bin/chromium-browser",
)
BROWSER_NAMES = ("google-chrome", "chrome", "chromium", "chromium-browser", "msedge")


def find_browser(explicit: str | None) -> Path:
    if explicit:
        path = Path(explicit)
        if not path.is_file():
            raise SystemExit(f"browser not found: {explicit}")
        return path
    env = os.environ.get("MERMAID_BROWSER")
    if env:
        path = Path(env)
        if path.is_file():
            return path
    for candidate in BROWSER_CANDIDATES:
        if Path(candidate).is_file():
            return Path(candidate)
    for name in BROWSER_NAMES:
        found = shutil.which(name)
        if found:
            return Path(found)
    raise SystemExit(
        "No Chromium browser found. Pass --browser /path/to/chrome or set MERMAID_BROWSER."
    )


def write_puppeteer_config(directory: Path, browser: Path) -> Path:
    config = {
        "executablePath": str(browser).replace("\\", "/"),
        "args": ["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
    }
    path = directory / "puppeteer-config.json"
    path.write_text(json.dumps(config, indent=2), encoding="utf-8")
    return path


def render(
    source: Path,
    output: Path,
    *,
    browser: Path,
    scale: float,
    background: str,
    workdir: Path,
) -> int:
    npx = shutil.which("npx")
    if npx is None:
        raise SystemExit("npx is required (Node.js 18+); install Node.js or use the Mermaid Live editor.")
    puppeteer_config = write_puppeteer_config(workdir, browser)
    command = [
        npx,
        "-y",
        MERMAID_CLI,
        "-i",
        str(source),
        "-o",
        str(output),
        "-b",
        background,
        "-s",
        str(scale),
        "-c",
        str(MERMAID_CONFIG),
        "-p",
        str(puppeteer_config),
    ]
    env = dict(os.environ, PUPPETEER_SKIP_DOWNLOAD="true")
    print("rendering", source.relative_to(REPO_ROOT), "->", output.relative_to(REPO_ROOT))
    completed = subprocess.run(command, cwd=REPO_ROOT, env=env)
    return completed.returncode


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Render a committed .mmd source to the PNG the documents cite.",
        epilog="Run from the repository root. Only the named output PNG is written.",
    )
    parser.add_argument("source", nargs="?", help="path to the .mmd file")
    parser.add_argument("output", nargs="?", help="path of the PNG to write")
    parser.add_argument("--check", action="store_true", help="render every registered figure to a temp dir")
    parser.add_argument("--scale", type=float, default=2, help="device pixel ratio (default 2)")
    parser.add_argument("--background", default="white", help="canvas colour (default white)")
    parser.add_argument("--browser", help="Chromium executable to use")
    parser.add_argument("--keep", action="store_true", help="keep the generated Puppeteer config")
    args = parser.parse_args(argv)

    browser = find_browser(args.browser)

    if args.check:
        with tempfile.TemporaryDirectory(prefix="mmd-check-") as tmp:
            failures = 0
            for png, mmd in FIGURES.items():
                source = REPO_ROOT / mmd
                if not source.is_file():
                    print(f"missing source: {mmd}", file=sys.stderr)
                    failures += 1
                    continue
                code = render(
                    source,
                    Path(tmp) / Path(png).name,
                    browser=browser,
                    scale=args.scale,
                    background=args.background,
                    workdir=Path(tmp),
                )
                failures += code != 0
            return 1 if failures else 0

    if not args.source or not args.output:
        parser.error("source and output are required (or use --check)")

    source = (REPO_ROOT / args.source).resolve()
    output = (REPO_ROOT / args.output).resolve()
    if not source.is_file():
        raise SystemExit(f"missing Mermaid source: {args.source}")
    if not str(output).startswith(str(REPO_ROOT)):
        raise SystemExit("output must be inside the repository")
    output.parent.mkdir(parents=True, exist_ok=True)

    if args.keep:
        config = write_puppeteer_config(TOOLS_DIR, browser)
        print("puppeteer config:", config.relative_to(REPO_ROOT))

    with tempfile.TemporaryDirectory(prefix="mmd-render-") as tmp:
        code = render(
            source,
            output,
            browser=browser,
            scale=args.scale,
            background=args.background,
            workdir=Path(tmp),
        )
    if code == 0:
        print(f"wrote {output.relative_to(REPO_ROOT)} ({output.stat().st_size:,} bytes)")
    return code


if __name__ == "__main__":
    raise SystemExit(main())
