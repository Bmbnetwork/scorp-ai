<?php

declare(strict_types=1);

use App\Config\Env;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);

$requiredExtensions = [
    'curl',
    'openssl',
    'json',
    'mbstring',
];

$requiredEnv = [
    'PAYPAL_ENV',
    'PAYPAL_CLIENT_ID',
    'PAYPAL_SECRET',
    'GEMINI_API_KEY',
    'GEMINI_MODEL',
];

$checks = [];
$fail = false;

$phpOk = PHP_VERSION_ID >= 80000;
if (!$phpOk) {
    $fail = true;
}

$checks['php'] = [
    'status' => $phpOk ? 'pass' : 'fail',
    'version' => PHP_VERSION,
];

$extStatus = 'pass';
$missingExtensions = [];

foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $extStatus = 'fail';
        $missingExtensions[] = $ext;
        $fail = true;
    }
}

$checks['extensions'] = [
    'status' => $extStatus,
    'missing' => $missingExtensions,
];

$envStatus = 'pass';
$missingEnv = [];

try {
    $env = require $root . '/app/config/bootstrap.php';

    if (!$env instanceof Env) {
        $envStatus = 'fail';
        $missingEnv[] = 'BOOTSTRAP_ENV_OBJECT';
        $fail = true;
    } else {
        foreach ($requiredEnv as $key) {
            $value = $env->get($key);

            if ($value === null || trim((string) $value) === '') {
                $envStatus = 'fail';
                $missingEnv[] = $key;
                $fail = true;
            }
        }
    }
} catch (Throwable $exception) {
    $envStatus = 'fail';
    $missingEnv[] = 'BOOTSTRAP_ERROR';
    $fail = true;
}

$checks['environment'] = [
    'status' => $envStatus,
    'missing' => $missingEnv,
];

$varDir = $root . DIRECTORY_SEPARATOR . 'var';
$rateDir = $varDir . DIRECTORY_SEPARATOR . 'rate';
$auditDir = $varDir . DIRECTORY_SEPARATOR . 'audit';

$fsStatus = 'pass';
$fsIssues = [];

if (!is_dir($varDir) && !@mkdir($varDir, 0775, true) && !is_dir($varDir)) {
    $fsStatus = 'fail';
    $fsIssues[] = 'VAR_DIR_NOT_CREATABLE';
    $fail = true;
}

if ($fsStatus === 'pass') {
    if (!is_dir($rateDir) && !@mkdir($rateDir, 0775, true) && !is_dir($rateDir)) {
        $fsStatus = 'fail';
        $fsIssues[] = 'RATE_DIR_NOT_CREATABLE';
        $fail = true;
    }

    if (!is_dir($auditDir) && !@mkdir($auditDir, 0775, true) && !is_dir($auditDir)) {
        $fsStatus = 'fail';
        $fsIssues[] = 'AUDIT_DIR_NOT_CREATABLE';
        $fail = true;
    }

    if ($fsStatus === 'pass' && !is_writable($rateDir)) {
        $fsStatus = 'fail';
        $fsIssues[] = 'RATE_DIR_NOT_WRITABLE';
        $fail = true;
    }

    if ($fsStatus === 'pass' && !is_writable($auditDir)) {
        $fsStatus = 'fail';
        $fsIssues[] = 'AUDIT_DIR_NOT_WRITABLE';
        $fail = true;
    }
}

$checks['filesystem'] = [
    'status' => $fsStatus,
    'issues' => $fsIssues,
];

$response = [
    'ok' => !$fail,
    'status' => $fail ? 'fail' : 'pass',
    'checks' => $checks,
    'timestamp' => gmdate('c'),
];

echo json_encode(
    $response,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);