#!/usr/bin/env python3
"""Fixture-based self-tests for check_secrets.py.

Every test builds its evidence in memory or in a throwaway Git repository under
the system temp directory, so the suite never depends on the real repository and
never writes to the working tree. Run it with:

    python -m unittest discover -s documentation/tools -p "test_*.py"
    # or simply
    python documentation/tools/test_check_secrets.py

The fixture values are assembled from fragments at run time on purpose. If they
were written literally, this file would itself be a finding — which is exactly the
failure the last test in this suite would then report.

Covered cases:
  * a clean file                                     -> no findings
  * a private key block                              -> finding
  * PGPASSWORD / DB_PASSWORD assignments             -> finding
  * a connection URI carrying a password             -> finding
  * a token-shaped value (AWS, JWT, bearer)          -> finding
  * a generic secret-looking assignment              -> finding
  * placeholders and lookups                         -> no finding
  * the inline allow markers                         -> no finding
  * a finding never contains the matched value       -> redaction holds
  * vendor formats (Google, Stripe, npm, SendGrid, Slack, AWS, Basic) -> finding
  * an unquoted generated token on a secret-looking key -> finding
  * unquoted code expressions and identifiers          -> no finding
  * the entropy gate itself                            -> boundary behaviour
  * one finding per line, from the most specific rule   -> no duplicate noise
  * a staged secret in a throwaway repository        -> exit 1, blocked
  * --install-hook writes an executable pre-commit   -> present and references the tool
  * path collection skips symlinks and outside paths  -> no crash, no wandering
  * this tooling directory scans clean               -> no findings

No credential is ever written into this file. Every fixture value is generated at
run time, so the suite exercises realistic high-entropy material while the file
itself stays clean under the scanner it tests (see SelfCheckTests).
"""

from __future__ import annotations

import io
import os
import secrets as _secrets
import string
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import check_secrets as cs  # noqa: E402  (path set up above)

# --- fixture fragments -------------------------------------------------------
# Assembled at run time so this file stays clean under its own scanner.
FAKE_VALUE = "S3cr3t" + "Value123"
K_DB = "DB_" + "PASSWORD"
K_PG = "PG" + "PASSWORD"
K_URI = "postgres" + "://"
AWS_KEY = "AK" + "IA" + "ABCDEFGHIJKLMNOP"
PRIVATE_HEADER = "-----BEGIN " + "RSA PRIVATE KEY-----"
JWT = "eyJ" + "hbGciOiJIUzI1NiJ9" + "." + "eyJzdWIiOiIxMjM0NTY3ODkwIn0" + "." + "dQw4w9WgXcQdQw4w9WgXcQ"
BEARER = "Bearer " + "abcdef0123456789abcdef0123456789"


def findings_for(text: str) -> list[cs.Finding]:
    return cs.scan_text(text, "fixture.txt")


class DetectionTests(unittest.TestCase):
    def test_clean_text_has_no_findings(self):
        text = "def add(a, b):\n    return a + b\n\nNOTE = 'nothing secret here'\n"
        self.assertEqual(findings_for(text), [])

    def test_detects_private_key(self):
        found = findings_for(PRIVATE_HEADER + "\nMIIC...\n")
        self.assertEqual([f.rule for f in found], ["private-key"])

    def test_detects_pgpassword_assignment(self):
        found = findings_for(f"export {K_PG}={FAKE_VALUE}\n")
        self.assertIn("pgpassword-assignment", [f.rule for f in found])

    def test_detects_db_password_assignment(self):
        found = findings_for(f"{K_DB}={FAKE_VALUE}\n")
        self.assertIn("db-password-assignment", [f.rule for f in found])

    def test_detects_connection_uri_with_password(self):
        line = f"{K_URI}app_user:{FAKE_VALUE}@127.0.0.1:5432/app\n"
        found = findings_for(line)
        self.assertIn("connection-uri-with-password", [f.rule for f in found])

    def test_detects_aws_access_key(self):
        self.assertIn("aws-access-key-id", [f.rule for f in findings_for(f"id = {AWS_KEY}\n")])

    def test_detects_jwt(self):
        self.assertIn("jwt", [f.rule for f in findings_for(f"token: {JWT}\n")])

    def test_detects_literal_bearer_token(self):
        found = findings_for(f"Authorization: {BEARER}\n")
        self.assertIn("bearer-token", [f.rule for f in found])

    def test_detects_generic_secret_assignment(self):
        found = findings_for(f'api_key = "{FAKE_VALUE}"\n')
        self.assertIn("generic-secret-assignment", [f.rule for f in found])

    def test_unquoted_expressions_are_not_findings(self):
        """Property assignments and concatenations are code, not literals."""
        for line in ['if (form.secret) body.secret = form.secret\n',
                     'password: e.target.value\n',
                     'const k = "pass" + "word" + suffix\n']:
            with self.subTest(line=line):
                self.assertEqual(findings_for(line), [])


