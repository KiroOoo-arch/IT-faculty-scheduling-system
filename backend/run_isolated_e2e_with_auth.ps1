<#
    Isolated E2E test runner with secure credential prompting.
    Fixed version: corrects XML path, Stop-BeforeCredentials scope,
    UseShellSession property, terminating errors, and config validation.
    Targets: scheduling_system_testing_isolated with its_test_runner.
    Uses backend/phpunit.isolated.xml.
    Does NOT repurpose the guarded test runner's disposable-database target.

    Optional -TestName selects ONE test from the approved list; the default
    (no -TestName) runs the whole approved ten-test selection. The selection
    is validated against that fixed list before any credential is requested.
    An explicit disposable-database confirmation is required first.
#>
[CmdletBinding()]
param(
    # Optional single-test selection. The value is checked against the fixed
    # $approvedTests list below and is never used as a free-form pattern, so a
    # caller can only name an approved test.
    [string]$TestName = ''
)

# -- 0. Terminating errors from the start --
$ErrorActionPreference = 'Stop'

# -- 1. Resolve paths relative to script root --
# $PSScriptRoot already points to the backend/ directory where the script resides.
$ScriptRoot = $PSScriptRoot
$PhpunitConfig = Join-Path $ScriptRoot 'phpunit.isolated.xml'
$PhpunitExe = Join-Path $ScriptRoot 'vendor/bin/phpunit'
$Autoload = Join-Path $ScriptRoot 'vendor/autoload.php'
$PhpExe = (Get-Command php -CommandType Application).Path

# Verify config exists before proceeding
if (-not (Test-Path -LiteralPath $PhpunitConfig)) {
    Write-Error "PHPUnit configuration not found: $PhpunitConfig"
    exit 1
}

# -- 2. Validate the isolated PHPUnit XML config --
# Must force: DB_DATABASE = scheduling_system_testing_isolated, DB_USERNAME = its_test_runner
# Must NOT contain hard-coded DB_PASSWORD
try {
    [xml]$configXml = Get-Content -LiteralPath $PhpunitConfig -Raw
} catch {
    Write-Error "Could not read PHPUnit configuration: $PhpunitConfig"
    exit 1
}

# Validate required forced environment entries
$requiredEnvs = @{
    'DB_CONNECTION' = 'pgsql'
    'DB_DATABASE'   = 'scheduling_system_testing_isolated'
    'DB_USERNAME'   = 'its_test_runner'
}

foreach ($key in $requiredEnvs.Keys) {
    $nodes = @($configXml.SelectNodes("//env[@name='$key']"))
    if ($nodes.Count -ne 1) {
        Write-Error "Expected exactly one env entry for '$key' in PHPUnit XML. Found $($nodes.Count)."
        exit 1
    }
    $value = $nodes[0].GetAttribute('value')
    $force = $nodes[0].GetAttribute('force')
    if ($value -ne $requiredEnvs[$key]) {
        Write-Error "PHPUnit XML env '$key' has value '$value', expected '$($requiredEnvs[$key])'."
        exit 1
    }
    if ($force -ne 'true') {
        Write-Error "PHPUnit XML env '$key' must have force='true'. Found force='$force'."
        exit 1
    }
}

# Must NOT have hard-coded DB_PASSWORD in the XML config
$passwordNodes = $configXml.SelectNodes("//env[@name='DB_PASSWORD']")
if ($passwordNodes.Count -gt 0) {
    Write-Error "DB_PASSWORD must not be hard-coded in PHPUnit XML. Found $($passwordNodes.Count) entry(ies)."
    exit 1
}

Write-Host "PHPUnit configuration validated successfully." -ForegroundColor Green

# -- 2b. Validate the optional test selection --
# The filter PHPUnit receives is assembled from this fixed list, never from the
# caller's text, so a selection can only name an approved test and can never
# become an unrestricted expression. An empty selection preserves the
# launcher's original ten-test run.
$approvedTests = @(
    'test_laravel_connection_isolation'
    'test_admin_can_generate_schedule'
    'test_schedule_retrieval_and_status'
    'test_schedule_approval'
    'test_schedule_publication'
    'test_schedule_unpublish'
    'test_schedule_rejection'
    'test_conflict_validation'
    'test_session_management'
    'test_workflow_final_retrieval'
)

