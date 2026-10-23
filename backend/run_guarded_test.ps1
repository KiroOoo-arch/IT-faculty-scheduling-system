<#
    Guarded launcher for one destructive PostgreSQL PHPUnit test.
    Compatible with Windows PowerShell 5.1 and PHP CLI.

    Password handling:
      - Hidden SecureString prompt only.
      - PtrToStringBSTR, then ZeroFreeBSTR exactly once in finally.
      - DB_PASSWORD is supplied only through each child ProcessStartInfo's
        EnvironmentVariables collection; never assigned to parent $env:.
      - Password is never written to arguments, files, or output.

    Safety:
      - Fixed target database and fixed test filter.
      - Validates launcher/config/guard/test declaration before prompting.
      - Requires explicit disposable database confirmation.
      - Runs a read-only PDO identity + catalog-inventory probe first.
      - Runs PHPUnit only if the probe confirms the exact DB/user/server, the
        password integrity digest, and zero user objects across every
        non-system schema.
#>
[CmdletBinding()]
param(
    [string]$Filter = 'it_fails_when_the_department_migrations_have_not_been_applied',
    # Loopback-only target port. The default is the original fixed target; 55432
    # reaches the separate disposable cluster created for this guarded run.
    [ValidateSet('55433')]
    [string]$TargetPort = '55433'
)

$ErrorActionPreference = 'Stop'

$TargetDatabase = 'scheduling_system_test_disposable_20261017'
$TargetRole = 'its_test_runner'
$ExpectedFilter = 'it_fails_when_the_department_migrations_have_not_been_applied'
$ExpectedHost = '127.0.0.1'
$ExpectedPort = $TargetPort
$BackendDir = $PSScriptRoot
$PhpUnit = Join-Path $BackendDir 'vendor\bin\phpunit'
$ComposerAutoload = Join-Path $BackendDir 'vendor\autoload.php'
$Config = Join-Path $BackendDir 'phpunit.pgsql-guarded.xml'
$GuardPath = Join-Path $BackendDir 'tests\support\DatabaseTargetGuard.php'
$Bootstrap = Join-Path $BackendDir 'tests\bootstrap.pgsql-guard.php'
$TestsDir = Join-Path $BackendDir 'tests'
$PhpExe = $null
$securePassword = $null
$dbPassword = $null
$bstr = [IntPtr]::Zero
$probePath = $null
$exitCode = 1

function Stop-BeforeCredentials {
    param([Parameter(Mandatory = $true)][string]$Message)
    throw "$Message No password requested; no database probe or PHPUnit test was started."
}

function New-PhpProcessStartInfo {
    param(
        [Parameter(Mandatory = $true)][string]$Arguments,
        [Parameter(Mandatory = $true)][string]$Password
    )

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName = $script:PhpExe
    $info.Arguments = $Arguments
    $info.WorkingDirectory = $script:BackendDir
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $false

    # .NET Framework / Windows PowerShell 5.1-compatible child environment.
    $envVars = $info.EnvironmentVariables
    $envVars['DB_CONNECTION'] = 'pgsql'
    $envVars['DB_HOST'] = $script:ExpectedHost
    $envVars['DB_PORT'] = $script:ExpectedPort
    $envVars['DB_DATABASE'] = $script:TargetDatabase
    $envVars['DB_USERNAME'] = $script:TargetRole
    $envVars['DB_URL'] = ''
    $envVars['DB_PASSWORD'] = $Password

    # Password-integrity expected digest: SHA-256 (lowercase hex) of the exact
    # DB_PASSWORD bytes the child PHP process receives. Windows PowerShell 5.1
    # / .NET Framework compatible. Computed from UTF-8 bytes, SHA256.ComputeHash(),
    # BitConverter to lowercase hex; components disposed/cleared in finally.
    $integrityExpected = ''
    if ($null -ne $Password) {
        try {
            $bytes = [System.Text.Encoding]::UTF8.GetBytes($Password)
            $sha = [System.Security.Cryptography.SHA256]::Create()
            try {
                $digest = $sha.ComputeHash($bytes)
            }
            finally {
                $sha.Dispose()
                [System.Array]::Clear($bytes, 0, $bytes.Length)
            }
            $integrityExpected = [BitConverter]::ToString($digest).Replace('-', '').ToLowerInvariant()
        }
        catch {
            $integrityExpected = ''
        }
    }
    $envVars['INTEGRITY_EXPECTED_HASHER'] = $integrityExpected
    return $info
}