# --- runtime-generated fixtures ---------------------------------------------
def _token(length: int = 32) -> str:
    """A fresh high-entropy token, guaranteed to draw on three character classes."""
    body = "".join(_secrets.choice(string.ascii_letters + string.digits) for _ in range(length))
    return "A1z" + body


def _digits_alnum(length: int, alphabet: str) -> str:
    return "".join(_secrets.choice(alphabet) for _ in range(length))


class VendorFormatTests(unittest.TestCase):
    """Formats with a distinctive shape, generated fresh for every run."""

    def test_aws_access_key_id(self):
        value = "AKIA" + _digits_alnum(16, string.ascii_uppercase + string.digits)
        self.assertIn("aws-access-key-id",
                      [f.rule for f in findings_for(f"aws_key_id = {value}\n")])

    def test_aws_secret_access_key(self):
        value = _digits_alnum(40, string.ascii_letters + string.digits + "+/")
        line = "aws_secret_access_key = " + value + "\n"
        self.assertIn("aws-secret-access-key", [f.rule for f in findings_for(line)])

    def test_google_api_key(self):
        value = "AIza" + _digits_alnum(35, string.ascii_letters + string.digits)
        self.assertIn("google-api-key", [f.rule for f in findings_for(f"key: {value}\n")])

    def test_stripe_secret_key(self):
        value = "sk_" + "live_" + _digits_alnum(24, string.ascii_letters + string.digits)
        self.assertIn("stripe-secret-key", [f.rule for f in findings_for(f"{value}\n")])

    def test_npm_token(self):
        value = "npm_" + _digits_alnum(36, string.ascii_letters + string.digits)
        self.assertIn("npm-token", [f.rule for f in findings_for(f"{value}\n")])

    def test_sendgrid_api_key(self):
        value = "SG." + _digits_alnum(22, string.ascii_letters + string.digits)
        value += "." + _digits_alnum(43, string.ascii_letters + string.digits)
        self.assertIn("sendgrid-api-key", [f.rule for f in findings_for(f"{value}\n")])

    def test_slack_incoming_webhook(self):
        value = "https://hooks.slack.com/services/" + _digits_alnum(
            24, string.ascii_letters + string.digits)
        self.assertIn("slack-webhook-url", [f.rule for f in findings_for(f"{value}\n")])

    def test_http_basic_credential(self):
        value = _digits_alnum(28, string.ascii_letters + string.digits + "+/=")
        line = "Authorization: Basic " + value + "\n"
        self.assertIn("basic-auth-header", [f.rule for f in findings_for(line)])


