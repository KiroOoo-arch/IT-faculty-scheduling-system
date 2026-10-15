<?php

/**
 * The target guard for tests that write to PostgreSQL.
 *
 * `DepartmentPreflightTest::it_fails_when_the_department_migrations_have_not_been_applied`
 * is genuinely destructive: `RefreshDatabase` runs `migrate:fresh` (drops every
 * table in the target database) and the test body then executes
 * `DROP TABLE IF EXISTS ... CASCADE`. Run it against the wrong database and it
 * drops that database's tables. There is no SQLite-style bootstrap protecting a
 * PostgreSQL run, and the PHPUnit environment elements that name a database are
 * not forced, so an exported shell value or a cached configuration can redirect
 * the connection.
 *
 * This class is the decision, with the connection injected, so the decision is
 * testable without touching PostgreSQL:
 *
 *   - nothing that can reach a database runs before every name-based refusal has
 *     passed. The order matters more than the wording: a refused name must never
 *     open a connection, and a probe that is never called cannot drop anything;
 *   - the allowlist is positive and explicit. A database is permitted because it
 *     is named here, not because nothing forbade it;
 *   - the source database and the migration rehearsal database are refused by
 *     name, before the allowlist is consulted, so no future allowlist edit can
 *     admit them by accident;
 *   - the identity the *server* reports must equal the database that was
 *     requested. Comparing the request against itself proves nothing, which is
 *     exactly the failure mode a cached or overridden configuration produces;
 *   - the role must hold the DDL rights the test needs. Without them the run
 *     would die halfway through `migrate:fresh` with a permission error, which
 *     reads like a broken test instead of a database that was never a valid
 *     target. The guard refuses instead, and never works around it.
 *
 * The class performs no writes of its own, opens no connection of its own, and
 * knows no credentials: the only SQL it carries is the two read-only probes.
 */
final class DatabaseTargetGuard
{
    /** The only driver a destructive PostgreSQL test may use. */
    public const EXPECTED_DRIVER = 'pgsql';

    /**
     * Databases a destructive test may run against: disposable test databases the
     * project's own PHPUnit configurations already name. `phpunit.xml` names the
     * first, `phpunit.phase2_test.xml` the second.
     */
    public const ALLOWED_DATABASES = [
        'scheduling_system_testing',
        'scheduling_system_phase2_test_20261011',
    ];

    /**
     * Databases that are never a test target, whatever any other list says: the
     * source database, and the migration rehearsal database.
     */
    public const REFUSED_DATABASES = [
        'scheduling_system',
        'its_migration_rehearsal_20261014',
    ];

    /**
     * The tests in this project's suites that destroy what they run against:
     * `RefreshDatabase` plus the test body's own `DROP TABLE ... CASCADE`. A
     * selection that reaches any of these is the only selection that needs DDL
     * rights, which is what lets the ordinary PostgreSQL suite keep running
     * against a database whose role has no DDL rights at all.
     */
    public const DESTRUCTIVE_TESTS = [
        'Tests\\Feature\\DepartmentPreflightTest::it_fails_when_the_department_migrations_have_not_been_applied',
    ];

    /** PHPUnit's options that narrow a selection, as the runner spells them. */
    public const SELECTION_OPTIONS = ['--filter', '--exclude-filter'];

    /** PHPUnit's options that print the suite's shape without executing a test. */
    public const LISTING_OPTIONS = ['--list-tests', '--list-suites', '--list-groups'];

    /** Environment keys that must not disagree across the environment sources. */
    public const AGREEMENT_KEYS = ['DB_CONNECTION', 'DB_DATABASE', 'DB_URL'];

    /** Read-only: the database name as the server itself reports it. */
    public const IDENTITY_SQL = 'select current_database()';

    /**
     * Read-only: whether the connected role could create objects in schema
     * `public` — the right `migrate:fresh` and the test's `DROP TABLE` both need.
     */
    public const DDL_SQL = "select has_schema_privilege(current_user, 'public', 'CREATE')";

    /**
     * Every value each environment source holds for a key, duplicates removed.
     * An absent or empty entry holds no opinion and is left out, so an unset
     * `DB_DATABASE` cannot masquerade as agreement.
     *
     * @return list<string>
     */
    public static function valuesFor(string $key, array $server, array $env, callable $getenv): array
    {
        $values = [];

        foreach ([$server, $env] as $bag) {
            if (array_key_exists($key, $bag) && $bag[$key] !== null && (string) $bag[$key] !== '') {
                $values[] = (string) $bag[$key];
            }
        }

        $value = $getenv($key);

        if ($value !== false && $value !== null && (string) $value !== '') {
            $values[] = (string) $value;
        }

        return array_values(array_unique($values));
    }