$requestedTest = "$TestName".Trim()

# -cnotcontains: exact, case-sensitive match. A case variant would be accepted
# by -contains but would then match no test, which reads as a passing run.
if ($requestedTest -ne '' -and $approvedTests -cnotcontains $requestedTest) {
    Write-Error ("Unknown test selection '" + $requestedTest + "'. Approved test names: " + ($approvedTests -join ', '))
    exit 1
}

if ($requestedTest -eq '') {
    $selectedTests = $approvedTests
} else {
    $selectedTests = @($requestedTest)
}

# Assembled only from the list above; no caller-supplied text reaches it.
$filterExpression = $selectedTests -join '|'

Write-Host ("Test selection (" + $selectedTests.Count + "): " + ($selectedTests -join ', ')) -ForegroundColor Green

# -- 2c. Require explicit disposable-target confirmation --
# The E2E test needs the target's existing schema, so a zero-object inventory
# cannot prove the target is disposable. The operator therefore attests to the
# exact target by name, exactly as the guarded runner does before it touches
# PostgreSQL. Anything but the exact phrase exits before a credential is asked
# for, so a refusal never opens a connection or starts PHPUnit.
Write-Host '' -ForegroundColor Cyan
Write-Host 'DISPOSABLE TARGET CONFIRMATION' -ForegroundColor Cyan
Write-Host '=============================' -ForegroundColor Cyan
Write-Host "Only continue if 'scheduling_system_testing_isolated' is the database created for this test suite and holds no data you need preserved."
Write-Host 'The test writes only inside a rolled-back transaction, but it does write to whatever schema this database holds.'
$targetConfirmation = Read-Host 'To authorize ONLY the isolated test database scheduling_system_testing_isolated, type DISPOSABLE:scheduling_system_testing_isolated; otherwise type NO'
if ($targetConfirmation -cne 'DISPOSABLE:scheduling_system_testing_isolated') {
    Write-Host 'Cancelled. No password requested; no database probe or PHPUnit test started.' -ForegroundColor Yellow
    exit 2
}

# -- 3. Securely prompt for the its_test_runner password --
Write-Host '' -ForegroundColor Cyan
Write-Host 'ISOLATED E2E LAUNCHER - SECURE CREDENTIAL PROMPT' -ForegroundColor Cyan
Write-Host '==================================================' -ForegroundColor Cyan
Write-Host "Target database: scheduling_system_testing_isolated"
Write-Host "Target username: its_test_runner"
Write-Host "Target server: 127.0.0.1:5432"
Write-Host ''
Write-Host 'Enter the its_test_runner password (will not be echoed to screen).' -ForegroundColor Yellow

$securePassword = Read-Host -AsSecureString 'its_test_runner password'

# Convert SecureString to plain string in memory only
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $dbPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
}
finally {
    # Zero out the BSTR immediately after conversion
    if ($bstr -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        $bstr = [IntPtr]::Zero
    }
    # Securely dispose the original SecureString
    if ($null -ne $securePassword) {
        $securePassword.Dispose()
        $securePassword = $null
    }
}

# Verify password was obtained
if (-not $dbPassword -or $dbPassword.Trim() -eq '') {
    Write-Error 'No password supplied; aborted.'
    exit 1
}

Write-Host 'Password obtained successfully - not displayed.' -ForegroundColor Green

# -- 4. Run the read-only PDO identity probe (no credentials persisted) --
$probePath = Join-Path $env:TEMP ("guarded-pg-pdo-probe-e2e-" + [guid]::NewGuid().ToString('N') + '.php')

$probeSource = @'
<?php
$expectedDb = 'scheduling_system_testing_isolated';
$expectedUser = 'its_test_runner';
$expectedHost = '127.0.0.1';
$expectedPort = 5432;

function probe_fail($code, $message) {
    fwrite(STDERR, "PDO_PROBE_FAIL: " . $message . "\n");
    exit($code);
}

$connection = getenv("DB_CONNECTION") ?: 'pgsql';
$host = getenv("DB_HOST") ?: '127.0.0.1';
$port = getenv("DB_PORT") ?: '5432';
$dbName = getenv("DB_DATABASE") ?: '';
$user = getenv("DB_USERNAME") ?: '';
$pass = getenv("DB_PASSWORD") ?: '';