function Invoke-PhpChild {
    param([Parameter(Mandatory = $true)][System.Diagnostics.ProcessStartInfo]$StartInfo)
    $child = New-Object System.Diagnostics.Process
    $child.StartInfo = $StartInfo
    try {
        if (-not $child.Start()) {
            throw 'Failed to start PHP child process.'
        }
        $child.WaitForExit()
        return [int]$child.ExitCode
    }
    finally {
        try { $StartInfo.EnvironmentVariables.Remove('DB_PASSWORD') } catch { }
        $child.Dispose()
    }
}

try {
    if ($Filter -cne $ExpectedFilter) {
        Stop-BeforeCredentials "This launcher is restricted to '$ExpectedFilter'."
    }

    foreach ($requiredPath in @($PhpUnit, $ComposerAutoload, $Config, $GuardPath, $Bootstrap, $TestsDir)) {
        if (-not (Test-Path -LiteralPath $requiredPath)) {
            Stop-BeforeCredentials "Required path not found: $requiredPath."
        }
    }

    $phpCommand = Get-Command php -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -eq $phpCommand -or [string]::IsNullOrWhiteSpace($phpCommand.Path)) {
        Stop-BeforeCredentials 'PHP executable was not found on PATH.'
    }
    $PhpExe = $phpCommand.Path

    try { [xml]$configXml = Get-Content -LiteralPath $Config -Raw }
    catch { Stop-BeforeCredentials "Could not parse PHPUnit configuration: $Config." }

    $requiredSettings = @{
        DB_CONNECTION = 'pgsql'
        DB_DATABASE   = $TargetDatabase
        DB_USERNAME   = $TargetRole
        DB_URL        = ''
    }
    foreach ($settingName in $requiredSettings.Keys) {
        $nodes = @($configXml.SelectNodes("//env[@name='$settingName']"))
        if ($nodes.Count -ne 1) {
            Stop-BeforeCredentials "Expected exactly one env entry for $settingName in guarded PHPUnit XML."
        }
        if ($nodes[0].GetAttribute('value') -cne $requiredSettings[$settingName] -or
            $nodes[0].GetAttribute('force') -cne 'true') {
            Stop-BeforeCredentials "Guarded PHPUnit XML must force $settingName to the approved value."
        }
    }
    if ($configXml.SelectNodes("//env[@name='DB_PASSWORD']").Count -ne 0) {
        Stop-BeforeCredentials 'DB_PASSWORD must not be hard-coded in PHPUnit XML.'
    }

    $guardText = Get-Content -LiteralPath $GuardPath -Raw
    $allowed = [regex]::Match($guardText, '(?s)\bALLOWED_DATABASES\b\s*=\s*\[(.*?)\]')
    if (-not $allowed.Success -or -not $allowed.Groups[1].Value.Contains($TargetDatabase)) {
        Stop-BeforeCredentials "Guard ALLOWED_DATABASES does not contain '$TargetDatabase'."
    }
    $refused = [regex]::Match($guardText, '(?s)\bREFUSED_DATABASES\b\s*=\s*\[(.*?)\]')
    if (-not $refused.Success) { Stop-BeforeCredentials 'Guard REFUSED_DATABASES list was not found.' }
    foreach ($protectedName in @('scheduling_system', 'its_migration_rehearsal_20261014')) {
        if (-not $refused.Groups[1].Value.Contains($protectedName)) {
            Stop-BeforeCredentials "Protected database '$protectedName' is missing from REFUSED_DATABASES."
        }
    }

    $declarationPattern = 'public\s+function\s+' + [regex]::Escape($ExpectedFilter) + '\s*\('
    $matchingFiles = @(
        Get-ChildItem -LiteralPath $TestsDir -Recurse -File -Filter '*.php' |
            Where-Object { Select-String -LiteralPath $_.FullName -Pattern $declarationPattern -Quiet }
    )
    if ($matchingFiles.Count -ne 1) {
        Stop-BeforeCredentials "Expected exactly one PHP test declaring '$ExpectedFilter'; found $($matchingFiles.Count)."
    }
    $TestFile = $matchingFiles[0].FullName

    Write-Host ''
    Write-Host 'READ-ONLY PRE-RUN REVIEW — no PDO probe or PHPUnit test has run yet.' -ForegroundColor Cyan
    Write-Host "PHP executable: $PhpExe"
    Write-Host "Working directory: $BackendDir"
    Write-Host "PHPUnit: $PhpUnit"
    Write-Host "Configuration: $Config"
    Write-Host "Fixed target database: $TargetDatabase"
    Write-Host "Fixed target role: $TargetRole"
    Write-Host "Target server: ${ExpectedHost}:$ExpectedPort"
    Write-Host "Test source: $TestFile"
    Write-Host "Test filter: $ExpectedFilter"

    Write-Host ''
    Write-Host 'Relevant test lifecycle lines:' -ForegroundColor Yellow
    $lifecyclePattern = 'RefreshDatabase|DatabaseTransactions|beginTransaction|migrate:fresh|DROP TABLE|ALTER TABLE|Schema::drop'
    foreach ($match in (Select-String -LiteralPath $TestFile -Pattern $lifecyclePattern -Context 2, 2)) {
        Write-Host "--- $($match.Path):$($match.LineNumber) ---"
        foreach ($line in $match.Context.PreContext) { Write-Host $line }
        Write-Host "> $($match.Line)"
        foreach ($line in $match.Context.PostContext) { Write-Host $line }
    }
    Write-Host ''
    Write-Host 'Relevant bootstrap lifecycle lines:' -ForegroundColor Yellow
    foreach ($match in (Select-String -LiteralPath $Bootstrap -Pattern 'migrate:fresh|RefreshDatabase|DROP TABLE|DB::statement|DB_PASSWORD|requireDdl|verdict\(' -Context 2, 2)) {
        Write-Host "--- $($match.Path):$($match.LineNumber) ---"
        foreach ($line in $match.Context.PreContext) { Write-Host $line }
        Write-Host "> $($match.Line)"
        foreach ($line in $match.Context.PostContext) { Write-Host $line }
    }

    Write-Host ''
    Write-Host 'WARNING: the target test can execute migrate:fresh and DROP TABLE ... CASCADE.' -ForegroundColor Yellow
    $confirmation = Read-Host "To authorize ONLY the empty disposable database $TargetDatabase, type DISPOSABLE:$TargetDatabase; otherwise type NO"
    if ($confirmation -cne "DISPOSABLE:$TargetDatabase") {
        Write-Host 'Cancelled. No password requested; no connection probe or PHPUnit test started.' -ForegroundColor Yellow
        exit 2
    }

    $securePassword = Read-Host -AsSecureString 'Enter the its_test_runner password for the disposable test database'
    try {
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
        $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    }
    finally {
        if ($bstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
            $bstr = [IntPtr]::Zero
        }
        if ($null -ne $securePassword) {
            $securePassword.Dispose()
            $securePassword = $null
        }
    }

    # Read-only PHP PDO probe lives in TEMP and contains no credentials.
    $probePath = Join-Path $env:TEMP ('guarded-pg-pdo-probe-' + [guid]::NewGuid().ToString('N') + '.php')
    $probeSource = @'
