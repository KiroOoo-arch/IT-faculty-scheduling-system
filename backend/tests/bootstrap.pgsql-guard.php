<?php

/**
 * Bootstrap for the PostgreSQL test suite, gated by DatabaseTargetGuard.
 *
 * The ordinary PHPUnit suite (phpunit.xml) includes the full Feature directory,
 * which contains DepartmentPreflightTest::it_fails_when_the_department_migrations_have_not_been_applied.
 * That test is destructive: RefreshDatabase runs migrate:fresh and the body
 * executes DROP TABLE ... CASCADE. It is therefore the only test that needs DDL
 * rights, and the guard refuses a selection that reaches it unless the connected
 * role holds those rights.
 *
 * The guard never weakens its decision. It refuses on disagreement, on a name
 * that is not on its allowlist, on the source database and the migration
 * rehearsal database, and on an identity the server reports that does not match
 * the requested database. The ordinary suite must always run with a database
 * whose role has no DDL rights.
 */

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Environment sources. PHPUnit's <env> elements are applied to $_ENV and
// putenv() but leave an already-set $_SERVER entry alone. Laravel's env() reads
// from $_ENV (which dotenv populates) and from getenv(). To decide which value
// Laravel will actually read, every non-empty value from every source is kept.
// An absent or empty entry holds no opinion and is left out.
// ---------------------------------------------------------------------------

$describe = static function (string $key): string {
    $parts = [];

    foreach (['_SERVER' => $_SERVER, '_ENV' => $_ENV] as $name => $bag) {
        if (array_key_exists($key, $bag) && $bag[$key] !== null && (string) $bag[$key] !== '') {
            $parts[] = $name . '=' . var_export((string) $bag[$key], true);
        }
    }

    $value = getenv($key);

    if ($value !== false && $value !== '') {
        $parts[] = 'getenv()=' . var_export($value, true);
    }

    return $parts === [] ? '(not set)' : implode(', ', $parts);
};

// ---------------------------------------------------------------------------
// Load .env exactly the way Laravel does: after Composer's autoloader, but
// before any framework or test class is loaded.
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/../.env';

if (file_exists($envFile)) {
    // Dotenv never overrides an existing environment variable.
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

// ---------------------------------------------------------------------------
// The guard's decision. The probe is injected, so the guard opens no connection
// of its own and runs no statement of its own. This bootstrap opens exactly one
// read-only connection and asks two questions:
//
//   select current_database()                     - identity
//   select has_schema_privilege(current_user, 'public', 'CREATE') - DDL
// ---------------------------------------------------------------------------

$driver   = $describe('DB_CONNECTION');
$database = $describe('DB_DATABASE');

// PHPUnit applies its <env> elements to $_ENV and putenv() but leaves an
// already-set $_SERVER entry alone, so the three sources can disagree. That
// disagreement is a refusal, never a guess.
$problems = [];

foreach (['DB_CONNECTION', 'DB_DATABASE', 'DB_URL'] as $key) {
    $values = [];

    foreach ([$_SERVER, $_ENV] as $bag) {
        if (array_key_exists($key, $bag) && $bag[$key] !== null && (string) $bag[$key] !== '') {
            $values[] = (string) $bag[$key];
        }
    }

    $value = getenv($key);

    if ($value !== false && $value !== '') {
        $values[] = $value;
    }

    $values = array_values(array_unique($values));

    if (count($values) > 1) {
        $problems[] = sprintf(
            '%s resolves to more than one value across the environment sources (%s); the guard refuses rather than guess which one Laravel will read.',
            $key,
            implode(', ', $values)
        );
    }
}

if ($problems !== []) {
    fwrite(STDERR, "REFUSING TO RUN TESTS:\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    fwrite(STDERR, "No test was loaded and no database was contacted.\n");

    exit(1);
}

$connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? '');
$database   = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '');

if ($connection === '' || $database === '') {
    fwrite(STDERR, "REFUSING TO RUN TESTS:\n");
    fwrite(STDERR, '  - DB_CONNECTION and DB_DATABASE must be set; they resolve to ' . $connection . ' and ' . $database . ".\n");
    fwrite(STDERR, "No test was loaded and no database was contacted.\n");

    exit(1);
}

// The guard is a plain final class in the global namespace; it is not part of
// the PSR-4 "Tests\" autoload path, so require it directly.
require __DIR__ . '/support/DatabaseTargetGuard.php';

// ---------------------------------------------------------------------------
// Read-only probes.
// ---------------------------------------------------------------------------

$probe = static function (string $database) use ($connection, &$opened): array {
    // The first call opens the connection and runs both probes. Later calls are
    // idempotent: they reuse the already-open connection.
    if ($opened === null) {
        $dsn = match ($connection) {
            'pgsql' => 'pgsql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($_ENV['DB_PORT'] ?? '5432') . ';dbname=' . $database,
            default  => throw new RuntimeException('DB_CONNECTION is not "pgsql".'),
        };

        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $identity = $pdo->query('select current_database()')->fetchColumn();
        $ddl      = $pdo->query("select has_schema_privilege(current_user, 'public', 'CREATE')")->fetchColumn();

        $opened = [$identity, (bool) $ddl];

        return ['ok' => true, 'identity' => $identity, 'ddl' => $ddl, 'error' => null];
    }

    return ['ok' => true, 'identity' => $opened[0], 'ddl' => $opened[1], 'error' => null];
};

// ---------------------------------------------------------------------------
// The guard decides. The ordinary suite (all of tests/Feature) selects the
// destructive test, so DDL rights are required; the guard refuses a full run
// against a role without them.
// ---------------------------------------------------------------------------

$requireDdl = DatabaseTargetGuard::selectionRequiresDdl($_SERVER['argv'] ?? []);

$result = DatabaseTargetGuard::verdict($connection, $database, $probe, $requireDdl);

if ($result['ok'] !== true) {
    fwrite(STDERR, 'REFUSING TO RUN TESTS (guard verdict: not permitted):' . "\n");

    foreach ($result['problems'] ?? [] as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    if ($result['probed'] === true) {
        fwrite(STDERR, '  - identity: ' . var_export($result['identity'], true) . "\n");
        fwrite(STDERR, '  - ddl: ' . var_export($result['ddl'], true) . "\n");
    }

    fwrite(STDERR, 'No test was loaded and no database was written to.' . "\n");

    exit(1);
}

fwrite(STDOUT, 'Guard OK: ' . $database . ' (identity verified; DDL required = ' . var_export($requireDdl, true) . ")\n");
