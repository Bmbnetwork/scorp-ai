<?php

declare(strict_types=1);

use App\Services\EnvironmentCheckService;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (!is_string($value)) {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $value = $json === false ? '' : $json;
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$isLocal = $ip === '127.0.0.1'
    || $ip === '::1'
    || str_starts_with($ip, '10.')
    || str_starts_with($ip, '192.168.')
    || (bool) preg_match('/\A172\.(1[6-9]|2[0-9]|3[01])\./', $ip);

if (!$isLocal) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Forbidden</title></head>';
    echo '<body><h1>Local only</h1><p>The environment check is not available from this address.</p></body></html>';
    exit;
}

$serviceError = null;
$result = null;

try {
    $service = new EnvironmentCheckService($env);
    $result = $service->run(true);
} catch (Throwable $exception) {
    $serviceError = $exception->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>SCORP AI — Environment check</title>
</head>
<body>
    <h1>SCORP AI — Environment check</h1>

    <?php if ($serviceError !== null): ?>
        <p><strong>Environment check failed:</strong> <?= e($serviceError) ?></p>
    <?php elseif (is_array($result)): ?>
        <p><strong>Overall:</strong> <?= e($result['overall'] ?? 'unknown') ?></p>

        <h2>Checks</h2>
        <table border="1" cellpadding="6">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($result['checks'] ?? []) as $check): ?>
                    <tr>
                        <td><?= e($check['id'] ?? '') ?></td>
                        <td><?= e($check['status'] ?? '') ?></td>
                        <td><?= e($check['message'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No result returned.</p>
    <?php endif; ?>

    <p><a href="index.php">Back to investigation</a></p>
</body>
</html>