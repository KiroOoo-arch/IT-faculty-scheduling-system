#!/usr/bin/env python3
"""Secret scanner for the IT faculty scheduling system.

Reports places where a credential, token, password or private key appears in the
repository, and exits non-zero so the same command can guard a commit. It uses
only the Python standard library and Git, adds no dependency, and never touches
the application, the database or the test suites.

    python documentation/tools/check_secrets.py                 # tracked + untracked files
    python documentation/tools/check_secrets.py --staged        # what a commit would add (pre-commit)
    python documentation/tools/check_secrets.py --history       # committed content, report only
    python documentation/tools/check_secrets.py --paths .tmp-run
    python documentation/tools/check_secrets.py --install-hook  # write .git/hooks/pre-commit
    python documentation/tools/check_secrets.py --list-rules

Exit status
-----------
``0``  nothing found.
``1``  at least one finding.
``2``  the scan could not be established (not a Git repository, Git missing).

Redaction
---------
A finding names the file, line, column and the rule that matched. The matched
text itself is **never** printed, logged or written anywhere — only the number of
characters that were withheld. That is what makes the output safe to paste into a
ticket. Do not add the value back.

Allowlisting
------------
A value that is obviously a placeholder (``<redacted>``, ``${PGPASSWORD}``,
``changeme``, ``example``, an ``env(...)``/``os.getenv(...)`` lookup, …) is not a
finding. Two deliberate escapes are available for a real exception:

* ``secret-scan:allow`` on the line itself, or
* ``secret-scan:allow-next`` on the line before the content it excuses.

Anything excused this way should say *why* in the same comment.

Unquoted assignments
--------------------
A quoted value on a secret-looking key is matched directly. An *unquoted* one can
only be judged by its shape, so it must clear three gates: not a placeholder and
not an expression (no property access, indexing, call, template or path), at
least 16 characters drawn from at least three character classes, and at least
3.2 bits of Shannon entropy per character. Identifiers such as ``buildToken`` or
``getPasswordFromVault`` fail those gates; generated tokens pass. The thresholds
live in ``MIN_UNQUOTED_SECRET_*`` and are deliberate: missing an unquoted secret
is recoverable, training people to bypass the guard is not.
"""

from __future__ import annotations

import argparse
import json
import math
import re
import subprocess
import sys
from collections import Counter
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

TOOL = "documentation/tools/check_secrets.py"

# Directories that are never scanned: they are generated, vendored or foreign.
SKIP_DIRS = {
    ".git", "node_modules", ".venv", "venv", "vendor",
    "__pycache__", ".pytest_cache", ".mypy_cache",
}

# A file larger than this is not a source file; skip it rather than scan a blob.
MAX_BYTES = 2_000_000


@dataclass(frozen=True)
class Rule:
    name: str
    pattern: re.Pattern
    description: str
    # Which match group holds the value to placeholder-check, or None when the
    # whole match is intrinsically secret (a private-key header, a token prefix).
    value_group: int | None
    fix: str
    # An extra gate on that value, for rules whose pattern alone is not enough to
    # tell a credential from ordinary code (the entropy rule below). Returning
    # False suppresses the finding.
    validator: Callable[[str], bool] | None = None


def _rx(pattern: str) -> re.Pattern:
    return re.compile(pattern)


# --- grading an unquoted right-hand side --------------------------------------
# An unquoted assignment cannot be judged by its pattern alone, so a candidate
# has to clear three independent gates. The numbers are deliberately conservative
# and were tuned against this repository: every identifier and expression that
# appears here (`buildToken`, `getPasswordFromVault`, `form.password`,
# `e.target.value`) fails at least one gate, while generated tokens pass all
# three. Lengthening a real secret is a cheaper mistake than crying wolf.
MIN_UNQUOTED_SECRET_LENGTH = 16
MIN_UNQUOTED_SECRET_ENTROPY = 3.2
MIN_UNQUOTED_SECRET_CLASSES = 3


