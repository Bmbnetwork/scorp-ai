<?php

declare(strict_types=1);

use App\Api\ApiResponse;
use App\Services\AuditLogger;
use App\Support\RateLimiter;

$env = require dirname(__DIR__, 2) . '/app/config/bootstrap.php';

$rootDir = dirname(__DIR__, 2);
$audit = AuditLogger::fromEnv($env, $rootDir);
$start = AuditLogger::now();
$requestId = bin2hex(random_bytes(8));

$finish = static function (string $event, array $context = []) use ($audit, $start, $requestId): void {
    $context['request_id'] = $requestId;
    $context['duration_ms'] = AuditLogger::elapsedMs($start);
    $audit->log($event, $context);
};

$reject = static function (int $status, string $code, string $title, string $message) use ($finish): void {
    $finish('seed.rejected', ['outcome' => $code, 'http_status' => $status]);
    ApiResponse::error($code, $title, $message, [], $status);
    exit;
};

if (!function_exists('sameOriginAllowed')) {
    function sameOriginAllowed(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return true;
        }
        $parsed = parse_url($origin);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return false;
        }
        $originHost = (string) $parsed['host'];
        if (isset($parsed['port'])) {
            $originHost .= ':' . $parsed['port'];
        }
        $serverHost = $_SERVER['HTTP_HOST'] ?? '';
        return strcasecmp($originHost, $serverHost) === 0;
    }
}

// POST only, same origin.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reject(405, 'method_not_allowed', 'Method not allowed', 'The test-order seeder accepts POST requests only.');
}
if (!sameOriginAllowed()) {
    $reject(403, 'forbidden_origin', 'Request origin not allowed', 'This endpoint only accepts same-origin requests.');
}

// Sandbox guard: this endpoint must NEVER be able to create a live order.
$paypalEnv = (string) $env->get('PAYPAL_ENV', 'sandbox');
if ($paypalEnv !== 'sandbox') {
    $reject(403, 'sandbox_only', 'Sandbox only', 'The test-order seeder is disabled outside PayPal Sandbox.');
}

$clientId = (string) $env->get('PAYPAL_CLIENT_ID', '');
$secret   = (string) $env->get('PAYPAL_SECRET', '');
$base     = rtrim((string) $env->get('PAYPAL_API_BASE', 'https://api-m.sandbox.paypal.com'), '/');

if ($clientId === '' || $secret === '') {
    $reject(503, 'paypal_credentials_missing', 'Backend not configured', 'PayPal Sandbox credentials are not configured on this server.');
}

// Rate limit the seeder HARD: each call creates a PayPal order, so cap abuse.
$limiter = new RateLimiter($rootDir . '/var/rate');
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$maxSeed = (int) $env->get('RATE_LIMIT_SEED_MAX', '3');
$seedWindow = (int) $env->get('RATE_LIMIT_SEED_WINDOW', '60');
if (!$limiter->allow('seed:' . $clientIp, $maxSeed, $seedWindow)) {
    $reject(429, 'rate_limited', 'Too many test orders', 'Please wait before creating another test order.');
}

$currency = (string) $env->get('JUDGE_ORDER_CURRENCY', 'USD');
$amount   = (string) $env->get('JUDGE_ORDER_AMOUNT', '10.00');

// 1. Access token (server-side only; never returned to the browser).
$tokenCh = curl_init($base . '/v1/oauth2/token');
curl_setopt_array($tokenCh, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
    CURLOPT_TIMEOUT => 30,
]);
$tokenBody = curl_exec($tokenCh);
$tokenStatus = (int) curl_getinfo($tokenCh, CURLINFO_HTTP_CODE);
curl_close($tokenCh);

if ($tokenStatus !== 200 || !is_string($tokenBody)) {
    $finish('seed.failed', ['outcome' => 'paypal_token_error', 'http_status' => 502]);
    ApiResponse::error('paypal_token_error', 'PayPal error', 'Could not authenticate to PayPal Sandbox to create a test order.', [], 502);
    exit;
}
$tokenJson = json_decode($tokenBody, true);
$accessToken = is_array($tokenJson) ? (string) ($tokenJson['access_token'] ?? '') : '';
if ($accessToken === '') {
    $finish('seed.failed', ['outcome' => 'paypal_token_error', 'http_status' => 502]);
    ApiResponse::error('paypal_token_error', 'PayPal error', 'PayPal did not return an access token.', [], 502);
    exit;
}

// 2. Create ONE real, capture-less Sandbox order (no money moves).
$payload = json_encode([
    'intent' => 'CAPTURE',
    'purchase_units' => [[
        'amount' => ['currency_code' => $currency, 'value' => $amount],
        'description' => 'SCORP AI live test order (no capture)',
    ]],
], JSON_UNESCAPED_SLASHES);

$createCh = curl_init($base . '/v2/checkout/orders');
curl_setopt_array($createCh, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 30,
]);
$createBody = curl_exec($createCh);
$createStatus = (int) curl_getinfo($createCh, CURLINFO_HTTP_CODE);
curl_close($createCh);

$reference = '';
if ($createStatus === 201 && is_string($createBody)) {
    $cj = json_decode($createBody, true);
    if (is_array($cj) && isset($cj['id']) && is_string($cj['id'])) {
        $reference = $cj['id'];
    }
}

if ($reference === '') {
    $finish('seed.failed', ['outcome' => 'paypal_create_error', 'http_status' => 502]);
    ApiResponse::error('paypal_create_error', 'PayPal error', 'PayPal Sandbox did not create a test order right now. Try again shortly.', [], 502);
    exit;
}

$finish('seed.complete', ['outcome' => 'created', 'reference' => $reference, 'http_status' => 200]);

ApiResponse::json(true, [
    'data' => [
        'reference' => $reference,
        'environment' => 'sandbox',
        'captured' => false,
        'amount' => ['value' => $amount, 'currency_code' => $currency],
        'note' => 'Real PayPal Sandbox order created for testing. No capture performed; no money moved. Investigate it like any order.',
    ],
]);