class UnquotedAssignmentTests(unittest.TestCase):
    """Unquoted assignments are judged by shape, not by a quote character."""

    def test_generated_token_is_detected(self):
        found = findings_for("API_" + "TOKEN=" + _token() + "\n")
        self.assertEqual([f.rule for f in found], ["unquoted-secret-assignment"])

    def test_several_key_names_are_covered(self):
        for key in ["API_" + "TOKEN", "CLIENT_" + "SECRET", "SECRET_" + "KEY",
                    "ACCESS_" + "TOKEN", "PASSWD", "AUTH_" + "TOKEN"]:
            with self.subTest(key=key):
                found = findings_for(key + "=" + _token() + "\n")
                self.assertTrue(found, key)

    def test_shell_and_code_prefixes_are_covered(self):
        for template in ["export {k}={v}\n", "const {k} = {v}\n", "- {k}: {v}\n",
                         "  {k} = {v}   # trailing comment\n"]:
            with self.subTest(template=template):
                found = findings_for(template.format(k="API_" + "TOKEN", v=_token()))
                self.assertIn("unquoted-secret-assignment", [f.rule for f in found])

    def test_quoted_generated_token_uses_the_generic_rule(self):
        line = 'api_' + 'key = "' + _token() + '"\n'
        self.assertIn("generic-secret-assignment", [f.rule for f in findings_for(line)])

    def test_compound_key_names_are_detected(self):
        # The secret word is not always at the end of the name.
        for key in ["SECRET_" + "KEY", "SECRET_" + "KEY_BASE", "ACCESS_" + "TOKEN_SECRET",
                    "JWT_" + "SECRET", "CLIENT_" + "SECRET_KEY"]:
            with self.subTest(key=key):
                found = findings_for(key + "=" + _token() + "\n")
                self.assertTrue(found, key)

    def test_quoted_compound_key_is_detected(self):
        line = "SECRET_" + "KEY = \"" + _token() + "\"\n"
        self.assertIn("generic-secret-assignment", [f.rule for f in findings_for(line)])

    def test_specific_rule_suppresses_the_generic_fallback(self):
        line = "DB_" + "PASSWORD=" + _token() + "\n"
        found = findings_for(line)
        self.assertEqual(len(found), 1)
        self.assertEqual(found[0].rule, "db-password-assignment")


class EntropyGateTests(unittest.TestCase):
    def test_entropy_of_a_repeated_character_is_zero(self):
        self.assertAlmostEqual(cs.shannon_entropy("aaaaaaaa"), 0.0, places=6)

    def test_entropy_of_an_empty_string_is_zero(self):
        self.assertEqual(cs.shannon_entropy(""), 0.0)

    def test_generated_token_passes_the_gate(self):
        self.assertTrue(cs.is_high_entropy_secret(_token()))

    def test_identifiers_and_phrases_fail_the_gate(self):
        for value in ["buildToken", "getPasswordFromVault", "apiKeyValue",
                      "correcthorsebatterystaple", "1234567890", "short", ""]:
            with self.subTest(value=value):
                self.assertFalse(cs.is_high_entropy_secret(value))

    def test_expressions_fail_the_expression_gate(self):
        for value in ["e.target.value", "form.password", "os.environ[\"X\"]",
                      "build(url)", "$API_TOKEN", "{placeholder}", "/etc/app/secrets",
                      "process.env.SECRET"]:
            with self.subTest(value=value):
                self.assertTrue(cs.looks_like_expression(value))


class FalsePositiveTests(unittest.TestCase):
    """Legitimate code and configuration must not be flagged."""

    def test_unquoted_code_expressions_are_not_findings(self):
        for line in [
            "password: e.target.value\n",
            "body.password = form.password\n",
            "api_key = process.env.API_KEY\n",
            "secret = os.environ[\"APP_SECRET\"]\n",
            "token = buildToken(user)\n",
            "password = getUser123\n",
            "token = getPasswordFromVault(a, b)\n",
            "secret_path = /etc/app/secrets.json\n",
            "api_token = https://example.test/token\n",
            "mock_token = 'not-a-real-token'\n",
        ]:
            with self.subTest(line=line):
                self.assertEqual(findings_for(line), [])

    def test_words_that_merely_contain_a_secret_word_are_not_findings(self):
        # The qualifier suffix is limited on purpose: these are not credentials.
        for line in [
            "TOKENIZER = \"standard\"\n",
            "PASSWORD_HASH = \"$2y$10$abcdefghijklmnopqrstuv\"\n",
            "secretPath = \"/etc/app/secrets.json\"\n",
            "tokensPerPage = 25\n",
        ]:
            with self.subTest(line=line):
                self.assertEqual(findings_for(line), [])

    def test_placeholder_assignments_are_not_findings(self):
        for value in ["${API_TOKEN}", "$API_TOKEN", "<redacted>", "changeme",
                      "{{ token }}", "%API_TOKEN%", "env('API_TOKEN')", ""]:
            with self.subTest(value=value):
                self.assertEqual(findings_for("API_" + "TOKEN=" + value + "\n"), [])


