<?php

/**
 * Self-test for tests/support/DatabaseTargetGuard.php.
 *
 * The guard decides whether a destructive PostgreSQL test may run. Its decisions
 * are exercised here with the connection injected, so nothing in this file — and
 * nothing the guard does while being tested — opens a socket, and no
 * `migrate:fresh`, `DROP TABLE` or any other statement is ever executed. The
 * probe is a stub that records what it was asked and answers what the case needs.
 *
 * That is deliberate: the guard must be provably safe *before* it is trusted to
 * gate a destructive test, and the destructive test cannot be run at all while no
 * database here grants the required DDL rights.
 *
 * Run it directly — it is not a PHPUnit case, and PHPUnit does not collect this
 * directory:
 *
 *   php tests/shell/database-target-guard.test.php
 *
 * Exit status: 0 = every check passed, 1 = at least one check failed.
 */

require __DIR__.'/../support/DatabaseTargetGuard.php';

$checks = 0;
$failures = 0;

function check(string $description, string $expected, string $actual): void
{
    global $checks, $failures;

    $checks++;

    if ($expected === $actual) {
        printf("  ok    %s\n", $description);

        return;
    }

    $failures++;
    printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n", $description, $expected, $actual);
}

function check_contains(string $description, string $needle, string $haystack): void
{
    global $checks, $failures;

    $checks++;

    if (str_contains($haystack, $needle)) {
        printf("  ok    %s\n", $description);

        return;
    }

    $failures++;
    printf("  FAIL  %s\n        expected to contain: %s\n        actual: %s\n", $description, $needle, $haystack);
}

/**
 * A probe that records every database it is asked about and answers as the case
 * requires — the connection itself is what it stands in for.
 *
 * @param  list<string>  $calls
 */
function probe(array &$calls, string $identity, bool $ddl, bool $reachable = true, string $error = 'SQLSTATE[08006] connection refused'): callable
{
    return static function (string $database) use (&$calls, $identity, $ddl, $reachable, $error): array {
        $calls[] = $database;

        if (! $reachable) {
            return ['ok' => false, 'identity' => null, 'ddl' => null, 'error' => $error];
        }

        return ['ok' => true, 'identity' => $identity, 'ddl' => $ddl, 'error' => null];
    };
}

/** @return array{0: array<string, mixed>, 1: list<string>} the verdict and the probe's calls */
function verdict(string $driver, string $database, string $identity = 'scheduling_system_testing', bool $ddl = true, bool $reachable = true, bool $requireDdl = true): array
{
    $calls = [];
    $result = DatabaseTargetGuard::verdict($driver, $database, probe($calls, $identity, $ddl, $reachable), $requireDdl);

    return [$result, $calls];
}

printf("database-target-guard self-test\n\n");

// ---------------------------------------------------------------------
// Refusals that must happen from the name and driver alone
// ---------------------------------------------------------------------
printf("refused driver\n");
[$result, $calls] = verdict('sqlite', 'scheduling_system_testing');
check('refused', 'false', json_encode($result['ok']));
check_contains('names the required driver', 'must be "pgsql"', $result['problems'][0] ?? '');
check('probe never called', '0', (string) count($calls));
check('reports that nothing was probed', 'false', json_encode($result['probed']));

printf("\nmissing driver\n");
[$result, $calls] = verdict('', 'scheduling_system_testing');
check('refused', 'false', json_encode($result['ok']));
check_contains('says nothing was set', 'nothing at all', $result['problems'][0] ?? '');
check('probe never called', '0', (string) count($calls));

