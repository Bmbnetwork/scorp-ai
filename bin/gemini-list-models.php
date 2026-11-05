<?php

declare(strict_types=1);

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

$apiKey = trim((string) $env->get('GEMINI_API_KEY', ''));
$apiBase = rtrim(trim((string) $env->get('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta')), '/');

if ($apiKey === '') {
    fwrite(STDERR, "GEMINI_API_KEY is not configured.\n");
    exit(2);
}

if ($apiBase === '' || !str_starts_with($apiBase, 'https://')) {
    fwrite(STDERR, "GEMINI_API_BASE must be an HTTPS URL.\n");
    exit(2);
}

fwrite(STDOUT, "SCORP AI Gemini model discovery\n");
fwrite(STDOUT, "This lists model names only. It does not print your API key.\n\n");

$pageToken = null;
$page = 0;
$maxPages = 10;

do {
    $page++;

    $url = $apiBase . '/models?pageSize=100';

    if ($pageToken !== null && $pageToken !== '') {
        $url .= '&pageToken=' . rawurlencode($pageToken);
    }

    $response = httpGet($url, [
        'Accept: application/json',
        'x-goog-api-key: ' . $apiKey,
    ]);

    $status = (int) ($response['status'] ?? 0);
    $body = (string) ($response['body'] ?? '');

    if ($status !== 200) {
        fwrite(STDERR, "Unable to list Gemini models.\n");
        fwrite(STDERR, "HTTP status: " . $status . "\n");

        $decoded = json_decode($body, true);

        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $message = trim((string) ($decoded['error']['message'] ?? ''));
            $providerStatus = trim((string) ($decoded['error']['status'] ?? ''));

            if ($providerStatus !== '') {
                fwrite(STDERR, "Provider status: " . substr($providerStatus, 0, 80) . "\n");
            }

            if ($message !== '') {
                fwrite(STDERR, "Provider message: " . substr($message, 0, 240) . "\n");
            }
        }

        exit(1);
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        fwrite(STDERR, "Gemini returned an invalid model list response.\n");
        exit(1);
    }

    $models = $decoded['models'] ?? [];

    if (!is_array($models)) {
        $models = [];
    }

    foreach ($models as $model) {
        if (!is_array($model)) {
            continue;
        }

        $name = isset($model['name']) && is_string($model['name']) ? trim($model['name']) : '';

        if ($name === '') {
            continue;
        }

        if (str_starts_with($name, 'models/')) {
            $name = substr($name, 7);
        }

        $methods = $model['supportedGenerationMethods'] ?? [];

        if (!is_array($methods)) {
            $methods = [];
        }

        $supportsGenerateContent = $methods === [] || in_array('generateContent', $methods, true);

        if (!$supportsGenerateContent) {
            continue;
        }

        fwrite(STDOUT, $name . "\n");
    }

    $pageToken = isset($decoded['nextPageToken']) && is_string($decoded['nextPageToken'])
        ? trim($decoded['nextPageToken'])
        : null;
} while ($pageToken !== null && $pageToken !== '' && $page < $maxPages);

fwrite(STDOUT, "\nDone. Choose one model name above and set it as GEMINI_MODEL in .env.\n");

/**
 * @param string[] $headers
 *
 * @return array{status:int, body:string}
 */
function httpGet(string $url, array $headers): array
{
    $ch = curl_init();

    if ($ch === false) {
        return [
            'status' => 0,
            'body' => '',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_values($headers),
        CURLOPT_USERAGENT => 'SCORP-AI-DevHelper/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    return [
        'status' => $status,
        'body' => $body === false ? '' : (string) $body,
    ];
}