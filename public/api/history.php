<?php

declare(strict_types=1);

use App\Api\ApiResponse;
use App\Database\Database;
use App\Database\DatabaseException;
use App\Services\AuditLogger;
use App\Services\InvestigationMapper;
use App\Services\InvestigationRepository;
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
    $finish('history.rejected', ['outcome' => $code, 'http_status' => $status]);
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

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'DELETE'], true)) {
    header('Allow: GET, DELETE');
    $reject(405, 'method_not_allowed', 'Method not allowed', 'The history endpoint accepts GET and DELETE.');
}

if (!sameOriginAllowed()) {
    $reject(403, 'forbidden_origin', 'Request origin not allowed', 'This local endpoint only accepts same-origin requests.');
}

$limiter = new RateLimiter($rootDir . '/var/rate');
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

$maxRequests = (int) $env->get('RATE_LIMIT_HISTORY_MAX', '60');
$windowSeconds = (int) $env->get('RATE_LIMIT_HISTORY_WINDOW', '60');

if (!$limiter->allow('history:' . $clientIp, $maxRequests, $windowSeconds)) {
    $finish('history.rejected', ['outcome' => 'rate_limited', 'http_status' => 429]);
    ApiResponse::error('rate_limited', 'Too many requests', 'Please wait before querying history again.', [], 429);
    exit;
}

$idRaw = $_GET['id'] ?? null;
$id = null;

if ($idRaw !== null && $idRaw !== '') {
    if (!is_string($idRaw) || preg_match('/\A\d+\z/', $idRaw) !== 1) {
        $reject(422, 'invalid_id', 'Invalid id', 'The history id must be a positive integer.');
    }

    $id = (int) $idRaw;

    if ($id <= 0) {
        $reject(422, 'invalid_id', 'Invalid id', 'The history id must be a positive integer.');
    }
}

try {
    $database = Database::fromEnv($env);
    $repository = new InvestigationRepository($database);
} catch (DatabaseException $exception) {
    $code = $exception->getErrorCode() === 'database_not_configured' ? 'history_unavailable' : 'history_unavailable';
    $finish('history.unavailable', ['outcome' => $exception->getErrorCode(), 'http_status' => 503]);
    ApiResponse::error(
        'history_unavailable',
        'History not available',
        $exception->getErrorCode() === 'database_not_configured'
            ? 'History is not configured on this machine. Set DB_* in .env and run bin/db-migrate.php (or create the scorp_ai tables in phpMyAdmin).'
            : 'History is not reachable right now.',
        [],
        503
    );
    exit;
}

try {
    if ($method === 'DELETE') {
        if ($id === null) {
            $reject(422, 'invalid_id', 'Invalid id', 'Provide a positive integer id to delete.');
        }

        $deleted = $repository->delete($id);

        $finish('history.remove', ['id' => $id, 'outcome' => $deleted ? 'deleted' : 'not_found']);

        ApiResponse::json(true, [
            'data' => ['deleted' => $deleted],
        ]);
        exit;
    }

    if ($id !== null) {
        $record = $repository->find($id);

        if ($record === null) {
            $finish('history.open', ['id' => $id, 'outcome' => 'not_found', 'http_status' => 404]);
            ApiResponse::error('history_not_found', 'Not found', 'No saved investigation matches that id.', [], 404);
            exit;
        }

        $data = InvestigationMapper::toReport($record['investigation'], $record['findings']);
        $data['persisted'] = true;
        $data['investigation_id'] = (int) $record['investigation']['id'];
        $data['persistence_error'] = null;
        $data['opened_from_history'] = true;

        $finish('history.open', ['id' => $id, 'outcome' => 'ok']);

        ApiResponse::json(true, [
            'data' => $data,
        ]);
        exit;
    }

    $rows = $repository->recent(25);

    $items = [];

    foreach ($rows as $row) {
        $items[] = [
            'id' => (int) ($row['id'] ?? 0),
            'reference' => $row['reference'] ?? null,
            'order_status' => $row['order_status'] ?? null,
            'amount_value' => $row['amount_value'] ?? null,
            'amount_currency' => $row['amount_currency'] ?? null,
            'ai_origin' => $row['ai_origin'] ?? null,
            'ai_available' => ((int) ($row['ai_available'] ?? 0)) === 1,
            'report_state' => $row['report_state'] ?? null,
            'created_timestamp' => $row['created_timestamp'] ?? null,
        ];
    }

    $finish('history.list', ['outcome' => 'ok', 'count' => count($items)]);

    ApiResponse::json(true, [
        'data' => [
            'items' => $items,
            'count' => count($items),
        ],
    ]);
} catch (DatabaseException $exception) {
    $finish('history.error', ['outcome' => $exception->getErrorCode(), 'http_status' => 500]);
    ApiResponse::error('history_error', 'History error', 'History could not be read right now.', [], 500);
} catch (Throwable $exception) {
    $finish('history.error', ['outcome' => 'internal_error', 'http_status' => 500]);
    ApiResponse::error('history_error', 'History error', 'History could not be read right now.', [], 500);
}