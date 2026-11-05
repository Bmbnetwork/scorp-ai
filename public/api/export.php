<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\DatabaseException;
use App\Services\AuditLogger;
use App\Services\InvestigationMapper;
use App\Services\InvestigationRepository;
use App\Services\ReportExporter;
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

$failExport = static function (int $status, string $code, string $title, string $message, array $context = []) use ($finish): void {
    $context['outcome'] = $code;
    $context['http_status'] = $status;
    $finish('export.rejected', $context);

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => $code,
            'title' => $title,
            'message' => $message,
            'details' => [],
        ],
    ], JSON_UNESCAPED_SLASHES);

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    $failExport(405, 'method_not_allowed', 'Method not allowed', 'The export endpoint accepts GET requests only.');
}

if (!sameOriginAllowed()) {
    $failExport(403, 'forbidden_origin', 'Request origin not allowed', 'This local endpoint only accepts same-origin requests.');
}

$limiter = new RateLimiter($rootDir . '/var/rate');
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

$maxRequests = (int) $env->get('RATE_LIMIT_EXPORT_MAX', '30');
$windowSeconds = (int) $env->get('RATE_LIMIT_EXPORT_WINDOW', '60');

if (!$limiter->allow('export:' . $clientIp, $maxRequests, $windowSeconds)) {
    $failExport(429, 'rate_limited', 'Too many requests', 'Please wait before exporting again.');
}

$idRaw = $_GET['id'] ?? null;
$formatRaw = $_GET['format'] ?? 'json';

if (!is_string($idRaw) || preg_match('/\A\d+\z/', $idRaw) !== 1) {
    $failExport(422, 'invalid_id', 'Invalid id', 'Provide a positive integer id.');
}

$id = (int) $idRaw;

if ($id <= 0) {
    $failExport(422, 'invalid_id', 'Invalid id', 'Provide a positive integer id.', ['id' => $id]);
}

if (!is_string($formatRaw) || !in_array(strtolower($formatRaw), ['json', 'csv'], true)) {
    $failExport(422, 'invalid_format', 'Invalid format', 'Format must be json or csv.', ['id' => $id]);
}

$format = strtolower($formatRaw);

try {
    $database = Database::fromEnv($env);
    $repository = new InvestigationRepository($database);
} catch (DatabaseException $exception) {
    $failExport(
        503,
        'export_unavailable',
        'History not available',
        $exception->getErrorCode() === 'database_not_configured'
            ? 'History is not configured on this machine. Set DB_* in .env and create the scorp_ai tables.'
            : 'History is not reachable right now.',
        ['id' => $id, 'format' => $format]
    );
}

try {
    $record = $repository->find($id);
} catch (DatabaseException | Throwable $exception) {
    $failExport(500, 'export_error', 'Export error', 'The saved investigation could not be read.', ['id' => $id, 'format' => $format]);
}

if ($record === null) {
    $failExport(404, 'export_not_found', 'Not found', 'No saved investigation matches that id.', ['id' => $id, 'format' => $format]);
}

$report = InvestigationMapper::toReport($record['investigation'], $record['findings']);
$report['investigation_id'] = $id;
$report['persisted'] = true;
$report['saved_at'] = $record['investigation']['created_timestamp'] ?? null;

$exporter = new ReportExporter();
$filename = 'scorp-report-' . $id;

header('Cache-Control: no-store');

if ($format === 'csv') {
    $body = "\xEF\xBB\xBF" . $exporter->toCsv($report);

    $finish('export.complete', ['id' => $id, 'format' => 'csv', 'outcome' => 'ok']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Content-Length: ' . strlen($body));

    echo $body;
    exit;
}

$body = $exporter->toJson($report);

$finish('export.complete', ['id' => $id, 'format' => 'json', 'outcome' => 'ok']);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.json"');
header('Content-Length: ' . strlen($body));

echo $body;