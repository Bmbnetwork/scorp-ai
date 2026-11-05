<?php

declare(strict_types=1);

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

$baseUrl = rtrim((string) $env->get('PAYPAL_API_BASE', 'https://api-m.sandbox.paypal.com'), '/');
$environment = strtolower(trim((string) $env->get('PAYPAL_ENV', 'sandbox')));
$clientId = trim((string) $env->get('PAYPAL_CLIENT_ID', ''));
$secret = trim((string) $env->get('PAYPAL_SECRET', ''));

if ($environment !== 'sandbox') {
    fwrite(STDERR, "Refusing to run: PAYPAL_ENV must be sandbox.\n");
    exit(2);
}

if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
    fwrite(STDERR, "Invalid PAYPAL_API_BASE. It must be an HTTPS URL.\n");
    exit(2);
}

if ($clientId === '' || $secret === '') {
    fwrite(STDERR, "Missing PayPal Sandbox credentials in .env.\n");
    exit(2);
}

$tokenResponse = httpPost(
    $baseUrl . '/v1/oauth2/token',
    [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
    http_build_query([
        'grant_type' => 'client_credentials',
    ])
);

if (($tokenResponse['status'] ?? 0) !== 200) {
    fwrite(STDERR, "Unable to acquire PayPal access token.\n");
    fwrite(STDERR, 'HTTP status: ' . (string) ($tokenResponse['status'] ?? 0) . "\n");
    exit(1);
}

$tokenData = json_decode((string) ($tokenResponse['body'] ?? ''), true);

if (!is_array($tokenData) || !isset($tokenData['access_token']) || !is_string($tokenData['access_token'])) {
    fwrite(STDERR, "PayPal did not return a usable access token.\n");
    exit(1);
}

$accessToken = trim($tokenData['access_token']);

$orderPayload = json_encode([
    'intent' => 'CAPTURE',
    'purchase_units' => [
        [
            'amount' => [
                'currency_code' => 'USD',
                'value' => '10.00',
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES);

if ($orderPayload === false) {
    fwrite(STDERR, "Unable to encode order payload.\n");
    exit(1);
}

$orderResponse = httpPost(
    $baseUrl . '/v2/checkout/orders',
    [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    $orderPayload
);

$status = (int) ($orderResponse['status'] ?? 0);
$body = (string) ($orderResponse['body'] ?? '');

if ($status !== 201 && $status !== 200) {
    fwrite(STDERR, "PayPal order creation failed.\n");
    fwrite(STDERR, 'HTTP status: ' . $status . "\n");

    $decoded = json_decode($body, true);

    if (is_array($decoded)) {
        if (isset($decoded['name']) && is_string($decoded['name'])) {
            fwrite(STDERR, 'PayPal error: ' . $decoded['name'] . "\n");
        }

        if (isset($decoded['debug_id']) && is_string($decoded['debug_id'])) {
            fwrite(STDERR, 'Debug ID: ' . $decoded['debug_id'] . "\n");
        }
    }

    exit(1);
}

$order = json_decode($body, true);

if (!is_array($order)) {
    fwrite(STDERR, "PayPal returned an invalid order response.\n");
    exit(1);
}

$orderId = isset($order['id']) && is_string($order['id']) ? trim($order['id']) : '';
$orderStatus = isset($order['status']) && is_string($order['status']) ? trim($order['status']) : 'UNKNOWN';

if ($orderId === '') {
    fwrite(STDERR, "PayPal did not return an order ID.\n");
    exit(1);
}

fwrite(STDOUT, "Sandbox order created.\n");
fwrite(STDOUT, "Order ID: " . $orderId . "\n");
fwrite(STDOUT, "Status: " . $orderStatus . "\n");

/**
 * @param string[] $headers
 *
 * @return array{status:int, body:string}
 */
function httpPost(string $url, array $headers, string $body): array
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
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_values($headers),
        CURLOPT_USERAGENT => 'SCORP-AI-DevHelper/1.0',
    ]);

    $responseBody = curl_exec($ch);
    $responseStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    curl_close($ch);

    return [
        'status' => $responseStatus,
        'body' => $responseBody === false ? '' : (string) $responseBody,
    ];
}