def shannon_entropy(text: str) -> float:
    """Bits per character of Shannon entropy, 0.0 for an empty string."""
    if not text:
        return 0.0
    length = len(text)
    return -sum(
        (count / length) * math.log2(count / length)
        for count in Counter(text).values()
    )


def _character_classes(text: str) -> int:
    """How many of lower/upper/digit/symbol the text draws on."""
    return sum(bool(re.search(p, text)) for p in (r"[a-z]", r"[A-Z]", r"[0-9]", r"[^A-Za-z0-9]"))


def looks_like_expression(value: str) -> bool:
    """True when a right-hand side is code rather than a literal.

    Property access, indexing, calls, template syntax and paths are all
    expressions, and a guard that flags them is a guard people disable.
    """
    v = value.strip().strip("\"'`")
    if not v:
        return True
    if any(ch in v for ch in "()[]{}$<>%@&|!*+~^"):
        return True
    if any(ch in v for ch in "./\\"):
        return True
    return False


def is_high_entropy_secret(value: str) -> bool:
    """Whether an unquoted value really looks like a credential.

    All three gates must pass: not a placeholder or an expression, at least
    MIN_UNQUOTED_SECRET_LENGTH characters of at least MIN_UNQUOTED_SECRET_CLASSES
    character classes, at MIN_UNQUOTED_SECRET_ENTROPY bits per character or more.
    """
    v = value.strip().strip("\"'`")
    if looks_like_placeholder(v) or looks_like_expression(v):
        return False
    if len(v) < MIN_UNQUOTED_SECRET_LENGTH:
        return False
    if _character_classes(v) < MIN_UNQUOTED_SECRET_CLASSES:
        return False
    return shannon_entropy(v) >= MIN_UNQUOTED_SECRET_ENTROPY


# --- what a secret-looking key name looks like --------------------------------
# The secret word may be followed by qualifiers, so compound names that do not
# *end* in the secret word are still recognised: `SECRET_KEY`, `SECRET_KEY_BASE`,
# `ACCESS_TOKEN_SECRET`. Qualifiers are limited to words that name a credential
# rather than something derived from one or merely spelled with the same letters,
# so `PASSWORD_HASH` (routinely published) and `TOKENIZER` are not caught.
SECRET_KEY_QUALIFIER = r"(?:[_-](?:key|token|secret|pass|pwd|password|base))*"


def _secret_key_name(words: str) -> str:
    return rf"[A-Za-z0-9_\-]*{words}{SECRET_KEY_QUALIFIER}"


# Keys recognised when the value is unquoted. Such a value has to survive the
# entropy gate in is_high_entropy_secret(), so the key name itself can be broad:
# an unquoted `token=…` in the `.env`/shell style is worth a look.
UNQUOTED_SECRET_KEY_NAME = _secret_key_name(
    r"(?:password|passwd|passphrase|secret|token|api[_-]?key|apikey|private[_-]?key)"
)

# Keys recognised when the value is a quoted literal. Here the key name is the
# *only* evidence — there is no entropy gate — so it has to be specific. Bare
# `token` is deliberately absent: `const token = 'abc'` is ordinary code, not a
# credential, and a rule that flags it gets switched off. Compound forms are kept
# explicitly, and a real token literal is still caught by the vendor rules above.
QUOTED_SECRET_KEY_NAME = _secret_key_name(
    r"(?:password|passwd|passphrase|secret|api[_-]?key|apikey|private[_-]?key|"
    r"access[_-]?token|auth[_-]?token|refresh[_-]?token|bearer[_-]?token|api[_-]?token)"
)


