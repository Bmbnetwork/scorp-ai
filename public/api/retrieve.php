<?php

declare(strict_types=1);

use App\Api\ApiResponse;
use App\Database\Database;
use App\Database\DatabaseException;
use App\Services\AuditLogger;
use App\Services\InvestigationRepository;
use App\Services\InvestigationWorkflow;
use App\Services\PayPalApiException;
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

$reject = static function (int $status, string $code, string $title, string $message, array $details = []) use ($finish): void {
    $finish('investigate.rejected', ['outcome' => $code, 'http_status' => $status]);
    ApiResponse::error($code, $title, $message, $details, $status);
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reject(405, 'method_not_allowed', 'Method not allowed', 'The investigation endpoint accepts POST requests only.');
}

if (!sameOriginAllowed()) {
    $reject(403, 'forbidden_origin', 'Request origin not allowed', 'This local development endpoint only accepts same-origin requests.');
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 8192) {
    $reject(413, 'payload_too_large', 'Request too large', 'The investigation request body is too large.');
}

$raw = file_get_contents('php://input');

if ($raw === false) {
    $reject(400, 'unreadable_request', 'Unable to read request', 'The backend could not read the request body.');
}

if (strlen($raw) > 8192) {
    $reject(413, 'payload_too_large', 'Request too large', 'The investigation request body is too large.');
}

if ($raw === '') {
    $reject(400, 'empty_request', 'Missing request body', 'Send a JSON body containing a reference field.');
}

try {
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    $reject(400, 'invalid_json', 'Invalid JSON', 'The request body must be valid JSON.');
}

if (!is_array($decoded)) {
    $reject(400, 'invalid_payload', 'Invalid payload', 'The request body must be a JSON object.');
}

$reference = $decoded['reference'] ?? null;

if (!is_string($reference)) {
    $reject(422, 'invalid_reference', 'Invalid reference', 'The reference field must be a string.');
}

$limiter = new RateLimiter($rootDir . '/var/rate');

$maxRequests = (int) $env->get('RATE_LIMIT_RETRIEVE_MAX', '10');
$windowSeconds = (int) $env->get('RATE_LIMIT_RETRIEVE_WINDOW', '60');
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if (!$limiter->allow('investigate:' . $clientIp, $maxRequests, $windowSeconds)) {
    $finish('investigate.rejected', ['outcome' => 'rate_limited', 'http_status' => 429, 'reference' => $reference]);
    ApiResponse::error('rate_limited', 'Too many requests', 'Please wait before starting another investigation.', [], 429);
    exit;
}

try {
    $workflow = new InvestigationWorkflow($env);
    $data = $workflow->run($reference);
} catch (PayPalApiException $exception) {
    $status = $exception->getHttpStatus();

    if ($status < 400 || $status > 599) {
        $status = match ($exception->getErrorCode()) {
            'invalid_reference' => 422,
            'paypal_credentials_missing',
            'paypal_environment_not_sandbox',
            'paypal_config_invalid' => 503,
            default => 502,
        };
    }

    $finish('investigate.failed', [
        'reference' => $reference,
        'outcome' => $exception->getErrorCode(),
        'http_status' => $status,
    ]);

    ApiResponse::error(
        $exception->getErrorCode(),
        $exception->getTitle(),
        $exception->getMessage(),
        $exception->getDetails(),
        $status
    );
    exit;
} catch (Throwable $exception) {
    $finish('investigate.error', ['reference' => $reference, 'outcome' => 'internal_error', 'http_status' => 500]);
    ApiResponse::error('internal_error', 'Unexpected backend error', 'The backend encountered an unexpected error.', [], 500);
    exit;
}

// Persistence is secondary and must never invalidate a completed investigation.
$persisted = false;
$investigationId = null;
$persistenceError = null;
$persistenceCode = null;

try {
    $database = Database::fromEnv($env);
    $repository = new InvestigationRepository($database);
    $investigationId = $repository->save($data);
    $persisted = true;
} catch (DatabaseException $exception) {
    $persistenceCode = $exception->getErrorCode();
    $persistenceError = $persistenceCode === 'database_not_configured'
        ? 'History is not configured on this machine.'
        : $exception->getMessage();
} catch (Throwable $exception) {
    $persistenceCode = 'persistence_unknown';
    $persistenceError = 'Investigation completed but could not be saved to history. Cause: ' . $exception->getMessage();
}

$ai = is_array($data['ai'] ?? null) ? $data['ai'] : null;

$data['persisted'] = $persisted;
$data['investigation_id'] = $investigationId;
$data['persistence_error'] = $persistenceError;

$finish('investigate.complete', [
    'reference' => $reference,
    'outcome' => $persisted ? 'saved' : 'not_saved',
    'error_code' => $persistenceCode,
    'persisted' => $persisted,
    'ai_origin' => is_array($ai) ? ($ai['origin'] ?? null) : null,
    'ai_available' => (bool) ($data['ai_available'] ?? false),
]);

ApiResponse::json(true, [
    'data' => $data,
]);