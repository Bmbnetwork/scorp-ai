<?php

declare(strict_types=1);

use App\Config\Env;

$rootDir = dirname(__DIR__);

fwrite(STDOUT, "SCORP AI — Phase 2 stateless setup & readiness check\n");
fwrite(STDOUT, str_repeat('=', 54) . "\n\n");

$hardFail = false;
$warnCount = 0;

function pass(string $msg): void
{
    fwrite(STDOUT, "[PASS] {$msg}\n");
}

function warn(string $msg): void
{
    global $warnCount;
    $warnCount++;
    fwrite(STDOUT, "[WARN] {$msg}\n");
}

function fail(string $msg): void
{
    global $hardFail;
    $hardFail = true;
    fwrite(STDERR, "[FAIL] {$msg}\n");
}

function section(string $title): void
{
    fwrite(STDOUT, "\n-- {$title} " . str_repeat('-', max(0, 50 - mb_strlen($title))) . "\n");
}

section('PHP runtime');

if (PHP_VERSION_ID < 80000) {
    fail('PHP 8.0+ is required. Found ' . PHP_VERSION . '.');
} else {
    pass('PHP ' . PHP_VERSION . ' (SAPI: ' . PHP_SAPI . ').');
}

$requiredExtensions = ['curl', 'openssl', 'json', 'mbstring'];

foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        pass("Extension {$ext} loaded.");
    } else {
        fail("Required extension {$ext} is MISSING. Enable it in php.ini and restart.");
    }
}

section('Application bootstrap');

$bootstrapPath = $rootDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!is_readable($bootstrapPath)) {
    fail('Missing app/config/bootstrap.php. The project files are incomplete.');
    fwrite(STDERR, "\nSetup cannot continue.\n");
    exit(1);
}

try {
    $env = require $bootstrapPath;
} catch (Throwable $exception) {
    fail('Bootstrap failed to load: ' . $exception->getMessage());
    fwrite(STDERR, "\nSetup cannot continue.\n");
    exit(1);
}

$keyClasses = [
    \App\Services\InvestigationWorkflow::class,
    \App\Services\PayPalService::class,
    \App\Services\GeminiService::class,
    \App\Services\InvestigationEngine::class,
    \App\Services\FallbackExplainer::class,
    \App\Services\AuditLogger::class,
    \App\Services\EnvironmentCheckService::class,
    \App\Support\RateLimiter::class,
    \App\Support\ReferenceValidator::class,
    \App\Support\OrderNormalizer::class,
];

$classesOk = true;
foreach ($keyClasses as $class) {
    if (!class_exists($class)) {
        fail("Expected class not loadable: {$class}");
        $classesOk = false;
    }
}

if ($classesOk) {
    pass('Core stateless workflow classes load.');
}

section('Configuration (.env)');

$envPath = $rootDir . DIRECTORY_SEPARATOR . '.env';
$examplePath = $rootDir . DIRECTORY_SEPARATOR . '.env.example';

if (!is_readable($envPath)) {
    if (is_readable($examplePath)) {
        $copied = @copy($examplePath, $envPath);
        if ($copied && is_readable($envPath)) {
            warn('.env was missing; copied from .env.example. You MUST fill in real keys before investigating.');
            $env = Env::fromFile($envPath);
        } else {
            fail('Could not create .env from .env.example. Create .env manually.');
        }
    } else {
        fail('.env is missing and .env.example is not present. Create .env manually.');
    }
} else {
    pass('.env present.');
    $env = Env::fromFile($envPath);
}

$requiredKeys = [
    'PAYPAL_ENV',
    'PAYPAL_CLIENT_ID',
    'PAYPAL_SECRET',
    'GEMINI_API_KEY',
    'GEMINI_MODEL',
];

foreach ($requiredKeys as $key) {
    $present = $env->get($key) !== null && $env->get($key) !== '';
    if ($present) {
        pass("{$key} configured.");
    } else {
        fail("{$key} is missing or empty in .env.");
    }
}

$auditEnabledRaw = $env->raw('AUDIT_LOG_ENABLED');
$auditEnabled = $auditEnabledRaw === null
    ? true
    : (filter_var(trim($auditEnabledRaw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);

if ($auditEnabled) {
    pass('Metadata-only audit logging enabled.');
} else {
    warn('Audit logging disabled by AUDIT_LOG_ENABLED=false.');
}

section('Outbound connectivity (PayPal Sandbox + Gemini)');

try {
    $checker = new \App\Services\EnvironmentCheckService($env);
    $result = $checker->run(true);

    $byId = [];
    foreach ($result['checks'] as $check) {
        $byId[$check['id']] = $check;
    }

    foreach (['outbound_paypal_sandbox_api', 'outbound_gemini_api'] as $cid) {
        $status = $byId[$cid]['status'] ?? 'fail';
        if ($status === 'pass') {
            pass("Connectivity check {$cid}: pass.");
        } else {
            warn("Connectivity check {$cid}: {$status}. Outbound HTTPS may be blocked by firewall/AV/proxy/ISP.");
        }
    }

    if (($result['overall'] ?? '') === 'fail') {
        warn('Overall environment check reported fail. Open /env-check.php locally for details.');
    }
} catch (Throwable $exception) {
    warn('Could not run the environment check: ' . $exception->getMessage());
}

section('Automated test suite');

$testFile = $rootDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'run-tests.php';

if (is_readable($testFile)) {
    $testCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile);
    passthru($testCmd, $testStatus);

    if ($testStatus === 0) {
        pass('Test suite passed (0 failures).');
    } else {
        fail('Test suite reported failures (exit ' . $testStatus . '). Fix before deploying.');
    }
} else {
    warn('tests/run-tests.php not found; skipping.');
}

section('Gemini model availability (optional)');

if (in_array('--gemini', $argv, true)) {
    $listCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rootDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'gemini-list-models.php');
    passthru($listCmd, $listStatus);
    if ($listStatus === 0) {
        pass('Gemini model list retrieved. Set GEMINI_MODEL to one of the names above.');
    } else {
        warn('Could not list Gemini models (network/quota/key). The app will fall back honestly when needed.');
    }
} else {
    warn('Skipped. Re-run with --gemini to list models: php bin/setup.php --gemini');
}

section('Summary');

if ($hardFail) {
    fail('One or more hard checks failed. Resolve the [FAIL] lines above before continuing.');
} else {
    pass('No hard failures. Optional warnings above do not block investigations.');
}

fwrite(STDOUT, "\nNext steps:\n");
fwrite(STDOUT, "  1. Fill real keys in .env (never commit it). Revoke any key that was ever pasted/shared.\n");
fwrite(STDOUT, "  2. Re-run: php bin/setup.php   (add --gemini to list models)\n");
fwrite(STDOUT, "  3. Run tests: php tests/run-tests.php\n");
fwrite(STDOUT, "  4. Smoke test: php bin/paypal-retrieve.php REAL_SANDBOX_ORDER_ID\n");
fwrite(STDOUT, "  5. Serve locally: php -S localhost:8000 -t public   ->  http://localhost:8000\n");
fwrite(STDOUT, "\nThis Phase 2 public build is stateless: no database, no history, no saved reports, no print/export endpoints.\n");
fwrite(STDOUT, "Audit log (metadata-only): var/audit/audit-YYYYMMDD.log  (git-ignored; delete old files freely)\n");

exit($hardFail ? 1 : 0);