<?php

/**
 * Bootstrap for the in-memory SQLite run of the SQLite-compatible part of
 * DepartmentPreflightTest: 24 of that class's 25 tests, against a database that
 * is created with the process and discarded with it.
 *
 * It makes two things impossible.
 *
 * 1. A run that silently resolves to PostgreSQL. PostgreSQL is refused here,
 *    before PHPUnit loads a single test, so the only database this run can touch
 *    is an in-process SQLite database. It also refuses when the pdo_sqlite
 *    driver is absent, because otherwise that shows up as a confusing "could not
 *    find driver" after the suite has started.
 *
 *    The three sources are checked separately and must agree, because they can
 *    disagree: PHPUnit applies this file's <env> elements to $_ENV and to
 *    putenv() but leaves an already-set $_SERVER entry alone, so a shell that
 *    exports DB_CONNECTION=pgsql while the configuration forces sqlite reads
 *    "sqlite" from $_SERVER and "pgsql" from the other two. Whichever source
 *    Laravel's env() ends up trusting, at least one of them describes
 *    PostgreSQL, and this refuses rather than guess which one wins.
 *
 * 2. A run that reaches the one PostgreSQL-only test in that class. PHPUnit
 *    cannot name individual tests in the XML file, so that file selects the
 *    whole class and the command line narrows it; an unfiltered
 *    `phpunit -c phpunit.b1-sqlite.xml` would therefore select all 25 tests, and
 *    it_fails_when_the_department_migrations_have_not_been_applied executes
 *    "DROP TABLE ... CASCADE", which is not SQLite syntax. This refuses that
 *    invocation, and any other whose selection includes that test, before a
 *    test is loaded, instead of letting it surface as a syntax error at the end
 *    of a run that would otherwise look like a real result.
 *
 * It deliberately does not boot the application: it only reads the environment
 * values Laravel's config/database.php reads (DB_CONNECTION decides `default`,
 * DB_DATABASE is the sqlite database, DB_URL could redirect the connection), so
 * it cannot itself open a connection to anything. It prints no credentials —
 * only the values it checked.
 *
 * Run the 24 SQLite-compatible tests with it like this, from backend/ (the
 * pdo_sqlite driver is loaded for that process only, and the key is generated in
 * memory):
 *
 *   APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" \
 *   DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
 *   php -d extension=pdo_sqlite vendor/bin/phpunit -c phpunit.b1-sqlite.xml \
 *     --do-not-cache-result \
 *     --exclude-filter 'it_fails_when_the_department_migrations_have_not_been_applied'
 */

require __DIR__.'/../vendor/autoload.php';

/**
 * What every source holds for a key, as `$_SERVER='x', $_ENV='y', getenv()='z'`.
 *
 * An absent or empty entry is left out: it holds no opinion. What is kept is
 * every non-empty value, because any one of them can be the value the
 * application ends up using, and this guard refuses on disagreement rather than
 * pick a winner.
 */
$describe = static function (string $key): string {
    $parts = [];

    foreach (['_SERVER' => $_SERVER, '_ENV' => $_ENV] as $name => $bag) {
        if (array_key_exists($key, $bag) && $bag[$key] !== null && (string) $bag[$key] !== '') {
            $parts[] = $name.'='.var_export((string) $bag[$key], true);
        }
    }

    $value = getenv($key);

    if ($value !== false && $value !== '') {
        $parts[] = 'getenv()='.var_export($value, true);
    }

    return $parts === [] ? '(not set)' : implode(', ', $parts);
};

/** Every distinct non-empty value any source holds for a key. */
$values = static function (string $key): array {
    $found = [];

    foreach ([$_SERVER, $_ENV] as $bag) {
        if (array_key_exists($key, $bag) && $bag[$key] !== null && (string) $bag[$key] !== '') {
            $found[] = (string) $bag[$key];
        }
    }

    $environment = getenv($key);

    if ($environment !== false && $environment !== '') {
        $found[] = $environment;
    }

    return array_values(array_unique($found));
};

