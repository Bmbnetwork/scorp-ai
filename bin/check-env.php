<?php

declare(strict_types=1);

use App\Services\EnvironmentCheckService;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

try {
    $service = new EnvironmentCheckService($env);
    $result = $service->run(true);
} catch (Throwable $exception) {
    fwrite(STDERR, "Environment check failed: " . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(
    STDOUT,
    json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    ) . "\n"
);

exit(($result['overall'] ?? '') === 'fail' ? 1 : 0);