<?php
$expectedDb = 'scheduling_system_test_disposable_20261017';
$expectedUser = 'its_test_runner';
$expectedHost = '127.0.0.1';
$expectedPort = '__EXPECTED_PORT__';

function probe_fail($code, $message)
{
    fwrite(STDERR, 'PDO_PROBE_FAIL: ' . $message . "\n");
    exit($code);
}

$db = isset($_ENV['DB_DATABASE']) ? $_ENV['DB_DATABASE'] : (getenv('DB_DATABASE') ?: '');
$user = isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : (getenv('DB_USERNAME') ?: '');
$password = isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : (getenv('DB_PASSWORD') ?: '');
$host = isset($_ENV['DB_HOST']) ? $_ENV['DB_HOST'] : (getenv('DB_HOST') ?: '');
$port = isset($_ENV['DB_PORT']) ? $_ENV['DB_PORT'] : (getenv('DB_PORT') ?: '');
$driver = isset($_ENV['DB_CONNECTION']) ? $_ENV['DB_CONNECTION'] : (getenv('DB_CONNECTION') ?: '');

// Fixed target only. The child process receives these values from the
// launcher's ProcessStartInfo.EnvironmentVariables, never from .env, and the
// DSN below is built from the constants above, so an edited environment can
// only make this check fail; it cannot redirect the connection.
if (!is_string($db) || $db !== $expectedDb
    || !is_string($user) || $user !== $expectedUser
    || !is_string($host) || $host !== $expectedHost
    || !is_string($port) || $port !== $expectedPort
    || !is_string($driver) || $driver !== 'pgsql'
    || !is_string($password) || $password === '') {
    probe_fail(80, 'fixed target environment incomplete or incorrect.');
}