RULES: list[Rule] = [
    Rule(
        "private-key",
        _rx(r"-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----"),
        "An embedded private key block.",
        None,
        "Remove the key and load it from a file outside the repository.",
    ),
    Rule(
        "aws-access-key-id",
        _rx(r"\b(?:AKIA|ASIA|AGPA|AIDA|AROA|AIPA|ANPA|ANVA)[A-Z0-9]{16}\b"),
        "An AWS access key id.",
        None,
        "Revoke the key and replace it with an env lookup.",
    ),
    Rule(
        "github-token",
        _rx(r"\bgh[pousr]_[A-Za-z0-9]{36,251}\b"),
        "A GitHub token.",
        None,
        "Revoke the token and replace it with an env lookup.",
    ),
    Rule(
        "slack-token",
        _rx(r"\bxox[baprs]-[A-Za-z0-9-]{10,}\b"),
        "A Slack token.",
        None,
        "Revoke the token and replace it with an env lookup.",
    ),
    Rule(
        "jwt",
        _rx(r"\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\b"),
        "A JSON Web Token.",
        None,
        "Tokens are short-lived; replace it with a placeholder in examples.",
    ),
    Rule(
        "laravel-app-key",
        _rx(r"\bAPP_KEY\s*[:=]\s*[\"']?(base64:[A-Za-z0-9+/=]{40,})"),
        "A Laravel application key.",
        1,
        "Generate a fresh APP_KEY per environment; keep it in .env only.",
    ),
    Rule(
        "connection-uri-with-password",
        _rx(r"\b(?:postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis|rediss|amqp|amqps)"
            r"://[^:@/\s]+:([^@/\s]+)@"),
        "A connection URI carrying a password.",
        1,
        "Drop the password from the URI; supply it from the environment.",
    ),
    Rule(
        "pgpassword-assignment",
        _rx(r"\bPGPASSWORD\s*[:=]\s*[\"']?([^\s\"'`;]+)"),
        "A PostgreSQL password assignment.",
        1,
        "Read PGPASSWORD from the environment or ~/.pgpass; never hardcode it.",
    ),
    Rule(
        "db-password-assignment",
        _rx(r"\bDB_PASSWORD\s*[:=]\s*[\"']?([^\s\"'`,;]+)"),
        "A database password assignment.",
        1,
        "Keep the value in an untracked .env; put a placeholder in docs.",
    ),
    Rule(
        "bearer-token",
        _rx(r"(?i)\bauthorization\s*[:=]\s*[\"']?bearer\s+([A-Za-z0-9._\-]{20,})"),
        "A literal bearer token.",
        1,
        "Never commit a token; fetch it at runtime.",
    ),
    # --- vendor formats with distinctive shapes ------------------------------
    Rule(
        "google-api-key",
        _rx(r"\bAIza[0-9A-Za-z_\-]{35}\b"),
        "A Google API key.",
        None,
        "Restrict and rotate the key; supply it from the environment.",
    ),
    Rule(
        "stripe-secret-key",
        _rx(r"\b(?:sk|rk)_(?:live|test)_[0-9A-Za-z]{16,}\b"),
        "A Stripe secret key.",
        None,
        "Roll the key in the Stripe dashboard; never commit it.",
    ),
    Rule(
        "npm-token",
        _rx(r"\bnpm_[A-Za-z0-9]{36}\b"),
        "An npm access token.",
        None,
        "Revoke the token and use an environment variable.",
    ),
    Rule(
        "sendgrid-api-key",
        _rx(r"\bSG\.[A-Za-z0-9_\-]{22}\.[A-Za-z0-9_\-]{43}\b"),
        "A SendGrid API key.",
        None,
        "Revoke the key and load it from the environment.",
    ),
    Rule(
        "slack-webhook-url",
        _rx(r"https://hooks\.slack\.com/services/[A-Za-z0-9_+/\-]{20,}"),
        "A Slack incoming-webhook URL (the URL is the credential).",
        None,
        "Revoke the webhook and store it as a secret.",
    ),
    Rule(
        "aws-secret-access-key",
        _rx(r"(?i)\baws_secret_access_key\s*[:=]\s*[\"']?([A-Za-z0-9/+=]{40})"),
        "An AWS secret access key.",
        1,
        "Revoke the pair and replace it with an env lookup.",
    ),
    Rule(
        "basic-auth-header",
        _rx(r"(?i)\bauthorization\s*[:=]\s*[\"']?basic\s+([A-Za-z0-9+/=]{16,})"),
        "A literal HTTP Basic credential.",
        1,
        "Never commit credentials; fetch them at runtime.",
    ),
    # Deliberately restricted to a *quoted* literal. An unquoted right-hand side
    # is often an expression (`body.password = form.password`,
    # `password: e.target.value`), and flagging those would train everyone to
    # bypass the guard. The unquoted `.env`-style cases that matter are covered by
    # the dedicated DB_PASSWORD / PGPASSWORD / connection-URI / token rules above,
    # and by `unquoted-secret-assignment` immediately below.
    Rule(
        "generic-secret-assignment",
        _rx(r"(?i)\b" + QUOTED_SECRET_KEY_NAME
            + r"\s*[:=]\s*([\"'])([A-Za-z0-9+/=_\-.]{8,})\1"),
        "A quoted secret-looking value assigned to a secret-looking key.",
        2,
        "Move the value out of the file; keep only a placeholder.",
    ),
    # The unquoted counterpart. Its pattern is only a *candidate* filter; the
    # entropy gate below is what decides. See is_high_entropy_secret().
    Rule(
        "unquoted-secret-assignment",
        _rx(r"(?i)^\s*(?:export\s+|declare\s+-x\s+|env\s+|const\s+|let\s+|var\s+|SET\s+|-\s+)?"
            r"(" + UNQUOTED_SECRET_KEY_NAME + r")\s*[:=]\s*([^\s\"'`,;#]+)\s*(?:#.*)?$"),
        "An unquoted high-entropy value on a secret-looking key.",
        2,
        "Move the value out of the file; keep only a placeholder.",
        validator=is_high_entropy_secret,
    ),
]