printf("\nsource database\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system');
check('refused', 'false', json_encode($result['ok']));
check_contains('names the source database', 'never a test target', $result['problems'][0] ?? '');
check('probe never called — no connection to the source', '0', (string) count($calls));

printf("\nmigration rehearsal database\n");
[$result, $calls] = verdict('pgsql', 'its_migration_rehearsal_20261014');
check('refused', 'false', json_encode($result['ok']));
check_contains('names the rehearsal database', 'never a test target', $result['problems'][0] ?? '');
check('probe never called', '0', (string) count($calls));

printf("\nunapproved database\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_walkthrough');
check('refused', 'false', json_encode($result['ok']));
check_contains('lists what is permitted', 'scheduling_system_testing', $result['problems'][0] ?? '');
check('probe never called', '0', (string) count($calls));

printf("\nmissing database identity\n");
[$result, $calls] = verdict('pgsql', '   ');
check('refused', 'false', json_encode($result['ok']));
check_contains('says the target cannot be identified', 'cannot be identified', $result['problems'][0] ?? '');
check('probe never called', '0', (string) count($calls));

// ---------------------------------------------------------------------
// Refusals that require the probe, and fail closed when it cannot confirm
// ---------------------------------------------------------------------
printf("\nunreachable target\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', reachable: false);
check('refused', 'false', json_encode($result['ok']));
check_contains('reports that it could not be verified', 'could not be reached or verified', $result['problems'][0] ?? '');
check('the target was probed once', '1', (string) count($calls));

printf("\nserver reports no identity\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', identity: '');
check('refused', 'false', json_encode($result['ok']));
check_contains('says it could not be positively verified', 'no database identity', $result['problems'][0] ?? '');

printf("\nserver reports a different database\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', identity: 'scheduling_system');
check('refused', 'false', json_encode($result['ok']));
check_contains('says which database the connection is really on', 'the connection is open on "scheduling_system"', $result['problems'][0] ?? '');
check('records the identity it saw', 'scheduling_system', (string) $result['identity']);

printf("\nno DDL rights\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', ddl: false);
check('refused', 'false', json_encode($result['ok']));
check_contains('explains the missing rights', 'cannot create objects in schema public', $result['problems'][0] ?? '');
check('records that DDL was refused', 'false', json_encode($result['ddl']));
check_contains('does not offer to work around it', 'does not work around this', $result['problems'][0] ?? '');

// ---------------------------------------------------------------------
// The permitted case
// ---------------------------------------------------------------------
printf("\nallowlisted, verified target\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing');
check('permitted', 'true', json_encode($result['ok']));
check('no problems', '0', (string) count($result['problems']));
check('identity is the server-reported one', 'scheduling_system_testing', (string) $result['identity']);
check('the probe was reached', 'true', json_encode($result['probed']));
check('the probe was asked about the requested database', 'scheduling_system_testing', $calls[0] ?? '');
check('exactly one probe', '1', (string) count($calls));

// ---------------------------------------------------------------------
// Environment disagreement
// ---------------------------------------------------------------------
printf("\nenvironment sources that disagree\n");
$problems = DatabaseTargetGuard::environmentProblems(
    ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'a'],
    ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'b'],
    static fn (string $key): string|false => false
);
check('one problem reported', '1', (string) count($problems));
check_contains('names the key that disagrees', 'DB_DATABASE', $problems[0] ?? '');
check_contains('refuses rather than guessing', 'refuses rather than guess', $problems[0] ?? '');

$agreed = DatabaseTargetGuard::environmentProblems(
    ['DB_DATABASE' => 'a'],
    ['DB_DATABASE' => 'a'],
    static fn (string $key): string => 'a'
);
check('identical values across sources pass', '0', (string) count($agreed));

$empty = DatabaseTargetGuard::environmentProblems(
    ['DB_DATABASE' => 'a'],
    ['DB_DATABASE' => ''],
    static fn (string $key): string|false => false
);
check('an empty source holds no opinion', '0', (string) count($empty));

$unset = DatabaseTargetGuard::environmentProblems(
    [],
    [],
    static fn (string $key): string|false => false
);
check('nothing set anywhere is not a disagreement', '0', (string) count($unset));

// ---------------------------------------------------------------------
// Which selections need DDL rights
// ---------------------------------------------------------------------
printf("\nselection awareness\n");
$destructive = DatabaseTargetGuard::DESTRUCTIVE_TESTS[0];
check_contains('the destructive test is the migrations-not-applied case', 'it_fails_when_the_department_migrations_have_not_been_applied', $destructive);
check('no filter selects the whole suite, so DDL is required', 'true', json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit'])));
$destructiveMethod = 'it_fails_when_the_department_migrations_have_not_been_applied';
check('a filter matching the destructive test requires DDL', 'true', json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', '--filter', $destructiveMethod])));
check('the equals form of the filter is understood', 'true', json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', "--filter=$destructiveMethod"])));

// A fully qualified class name pasted as a filter is not a usable pattern, because
// its backslashes are not escaped. Such a filter is treated as though it selects
// the destructive test rather than as though it selects nothing.
check(
    'an unreadable filter is treated as selecting the destructive test',
    'true',
    json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', '--filter', $destructive]))
);
check(
    'an unreadable exclude filter does not lift the requirement',
    'true',
    json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', '--exclude-filter', $destructive]))
);
check('a usable pattern is recognised as usable', 'true', json_encode(DatabaseTargetGuard::patternIsUsable($destructiveMethod)));
check('an unescaped class name is recognised as unusable', 'false', json_encode(DatabaseTargetGuard::patternIsUsable($destructive)));
check(
    'a filter matching another test needs no DDL',
    'false',
    json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', '--filter', 'it_passes_when_the_classification_is_complete_and_consistent']))
);
check(
    'an exclude filter takes the destructive test back out',
    'false',
    json_encode(DatabaseTargetGuard::selectionRequiresDdl([
        'phpunit',
        '--filter', 'DepartmentPreflightTest',
        '--exclude-filter', 'it_fails_when_the_department_migrations_have_not_been_applied',
    ]))
);
check('listing runs no test body, so no DDL is required', 'false', json_encode(DatabaseTargetGuard::selectionRequiresDdl(['phpunit', '--list-tests'])));
check('an unreadable selection is treated as the dangerous one', 'true', json_encode(DatabaseTargetGuard::selectionRequiresDdl([])));

// ---------------------------------------------------------------------
// The DDL rule follows the selection, and nothing else is relaxed with it
// ---------------------------------------------------------------------
printf("\nthe DDL rule follows the selection\n");
[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', ddl: false, requireDdl: false);
check('a non-destructive selection is permitted without DDL rights', 'true', json_encode($result['ok']));
check('its identity is still verified', 'scheduling_system_testing', (string) $result['identity']);
check('it records that DDL was not required', 'false', json_encode($result['ddl_required']));
check('it still records the DDL answer it saw', 'false', json_encode($result['ddl']));

[$result, $calls] = verdict('pgsql', 'scheduling_system_testing', identity: 'scheduling_system', ddl: false, requireDdl: false);
check('identity is still enforced when the DDL rule is lifted', 'false', json_encode($result['ok']));
check_contains('and says what it is really connected to', 'the connection is open on "scheduling_system"', $result['problems'][0] ?? '');

[$result, $calls] = verdict('pgsql', 'scheduling_system', ddl: false, requireDdl: false);
check('the source database is still refused when the DDL rule is lifted', 'false', json_encode($result['ok']));
check('and is still never connected to', '0', (string) count($calls));

[$result, $calls] = verdict('sqlite', 'scheduling_system_testing', ddl: true, requireDdl: false);
check('the driver rule is still enforced when the DDL rule is lifted', 'false', json_encode($result['ok']));

[$result, $calls] = verdict('pgsql', 'scheduling_system_testing');
check('the strict rule is the default for a caller that says nothing', 'true', json_encode($result['ddl_required']));

// ---------------------------------------------------------------------
// Invariants read off the class itself
// ---------------------------------------------------------------------
printf("\ninvariants\n");
check('the source database is refused by name', 'true', json_encode(in_array('scheduling_system', DatabaseTargetGuard::REFUSED_DATABASES, true)));
check('the rehearsal database is refused by name', 'true', json_encode(in_array('its_migration_rehearsal_20261014', DatabaseTargetGuard::REFUSED_DATABASES, true)));
check('the source database is not allowlisted', 'false', json_encode(in_array('scheduling_system', DatabaseTargetGuard::ALLOWED_DATABASES, true)));
check('the rehearsal database is not allowlisted', 'false', json_encode(in_array('its_migration_rehearsal_20261014', DatabaseTargetGuard::ALLOWED_DATABASES, true)));

$source = (string) file_get_contents(__DIR__.'/../support/DatabaseTargetGuard.php');
check('the guard opens no connection of its own', '0', (string) preg_match_all('/new\s+PDO/', $source));
check('the guard runs no statement of its own', '0', (string) preg_match_all('/->(query|exec|prepare|statement)\s*\(/', $source));
check('the identity probe is the read-only identity query', 'select current_database()', DatabaseTargetGuard::IDENTITY_SQL);
check_contains('the DDL probe asks about the CREATE privilege', "'CREATE'", DatabaseTargetGuard::DDL_SQL);

// The privilege the DDL probe names is the string literal 'CREATE', not a
// statement: strip quoted literals before looking for statement keywords, or the
// guard's own question about permissions would read as a permission change.
$probeSql = (string) preg_replace("/'[^']*'/", "''", DatabaseTargetGuard::IDENTITY_SQL.' '.DatabaseTargetGuard::DDL_SQL);
check(
    'both probes are read-only statements',
    '0',
    (string) preg_match_all('/\b(drop|truncate|alter|delete|insert|update|create)\b/i', $probeSql)
);

printf("\n%s checks, %s failure(s)\n", $checks, $failures);

exit($failures === 0 ? 0 : 1);