// Password-integrity check (read-only, no credentials printed/logged/persisted).
// Expected digest passed from PowerShell via the child ProcessStartInfo
// EnvironmentVariables under INTEGRITY_EXPECTED_HASHER. PHP computes
// hash('sha256', $password) from the exact DB_PASSWORD bytes received and
// compares with hash_equals(). Never prints either digest; reports only
// PASSWORD_INTEGRITY=MATCH. This check runs BEFORE any PDO connection.
$integrityExpected = '';
if (isset($_ENV['INTEGRITY_EXPECTED_HASHER'])) {
    $integrityExpected = $_ENV['INTEGRITY_EXPECTED_HASHER'];
    unset($_ENV['INTEGRITY_EXPECTED_HASHER']);
}
if (getenv('INTEGRITY_EXPECTED_HASHER') !== false) {
    putenv('INTEGRITY_EXPECTED_HASHER=');
}
if (!is_string($integrityExpected) || !preg_match('/^[0-9a-f]{64}$/', $integrityExpected)) {
    probe_fail(81, 'password integrity token is missing or malformed.');
}
if (!function_exists('hash_equals')) {
    probe_fail(81, 'hash_equals() is unavailable; refusing a non-constant-time comparison.');
}
$receivedHash = hash('sha256', $password);
if (!is_string($receivedHash) || !hash_equals($integrityExpected, $receivedHash)) {
    probe_fail(81, 'password integrity check failed.');
}
unset($integrityExpected, $receivedHash);
echo 'PASSWORD_INTEGRITY=MATCH' . PHP_EOL;

// Connect only to the fixed target. ERRMODE_EXCEPTION is deliberate: with
// silent errors a failed catalog query would return false and could be read as
// an empty database.
try {
    $pdo = new PDO(
        'pgsql:host=' . $expectedHost . ';port=' . $expectedPort . ';dbname=' . $expectedDb . ';connect_timeout=5',
        $expectedUser,
        $password,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );
} catch (Exception $e) {
    probe_fail(82, 'PDO connection to the fixed target failed.');
} catch (Throwable $e) {
    probe_fail(82, 'PDO connection to the fixed target failed.');
}

// The probe must not be able to write even by mistake.
try {
    $pdo->exec('SET default_transaction_read_only = on');
} catch (Exception $e) {
    probe_fail(83, 'could not force a read-only session.');
}

