#!/usr/bin/env bash
#
# Self-test for scripts/preflight-gate.sh.
#
# The gate's own contract is small and entirely about control flow: run
# `departments:preflight`, reproduce its exit code, refuse a target it must not
# touch, and never print a credential. Every one of those is testable without a
# database, because the gate resolves PHP through `PHP_BIN` — this suite points
# that at a stub which records its arguments and exits with a status the test
# chooses. Nothing here connects to PostgreSQL, SQLite or any other database, and
# no file outside this suite's own temporary directory is written.
#
# Run it from anywhere:
#
#   bash tests/shell/preflight-gate.test.sh
#
# Exit status: 0 = every check passed, 1 = at least one check failed. The
# per-check lines name the case they belong to, so a failure says which part of
# the contract broke.

set -uo pipefail

test_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
backend_dir="$(cd -- "$test_dir/../.." && pwd)"
gate="$backend_dir/scripts/preflight-gate.sh"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

stub="$work/php-stub"
arglog="$work/invocations"
exits="$work/stub-status"

# The stand-in for the php binary the gate would otherwise run. It records the
# argv it was handed — that recording is the evidence for what the gate actually
# invoked — and exits with the status this suite asked for.
cat > "$stub" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$ARGLOG"
exit "$(cat "$EXITS" 2>/dev/null || echo 0)"
STUB
chmod +x "$stub"

checks=0
failures=0
gate_status=0
gate_out=''

check() { # description, expected, actual
    checks=$((checks + 1))

    if [ "$2" = "$3" ]; then
        printf '  ok    %s\n' "$1"
    else
        printf '  FAIL  %s\n        expected: %s\n        actual:   %s\n' "$1" "$2" "$3"
        failures=$((failures + 1))
    fi
}

check_contains() { # description, needle, haystack
    checks=$((checks + 1))

    case "$3" in
        *"$2"*) printf '  ok    %s\n' "$1" ;;
        *)
            printf '  FAIL  %s\n        expected to contain: %s\n        output was: %s\n' "$1" "$2" "$3"
            failures=$((failures + 1))
            ;;
    esac
}

check_absent() { # description, needle, haystack
    checks=$((checks + 1))

    case "$3" in
        *"$2"*)
            printf '  FAIL  %s\n        must not contain: %s\n' "$1" "$2"
            failures=$((failures + 1))
            ;;
        *) printf '  ok    %s\n' "$1" ;;
    esac
}

invocations() { # how many times the stub was asked to run something
    if [ -f "$arglog" ]; then wc -l < "$arglog" | tr -d ' '; else printf '0'; fi
}

# Run the gate with a chosen stub status, database name ("-" leaves DB_DATABASE
# unset) and any extra environment assignments. Unsets are forced so an
# unrelated shell cannot make a case pass or fail by accident.
run_gate() {
    local status="$1" database="$2"
    shift 2

    printf '%s\n' "$status" > "$exits"
    : > "$arglog"

    # "-" means "leave DB_DATABASE unset"; anything else is exported as the name
    # the gate is told to target.
    local db_binding=()
    if [ "$database" != '-' ]; then
        db_binding=(DB_DATABASE="$database")
    fi

    # This suite deliberately runs without `set -e`: a nonzero status here is the
    # measurement, not a failure. run_gate leaves the caller's mode alone.
    local gate_env=(env -u DB_DATABASE -u PREFLIGHT_ALLOW_SOURCE -u DB_PASSWORD -u APP_KEY \
        ARGLOG="$arglog" EXITS="$exits" PHP_BIN="$stub" ARTISAN_BIN="$stub" DB_CONNECTION=pgsql)
    gate_out="$(
        "${gate_env[@]}" ${db_binding[@]+"${db_binding[@]}"} "$@" bash "$gate" 2>&1
    )"
    gate_status=$?
}

printf 'preflight-gate self-test\n\n'

# ---------------------------------------------------------------------
# Case: success — preflight passes
# ---------------------------------------------------------------------
printf 'success\n'
run_gate 0 scheduling_system_testing
check 'exit status is 0' '0' "$gate_status"
check_contains 'says the release may continue' 'may continue' "$gate_out"
check 'invokes exactly one command' '1' "$(invocations)"
check_contains 'invokes departments:preflight with the non-interactive flag' \
    'departments:preflight --no-interaction' "$(cat "$arglog")"

# ---------------------------------------------------------------------
# Case: failure — the preflight's own status is reproduced, nothing runs after
# ---------------------------------------------------------------------
printf '\nfailure\n'
run_gate 7 scheduling_system_testing
check 'exit status is the preflight status (7), not a generic 1' '7' "$gate_status"
check_contains 'announces the block' 'DEPLOYMENT BLOCKED' "$gate_out"
check_contains 'names the preflight exit status' 'exited 7' "$gate_out"