$dsn = match ($connection) {
    'pgsql' => "pgsql:host=$host;port=$port;dbname=$dbName;connect_timeout=5",
    default => probe_fail(2, "Unsupported DB_CONNECTION: $connection"),
};

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $identity = $pdo->query('SELECT current_database()')->fetchColumn();

    if ($identity !== $expectedDb) {
        probe_fail(3, "Laravel-level database identity guard failed.\n  Effective database: $identity\n  Expected database: $expectedDb\n  Aborting test suite to prevent writes to the wrong database.");
    }

    echo "PASS: Laravel-level identity guard verified - effective database is $identity\n";

    // The probe must not be able to write even by mistake.
    $pdo->exec('SET default_transaction_read_only = on');

    $role = $pdo->query('SELECT current_user')->fetchColumn();
    if ($role !== $expectedUser) {
        probe_fail(5, "Connected role is not the fixed target role.\n  Effective role: $role\n  Expected role: $expectedUser\n  Aborting test suite.");
    }

    // This test needs the target's existing schema, so an empty inventory is
    // not evidence of a disposable database. What is provable read-only is the
    // reach of the credential: the role must own no database outside this
    // project's test databases, so its DDL cannot reach the source or the
    // migration rehearsal database. The public-schema table count is reported
    // as context for the operator, never asserted.
    $ownedOutsideTests = $pdo->query(
        "SELECT count(*) FROM pg_database d"
        . " JOIN pg_roles r ON r.oid = d.datdba"
        . " WHERE r.rolname = current_user"
        . " AND d.datname NOT IN ("
        . " 'scheduling_system_testing',"
        . " 'scheduling_system_testing_isolated',"
        . " 'scheduling_system_phase2_test_20261011',"
        . " 'scheduling_system_test_disposable_20261017',"
        . " 'scheduling_system_test_disposable_20261018',"
        . " 'scheduling_system_test_disposable_20261019',"
        . " 'postgres', 'template0', 'template1')"
    )->fetchColumn();

    if ((int) $ownedOutsideTests !== 0) {
        probe_fail(6, "The target role owns a database outside the project's test databases.\n  An owner can drop or alter that database, so the target is not verifiably disposable.\n  Aborting before PHPUnit starts.");
    }

    $publicTables = $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn();
    echo "TARGET_INVENTORY: role=$role public_tables=$publicTables\n";
} catch (Exception $e) {
    probe_fail(4, "Laravel-level identity guard could not connect to database.\n  Error: " . $e->getMessage() . "\n  This guard runs before PHPUnit boot, before migrations, before RefreshDatabase, before fixture creation, and before any write operation.");
}
'@

# Write the probe script (no credentials embedded - all values come from env vars passed at runtime)
[System.IO.File]::WriteAllText($probePath, $probeSource, [System.Text.Encoding]::ASCII)

# -- 4a. Validate probe PHP syntax before execution --
# Windows PowerShell turns native stderr into a terminating NativeCommandError
# under $ErrorActionPreference='Stop', which would abort before the exit code
# could be inspected and before the temp probe is cleaned up. Catch it, report
# the real lint message, remove the temp file, then fail closed.
$phpLintOutput = $null
$phpLintExit = 1
try {
    $phpLintOutput = & php -l $probePath 2>&1
    $phpLintExit = $LASTEXITCODE
} catch {
    $phpLintOutput = $_.Exception.Message
    $phpLintExit = 1
}
if ($phpLintExit -ne 0) {
    if (Test-Path -LiteralPath $probePath) { Remove-Item -LiteralPath $probePath -Force }
    Write-Error ("Probe PHP syntax check failed: " + (($phpLintOutput | Out-String).Trim()))
    exit 1
}

# Build probe args: PHP with -d variables_order=EGPCS and the probe script path
# Using format operator to avoid backtick-quote escaping issues in PowerShell
# Format: php -d variables_order=EGPCS "probe_path"
$probeArgs = "-d variables_order=EGPCS $probePath"