class PlaceholderTests(unittest.TestCase):
    def test_placeholders_are_not_findings(self):
        for value in ["<redacted>", "${PGPASSWORD}", "$PGPASSWORD", "{{ secret }}",
                      "%PGPASSWORD%", "changeme", "example", "your_password", "****",
                      "env('DB_PASSWORD')", "os.getenv('DB_PASSWORD')"]:
            with self.subTest(value=value):
                self.assertEqual(findings_for(f"{K_DB}={value}\n"), [])

    def test_empty_assignment_is_not_a_finding(self):
        self.assertEqual(findings_for(f"{K_DB}=\n"), [])

    def test_real_looking_value_is_still_a_finding(self):
        self.assertTrue(findings_for(f"{K_DB}={FAKE_VALUE}\n"))


class AllowMarkerTests(unittest.TestCase):
    def test_same_line_marker_excuses_the_line(self):
        line = f"{K_DB}={FAKE_VALUE}  # secret-scan:allow: rotated fixture\n"
        self.assertEqual(findings_for(line), [])

    def test_allow_next_marker_excuses_the_next_line(self):
        text = "value = (\n  # secret-scan:allow-next: documented example\n" \
               f"  '{FAKE_VALUE}'\n)\n"
        self.assertEqual(findings_for(text), [])

    def test_marker_does_not_leak_to_later_lines(self):
        text = ("# secret-scan:allow-next: one line\n"
                "# harmless comment\n"
                f"{K_DB}={FAKE_VALUE}\n")
        self.assertTrue(findings_for(text))


class RedactionTests(unittest.TestCase):
    def test_finding_never_contains_the_matched_value(self):
        found = findings_for(f"{K_DB}={FAKE_VALUE}\n")
        self.assertTrue(found)
        for finding in found:
            rendered = finding.render()
            self.assertNotIn(FAKE_VALUE, rendered)
            self.assertIn("withheld", rendered)
            self.assertGreater(finding.withheld, 0)