// Identify the server the connection actually reached. dbname/user in the DSN
// are requests, not proof, and a TCP proxy could forward elsewhere.
try {
    $identity = $pdo->query(
        "SELECT current_database() AS database_name,"
        . " current_user AS role_name,"
        . " COALESCE(host(inet_server_addr()), '') AS server_address,"
        . " COALESCE(inet_server_port(), 0) AS server_port,"
        . " CAST(current_setting('server_version_num') AS integer) AS server_version_num"
    )->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    probe_fail(83, 'server identity query failed.');
}
if (!is_array($identity)
    || !array_key_exists('database_name', $identity)
    || !array_key_exists('role_name', $identity)
    || !array_key_exists('server_address', $identity)
    || !array_key_exists('server_port', $identity)
    || !array_key_exists('server_version_num', $identity)) {
    probe_fail(83, 'server identity row is malformed.');
}
if ($identity['database_name'] !== $expectedDb) {
    probe_fail(82, 'connected database is not the fixed target database.');
}
if ($identity['role_name'] !== $expectedUser) {
    probe_fail(82, 'connected role is not the fixed target role.');
}
if ($identity['server_address'] !== $expectedHost) {
    probe_fail(82, 'server address is not the fixed target host.');
}
if ((string) $identity['server_port'] !== $expectedPort) {
    probe_fail(82, 'server port is not the fixed target port.');
}
$serverVersion = filter_var($identity['server_version_num'], FILTER_VALIDATE_INT);
if ($serverVersion === false || $serverVersion < 90600) {
    probe_fail(83, 'server version could not be read.');
}
echo 'TARGET_IDENTITY=MATCH server_version_num=' . $serverVersion . PHP_EOL;