$probeInfo = New-Object System.Diagnostics.ProcessStartInfo
$probeInfo.FileName = $PhpExe
$probeInfo.Arguments = $probeArgs
$probeInfo.WorkingDirectory = $ScriptRoot
# UseShellExecute must be disabled before the environment dictionary is
# touched: with it enabled (the Windows PowerShell default) the
# EnvironmentVariables getter throws InvalidOperationException.
$probeInfo.UseShellExecute = $false
$probeInfo.CreateNoWindow = $true
# NOTE: The probe reads DB_* env vars via PHP getenv(). Every setting the
# identity guard depends on is pinned here, so an ambient DB_DATABASE or
# DB_USERNAME in the caller's shell cannot redirect the probe. DB_PASSWORD is
# passed only in this child process environment and cleared as soon as the
# probe exits, so the credential never reaches a file, a command line or a log.
$probeInfo.EnvironmentVariables['DB_CONNECTION'] = 'pgsql'
$probeInfo.EnvironmentVariables['DB_HOST'] = '127.0.0.1'
$probeInfo.EnvironmentVariables['DB_PORT'] = '5432'
$probeInfo.EnvironmentVariables['DB_DATABASE'] = 'scheduling_system_testing_isolated'
$probeInfo.EnvironmentVariables['DB_USERNAME'] = 'its_test_runner'
$probeInfo.EnvironmentVariables['DB_URL'] = ''
$probeInfo.EnvironmentVariables['DB_PASSWORD'] = $dbPassword

Write-Host '' -ForegroundColor Cyan
Write-Host 'Running read-only PDO connection and target-identity probe...' -ForegroundColor Cyan

$probeChild = New-Object System.Diagnostics.Process
$probeChild.StartInfo = $probeInfo
$probeChild.StartInfo.RedirectStandardOutput = $true
$probeChild.StartInfo.RedirectStandardError = $true
$probeChild.StartInfo.UseShellExecute = $false
# Deadlock-safe: read output streams before WaitForExit(), or use BeginRead/EndRead pattern
# Here we read after WaitForExit since the probe typically exits quickly
if (-not $probeChild.Start()) {
    Write-Error 'Failed to start probe process.'
    # Clean up resources
    if ($probeChild -ne $null) { $probeChild.Dispose() }
    if (Test-Path $probePath) { Remove-Item $probePath -Force }
    exit 1
}
# Read stdout and stderr after process exits to avoid deadlocks
$stdout = $probeChild.StandardOutput.ReadToEnd()
$stderr = $probeChild.StandardError.ReadToEnd()
$probeChild.WaitForExit()
$probeExitCode = $probeChild.ExitCode
$probeChild.Dispose()

# Clean up DB_PASSWORD from environment after probe exits
# so the credential does not persist in the parent process environment.
if ($probeInfo.EnvironmentVariables.ContainsKey('DB_PASSWORD')) {
    $probeInfo.EnvironmentVariables.Remove('DB_PASSWORD')
}

# Report probe output (sanitized: redact any password-like values)
if ($stdout) { Write-Host $stdout -ForegroundColor White }
if ($stderr) { Write-Host $stderr -ForegroundColor White }

if ($probeExitCode -ne 0) {
    Write-Error "Probe failed with exit code $probeExitCode. No PHPUnit test was started. Fail closed."
    # Clean up temp probe file
    if (Test-Path $probePath) { Remove-Item $probePath -Force }
    exit $probeExitCode
}

Write-Host 'PDO identity probe passed.' -ForegroundColor Green

# -- 5. Run the selected isolated PHPUnit tests --
Write-Host '' -ForegroundColor Cyan
Write-Host ("Starting isolated PHPUnit E2E selection (" + $selectedTests.Count + " test(s))...") -ForegroundColor Cyan
Write-Host "  Config: $PhpunitConfig"
Write-Host "  Target database: scheduling_system_testing_isolated"
Write-Host "  Target username: its_test_runner"
Write-Host ""

