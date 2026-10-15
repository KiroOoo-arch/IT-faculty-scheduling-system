#!/usr/bin/env bash
#
# Deployment gate: run departments:preflight and refuse to continue unless it
# passes.
#
# The preflight answers one question — is the reviewed subject classification
# fully deployed in *this* database? — and exits nonzero when it is not. That
# makes it the right first step of a release: the features it protects all read
# that classification, and a database that fails preflight is one where subjects
# are schedulable nowhere, or by the wrong department. Run it before
# `php artisan migrate --force`, before department activation, and before any
# other irreversible step:
#
#   DB_DATABASE=<deployment database> bash scripts/preflight-gate.sh \
#     && php artisan migrate --force
#
# It fails closed: the preflight's own exit code is reproduced by this script, so
# an `&&` chain — or any release runner that checks exit codes — stops there and
# no later step runs.
#
# What it deliberately does not do:
#
#   - it never guesses a target. DB_DATABASE must be set in the environment, and
#     the source database (SOURCE_DATABASE, default `scheduling_system`) is
#     refused, so a test or a dry run cannot point at it by accident;
#   - it prints no credentials. The connection driver and the database *name* are
#     printed; DB_PASSWORD and APP_KEY are never read, printed or exported.
#
# Exit status:
#   0 = preflight passed; the release may continue.
#   Nonzero status from departments:preflight = reproduced unchanged, so the
#       caller can see the original failure code. This may overlap with the
#       gate's own refusal codes below.
#   2 = gate refused before running preflight because the environment is
#       unusable (no artisan entry point or DB_DATABASE).
#   3 = gate refused before running preflight because the target is the source
#       database.
#
# A preflight failure may itself return 2 or 3; the output message distinguishes
# a preflight result from a gate refusal.

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
app_dir="$(cd -- "$script_dir/.." && pwd)"

php_bin="${PHP_BIN:-php}"
artisan_bin="${ARTISAN_BIN:-$app_dir/artisan}"
source_database="${SOURCE_DATABASE:-scheduling_system}"

refuse() {
    printf 'REFUSING TO RUN THE GATE: %s\n' "$1" >&2
    exit "${2:-2}"
}

block() {
    printf 'DEPLOYMENT BLOCKED: %s\n' "$1" >&2
    exit "${2:-1}"
}

[ -f "$artisan_bin" ] || refuse "no artisan entry point at $artisan_bin"

database="${DB_DATABASE:-}"
[ -n "$database" ] || refuse 'DB_DATABASE is not set, so the database this release targets is unknown. Set it explicitly.'

if [ "$database" = "$source_database" ] && [ "${PREFLIGHT_ALLOW_SOURCE:-0}" != '1' ]; then
    refuse "the target database is the source database ('$database'), which the gate is not run against. Set PREFLIGHT_ALLOW_SOURCE=1 only if that is genuinely intended." 3
fi

printf 'Preflight gate: driver=%s database=%s\n' "${DB_CONNECTION:-<unset>}" "$database"

status=0
"$php_bin" "$artisan_bin" departments:preflight --no-interaction || status=$?

[ "$status" -eq 0 ] || block "departments:preflight exited $status. The deployment stops here: no migration, activation or release step may run against a database that fails preflight." "$status"

printf 'Preflight gate: departments:preflight passed — the reviewed classification is deployed, so the release may continue.\n'