    /**
     * The keys whose sources disagree. PHPUnit applies its `<env force="true">`
     * elements to `$_ENV` and `putenv()` but leaves an already-set `$_SERVER`
     * entry alone, so the three sources can describe different databases — and
     * which one Laravel's `env()` trusts is not something this guard should have
     * to predict. Disagreement is therefore a refusal.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function environmentProblems(array $server, array $env, callable $getenv, array $keys = self::AGREEMENT_KEYS): array
    {
        $problems = [];

        foreach ($keys as $key) {
            $values = self::valuesFor($key, $server, $env, $getenv);

            if (count($values) > 1) {
                $problems[] = sprintf(
                    '%s resolves to more than one value across the environment sources (%s); the guard refuses '
                        .'rather than guess which one Laravel will read.',
                    $key,
                    implode(', ', $values)
                );
            }
        }

        return $problems;
    }

    /**
     * The value PHPUnit was given for an option, or null when it was not given at
     * all. Like PHPUnit's own parser, the last occurrence wins, and both the
     * `--option=value` and `--option value` forms are understood.
     */
    public static function optionValue(array $argv, string $option): ?string
    {
        $value = null;

        foreach (array_values($argv) as $index => $candidate) {
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
    }

    /**
     * Would this pattern select that test? Derived exactly as
     * `PHPUnit\Runner\Filter\NameFilterIterator::prepareFilter()` derives it — a
     * pattern that begins with an alphanumeric character, or that is not a valid
     * delimited pattern at all, is wrapped in `{..}` and matched
     * case-insensitively; anything else is used as given — and matched against
     * `ClassName::methodName`, the way PHPUnit matches it.
     */
    public static function selects(string $pattern, string $test): bool
    {
        return @preg_match(self::asRegularExpression($pattern), $test) === 1;
    }

    /**
     * Whether a filter can be interpreted as a pattern at all. A filter carrying
     * unescaped backslashes — a fully qualified class name pasted as-is, for
     * instance — is not a valid pattern and matches nothing.
     */
    public static function patternIsUsable(string $pattern): bool
    {
        // Compiling is what is being asked about, so the answer is "not false",
        // not the truthiness of the match count: a valid pattern that matches an
        // empty subject returns 0, which is perfectly usable.
        return @preg_match(self::asRegularExpression($pattern), '') !== false;
    }

    /** The pattern as PHPUnit would compile it: wrapped and case-insensitive when it
     * begins with an alphanumeric character, or when it is not a delimited pattern
     * at all. */
    private static function asRegularExpression(string $pattern): string
    {
        $first = substr($pattern, 0, 1);

        if (($first !== '' && preg_match('/[a-zA-Z0-9]/', $first) === 1) || @preg_match($pattern, '') === false) {
            return sprintf('{%s}i', $pattern);
        }

        return $pattern;
    }

    /**
     * Whether this invocation would run a destructive test, and therefore needs a
     * target whose role holds DDL rights.
     *
     * Without any filter PHPUnit selects the whole suite, so every destructive
     * test in it is selected; a filter selects one only when it matches, and an
     * exclude filter takes it back out. When the arguments are not available at
     * all the answer is "yes": an unreadable selection is treated as the
     * dangerous one.
     *
     * Listing is the one exception, and it is not a weakening of the rule: a
     * listing loads test classes and prints their names but runs no test body, so
     * neither RefreshDatabase nor a `DROP TABLE` can execute. Tools that only need
     * the suite's shape — the documentation drift checker asks PHPUnit for its
     * test listing — must therefore keep working on a database whose role has no
     * DDL rights. Identity and allowlist checks still apply in that mode.
     *
     * @param  list<string>  $tests
     */
    public static function selectionRequiresDdl(array $argv, array $tests = self::DESTRUCTIVE_TESTS): bool
    {
        if ($argv === []) {
            return true;
        }

        foreach (self::LISTING_OPTIONS as $listingOption) {
            if (self::optionValue($argv, $listingOption) !== null) {
                return false;
            }
        }

        $filter = self::optionValue($argv, '--filter');
        $excludeFilter = self::optionValue($argv, '--exclude-filter');

        // A filter this guard cannot interpret is treated as though it selects the
        // destructive test, and an exclude filter it cannot interpret is treated as
        // though it excludes nothing. Both directions fail closed: an unreadable
        // selection can only produce a refusal, never a destructive run.
        $filterUnreadable = $filter !== null && ! self::patternIsUsable($filter);
        $excludeUnreadable = $excludeFilter !== null && ! self::patternIsUsable($excludeFilter);

        foreach ($tests as $test) {
            $selected = ($filter === null || $filterUnreadable || self::selects($filter, $test))
                && ! ($excludeFilter !== null && ! $excludeUnreadable && self::selects($excludeFilter, $test));

            if ($selected) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether this target may be used.
     *
     * The probe is only reached once every name-based check has passed, so for a
     * refused target it is never called — and a probe that is never called cannot
     * touch a database.
     *
     * @param  callable(string): array{ok: bool, identity?: ?string, ddl?: ?bool, error?: ?string}  $probe
     * @param  bool  $requireDdl  whether the selection needs a role that can drop and create: true for a
     *                            destructive test, false for the rest of the suite. The default is true, so
     *                            a caller that says nothing gets the strict rule.
     * @return array{ok: bool, identity: ?string, ddl: ?bool, problems: list<string>, probed: bool, ddl_required: bool}
     */
    public static function verdict(string $driver, string $database, callable $probe, bool $requireDdl = true): array
    {
        $problems = [];

        if ($driver !== self::EXPECTED_DRIVER) {
            $problems[] = sprintf(
                'the connection driver must be "%s"; it resolves to %s.',
                self::EXPECTED_DRIVER,
                $driver === '' ? 'nothing at all' : '"'.$driver.'"'
            );
        }

        $requested = trim($database);

        if ($requested === '') {
            $problems[] = 'the database name is empty, so the target cannot be identified.';
        } elseif (in_array($requested, self::REFUSED_DATABASES, true)) {
            $problems[] = sprintf(
                '"%s" is never a test target: it is the source database or the migration rehearsal database.',
                $requested
            );
        } elseif (! in_array($requested, self::ALLOWED_DATABASES, true)) {
            $problems[] = sprintf(
                '"%s" is not a disposable test database this guard permits (%s), so it is refused.',
                $requested,
                implode(', ', self::ALLOWED_DATABASES)
            );
        }

        if ($problems !== []) {
            return ['ok' => false, 'identity' => null, 'ddl' => null, 'problems' => $problems, 'probed' => false, 'ddl_required' => $requireDdl];
        }

        $result = $probe($requested);

        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'identity' => null,
                'ddl' => null,
                'problems' => ['the target could not be reached or verified: '.($result['error'] ?? 'the probe failed without saying why')],
                'probed' => true,
                'ddl_required' => $requireDdl,
            ];
        }

        $identity = trim((string) ($result['identity'] ?? ''));

        if ($identity === '') {
            return [
                'ok' => false,
                'identity' => null,
                'ddl' => null,
                'problems' => ['the connection reported no database identity, so the target could not be positively verified.'],
                'probed' => true,
                'ddl_required' => $requireDdl,
            ];
        }

        if ($identity !== $requested) {
            return [
                'ok' => false,
                'identity' => $identity,
                'ddl' => null,
                'problems' => [sprintf(
                    'the connection is open on "%s" while the requested database is "%s". Something overrode or '
                        .'cached the configuration; the guard refuses.',
                    $identity,
                    $requested
                )],
                'probed' => true,
                'ddl_required' => $requireDdl,
            ];
        }

        if ($requireDdl && ($result['ddl'] ?? false) !== true) {
            return [
                'ok' => false,
                'identity' => $identity,
                'ddl' => false,
                'problems' => [sprintf(
                    'the role connected to "%s" cannot create objects in schema public, so the destructive setup '
                        .'(migrate:fresh, DROP TABLE) could not run. Provision a disposable database whose role has '
                        .'those rights; the guard does not work around this by using a different credential.',
                    $identity
                )],
                'probed' => true,
                'ddl_required' => true,
            ];
        }

        return [
            'ok' => true,
            'identity' => $identity,
            'ddl' => ($result['ddl'] ?? null) === true,
            'problems' => [],
            'probed' => true,
            'ddl_required' => $requireDdl,
        ];
    }
}