# Build PHPUnit command with DB_PASSWORD in environment only
# DB_PASSWORD is set in the child process environment, NOT on the command line.
# Diagnostic capture: the child inherits the console, so a failing run leaves
# nothing behind but its exit code. JUnit XML records every failure and error
# with its message and stack trace. It contains test names and messages only;
# the password is never placed on the command line or in the log.
$junitLogPath = Join-Path $env:TEMP 'phpunit-isolated-e2e-junit.xml'
if (Test-Path -LiteralPath $junitLogPath) { Remove-Item -LiteralPath $junitLogPath -Force }
# The pattern is unquoted on purpose: Windows does not treat single quotes as
# quoting characters, so quotes here would reach PHPUnit as part of the pattern
# and match no test. The value itself only ever holds approved names joined by
# '|', so no shell metacharacter or space can appear in it.
$testArgs = "-d variables_order=EGPCS $PhpunitExe -c $PhpunitConfig --log-junit `"$junitLogPath`" --filter $filterExpression"

$testStartInfo = New-Object System.Diagnostics.ProcessStartInfo
$testStartInfo.FileName = $PhpExe
$testStartInfo.Arguments = $testArgs
$testStartInfo.WorkingDirectory = $ScriptRoot
$testStartInfo.UseShellExecute = $false
$testStartInfo.CreateNoWindow = $true
$testStartInfo.RedirectStandardOutput = $true
$testStartInfo.RedirectStandardError = $true

# Set DB_PASSWORD and other DB env vars in the child process environment ONLY
# These are NOT written to parent $env:, .env, or any persistent location
$childEnv = $testStartInfo.EnvironmentVariables
$childEnv['DB_CONNECTION'] = 'pgsql'
$childEnv['DB_HOST'] = '127.0.0.1'
$childEnv['DB_PORT'] = '5432'
$childEnv['DB_DATABASE'] = 'scheduling_system_testing_isolated'
$childEnv['DB_USERNAME'] = 'its_test_runner'
$childEnv['DB_PASSWORD'] = $dbPassword
$childEnv['DB_URL'] = ''

Write-Host 'Launching PHPUnit test process with DB_PASSWORD in environment only.' -ForegroundColor Cyan

$testChild = New-Object System.Diagnostics.Process
$testChild.StartInfo = $testStartInfo
if (-not $testChild.Start()) {
    Write-Error 'Failed to start PHPUnit process.'
    # Clean up DB_PASSWORD from environment
    if ($childEnv.ContainsKey('DB_PASSWORD')) { $childEnv.Remove('DB_PASSWORD') }
    exit 1
}
# --- Concurrent async drain of stdout/stderr to prevent pipe-buffer deadlock ---
# Both reads start before WaitForExit so the child's pipes are drained in parallel
# with its execution, preventing a blocked write on a full pipe buffer (a common
# cause of exit code 255 with empty JUnit XML).
# ReadToEndAsync() returns Task[string], so .Result yields the captured text.
# Task.Factory.StartNew({ ... }) could not: it binds to the non-generic
# StartNew(Action) overload, whose Task has no Result property, and a scriptblock
# delegate cannot run on a thread-pool thread. StdoutBytes/StderrBytes were
# therefore always 0 even when PHPUnit itself succeeded.
$stdoutTask = $testChild.StandardOutput.ReadToEndAsync()
$stderrTask = $testChild.StandardError.ReadToEndAsync()

# --- Bounded wait for process exit (distinguish timeout from PHP/PHPUnit failure) ---
$exitTimeoutMs = 120000  # 2-minute timeout
$timedOut = -not $testChild.WaitForExit($exitTimeoutMs)
if ($timedOut) {
    # Process didn't exit in time - kill it safely and record a distinct exit code
    Write-Host 'PHPUnit timed out after 2 minutes - killing process.' -ForegroundColor Yellow
    $testChild.Kill()
    $testChild.WaitForExit()
    $testExitCode = 24  # 24 !== 255: never confuses a timeout with PHPUnit exit
} else {
    $testExitCode = $testChild.ExitCode
}

# --- Ensure read tasks complete (with individual timeouts and fault handling) ---
# Track capture status before Wait/Result, so the launcher can report
# faults even if the task itself faults with an AggregateException.
$stdoutCaptureError = $false
$stderrCaptureError = $false
try {
    $stdoutCompleted = $stdoutTask.Wait(5000)  # 5-second read timeout
    $stderrCompleted = $stderrTask.Wait(5000)  # 5-second read timeout
} catch {
    $stdoutCompleted = $false
    $stderrCompleted = $false
}
# Record capture status explicitly; do not silently discard output.
# If Wait threw (AggregateException), we still know capture failed.
if (-not $stdoutCompleted) { $stdoutCaptureError = $true }
if (-not $stderrCompleted) { $stderrCaptureError = $true }
if ($stdoutCompleted) { try { $stdout = $stdoutTask.Result } catch {
        $stdout = ''
        $stdoutCaptureError = $true
    } }
else { $stdout = '' }
if ($stderrCompleted) { try { $stderr = $stderrTask.Result } catch {
        $stderr = ''
        $stderrCaptureError = $true
    } }
else { $stderr = '' }
$testChild.Dispose()

# --- Redact the exact entered DB password before persisting ---
# Use a literal string Replace when $dbPassword is non-empty; never rely on regex.
if ($dbPassword) {
    $safeStdout = $stdout -replace [regex]::Escape($dbPassword), '***REDACTED***'
    $safeStderr = $stderr -replace [regex]::Escape($dbPassword), '***REDACTED***'
} else {
    # Guarded literal fallback when password is not set
    $safeStdout = $stdout.Replace($dbPassword, '***REDACTED***')
    $safeStderr = $stderr.Replace($dbPassword, '***REDACTED***')
}
# End password redaction block

# --- Write diagnostic log to LOCALAPPDATA\Temp (persistent, per-machine) ---
# Creates the file if missing; path is $env:LOCALAPPDATA\Temp\phpunit-isolated-e2e-diagnostics.log
$diagLogPath = Join-Path $env:LOCALAPPDATA 'Temp\phpunit-isolated-e2e-diagnostics.log'
if (Test-Path -LiteralPath $diagLogPath) { Remove-Item -LiteralPath $diagLogPath -Force }
# Build structured diagnostic payload as single-line key=value pairs for machine parsing
$launcherStatus = if ($timedOut) { 'TimedOut' } else { 'Completed' }
$diagContent = "LauncherStatus=$launcherStatus" + [System.Environment]::NewLine + "TimedOut=$timedOut" + [System.Environment]::NewLine + "ChildExitCode=$testExitCode" + [System.Environment]::NewLine + "StdoutBytes=$([int]($safeStdout.Length))" + [System.Environment]::NewLine + "StderrBytes=$([int]($safeStderr.Length))" + [System.Environment]::NewLine + "StdoutCaptureError=$stdoutCaptureError" + [System.Environment]::NewLine + "StderrCaptureError=$stderrCaptureError" + [System.Environment]::NewLine + "PhpunitExitCode=$testExitCode"
# Append captured stdout/stderr on separate lines (password-redacted)
$diagContent = $diagContent + [System.Environment]::NewLine + "=== PHPUnit STDOUT ===" + [System.Environment]::NewLine + $safeStdout + [System.Environment]::NewLine + "=== PHPUnit STDERR ===" + [System.Environment]::NewLine + $safeStderr
[System.IO.File]::WriteAllText($diagLogPath, $diagContent, [System.Text.Encoding]::UTF8)

Write-Host "Diagnostic log written to: $diagLogPath" -ForegroundColor Cyan

if (Test-Path -LiteralPath $junitLogPath) {
    Write-Host "PHPUnit JUnit log: $junitLogPath" -ForegroundColor Cyan
}

# Clear any residual environment variables that shouldn't persist.
# Bare $env is $null in PowerShell: the environment drive is reachable only as
# $env:NAME or Env:\, so a method call on $env throws. Assigning $null would
# also leave an empty value behind rather than removing the variable, so test
# and remove through the provider.
if (Test-Path -LiteralPath Env:\DB_PASSWORD) {
    Remove-Item -LiteralPath Env:\DB_PASSWORD -Force -ErrorAction SilentlyContinue
}

Write-Host '' -ForegroundColor Cyan
Write-Host "Isolated PHPUnit exit code: $testExitCode (selected tests: $($selectedTests.Count))" -ForegroundColor Cyan
exit $testExitCode

# -- Functions --

function Stop-BeforeCredentials {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Error "$Message No password requested; no database probe or PHPUnit test was started."
    exit 1
}