ALLOW_MARKER = "secret-scan:allow"
ALLOW_NEXT_MARKER = "secret-scan:allow-next"

# Values that are plainly not secrets, compared case-insensitively.
PLACEHOLDER_WORDS = {
    "", "redacted", "changeme", "change_me", "changethis", "change_this",
    "placeholder", "example", "examples", "sample", "dummy", "fake", "test",
    "testing", "secret", "password", "passwd", "pwd", "none", "null", "nil",
    "unset", "undefined", "hidden", "masked", "todo", "xxx", "xxxx", "xxxxx",
    "your_password", "yourpassword", "your-password", "n/a", "na", "true",
    "false", "foo", "bar", "baz", "whatever", "irrelevant",
}

# A stored value that is really a lookup: `$VAR`, `${VAR}`, `$(...)`, `{{ var }}`,
# `env('VAR')`, `os.getenv('VAR')`, `process.env.VAR`, `%VAR%`, `<anything>`.
LOOKUP_PATTERNS = [
    re.compile(r"^\$\{.*\}$"),
    re.compile(r"^\$\(.*\)$"),
    re.compile(r"^\$[A-Za-z_][A-Za-z0-9_]*$"),
    re.compile(r"^\{\{.*\}\}$"),
    re.compile(r"^%.*%$"),
    re.compile(r"^<.*>$"),
    re.compile(r"^(?:env|getenv|os\.getenv|process\.env|import\.meta\.env)\b"),
    re.compile(r"^config\("),
    re.compile(r"^secrets?\."),
]

# Characters that open a template, variable reference or redaction marker. A
# value starting with one of these is a placeholder, not a credential. This also
# covers a template the assignment pattern truncated at its first space, such as
# `{{ secret }}` arriving here as `{{`.
PLACEHOLDER_OPENERS = ("$", "<", "%", "{", "[")


def looks_like_placeholder(value: str) -> bool:
    """True when a matched value is obviously not a real secret."""
    v = value.strip().strip("\"'`").strip()
    if v.lower() in PLACEHOLDER_WORDS:
        return True
    if any(p.match(v) for p in LOOKUP_PATTERNS):
        return True
    if v.startswith(PLACEHOLDER_OPENERS):
        return True
    if set(v) <= {"*", "x", "X", "."}:
        return True
    low = v.lower()
    if any(word in low for word in ("placeholder", "changeme", "redacted", "example", "your_")):
        return True
    return False


