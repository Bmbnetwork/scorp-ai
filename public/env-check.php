<?php

declare(strict_types=1);

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

function isLocalRequest(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if ($ip === '') {
        return false;
    }

    if ($ip === '::1' || $ip === 'localhost') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (strpos($ip, '127.') === 0) {
            return true;
        }

        if (strpos($ip, '10.') === 0) {
            return true;
        }

        if (strpos($ip, '192.168.') === 0) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip)) {
            return true;
        }
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if (stripos($ip, 'fc') === 0 || stripos($ip, 'fd') === 0) {
            return true;
        }
    }

    return false;
}

if (!isLocalRequest() && $env->get('APP_ALLOW_DIAGNOSTICS') !== 'true') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Forbidden';
    exit;
}

$service = new \App\Services\EnvironmentCheckService($env);
$result = $service->run(PHP_SAPI !== 'cli');

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$statusLabels = [
    'pass' => 'Ready',
    'warn' => 'Attention',
    'fail' => 'Blocked',
];

$overall = (string) $result['overall'];
$overallLabel = $statusLabels[$overall] ?? ucfirst($overall);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>SCORP AI — Environment check</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="container">
        <header class="page-header">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"></path>
                        <path d="M9 12l2 2 4-4"></path>
                    </svg>
                </span>
                <div>
                    <h1>SCORP AI</h1>
                    <p class="subtitle">Phase 0 environment verification</p>
                </div>
            </div>

            <div class="header-meta">
                <span id="overall-status" class="badge" data-state="<?= e($overall) ?>">
                    <?= e($overallLabel) ?>
                </span>
                <span class="muted">
                    Last updated: <span id="last-updated"><?= e($result['generated_at']) ?></span>
                </span>
            </div>
        </header>

        <section class="panel">
            <div class="panel-header">
                <h2>Environment checks</h2>
                <form id="check-form" method="get">
                    <button type="submit">Re-run checks</button>
                </form>
            </div>

            <p class="notice">
                This page verifies local readiness only. It does not display secret values and does not
                authenticate to PayPal or Gemini.
            </p>

            <div class="table-wrap">
                <table aria-label="Environment check results">
                    <thead>
                        <tr>
                            <th scope="col">Check</th>
                            <th scope="col">Status</th>
                            <th scope="col">Detail</th>
                        </tr>
                    </thead>
                    <tbody id="checks-body">
                        <?php foreach ($result['checks'] as $check): ?>
                            <tr>
                                <td><?= e($check['label']) ?></td>
                                <td>
                                    <span class="status-pill" data-state="<?= e($check['status']) ?>">
                                        <?= e(ucfirst($check['status'])) ?>
                                    </span>
                                </td>
                                <td><?= e($check['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <footer class="page-footer">
            <p>
                PHP <?= e($result['php_version']) ?> · SAPI <?= e($result['sapi']) ?>
                · <a href="index.php">Return to application</a>
            </p>
        </footer>
    </main>
</body>
</html>