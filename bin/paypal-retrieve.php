<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\DatabaseException;
use App\Services\AuditLogger;
use App\Services\InvestigationRepository;
use App\Services\InvestigationWorkflow;
use App\Services\PayPalApiException;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

$rootDir = dirname(__DIR__);
$audit = AuditLogger::fromEnv($env, $rootDir);
$start = AuditLogger::now();
$requestId = 'cli-' . bin2hex(random_bytes(6));

$finish = static function (string $event, array $context = []) use ($audit, $start, $requestId): void {
    $context['request_id'] = $requestId;
    $context['duration_ms'] = AuditLogger::elapsedMs($start);
    $audit->log($event, $context);
};

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin\\paypal-retrieve.php <PayPal-Sandbox-order-ID>\n");
    exit(2);
}

$orderId = (string) $argv[1];

try {
    $workflow = new InvestigationWorkflow($env);
    $result = $workflow->run($orderId);
} catch (PayPalApiException $exception) {
    $finish('investigate.failed', ['reference' => $orderId, 'outcome' => $exception->getErrorCode()]);
    fwrite(STDERR, "Investigation failed at PayPal retrieval step: " . $exception->getMessage() . "\n");

    foreach ($exception->getDetails() as $detail) {
        fwrite(STDERR, " - " . $detail . "\n");
    }

    exit(1);
} catch (Throwable $exception) {
    $finish('investigate.error', ['reference' => $orderId, 'outcome' => 'internal_error']);
    fwrite(STDERR, "Unexpected error: " . $exception->getMessage() . "\n");
    exit(1);
}

$persisted = false;
$investigationId = null;
$persistenceError = null;
$persistenceCode = null;

try {
    $database = Database::fromEnv($env);
    $repository = new InvestigationRepository($database);
    $investigationId = $repository->save($result);
    $persisted = true;
} catch (DatabaseException $exception) {
    $persistenceCode = $exception->getErrorCode();
    $persistenceError = $persistenceCode === 'database_not_configured'
        ? 'History is not configured. Set DB_* in .env and create the scorp_ai tables.'
        : $exception->getMessage();
} catch (Throwable $exception) {
    $persistenceCode = 'persistence_unknown';
    $persistenceError = 'Could not save to history. Cause: ' . $exception->getMessage();
}

$ai = is_array($result['ai'] ?? null) ? $result['ai'] : null;

$result['persisted'] = $persisted;
$result['investigation_id'] = $investigationId;
$result['persistence_error'] = $persistenceError;

$finish('investigate.complete', [
    'reference' => $orderId,
    'outcome' => $persisted ? 'saved' : 'not_saved',
    'error_code' => $persistenceCode,
    'persisted' => $persisted,
    'ai_origin' => is_array($ai) ? ($ai['origin'] ?? null) : null,
    'ai_available' => (bool) ($result['ai_available'] ?? false),
]);

fwrite(
    STDOUT,
    json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    ) . "\n"
);

if ($persisted) {
    fwrite(STDOUT, "\nSaved to history as #{$investigationId}.\n");
} else {
    fwrite(STDERR, "\nNOT SAVED TO HISTORY:\n" . ($persistenceError ?? 'unknown') . "\n");
}