@dataclass
class Finding:
    path: str
    line: int
    column: int
    rule: str
    severity: str
    description: str
    fix: str
    withheld: int
    source: str = "working tree"

    def render(self) -> str:
        return (
            f"{self.path}:{self.line}:{self.column}: {self.severity}: {self.rule}: "
            f"{self.description} [{self.withheld} character(s) withheld]"
        )


def _is_binary(data: bytes) -> bool:
    return b"\x00" in data[:8192]


def scan_text(text: str, path: str, source: str = "working tree") -> list[Finding]:
    """Every finding in one file's text. Never returns the matched value."""
    findings: list[Finding] = []
    allowed_next = False

    for index, raw_line in enumerate(text.splitlines(), start=1):
        line = raw_line
        excused = allowed_next or ALLOW_MARKER in line
        allowed_next = ALLOW_NEXT_MARKER in line

        if excused:
            continue

        for rule in RULES:
            reported = False
            for match in rule.pattern.finditer(line):
                if rule.value_group is not None:
                    value = match.group(rule.value_group)
                    if looks_like_placeholder(value):
                        continue
                    if rule.validator is not None and not rule.validator(value):
                        continue
                findings.append(
                    Finding(
                        path=path,
                        line=index,
                        column=match.start() + 1,
                        rule=rule.name,
                        severity="error",
                        description=rule.description,
                        fix=rule.fix,
                        withheld=len(match.group(0)),
                        source=source,
                    )
                )
                reported = True
                break
            if reported:
                # One finding per line, from the most specific rule that matched:
                # a line caught by `db-password-assignment` is not reported a
                # second time by the generic rule.
                break
    return findings


def git(*args: str, cwd: Path) -> tuple[int, str]:
    try:
        proc = subprocess.run(
            ["git", *args], cwd=str(cwd), capture_output=True, text=True,
            encoding="utf-8", errors="replace",
        )
    except (OSError, FileNotFoundError):
        return 2, ""
    return proc.returncode, proc.stdout


def repo_root(cwd: Path | None = None) -> Path | None:
    code, out = git("rev-parse", "--show-toplevel", cwd=cwd or Path.cwd())
    if code != 0 or not out.strip():
        return None
    return Path(out.strip())


def tracked_files(root: Path) -> list[str]:
    code, out = git("ls-files", cwd=root)
    return [l for l in out.splitlines() if l.strip()] if code == 0 else []


def untracked_files(root: Path) -> list[str]:
    code, out = git("ls-files", "--others", "--exclude-standard", cwd=root)
    return [l for l in out.splitlines() if l.strip()] if code == 0 else []


def staged_files(root: Path) -> list[str]:
    code, out = git("diff", "--cached", "--name-only", "--diff-filter=ACMR", cwd=root)
    return [l for l in out.splitlines() if l.strip()] if code == 0 else []


def _skip(rel: str) -> bool:
    parts = Path(rel).parts
    return any(part in SKIP_DIRS for part in parts)


def read_worktree(root: Path, rel: str) -> bytes | None:
    path = root / rel
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_BYTES:
            return None
        return path.read_bytes()
    except OSError:
        return None


def read_index(root: Path, rel: str) -> bytes | None:
    code, out = git("show", f":{rel}", cwd=root)
    if code != 0:
        return None
    try:
        return out.encode("utf-8", "surrogateescape")
    except Exception:
        return None


def collect_paths(root: Path, raw_paths: list[str]) -> list[str]:
    """Resolve --paths arguments to repo-relative file names.

    Symlinks and anything that leaves the repository are skipped rather than
    followed: a link inside a scratch directory can point anywhere on the
    machine, and a scanner must not wander out of the tree it was pointed at.
    """
    rels: list[str] = []
    for raw in raw_paths:
        path = Path(raw)
        if path.is_symlink():
            # Symlinked files are skipped entirely: they can point anywhere on
            # the machine, and the scanner must not follow them out of the
            # repository it was told to scan. This check runs before
            # path.is_dir(), because is_dir() follows symlinks and would
            # otherwise classify a symlink-to-directory as an ordinary
            # directory and scan its contents.
            continue
        if path.is_dir():
            for child in sorted(path.rglob("*")):
                if child.is_symlink() or not child.is_file():
                    continue
                try:
                    rel = child.relative_to(root)
                except ValueError:
                    continue
                rels.append(str(rel).replace("\\", "/"))
        else:
            try:
                rel = path.relative_to(root)
            except ValueError:
                rel = path
            rels.append(str(rel).replace("\\", "/"))
    return rels