// Non-system schemas: everything except information_schema and the reserved
// pg_ namespace (pg_catalog, pg_toast, pg_temp_*, pg_toast_temp_*).
try {
    $schemaRows = $pdo->query(
        "SELECT n.nspname AS schema_name FROM pg_namespace AS n"
        . " WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'"
        . " ORDER BY n.nspname"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    probe_fail(83, 'schema enumeration query failed.');
}
if (!is_array($schemaRows)) {
    probe_fail(83, 'schema enumeration returned no result set.');
}
$schemas = array();
foreach ($schemaRows as $row) {
    if (!is_array($row)
        || !array_key_exists('schema_name', $row)
        || !is_string($row['schema_name'])
        || $row['schema_name'] === '') {
        probe_fail(83, 'schema enumeration row is malformed.');
    }
    if (in_array($row['schema_name'], $schemas, true)) {
        probe_fail(83, 'schema enumeration returned a duplicate schema.');
    }
    $schemas[] = $row['schema_name'];
}

// A new PostgreSQL database always contains the public schema (PostgreSQL 17
// documentation, 5.10.2 "The Public Schema": every new database contains such
// a schema), and public is a pinned pg_namespace row, so a trustworthy
// enumeration of a real database is never empty. It can be dropped, and a
// proxy or a broken catalog read can also return no rows, so an empty list is
// treated as an untrustworthy inventory, never as "zero objects".
if (count($schemas) === 0) {
    probe_fail(83, 'schema enumeration returned no non-system schema; refusing to treat an empty inventory as an empty database.');
}

// Object classes a destructive wipe can reach or leave behind. Each class is
// reported once per schema, including zeros, so a truncated or partial result
// cannot look like an empty database.
$classes = array(
    'table',
    'partitioned table',
    'view',
    'materialized view',
    'foreign table',
    'sequence',
    'function',
    'composite type',
);
$totalObjects = 0;
$detail = '';

// Always true after the fail-closed check above; kept so a later edit cannot
// silently skip the inventory.
if (count($schemas) > 0) {
    // Catalogs verified against PostgreSQL 17 (the project's pinned server).
    // relkind 'p' needs PostgreSQL 10+, so on an older server no partitioned
    // tables exist and the class correctly reports zero. Composite types count
    // only standalone ones: a composite type's typrelid always points at its
    // pg_class entry, and relkind 'c' separates CREATE TYPE ... AS (...) from
    // the implicit row type of a table, view, materialized view or foreign
    // table (relkind r/p/v/m/f), which is not an independent object.
    // Domains, enums, ranges and array types are not
    // counted, because migrate:fresh runs db:wipe without --drop-types and
    // drop table ... cascade does not remove them. Objects owned by an
    // installed extension (pg_depend.deptype = 'e') are ignored: they are part
    // of the extension, not leftovers, and no wipe is expected to remove them.
    $inventorySql = <<<'SQL'
WITH user_schema AS (
    SELECT n.oid, n.nspname
    FROM pg_namespace AS n
    WHERE n.nspname <> 'information_schema' AND n.nspname !~ '^pg_'
),
expected_class (object_class) AS (
    VALUES ('table'), ('partitioned table'), ('view'), ('materialized view'),
           ('foreign table'), ('sequence'), ('function'), ('composite type')
),
found AS (
    SELECT c.relnamespace AS schema_oid, 'table' AS object_class, c.relname AS object_name
    FROM pg_class AS c
    WHERE c.relkind = 'r'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT c.relnamespace, 'partitioned table', c.relname
    FROM pg_class AS c
    WHERE c.relkind = 'p'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT c.relnamespace, 'view', c.relname
    FROM pg_class AS c
    WHERE c.relkind = 'v'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT c.relnamespace, 'materialized view', c.relname
    FROM pg_class AS c
    WHERE c.relkind = 'm'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT c.relnamespace, 'foreign table', c.relname
    FROM pg_class AS c
    WHERE c.relkind = 'f'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT c.relnamespace, 'sequence', c.relname
    FROM pg_class AS c
    WHERE c.relkind = 'S'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_class'::regclass
                        AND d.objid = c.oid AND d.deptype = 'e')
    UNION ALL
    SELECT p.pronamespace, 'function', p.proname
    FROM pg_proc AS p
    WHERE NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_proc'::regclass
                        AND d.objid = p.oid AND d.deptype = 'e')
    UNION ALL
    SELECT t.typnamespace, 'composite type', t.typname
    FROM pg_type AS t
    JOIN pg_class AS tc ON tc.oid = t.typrelid
    WHERE t.typtype = 'c' AND tc.relkind = 'c'
      AND NOT EXISTS (SELECT 1 FROM pg_depend AS d
                      WHERE d.classid = 'pg_type'::regclass
                        AND d.objid = t.oid AND d.deptype = 'e')
)
SELECT s.nspname AS schema_name,
       e.object_class AS object_class,
       COUNT(f.object_name) AS object_count
FROM user_schema AS s
CROSS JOIN expected_class AS e
LEFT JOIN found AS f
       ON f.schema_oid = s.oid AND f.object_class = e.object_class