/**
 * The value PHPUnit was given for a command-line option, or null when the option
 * was not given at all. Like PHPUnit's own parser (TextUI\CliArguments\Builder),
 * the last occurrence of an option is the one that counts.
 */
$argument = static function (string $option): ?string {
    $argv = $_SERVER['argv'] ?? null;

    if (! is_array($argv)) {
        return null;
    }

    $value = null;

    foreach ($argv as $index => $candidate) {
        if (! is_string($candidate)) {
            continue;
        }

        if ($candidate === $option) {
            $next = $argv[$index + 1] ?? null;
            $value = is_string($next) ? $next : '';
        } elseif (str_starts_with($candidate, $option.'=')) {
            $value = substr($candidate, strlen($option) + 1);
        }
    }

    return $value;
};

/**
 * Would this pattern select that test? The regular expression is derived exactly
 * as PHPUnit\Runner\Filter\NameFilterIterator::prepareFilter() derives it, and
 * it is matched against "ClassName::methodName": a pattern that begins with an
 * alphanumeric character, or that is not a valid delimited pattern at all, is
 * wrapped in {..} and matched case-insensitively; anything else is used as
 * given. Mirroring PHPUnit rather than approximating it is what makes the check
 * trustworthy: it answers "would this invocation reach the PostgreSQL-only
 * test?" the same way the run itself would.
 */
$selects = static function (string $pattern, string $test): bool {
    $regularExpression = $pattern;

    if (preg_match('/[a-zA-Z0-9]/', substr($pattern, 0, 1)) === 1 || @preg_match($pattern, '') === false) {
        $regularExpression = sprintf('{%s}i', $pattern);
    }

    return @preg_match($regularExpression, $test) === 1;
};

/** The only test in DepartmentPreflightTest that needs PostgreSQL. */
$postgresOnlyTest = 'Tests\\Feature\\DepartmentPreflightTest::it_fails_when_the_department_migrations_have_not_been_applied';

$problems = [];

$connection = $values('DB_CONNECTION');
$database = $values('DB_DATABASE');
$url = $values('DB_URL');

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $problems[] = 'the pdo_sqlite PDO driver is not loaded (invoke php with -d extension=pdo_sqlite)';
}

if ($connection !== ['sqlite']) {
    $problems[] = 'DB_CONNECTION must be "sqlite" in every source; it resolves to '
        .$describe('DB_CONNECTION');
}

if ($database !== [':memory:']) {
    $problems[] = 'DB_DATABASE must be ":memory:" in every source; it resolves to '
        .$describe('DB_DATABASE');
}

if ($url !== []) {
    $problems[] = 'DB_URL is set, which could redirect the connection away from the in-memory database; it '
        .'resolves to '.$describe('DB_URL');
}

$filter = $argument('--filter');
$excludeFilter = $argument('--exclude-filter');

// Without --filter, PHPUnit selects the whole class, so only an --exclude-filter
// that matches the PostgreSQL-only test can take it back out of the selection.
// With --filter, that test is selected only when the pattern matches it.
$selectsPostgresOnlyTest = ($filter === null || $selects($filter, $postgresOnlyTest))
    && ! ($excludeFilter !== null && $selects($excludeFilter, $postgresOnlyTest));

if ($selectsPostgresOnlyTest) {
    $problems[] = sprintf(
        'the selection would run %s, which needs PostgreSQL: it executes '
            .'"DROP TABLE ... CASCADE", which is not SQLite syntax. Exclude it as the command above does: '
            .'--exclude-filter \'it_fails_when_the_department_migrations_have_not_been_applied\'',
        $postgresOnlyTest
    );
}

if ($problems !== []) {
    fwrite(STDERR, "REFUSING TO RUN TESTS:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, "  - $problem\n");
    }

    fwrite(STDERR, "No test was loaded and no database was contacted.\n");

    exit(1);
}

fwrite(STDOUT, "Guard OK: sqlite :memory: (in-process and disposable; PostgreSQL refused), "
    .'PostgreSQL-only test excluded (--filter='.var_export($filter, true)
    .', --exclude-filter='.var_export($excludeFilter, true).")\n");