def scan_paths(root: Path, rels: list[str], source: str, from_index: bool = False) -> list[Finding]:
    findings: list[Finding] = []
    for rel in rels:
        if _skip(rel):
            continue
        data = read_index(root, rel) if from_index else read_worktree(root, rel)
        if data is None or _is_binary(data):
            continue
        try:
            text = data.decode("utf-8")
        except UnicodeDecodeError:
            continue
        findings.extend(scan_text(text, rel, source))
    return findings


def _reachable_objects(root: Path, limit: int | None) -> dict[str, str]:
    """Every reachable object id mapped to a repository path ("" when unknown)."""
    args = ["rev-list"]
    if limit:
        args.append(f"--max-count={limit}")
    args += ["--all", "--objects"]
    code, out = git(*args, cwd=root)
    if code != 0:
        return {}
    mapping: dict[str, str] = {}
    for line in out.splitlines():
        sha, _, path = line.partition(" ")
        if not sha:
            continue
        if sha not in mapping:
            mapping[sha] = path
        elif path and not mapping[sha]:
            mapping[sha] = path
    return mapping


def scan_history(root: Path, limit: int | None = None) -> tuple[list[Finding], int]:
    """Committed content across all refs. Report only — nothing is rewritten.

    Contents are read with ``git cat-file --batch`` and matched with exactly the
    same Python patterns the working-tree scan uses. Shelling out to
    ``git grep -E`` was tried first and rejected: that engine is POSIX ERE, so the
    ``(?i)`` the token rules rely on makes it exit 128, and every commit is
    skipped in silence — a false "clean" on the one check whose whole job is to
    find what is already committed.

    Returns the findings and the number of distinct blobs actually scanned.
    """
    mapping = _reachable_objects(root, limit)
    if not mapping:
        return [], 0

    try:
        proc = subprocess.run(
            ["git", "cat-file", "--batch"], cwd=str(root),
            input="\n".join(mapping).encode("ascii"), capture_output=True,
        )
    except (OSError, FileNotFoundError):
        return [], 0
    if proc.returncode != 0 and not proc.stdout:
        return [], 0

    data = proc.stdout
    findings: list[Finding] = []
    scanned = 0
    pos = 0
    while pos < len(data):
        newline = data.find(b"\n", pos)
        if newline == -1:
            break
        header = data[pos:newline].decode("utf-8", "replace").split()
        pos = newline + 1
        if len(header) < 3:
            break                      # '<sha> missing' or a truncated tail
        sha, otype, size_text = header[0], header[1], header[2]
        if not size_text.isdigit():
            break
        size = int(size_text)
        blob = data[pos:pos + size]
        pos += size + 1                # the newline cat-file adds after content
        if otype != "blob":
            continue
        path = mapping.get(sha, "")
        if not path or _skip(path) or size > MAX_BYTES or _is_binary(blob):
            continue
        try:
            text = blob.decode("utf-8")
        except UnicodeDecodeError:
            continue
        scanned += 1
        findings.extend(scan_text(text, path, f"history blob {sha[:12]}"))
    return findings, scanned


HOOK_BODY = """#!/bin/sh
# Installed by {tool} --install-hook
# Blocks a commit whose staged changes introduce a detected secret.
# Bypass deliberately with: git commit --no-verify
set -e
if command -v python3 >/dev/null 2>&1; then PY=python3; else PY=python; fi
exec "$PY" {tool} --staged
"""


