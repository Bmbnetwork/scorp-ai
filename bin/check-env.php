<?php

declare(strict_types=1);

use App\Services\EnvironmentCheckService;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

$service = new EnvironmentCheckService($env);
$result = $service->run(false);

fwrite(STDOUT, "SCORP AI Phase 0 environment check\n");
fwrite(STDOUT, str_repeat('=', 36) . "\n");
fwrite(STDOUT, "Generated: {$result['generated_at']}\n");
fwrite(STDOUT, "PHP: {$result['php_version']} ({$result['sapi']})\n");
fwrite(STDOUT, "Overall: " . strtoupper($result['overall']) . "\n\n");

foreach ($result['checks'] as $check) {
    fwrite(
        STDOUT,
        sprintf(
            "[%s] %s\n    %s\n",
            strtoupper($check['status']),
            $check['label'],
            $check['detail']
        )
    );
}

if ($result['overall'] !== 'pass') {
    fwrite(STDERR, "\nSome checks did not fully pass.\n");
    fwrite(STDERR, "Fix configuration/network issues and rerun.\n");
    fwrite(STDERR, "To verify web delivery, run:\n");
    fwrite(STDERR, "  php -S localhost:8000 -t public\n");
    fwrite(STDERR, "then open:\n");
    fwrite(STDERR, "  http://localhost:8000/env-check.php\n");
}

exit($result['overall'] === 'pass' ? 0 : 1);