GROUP BY s.nspname, e.object_class
ORDER BY s.nspname, e.object_class
SQL;

    try {
        $inventoryRows = $pdo->query($inventorySql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        probe_fail(83, 'catalog inventory query failed.');
    }
    if (!is_array($inventoryRows)) {
        probe_fail(83, 'catalog inventory returned no result set.');
    }

    $expectedRowCount = count($schemas) * count($classes);
    if (count($inventoryRows) !== $expectedRowCount) {
        probe_fail(83, 'catalog inventory is incomplete: expected ' . $expectedRowCount
            . ' schema/class rows, got ' . count($inventoryRows) . '.');
    }

    $seenPairs = array();
    $seenClasses = array();
    $parsed = array();
    foreach ($inventoryRows as $row) {
        if (!is_array($row)
            || !array_key_exists('schema_name', $row)
            || !array_key_exists('object_class', $row)
            || !array_key_exists('object_count', $row)) {
            probe_fail(83, 'catalog inventory row is malformed.');
        }
        $schemaName = $row['schema_name'];
        $objectClass = $row['object_class'];
        $rawCount = $row['object_count'];
        if (!is_string($schemaName) || !in_array($schemaName, $schemas, true)) {
            probe_fail(83, 'catalog inventory names an unknown schema.');
        }
        if (!is_string($objectClass) || !in_array($objectClass, $classes, true)) {
            probe_fail(83, 'catalog inventory names an unknown object class.');
        }
        if (is_int($rawCount)) {
            $objectCount = $rawCount;
        } elseif (is_string($rawCount) && preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/', $rawCount)) {
            $objectCount = (int) $rawCount;
        } else {
            probe_fail(83, 'catalog inventory count is malformed.');
        }
        $pairKey = $schemaName . "\0" . $objectClass;
        if (isset($seenPairs[$pairKey])) {
            probe_fail(83, 'catalog inventory repeated a schema/class row.');
        }
        $seenPairs[$pairKey] = true;
        $seenClasses[$objectClass] = true;
        $parsed[] = array('schema' => $schemaName, 'class' => $objectClass, 'count' => $objectCount);
    }
    if (count($seenClasses) !== count($classes)) {
        probe_fail(83, 'catalog inventory did not report every object class.');
    }

    foreach ($parsed as $entry) {
        if ($entry['count'] > 0) {
            $totalObjects += $entry['count'];
            $detail .= '  ' . $entry['schema'] . '.' . $entry['class'] . ': ' . $entry['count'] . "\n";
        }
    }
}

if ($totalObjects !== 0) {
    fwrite(STDERR, 'PDO_PROBE_FAIL: expected zero user objects across all non-system schemas; found '
        . $totalObjects . ".\n");
    fwrite(STDERR, $detail);
    exit(84);
}

echo 'OBJECT_INVENTORY=PASS schemas=' . count($schemas) . ' objects=0' . PHP_EOL;
unset($password, $integrityExpected, $receivedHash);
$pdo = null;
exit(0);

'@
    # The probe source is a literal here-string so PowerShell never interpolates
    # PHP's own $variables. The already-validated port is substituted afterwards.
    $probeSource = $probeSource.Replace('__EXPECTED_PORT__', $ExpectedPort)
    [System.IO.File]::WriteAllText($probePath, $probeSource, [System.Text.Encoding]::ASCII)
    $probeArgs = '-d variables_order=EGPCS "' + $probePath + '"'
    $probeInfo = New-PhpProcessStartInfo -Arguments $probeArgs -Password $dbPassword
    Write-Host ''
    Write-Host 'Running read-only PDO connection and target-identity probe...' -ForegroundColor Cyan
    $probeExit = Invoke-PhpChild -StartInfo $probeInfo
    if ($probeExit -ne 0) {
        # Fail closed: any missing result, exception, process-start failure, or
        # nonzero probe exit code prevents PHPUnit from being constructed/started.
        Write-Host "Probe failed with exit code $probeExit. No PHPUnit test was started." -ForegroundColor Red
        exit $probeExit
    }

    $testArgs = '-d variables_order=EGPCS "' + $PhpUnit + '" --configuration "' + $Config + '" --filter "' + $ExpectedFilter + '"'
    $testInfo = New-PhpProcessStartInfo -Arguments $testArgs -Password $dbPassword
    Write-Host ''
    Write-Host 'Starting only the guarded PHPUnit test against the disposable database...' -ForegroundColor Cyan
    $exitCode = Invoke-PhpChild -StartInfo $testInfo
}
catch {
    Write-Host "Guarded test launcher failed: $($_.Exception.Message)" -ForegroundColor Red
    if ($exitCode -eq 1) { $exitCode = 1 }
}
finally {
    if ($null -ne $dbPassword) { $dbPassword = $null }
    if ($null -ne $securePassword) {
        $securePassword.Dispose()
        $securePassword = $null
    }
    if ($null -ne $probePath -and (Test-Path -LiteralPath $probePath)) {
        Remove-Item -LiteralPath $probePath -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "Guarded PHPUnit exit code: $exitCode"
exit $exitCode
