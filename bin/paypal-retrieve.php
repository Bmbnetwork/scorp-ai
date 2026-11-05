<?php

declare(strict_types=1);

use App\Services\AuditLogger;
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

$ai = is_array($result['ai'] ?? null) ? $result['ai'] : null;

$finish('investigate.complete', [
    'reference' => $orderId,
    'outcome' => 'complete',
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