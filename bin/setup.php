<?php

declare(strict_types=1);

use App\Config\Env;
use App\Database\Database;
use App\Database\DatabaseException;

$rootDir = dirname(__DIR__);

fwrite(STDOUT, "SCORP AI — Phase 8 local setup & readiness check\n");
fwrite(STDOUT, str_repeat('=', 50) . "\n\n");

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
    fwrite(STDOUT, "\n-- {$title} " . str_repeat('-', max(0, 46 - mb_strlen($title))) . "\n");
}

// 1. PHP version -------------------------------------------------------------
section('PHP runtime');

if (PHP_VERSION_ID < 80000) {
    fail('PHP 8.0+ is required. Found ' . PHP_VERSION . '.');
} else {
    pass('PHP ' . PHP_VERSION . ' (SAPI: ' . PHP_SAPI . ').');
}

$requiredExtensions = ['curl', 'openssl', 'json', 'mbstring'];
$optionalExtensions = ['pdo_mysql'];

foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        pass("Extension {$ext} loaded.");
    } else {
        fail("Required extension {$ext} is MISSING. Enable it in php.ini and restart.");
    }
}

foreach ($optionalExtensions as $ext) {
    if (extension_loaded($ext)) {
        pass("Extension {$ext} loaded (history available).");
    } else {
        warn("Optional extension {$ext} missing. Local history will be unavailable until enabled.");
    }
}

// 2. Bootstrap / autoload ----------------------------------------------------
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
    \App\Services\InvestigationRepository::class,
    \App\Services\ReportExporter::class,
    \App\Services\AuditLogger::class,
    \App\Database\Database::class,
];

$classesOk = true;
foreach ($keyClasses as $class) {
    if (!class_exists($class)) {
        fail("Expected class not loadable: {$class}");
        $classesOk = false;
    }
}

if ($classesOk) {
    pass('Core classes load.');
}

// 3. .env presence + required keys (presence only, never values) -------------
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

$dbName = $env->get('DB_NAME');
if ($dbName === null || $dbName === '') {
    warn('DB_NAME is empty. Local history, printable reports, and exports will be disabled until configured.');
} else {
    pass('DB_NAME configured (history enabled).');
}

// 4. Outbound connectivity (real, non-fatal) ---------------------------------
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
        warn('Overall environment check reported fail. See http://localhost:8000/env-check.php for details.');
    }
} catch (Throwable $exception) {
    warn('Could not run the environment check: ' . $exception->getMessage());
}

// 5. Database + schema (non-fatal) -------------------------------------------
section('Local history database');

$dbUsable = false;

try {
    $database = Database::fromEnv($env);
    $pdo = $database->pdo();
    pass('Database connection succeeded.');
    $dbUsable = true;

    $tables = [];
    $stmt = $pdo->query('SHOW TABLES');
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }

    $need = ['investigations', 'investigation_findings'];
    $missing = array_values(array_diff($need, $tables));

    if ($missing === []) {
        pass('Schema present (investigations + investigation_findings).');
    } else {
        warn('Missing table(s): ' . implode(', ', $missing) . '. Attempting migration via bin/db-migrate.php ...');

        $migrateCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rootDir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'db-migrate.php');
        passthru($migrateCmd, $migrateStatus);

        if ($migrateStatus === 0) {
            pass('Migration reported success.');
        } else {
            warn('Migration exited with status ' . $migrateStatus . '. Create the scorp_ai tables in phpMyAdmin (see README).');
        }
    }
} catch (DatabaseException $exception) {
    if ($exception->getErrorCode() === 'database_not_configured') {
        warn('History not configured (DB_NAME empty). Investigations still run; save/print/export disabled.');
    } else {
        // Safe: contains host/user and whether a password was sent, never the password.
        warn('Database not reachable: ' . $exception->getMessage());
        warn('Check MySQL is running in XAMPP and that DB_USER/DB_PASS in .env match phpMyAdmin.');
    }
} catch (Throwable $exception) {
    warn('Database check error: ' . $exception->getMessage());
}

// 6. Automated tests ---------------------------------------------------------
section('Automated test suite');

$testFile = $rootDir . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'run-tests.php';

if (is_readable($testFile)) {
    $testCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile);
    passthru($testCmd, $testStatus);

    if ($testStatus === 0) {
        pass('Test suite passed (0 failures).');
    } else {
        fail('Test suite reported failures (exit ' . $testStatus . '). Fix before demoing.');
    }
} else {
    warn('tests/run-tests.php not found; skipping.');
}

// 7. Optional Gemini model listing -------------------------------------------
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
    warn('Skipped. Re-run with --gemini to list models available to your key: php bin/setup.php --gemini');
}

// 8. Summary + next steps ----------------------------------------------------
section('Summary');

if ($hardFail) {
    fail('One or more hard checks failed. Resolve the [FAIL] lines above before continuing.');
} else {
    pass('No hard failures. Optional warnings above do not block investigations, but disable history/print/export if unresolved.');
}

fwrite(STDOUT, "\nNext steps:\n");
fwrite(STDOUT, "  1. Fill real keys in .env (never commit it). Revoke any key that was ever pasted/shared.\n");
fwrite(STDOUT, "  2. Start MySQL in XAMPP; ensure DB_* in .env match phpMyAdmin (DB_USER must be non-empty).\n");
fwrite(STDOUT, "  3. Re-run: php bin/setup.php   (add --gemini to list models)\n");
fwrite(STDOUT, "  4. Serve:  php -S localhost:8000 -t public   ->  http://localhost:8000\n");
fwrite(STDOUT, "  5. Rehearse the demo from docs/demo-script.md (incl. the honest fallback + not-saved paths).\n");
fwrite(STDOUT, "\nAudit log (metadata-only): var/audit/audit-YYYYMMDD.log  (git-ignored; delete old files freely)\n");

exit($hardFail ? 1 : 0);