marker="$work/subsequent-step-ran"
run_gate 7 scheduling_system_testing
if [ "$gate_status" -eq 0 ]; then printf 'ran\n' > "$marker"; fi

if [ -e "$marker" ]; then
    check 'the step chained after the gate did not run' 'not run' 'ran'
else
    check 'the step chained after the gate did not run' 'not run' 'not run'
fi

# ---------------------------------------------------------------------
# Case: preflight itself returns 2 or 3 — same numeric codes as gate refusals,
# but the output must identify this as a preflight failure, not a gate refusal.
# ---------------------------------------------------------------------
printf '\npreflight failure code 2\n'
run_gate 2 scheduling_system_testing
check 'preflight exit status 2 is preserved' '2' "$gate_status"
check_contains 'identifies this as a preflight failure' 'DEPLOYMENT BLOCKED: departments:preflight exited 2' "$gate_out"
check_absent 'does not label it a gate refusal' 'REFUSING TO RUN THE GATE' "$gate_out"
check 'php was invoked for the preflight' '1' "$(invocations)"

printf '\npreflight failure code 3\n'
run_gate 3 scheduling_system_testing
check 'preflight exit status 3 is preserved' '3' "$gate_status"
check_contains 'identifies this as a preflight failure' 'DEPLOYMENT BLOCKED: departments:preflight exited 3' "$gate_out"
check_absent 'does not label it a gate refusal' 'REFUSING TO RUN THE GATE' "$gate_out"
check 'php was invoked for the preflight' '1' "$(invocations)"

# ---------------------------------------------------------------------
# Case: missing database name — refuse before php is invoked at all
# ---------------------------------------------------------------------
printf '\nmissing database name\n'
run_gate 0 -
check 'exit status is 2' '2' "$gate_status"
check_contains 'explains why' 'DB_DATABASE is not set' "$gate_out"
check 'php is never invoked' '0' "$(invocations)"

# ---------------------------------------------------------------------
# Case: source database — refuse before php is invoked at all
# ---------------------------------------------------------------------
printf '\nsource database\n'
run_gate 0 scheduling_system
check 'exit status is 3' '3' "$gate_status"
check_contains 'names the source database' "source database ('scheduling_system')" "$gate_out"
check 'php is never invoked' '0' "$(invocations)"

# The refusal is lifted only by the explicit, documented opt-in; with it, the
# gate still targets the database it was told to (the stub never runs artisan).
printf '\nsource database with the explicit opt-in\n'
run_gate 0 scheduling_system PREFLIGHT_ALLOW_SOURCE=1
check 'exit status is 0 once the opt-in is set' '0' "$gate_status"
check 'php is invoked with the opt-in' '1' "$(invocations)"

# ---------------------------------------------------------------------
# Case: credential hygiene — runtime-made sentinels must not appear anywhere
# ---------------------------------------------------------------------
printf '\ncredential hygiene\n'
password_sentinel="gatepw-$(date +%s)-$RANDOM"
key_sentinel="gatekey-$(date +%s)-$RANDOM"

run_gate 0 scheduling_system_testing \
    "DB_PASSWORD=$password_sentinel" "APP_KEY=base64:$key_sentinel"
check_absent 'the password value is not printed' "$password_sentinel" "$gate_out"
check_absent 'the application key value is not printed' "$key_sentinel" "$gate_out"
check_absent 'no credential is passed on the command line' "$password_sentinel" "$(cat "$arglog")"
check_absent 'no credential is passed on the command line' "$key_sentinel" "$(cat "$arglog")"

# ---------------------------------------------------------------------
# Case: invocation integrity — one command, and it is the preflight
# ---------------------------------------------------------------------
printf '\ninvocation integrity\n'
run_gate 0 scheduling_system_testing
recorded="$(cat "$arglog")"
check 'exactly one invocation' '1' "$(invocations)"
check_absent 'never runs the diagnostic variant' 'preflight:diagnostic' "$recorded"
check_absent 'never runs a migration' 'migrate' "$recorded"
check_absent 'never runs a seeder' 'db:seed' "$recorded"

# The same rule read off the script itself: no executable line (comments
# excluded — the header documents the migration step the gate precedes) may call
# a migration or a seeder.
executable_calls="$(grep -v '^[[:space:]]*#' "$gate" | grep -cE '(migrate|db:seed)' || true)"
check 'the script body contains no migration or seeder call' '0' "$executable_calls"

printf '\n%s checks, %s failure(s)\n' "$checks" "$failures"

[ "$failures" -eq 0 ]