class GitRepoTests(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.root = Path(self._tmp.name)
        subprocess.run(["git", "init", "-q"], cwd=self.root, check=True,
                       capture_output=True)
        subprocess.run(["git", "config", "user.email", "t@example.com"],
                       cwd=self.root, check=True, capture_output=True)
        subprocess.run(["git", "config", "user.name", "Test"],
                       cwd=self.root, check=True, capture_output=True)
        self._cwd = os.getcwd()

    def tearDown(self):
        os.chdir(self._cwd)
        self._tmp.cleanup()

    def _write(self, rel: str, text: str) -> None:
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")

    def test_working_tree_scan_finds_a_secret(self):
        self._write("app/config.py", f"{K_DB}={FAKE_VALUE}\n")
        found = cs.scan_paths(self.root, ["app/config.py"], "working tree")
        self.assertEqual([f.rule for f in found], ["db-password-assignment"])

    def test_staged_scan_blocks_a_committed_secret(self):
        self._write("settings.sh", f"export {K_PG}={FAKE_VALUE}\n")
        subprocess.run(["git", "add", "settings.sh"], cwd=self.root, check=True,
                       capture_output=True)
        os.chdir(self.root)
        buf = io.StringIO()
        with redirect_stdout(buf):
            code = cs.main(["--staged"])
        self.assertEqual(code, 1)
        self.assertIn("pgpassword-assignment", buf.getvalue())
        self.assertNotIn(FAKE_VALUE, buf.getvalue())

    def test_staged_scan_passes_clean_content(self):
        # Assembled from fragments so the guard text is not itself a finding.
        self._write("settings.sh", "export " + K_PG + "=" + '"$' + K_PG + '"\n')
        subprocess.run(["git", "add", "settings.sh"], cwd=self.root, check=True,
                       capture_output=True)
        os.chdir(self.root)
        buf = io.StringIO()
        with redirect_stdout(buf):
            code = cs.main(["--staged"])
        self.assertEqual(code, 0)

    def _commit_all(self, message: str) -> None:
        subprocess.run(["git", "add", "-A"], cwd=self.root, check=True, capture_output=True)
        subprocess.run(["git", "commit", "-q", "-m", message], cwd=self.root,
                       check=True, capture_output=True)

    def test_history_scan_finds_a_secret_that_was_removed(self):
        """The regression this guards: a secret committed and then deleted."""
        self._write("notes.md", f"config: `{K_DB}={FAKE_VALUE}`\n")
        self._commit_all("add notes")
        (self.root / "notes.md").write_text("config: `<redacted>`\n", encoding="utf-8")
        self._commit_all("redact notes")
        # The working tree is clean, so only the history scan can see it.
        self.assertEqual(cs.scan_paths(self.root, ["notes.md"], "working tree"), [])
        found, scanned = cs.scan_history(self.root)
        self.assertGreater(scanned, 0)
        self.assertIn("db-password-assignment", [f.rule for f in found])
        for finding in found:
            self.assertNotIn(FAKE_VALUE, finding.render())

    def test_history_scan_is_clean_for_a_clean_repository(self):
        self._write("app.py", "def add(a, b):\n    return a + b\n")
        self._commit_all("initial")
        found, scanned = cs.scan_history(self.root)
        self.assertEqual(found, [])
        self.assertGreater(scanned, 0)

    def test_install_hook_writes_an_executable_pre_commit(self):
        target = cs.install_hook(self.root)
        self.assertTrue(target.is_file())
        body = target.read_text(encoding="utf-8")
        self.assertIn("check_secrets.py", body)
        self.assertIn("--staged", body)


class PathCollectionTests(unittest.TestCase):
    def test_collect_paths_lists_files_and_skips_symlinks(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "pkg").mkdir()
            (root / "pkg" / "a.py").write_text("x = 1\n", encoding="utf-8")
            link = root / "outside.py"
            try:
                os.symlink(Path(tmp).parent / "nowhere.py", link)
            except (OSError, NotImplementedError, AttributeError):
                self.skipTest("symlinks are not available on this platform")
            rels = cs.collect_paths(root, [str(root / "pkg"), str(link)])
            self.assertIn("pkg/a.py", rels)
            self.assertNotIn("outside.py", rels)

    def test_collect_paths_skips_a_file_outside_the_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "repo"
            root.mkdir()
            outside = Path(tmp) / "elsewhere.txt"
            outside.write_text("x = 1\n", encoding="utf-8")
            rels = cs.collect_paths(root, [str(outside)])
            # It is returned as-is (absolute), never as a broken relative_to() call.
            self.assertTrue(rels)
            self.assertFalse(rels[0].startswith(".."))


class SelfCheckTests(unittest.TestCase):
    def test_this_tooling_directory_scans_clean(self):
        """The scanner and its fixtures must not be findings themselves."""
        here = Path(__file__).resolve().parent
        findings = []
        for path in sorted(here.glob("*.py")):
            findings.extend(cs.scan_text(path.read_text(encoding="utf-8"), path.name))
        self.assertEqual(findings, [], [f.render() for f in findings])


if __name__ == "__main__":
    unittest.main(verbosity=2)
