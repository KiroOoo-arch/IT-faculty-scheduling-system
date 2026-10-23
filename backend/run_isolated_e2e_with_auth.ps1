<#
    Isolated E2E test runner with secure credential prompting.
    Fixed version: corrects XML path, Stop-BeforeCredentials scope,
    UseShellSession property, terminating errors, and config validation.
    Targets: scheduling_system_testing_isolated with its_test_runner.
    Uses backend/phpunit.isolated.xml.
    Does NOT repurpose the guarded test runner's disposable-database target.
#>

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

# -- 3. Securely prompt for the its_test_runner password --
Write-Host '' -ForegroundColor Cyan
Write-Host 'ISOLATED E2E LAUNCHER — SECURE CREDENTIAL PROMPT' -ForegroundColor Cyan
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

Write-Host 'Password obtained successfully — not displayed.' -ForegroundColor Green

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

# -- 5. Run the nine-test isolated PHPUnit suite --
Write-Host '' -ForegroundColor Cyan
Write-Host 'Starting isolated 9-test PHPUnit E2E suite...' -ForegroundColor Cyan
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
$testArgs = "-d variables_order=EGPCS $PhpunitExe -c $PhpunitConfig --log-junit `"$junitLogPath`" --filter 'test_laravel_connection_isolation|test_admin_can_generate_schedule|test_schedule_retrieval_and_status|test_schedule_approval|test_schedule_publication|test_schedule_unpublish|test_schedule_rejection|test_conflict_validation|test_session_management|test_workflow_final_retrieval'"

$testStartInfo = New-Object System.Diagnostics.ProcessStartInfo
$testStartInfo.FileName = $PhpExe
$testStartInfo.Arguments = $testArgs
$testStartInfo.WorkingDirectory = $ScriptRoot
$testStartInfo.UseShellExecute = $false
$testStartInfo.CreateNoWindow = $true

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
$testChild.WaitForExit()
$testExitCode = $testChild.ExitCode
$testChild.Dispose()

if (Test-Path -LiteralPath $junitLogPath) {
    Write-Host "PHPUnit JUnit log: $junitLogPath" -ForegroundColor Cyan
}

# -- 6. Cleanup: clear DB_PASSWORD from environment in finally block --
if ($childEnv.ContainsKey('DB_PASSWORD')) {
    $childEnv.Remove('DB_PASSWORD')
}
$dbPassword = $null

# Clear any residual environment variables that shouldn't persist.
# Bare $env is $null in PowerShell: the environment drive is reachable only as
# $env:NAME or Env:\, so a method call on $env throws. Assigning $null would
# also leave an empty value behind rather than removing the variable, so test
# and remove through the provider.
if (Test-Path -LiteralPath Env:\DB_PASSWORD) {
    Remove-Item -LiteralPath Env:\DB_PASSWORD -Force -ErrorAction SilentlyContinue
}

Write-Host '' -ForegroundColor Cyan
Write-Host "Isolated 9-test PHPUnit exit code: $testExitCode" -ForegroundColor Cyan
exit $testExitCode

# -- Functions --

function Stop-BeforeCredentials {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Error "$Message No password requested; no database probe or PHPUnit test was started."
    exit 1
}