def install_hook(root: Path) -> Path:
    hooks = root / ".git" / "hooks"
    hooks.mkdir(parents=True, exist_ok=True)
    target = hooks / "pre-commit"
    target.write_text(HOOK_BODY.format(tool=TOOL), encoding="utf-8")
    try:
        target.chmod(0o755)
    except OSError:
        pass
    return target


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="check_secrets.py",
        description="Report secrets in the repository; exit 1 when any are found.",
    )
    parser.add_argument("--staged", action="store_true",
                        help="scan only the staged content (for a pre-commit hook)")
    parser.add_argument("--history", action="store_true",
                        help="scan committed content across all refs (report only)")
    parser.add_argument("--include-untracked", action="store_true", default=True,
                        help="include untracked, non-ignored files (default: on)")
    parser.add_argument("--only-tracked", action="store_true",
                        help="scan tracked files only")
    parser.add_argument("--paths", nargs="*", metavar="PATH",
                        help="scan these paths instead of the tracked file list")
    parser.add_argument("--limit", type=int, metavar="N",
                        help="with --history, only the most recent N commits")
    parser.add_argument("--json", action="store_true", help="machine-readable output")
    parser.add_argument("--verbose", action="store_true",
                        help="also print the fix advice for each finding")
    parser.add_argument("--list-rules", action="store_true",
                        help="list the rules, the placeholder policy and the escapes")
    parser.add_argument("--install-hook", action="store_true",
                        help="write .git/hooks/pre-commit to run this scanner on staged content")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)

    if args.list_rules:
        print("check_secrets — rules")
        for rule in RULES:
            print(f"  {rule.name:32s} {rule.description}")
        print(f"\n  escapes: '{ALLOW_MARKER}' on a line, or '{ALLOW_NEXT_MARKER}' on the line before it")
        print("  placeholders (never reported): " + ", ".join(sorted(w for w in PLACEHOLDER_WORDS if w)))
        print(
            "  unquoted values must also clear: >= "
            f"{MIN_UNQUOTED_SECRET_LENGTH} chars, >= {MIN_UNQUOTED_SECRET_CLASSES} character classes, "
            f">= {MIN_UNQUOTED_SECRET_ENTROPY} bits/char entropy"
        )
        print("  matched values are never printed — only the number of characters withheld")
        return 0

    root = repo_root()
    if root is None:
        print("check_secrets: not inside a Git repository", file=sys.stderr)
        return 2

    if args.install_hook:
        target = install_hook(root)
        print(f"check_secrets: installed pre-commit guard at {target}")
        print(f"  it runs: python {TOOL} --staged")
        print("  bypass a commit deliberately with: git commit --no-verify")
        return 0

    if args.history:
        findings, scanned = scan_history(root, limit=args.limit)
        return _report(findings, args, extra=f"history blobs scanned: {scanned}")

    if args.paths:
        findings = scan_paths(root, collect_paths(root, args.paths), "explicit paths")
    elif args.staged:
        findings = scan_paths(root, staged_files(root), "staged", from_index=True)
    else:
        rels = tracked_files(root)
        if not args.only_tracked:
            rels += untracked_files(root)
        findings = scan_paths(root, rels, "working tree")

    if args.staged and not findings:
        print("check_secrets: staged content clean")
    return _report(findings, args)


def _report(findings: list[Finding], args: argparse.Namespace, extra: str = "") -> int:
    findings = sorted(findings, key=lambda f: (f.path, f.line, f.column))
    if args.json:
        print(json.dumps({
            "findings": [
                {
                    "path": f.path, "line": f.line, "column": f.column,
                    "rule": f.rule, "severity": f.severity, "source": f.source,
                    "withheld_characters": f.withheld,
                }
                for f in findings
            ],
            "count": len(findings),
        }, indent=2))
    else:
        if findings:
            print(f"check_secrets: {len(findings)} finding(s) — values are never shown")
            for f in findings:
                print("  " + f.render())
                if args.verbose:
                    print("    fix: " + f.fix)
        else:
            print("check_secrets: clean — no secrets found")
        if extra:
            print("  (" + extra + ")")
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
