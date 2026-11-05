<?php

declare(strict_types=1);

use App\Services\GeminiApiException;
use App\Services\GeminiService;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

function redactSecrets(string $text, object $env): string
{
    $secretKeys = [
        'GEMINI_API_KEY',
        'PAYPAL_SECRET',
        'PAYPAL_CLIENT_ID',
        'DB_PASS',
    ];

    foreach ($secretKeys as $key) {
        if (!method_exists($env, 'get')) {
            continue;
        }

        $secret = (string) $env->get($key, '');

        if ($secret !== '') {
            $text = str_replace($secret, '[REDACTED]', $text);
        }
    }

    return $text;
}

function configPresent(object $env, string $key): string
{
    if (!method_exists($env, 'has')) {
        return 'unknown';
    }

    return $env->has($key) ? 'yes' : 'no';
}

function configValueOrMasked(object $env, string $key, string $default = ''): string
{
    if (method_exists($env, 'raw')) {
        $value = $env->raw($key);

        if ($value === null) {
            return $default === '' ? '(not set)' : $default;
        }

        $value = trim($value);

        return $value === '' ? '(empty)' : $value;
    }

    if (!method_exists($env, 'get')) {
        return $default;
    }

    $value = (string) $env->get($key, $default);

    if ($value === '') {
        return $default === '' ? '(empty)' : $default;
    }

    return $value;
}

fwrite(STDOUT, "SCORP AI Gemini diagnostic v2\n");
fwrite(STDOUT, "This uses synthetic evidence only. No PayPal request is made.\n");
fwrite(STDOUT, "Secret values are redacted.\n\n");

fwrite(STDOUT, "PHP version: " . PHP_VERSION . "\n");
fwrite(STDOUT, "PHP SAPI: " . PHP_SAPI . "\n");
fwrite(STDOUT, "PHP ini loaded: " . (php_ini_loaded_file() ?: 'none') . "\n\n");

foreach (['curl', 'openssl', 'json', 'mbstring', 'pdo_mysql'] as $extension) {
    fwrite(
        STDOUT,
        "Extension {$extension}: " .
        (extension_loaded($extension) ? 'loaded' : 'MISSING') .
        "\n"
    );
}

fwrite(STDOUT, "\nConfiguration presence:\n");
fwrite(STDOUT, "GEMINI_API_KEY configured: " . configPresent($env, 'GEMINI_API_KEY') . "\n");
fwrite(STDOUT, "PAYPAL_CLIENT_ID configured: " . configPresent($env, 'PAYPAL_CLIENT_ID') . "\n");
fwrite(STDOUT, "PAYPAL_SECRET configured: " . configPresent($env, 'PAYPAL_SECRET') . "\n\n");

fwrite(STDOUT, "Non-secret Gemini settings:\n");
fwrite(STDOUT, "GEMINI_API_BASE: " . configValueOrMasked($env, 'GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta') . "\n");
fwrite(STDOUT, "GEMINI_MODEL: " . configValueOrMasked($env, 'GEMINI_MODEL', 'gemini-1.5-flash') . "\n");
fwrite(STDOUT, "GEMINI_FALLBACK_MODELS: " . configValueOrMasked($env, 'GEMINI_FALLBACK_MODELS', 'gemini-2.0-flash,gemini-1.5-flash-001') . "\n");
fwrite(STDOUT, "GEMINI_USE_RESPONSE_SCHEMA: " . configValueOrMasked($env, 'GEMINI_USE_RESPONSE_SCHEMA', 'true') . "\n");
fwrite(STDOUT, "GEMINI_TIMEOUT_SECONDS: " . configValueOrMasked($env, 'GEMINI_TIMEOUT_SECONDS', '20') . "\n");
fwrite(STDOUT, "GEMINI_MAX_OUTPUT_TOKENS: " . configValueOrMasked($env, 'GEMINI_MAX_OUTPUT_TOKENS', '800') . "\n\n");

$syntheticData = [
    'source' => 'synthetic_diagnostic',
    'environment' => 'diagnostic',
    'endpoint' => 'local-gemini-diagnostic',
    'retrieved_at' => gmdate('c'),
    'reference' => 'GEMINIDIAGTEST1',
    'status' => 'COMPLETED',
    'intent' => 'CAPTURE',
    'amount' => [
        'value' => '1.00',
        'currency_code' => 'USD',
    ],
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'purchase_unit_count' => 1,
    'capture_count' => 0,
    'captures' => [],
    'findings' => [],
    'next_steps' => [
        'Review synthetic diagnostic evidence.',
    ],
];

$investigation = [
    'findings' => [],
    'next_steps' => $syntheticData['next_steps'],
];

try {
    $service = new GeminiService($env);
    $result = $service->explain($syntheticData, $investigation);

    fwrite(
        STDOUT,
        json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) . "\n"
    );

    fwrite(STDOUT, "\nGemini diagnostic succeeded.\n");
    exit(0);
} catch (GeminiApiException $exception) {
    fwrite(STDERR, "Gemini API diagnostic failed.\n");
    fwrite(STDERR, "Error code: " . $exception->getErrorCode() . "\n");
    fwrite(STDERR, "Title: " . $exception->getTitle() . "\n");
    fwrite(STDERR, "Message: " . redactSecrets($exception->getMessage(), $env) . "\n");
    fwrite(STDERR, "HTTP status: " . $exception->getHttpStatus() . "\n");

    foreach ($exception->getDetails() as $detail) {
        fwrite(STDERR, "Detail: " . redactSecrets($detail, $env) . "\n");
    }

    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Unexpected PHP diagnostic error.\n");
    fwrite(STDERR, "Exception class: " . get_class($exception) . "\n");
    fwrite(STDERR, "Message: " . redactSecrets($exception->getMessage(), $env) . "\n");
    fwrite(STDERR, "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n");
    fwrite(STDERR, "Trace:\n" . redactSecrets($exception->getTraceAsString(), $env) . "\n");

    exit(2);
}