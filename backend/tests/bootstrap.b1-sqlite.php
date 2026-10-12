<?php

/**
 * Bootstrap for the temporary SQLite-in-memory run of the two B1 tests.
 *
 * This exists to make one thing impossible: a run that silently resolves to
 * PostgreSQL. PostgreSQL is refused here, before PHPUnit loads a single test,
 * so the only database this run can touch is an in-process SQLite database that
 * is created with the process and discarded with it.
 *
 * It also refuses when the pdo_sqlite driver is absent, because otherwise that
 * shows up as a confusing "could not find driver" after the suite has started.
 *
 * It deliberately does not boot the application: it reads the same environment
 * values Laravel's config/database.php reads (DB_CONNECTION decides `default`,
 * DB_DATABASE is the sqlite database, DB_URL could redirect the connection), so
 * it cannot itself open a connection to anything. It prints no credentials —
 * only the values it checked.
 *
 * Run the two B1 tests with it like this, from backend/ (the pdo_sqlite driver
 * is loaded for that process only, and the key is generated in memory):
 *
 *   APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" \
 *   DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
 *   php -d extension=pdo_sqlite vendor/bin/phpunit -c phpunit.b1-sqlite.xml \
 *     --do-not-cache-result --filter 'it_fails_when_a_listed_minor_is_stored_as_a_major|it_passes_when_a_listed_minor_is_stored_as_a_minor'
 */

require __DIR__.'/../vendor/autoload.php';

/** PHPUnit's <env> writes to $_ENV/$_SERVER/putenv; read all three. */
$read = static function (string $key): ?string {
    foreach ([$_SERVER, $_ENV] as $source) {
        if (array_key_exists($key, $source) && $source[$key] !== '') {
            return (string) $source[$key];
        }
    }

    $value = getenv($key);

    return $value === false || $value === '' ? null : (string) $value;
};

$problems = [];

$connection = $read('DB_CONNECTION');
$database = $read('DB_DATABASE');
$url = $read('DB_URL');

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $problems[] = 'the pdo_sqlite PDO driver is not loaded (invoke php with -d extension=pdo_sqlite)';
}

if ($connection !== 'sqlite') {
    $problems[] = 'DB_CONNECTION resolves to '.var_export($connection, true).', not "sqlite"';
}

if ($database !== ':memory:') {
    $problems[] = 'DB_DATABASE resolves to '.var_export($database, true).', not ":memory:"';
}

if ($url !== null) {
    $problems[] = 'DB_URL is set, which could redirect the connection away from the in-memory database';
}

if ($problems !== []) {
    fwrite(STDERR, "REFUSING TO RUN TESTS:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, "  - $problem\n");
    }

    fwrite(STDERR, "No test was loaded and no database was contacted.\n");

    exit(1);
}

fwrite(STDOUT, "Guard OK: sqlite :memory: (in-process and disposable; PostgreSQL refused)\n");
