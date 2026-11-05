<?php

declare(strict_types=1);

use App\Config\Env;
use App\Services\AuditLogger;
use App\Services\EnvironmentCheckService;
use App\Services\FallbackExplainer;
use App\Services\GeminiApiException;
use App\Services\GeminiPromptBuilder;
use App\Services\GeminiService;
use App\Services\GeminiTransport;
use App\Services\InvestigationEngine;
use App\Services\InvestigationWorkflow;
use App\Services\PayPalApiException;
use App\Services\PayPalService;
use App\Services\PayPalTransport;
use App\Support\OrderNormalizer;
use App\Support\RateLimiter;
use App\Support\ReferenceValidator;

require dirname(__DIR__) . '/app/config/bootstrap.php';

final class FakePayPalTransport implements PayPalTransport
{
    private array $responses = [];
    private int $index = 0;

    public array $postCalls = [];
    public array $getCalls = [];

    public function addResponse(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function postForm(string $url, array $fields, array $headers): array
    {
        $this->postCalls[] = ['url' => $url, 'fields' => $fields, 'headers' => $headers];
        return $this->nextResponse();
    }

    public function get(string $url, array $headers): array
    {
        $this->getCalls[] = ['url' => $url, 'headers' => $headers];
        return $this->nextResponse();
    }

    private function nextResponse(): array
    {
        return $this->responses[$this->index++] ?? ['status' => 0, 'body' => '', 'error' => 'No stubbed response.'];
    }
}

final class FakeGeminiTransport implements GeminiTransport
{
    private array $responses = [];
    private int $index = 0;

    public array $postCalls = [];

    public function addResponse(int $status, string $body): void
    {
        $this->responses[] = ['status' => $status, 'body' => $body];
    }

    public function postJson(string $url, array $headers, string $body): array
    {
        $this->postCalls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        return $this->responses[$this->index++] ?? ['status' => 0, 'body' => '', 'error' => 'No stubbed Gemini response.'];
    }
}

$failures = 0;
$tests = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    global $tests;

    $tests++;

    if ($condition) {
        fwrite(STDOUT, "PASS: {$message}\n");
        return;
    }

    $failures++;
    fwrite(STDERR, "FAIL: {$message}\n");
}

function assertThrowsPayPal(callable $callback, string $expectedCode, string $message): void
{
    try {
        $callback();
        assertTrue(false, $message . ' — expected exception but none was thrown');
    } catch (PayPalApiException $exception) {
        assertTrue($exception->getErrorCode() === $expectedCode, $message . ' — got ' . $exception->getErrorCode());
    } catch (Throwable $exception) {
        assertTrue(false, $message . ' — wrong exception type: ' . get_class($exception));
    }
}

function assertThrowsGemini(callable $callback, string $expectedCode, string $message): void
{
    try {
        $callback();
        assertTrue(false, $message . ' — expected exception but none was thrown');
    } catch (GeminiApiException $exception) {
        assertTrue($exception->getErrorCode() === $expectedCode, $message . ' — got ' . $exception->getErrorCode());
    } catch (Throwable $exception) {
        assertTrue(false, $message . ' — wrong exception type: ' . get_class($exception));
    }
}

function findFinding(array $findings, string $code): ?array
{
    foreach ($findings as $finding) {
        if (($finding['code'] ?? null) === $code) {
            return $finding;
        }
    }

    return null;
}

function hasFinding(array $findings, string $code, ?string $severity = null): bool
{
    $finding = findFinding($findings, $code);

    if ($finding === null) {
        return false;
    }

    return $severity === null || ($finding['severity'] ?? null) === $severity;
}

function geminiCandidate(string $text): string
{
    return json_encode([
        'candidates' => [[
            'content' => ['parts' => [['text' => $text]]],
            'finishReason' => 'STOP',
        ]],
    ]);
}

// ---------------------------------------------------------------------------
// Shared fixtures.
// ---------------------------------------------------------------------------

$completedOrder = [
    'id' => 'ORDER123456',
    'status' => 'COMPLETED',
    'intent' => 'CAPTURE',
    'create_time' => '2026-01-01T00:00:00Z',
    'update_time' => '2026-01-01T00:05:00Z',
    'purchase_units' => [[
        'reference_id' => 'PUFT',
        'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
        'payments' => ['captures' => [[
            'id' => 'CAPTURE123',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'create_time' => '2026-01-01T00:04:00Z',
            'update_time' => '2026-01-01T00:05:00Z',
            'seller_protection' => ['status' => 'ELIGIBLE'],
        ]]],
    ]],
];

$tokenResponse = json_encode(['access_token' => 'fake-access-token', 'expires_in' => 3600]);

// ---------------------------------------------------------------------------
// Env parser tests.
// ---------------------------------------------------------------------------

$parsed = Env::parseString("# comment\nAPP_ENV=local\nPAYPAL_CLIENT_ID=abc\nQUOTED=\"hello world\"\nSINGLE='single'\nEMPTY=\n");
assertTrue(($parsed['APP_ENV'] ?? null) === 'local', 'Env parses unquoted values');
assertTrue(($parsed['QUOTED'] ?? null) === 'hello world', 'Env parses double-quoted values');
assertTrue(($parsed['SINGLE'] ?? null) === 'single', 'Env parses single-quoted values');
assertTrue(array_key_exists('EMPTY', $parsed) && $parsed['EMPTY'] === '', 'Env preserves empty values in parsed array');

$tmp = tempnam(sys_get_temp_dir(), 'scorp-env-');
file_put_contents($tmp, "TEST_KEY=test-value\n");
$envFromFile = Env::fromFile($tmp);
assertTrue($envFromFile->get('TEST_KEY') === 'test-value', 'Env reads from file');
unlink($tmp);

// ---------------------------------------------------------------------------
// EnvironmentCheckService tests.
// ---------------------------------------------------------------------------

$env = Env::fromArray([
    'PAYPAL_CLIENT_ID' => 'unit-test-client-id',
    'PAYPAL_SECRET' => 'unit-test-secret',
    'GEMINI_API_KEY' => 'unit-test-gemini-key',
    'PAYPAL_ENV' => 'sandbox',
]);

$stubChecker = static function (string $url): array {
    return ['ok' => true, 'status' => 200, 'message' => 'Stubbed HTTPS connectivity OK.'];
};

$service = new EnvironmentCheckService($env, $stubChecker);
$result = $service->run(true);

assertTrue(is_array($result), 'Environment check result is an array');
assertTrue(isset($result['checks'], $result['overall']), 'Result contains checks and overall');
assertTrue(in_array($result['overall'], ['pass', 'warn', 'fail'], true), 'Overall status is valid');

$byId = [];
foreach ($result['checks'] as $check) {
    $byId[$check['id']] = $check;
}

assertTrue(isset($byId['paypal_credentials']), 'PayPal credential check exists');
assertTrue(isset($byId['gemini_credentials']), 'Gemini credential check exists');
assertTrue(($byId['paypal_credentials']['status'] ?? '') === 'pass', 'PayPal credentials pass when configured');
assertTrue(($byId['gemini_credentials']['status'] ?? '') === 'pass', 'Gemini credentials pass when configured');
assertTrue(($byId['outbound_paypal_sandbox_api']['status'] ?? '') === 'pass', 'Stubbed PayPal outbound check passes');
assertTrue(($byId['outbound_gemini_api']['status'] ?? '') === 'pass', 'Stubbed Gemini outbound check passes');

$json = json_encode($result, JSON_UNESCAPED_SLASHES);
assertTrue($json !== false, 'Result is JSON serializable');
assertTrue(strpos($json, 'unit-test-secret') === false, 'JSON does not contain PayPal secret value');
assertTrue(strpos($json, 'unit-test-gemini-key') === false, 'JSON does not contain Gemini key value');
assertTrue(strpos($json, 'unit-test-client-id') === false, 'JSON does not contain PayPal client ID value');

// ---------------------------------------------------------------------------
// ReferenceValidator tests.
// ---------------------------------------------------------------------------

$valid = ReferenceValidator::validate('ABC123456');
assertTrue(($valid['ok'] ?? false) === true, 'Valid reference passes validation');
assertTrue(($valid['value'] ?? '') === 'ABC123456', 'Valid reference returns trimmed value');

$empty = ReferenceValidator::validate('   ');
assertTrue(($empty['ok'] ?? true) === false, 'Empty reference fails validation');
assertTrue(($empty['code'] ?? '') === 'required', 'Empty reference returns required code');

$short = ReferenceValidator::validate('ABC12');
assertTrue(($short['ok'] ?? true) === false, 'Short reference fails validation');
assertTrue(($short['code'] ?? '') === 'too_short', 'Short reference returns too_short code');

$long = ReferenceValidator::validate(str_repeat('A', 65));
assertTrue(($long['ok'] ?? true) === false, 'Long reference fails validation');
assertTrue(($long['code'] ?? '') === 'too_long', 'Long reference returns too_long code');

$invalidChars = ReferenceValidator::validate('ABC 123!');
assertTrue(($invalidChars['ok'] ?? true) === false, 'Reference with spaces/symbols fails validation');
assertTrue(($invalidChars['code'] ?? '') === 'invalid_characters', 'Invalid characters return invalid_characters code');

$hyphenUnderscore = ReferenceValidator::validate('ORDER-123_TEST');
assertTrue(($hyphenUnderscore['ok'] ?? false) === true, 'Hyphen and underscore are accepted');

// ---------------------------------------------------------------------------
// OrderNormalizer tests.
// ---------------------------------------------------------------------------

$normalized = OrderNormalizer::normalize('ORDER123456', $completedOrder, 'sandbox', '/v2/checkout/orders/{order_id}');
assertTrue(($normalized['status'] ?? null) === 'COMPLETED', 'Normalizer preserves order status');
assertTrue(($normalized['intent'] ?? null) === 'CAPTURE', 'Normalizer preserves intent');
assertTrue(($normalized['amount']['value'] ?? null) === '10.00', 'Normalizer extracts amount value');
assertTrue(($normalized['amount']['currency_code'] ?? null) === 'USD', 'Normalizer extracts currency');
assertTrue(($normalized['capture_count'] ?? null) === 1, 'Normalizer counts one capture');
assertTrue(($normalized['captures'][0]['id'] ?? null) === 'CAPTURE123', 'Normalizer extracts capture ID');
assertTrue(($normalized['captures'][0]['seller_protection_status'] ?? null) === 'ELIGIBLE', 'Normalizer extracts seller protection status');

$emptyOrder = OrderNormalizer::normalize('ORDER123456', [], 'sandbox', '/v2/checkout/orders/{order_id}');
assertTrue(array_key_exists('status', $emptyOrder) && $emptyOrder['status'] === null, 'Normalizer returns null for missing status');
assertTrue(array_key_exists('amount', $emptyOrder) && $emptyOrder['amount'] === null, 'Normalizer returns null for missing amount');
assertTrue(($emptyOrder['captures'] ?? null) === [], 'Normalizer returns empty captures array');
assertTrue(($emptyOrder['capture_count'] ?? null) === 0, 'Normalizer counts zero captures');

$unexpectedOrder = OrderNormalizer::normalize('ORDER123456', ['status' => 'SOME_FUTURE_STATUS'], 'sandbox', '/v2/checkout/orders/{order_id}');
assertTrue(($unexpectedOrder['status'] ?? null) === 'SOME_FUTURE_STATUS', 'Normalizer preserves unexpected status without reinterpreting it');

// ---------------------------------------------------------------------------
// PayPalService tests.
// ---------------------------------------------------------------------------

$env = Env::fromArray([
    'PAYPAL_ENV' => 'sandbox',
    'PAYPAL_CLIENT_ID' => 'unit-test-client-id',
    'PAYPAL_SECRET' => 'unit-test-secret',
    'PAYPAL_API_BASE' => 'https://api-m.sandbox.paypal.com',
]);

$transport = new FakePayPalTransport();
$transport->addResponse(200, (string) $tokenResponse);
$transport->addResponse(200, json_encode($completedOrder));

$service = new PayPalService($env, $transport);
$result = $service->retrieveOrder('ORDER123456');

assertTrue(($result['status'] ?? null) === 'COMPLETED', 'Service returns normalized successful order');
assertTrue(count($transport->postCalls) === 1, 'Service requests access token once on success');
assertTrue(count($transport->getCalls) === 1, 'Service requests order once on success');
assertTrue(strpos($transport->getCalls[0]['url'], 'ORDER123456') !== false, 'Service requests the supplied order ID');
assertTrue(strpos($transport->getCalls[0]['headers'][0], 'Bearer fake-access-token') !== false, 'Service uses bearer token for order request');

$resultJson = json_encode($result);
assertTrue($resultJson !== false, 'Service result is JSON serializable');
assertTrue(strpos($resultJson, 'unit-test-secret') === false, 'Result does not contain PayPal secret');
assertTrue(strpos($resultJson, 'fake-access-token') === false, 'Result does not contain access token');

$authFailTransport = new FakePayPalTransport();
$authFailTransport->addResponse(401, json_encode(['name' => 'AUTHENTICATION_FAILURE']));

assertThrowsPayPal(static function () use ($env, $authFailTransport): void {
    (new PayPalService($env, $authFailTransport))->retrieveOrder('ORDER123456');
}, 'paypal_authentication_failed', 'Service maps token authentication failure');

$notFoundTransport = new FakePayPalTransport();
$notFoundTransport->addResponse(200, (string) $tokenResponse);
$notFoundTransport->addResponse(404, json_encode(['name' => 'RESOURCE_NOT_FOUND', 'debug_id' => 'test-debug-id']));

try {
    (new PayPalService($env, $notFoundTransport))->retrieveOrder('ORDER123456');
    assertTrue(false, 'Service maps order not found');
} catch (PayPalApiException $exception) {
    assertTrue($exception->getErrorCode() === 'paypal_order_not_found', 'Service maps order not found');
    assertTrue(stripos($exception->getMessage(), 'failed') === false, 'Order-not-found message does not claim payment failed');
}

$retryTransport = new FakePayPalTransport();
$retryTransport->addResponse(200, (string) $tokenResponse);
$retryTransport->addResponse(401, json_encode(['name' => 'AUTHENTICATION_FAILURE']));
$retryTransport->addResponse(200, (string) $tokenResponse);
$retryTransport->addResponse(200, json_encode($completedOrder));

$service = new PayPalService($env, $retryTransport);
$result = $service->retrieveOrder('ORDER123456');

assertTrue(($result['status'] ?? null) === 'COMPLETED', 'Service retries once after 401');
assertTrue(count($retryTransport->postCalls) === 2, 'Service requests a new token after 401');
assertTrue(count($retryTransport->getCalls) === 2, 'Service retries order request after 401');

$invalidTransport = new FakePayPalTransport();
assertThrowsPayPal(static function () use ($env, $invalidTransport): void {
    (new PayPalService($env, $invalidTransport))->retrieveOrder('bad id!');
}, 'invalid_reference', 'Service rejects invalid reference before contacting PayPal');
assertTrue(count($invalidTransport->postCalls) === 0, 'Invalid reference does not request token');
assertTrue(count($invalidTransport->getCalls) === 0, 'Invalid reference does not request order');

$missingCredentialsEnv = Env::fromArray(['PAYPAL_ENV' => 'sandbox']);
assertThrowsPayPal(static function () use ($missingCredentialsEnv): void {
    new PayPalService($missingCredentialsEnv, new FakePayPalTransport());
}, 'paypal_credentials_missing', 'Service rejects missing PayPal credentials');

$liveEnv = Env::fromArray(['PAYPAL_ENV' => 'live', 'PAYPAL_CLIENT_ID' => 'x', 'PAYPAL_SECRET' => 'y']);
assertThrowsPayPal(static function () use ($liveEnv): void {
    new PayPalService($liveEnv, new FakePayPalTransport());
}, 'paypal_environment_not_sandbox', 'Service rejects non-sandbox environment');

// ---------------------------------------------------------------------------
// RateLimiter tests.
// ---------------------------------------------------------------------------

$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorp-rate-' . uniqid('', true);
$limiter = new RateLimiter($rateDir);

assertTrue($limiter->allow('test-key', 2, 60), 'Rate limiter allows first request');
assertTrue($limiter->allow('test-key', 2, 60), 'Rate limiter allows second request');
assertTrue(!$limiter->allow('test-key', 2, 60), 'Rate limiter blocks third request');

foreach (glob($rateDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
    @unlink($file);
}
@rmdir($rateDir);

// ---------------------------------------------------------------------------
// InvestigationEngine tests.
// ---------------------------------------------------------------------------

$engine = new InvestigationEngine();

$completedData = [
    'source' => 'paypal',
    'environment' => 'sandbox',
    'endpoint' => '/v2/checkout/orders/{order_id}',
    'retrieved_at' => '2026-01-01T00:00:00Z',
    'reference' => 'ORDER123456',
    'status' => 'COMPLETED',
    'intent' => 'CAPTURE',
    'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
    'created_at' => '2026-01-01T00:00:00Z',
    'updated_at' => '2026-01-01T00:05:00Z',
    'purchase_unit_count' => 1,
    'capture_count' => 1,
    'captures' => [[
        'id' => 'CAPTURE123',
        'status' => 'COMPLETED',
        'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
        'created_at' => '2026-01-01T00:04:00Z',
        'updated_at' => '2026-01-01T00:05:00Z',
        'seller_protection_status' => 'ELIGIBLE',
    ]],
];

$inv = $engine->investigate($completedData);

assertTrue(hasFinding($inv['findings'], 'paypal_evidence_retrieved', 'confirmed'), 'Engine confirms PayPal evidence retrieval');
assertTrue(hasFinding($inv['findings'], 'order_status_completed', 'confirmed'), 'Engine confirms COMPLETED order status');
assertTrue(hasFinding($inv['findings'], 'capture_completed', 'confirmed'), 'Engine confirms COMPLETED capture status');
assertTrue(!hasFinding($inv['findings'], 'amount_value_mismatch'), 'Engine does not report amount mismatch when amounts match');
assertTrue(count($inv['next_steps']) > 0, 'Engine returns next steps for completed order');

$createdNoCapture = $completedData;
$createdNoCapture['status'] = 'CREATED';
$createdNoCapture['capture_count'] = 0;
$createdNoCapture['captures'] = [];

$inv = $engine->investigate($createdNoCapture);
$finding = findFinding($inv['findings'], 'order_created_without_capture');

assertTrue($finding !== null && ($finding['severity'] ?? '') === 'warning', 'Engine warns for CREATED order without capture');
assertTrue(
    $finding !== null && stripos((string) ($finding['detail'] ?? ''), 'not evidence that payment failed') !== false,
    'CREATED without capture does not claim payment failed'
);

$declinedData = $completedData;
$declinedData['captures'][0]['status'] = 'DECLINED';

$inv = $engine->investigate($declinedData);
$declinedJson = json_encode($inv);

assertTrue(hasFinding($inv['findings'], 'capture_declined', 'warning'), 'Engine warns for DECLINED capture');
assertTrue($declinedJson !== false && stripos($declinedJson, 'fraud') === false, 'Declined capture finding does not mention fraud');

$missingStatusData = $completedData;
$missingStatusData['status'] = null;

$inv = $engine->investigate($missingStatusData);
assertTrue(hasFinding($inv['findings'], 'order_status_missing', 'unknown'), 'Engine reports unknown when order status missing');

$unexpectedData = $completedData;
$unexpectedData['status'] = 'FUTURE_STATUS';

$inv = $engine->investigate($unexpectedData);
$finding = findFinding($inv['findings'], 'order_status_unrecognized');
$evidenceJson = json_encode($finding['evidence'] ?? []);

assertTrue($finding !== null && ($finding['severity'] ?? '') === 'unknown', 'Engine reports unknown for unrecognized status');
assertTrue($evidenceJson !== false && stripos($evidenceJson, 'FUTURE_STATUS') !== false, 'Unexpected status is preserved in evidence');

$amountMismatch = $completedData;
$amountMismatch['captures'][0]['amount']['value'] = '9.00';

$inv = $engine->investigate($amountMismatch);
assertTrue(hasFinding($inv['findings'], 'amount_value_mismatch', 'warning'), 'Engine warns when order and capture amounts differ');

$emptyInv = $engine->investigate([]);
assertTrue(hasFinding($emptyInv['findings'], 'order_status_missing', 'unknown'), 'Engine handles empty data with missing status');
assertTrue(hasFinding($emptyInv['findings'], 'order_amount_missing', 'unknown'), 'Engine handles empty data with missing amount');
assertTrue(hasFinding($emptyInv['findings'], 'no_purchase_units', 'unknown'), 'Engine handles empty data with no purchase units');

// ---------------------------------------------------------------------------
// GeminiPromptBuilder tests.
// ---------------------------------------------------------------------------

$builder = new GeminiPromptBuilder();

$built = $builder->build(
    [
        'reference' => 'ORDER123456',
        'status' => 'COMPLETED',
        'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
        'captures' => [],
        'secret' => 'super-secret-should-not-be-sent',
    ],
    [
        'findings' => [],
        'next_steps' => [],
    ],
    800
);

assertTrue(is_string($built['request_body'] ?? null), 'Gemini prompt builder returns request body');
assertTrue(strpos((string) ($built['request_body'] ?? ''), 'super-secret-should-not-be-sent') === false, 'Gemini prompt omits unexpected secret field');
assertTrue(strpos((string) ($built['request_body'] ?? ''), 'ORDER123456') !== false, 'Gemini prompt includes allowed reference');
assertTrue(in_array('ORDER123456', $built['allowed_identifiers'] ?? [], true), 'Gemini prompt builder collects allowed identifier');
assertTrue(in_array('10.00', $built['allowed_amounts'] ?? [], true), 'Gemini prompt builder collects allowed amount');
assertTrue(in_array('USD', $built['allowed_currencies'] ?? [], true), 'Gemini prompt builder collects allowed currency');

// ---------------------------------------------------------------------------
// GeminiService tests.
// ---------------------------------------------------------------------------

$geminiEnv = Env::fromArray([
    'GEMINI_API_KEY' => 'unit-test-gemini-key',
    'GEMINI_MODEL' => 'unit-test-model',
    'GEMINI_FALLBACK_MODELS' => '',
    'GEMINI_RESPONSE_MODE' => 'json',
    'GEMINI_API_BASE' => 'https://generativelanguage.googleapis.com/v1beta',
    'GEMINI_TIMEOUT_SECONDS' => '5',
    'GEMINI_MAX_OUTPUT_TOKENS' => '512',
]);

$aiOutput = [
    'summary' => 'The order status is COMPLETED and the capture amount is USD 10.00.',
    'facts' => ['PayPal returned COMPLETED for the order.'],
    'uncertainties' => [],
    'next_steps' => ['Verify the capture in PayPal Sandbox.'],
    'limitations' => ['This explanation is based only on supplied evidence.'],
];

$geminiTransport = new FakeGeminiTransport();
$geminiTransport->addResponse(200, geminiCandidate(json_encode($aiOutput)));

$geminiService = new GeminiService($geminiEnv, $geminiTransport);
$ai = $geminiService->explain($completedData, $inv);

assertTrue(($ai['origin'] ?? null) === 'gemini', 'Gemini service returns gemini origin');
assertTrue(($ai['available'] ?? null) === true, 'Gemini service marks explanation available');
assertTrue(($ai['summary'] ?? null) === $aiOutput['summary'], 'Gemini service returns validated summary');
assertTrue(count($geminiTransport->postCalls) === 1, 'Gemini service makes one request');
assertTrue(strpos($geminiTransport->postCalls[0]['url'], 'unit-test-model') !== false, 'Gemini service uses configured model in URL');
assertTrue(strpos((string) $geminiTransport->postCalls[0]['body'], 'unit-test-gemini-key') === false, 'Gemini request body does not contain API key');

$missingKeyEnv = Env::fromArray(['GEMINI_MODEL' => 'unit-test-model']);
assertThrowsGemini(static function () use ($missingKeyEnv): void {
    new GeminiService($missingKeyEnv, new FakeGeminiTransport());
}, 'gemini_key_missing', 'Gemini service rejects missing API key');

$rateLimitedTransport = new FakeGeminiTransport();
$rateLimitedTransport->addResponse(429, json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED']]));

assertThrowsGemini(static function () use ($geminiEnv, $rateLimitedTransport, $completedData, $inv): void {
    (new GeminiService($geminiEnv, $rateLimitedTransport))->explain($completedData, $inv);
}, 'gemini_rate_limited', 'Gemini service maps rate limit');

$malformedTransport = new FakeGeminiTransport();
$malformedTransport->addResponse(200, 'not json');

assertThrowsGemini(static function () use ($geminiEnv, $malformedTransport, $completedData, $inv): void {
    (new GeminiService($geminiEnv, $malformedTransport))->explain($completedData, $inv);
}, 'gemini_invalid_response', 'Gemini service rejects malformed API response');

$missingSummaryTransport = new FakeGeminiTransport();
$missingSummaryTransport->addResponse(200, geminiCandidate(json_encode([
    'facts' => [],
    'uncertainties' => [],
    'next_steps' => [],
    'limitations' => [],
])));

assertThrowsGemini(static function () use ($geminiEnv, $missingSummaryTransport, $completedData, $inv): void {
    (new GeminiService($geminiEnv, $missingSummaryTransport))->explain($completedData, $inv);
}, 'gemini_invalid_output', 'Gemini service rejects output without summary');

$unsupportedIdTransport = new FakeGeminiTransport();
$unsupportedIdTransport->addResponse(200, geminiCandidate(json_encode([
    'summary' => 'The related order was ORDER999999999999.',
    'facts' => [],
    'uncertainties' => [],
    'next_steps' => [],
    'limitations' => [],
])));

assertThrowsGemini(static function () use ($geminiEnv, $unsupportedIdTransport, $completedData, $inv): void {
    (new GeminiService($geminiEnv, $unsupportedIdTransport))->explain($completedData, $inv);
}, 'gemini_unsupported_identifier', 'Gemini service rejects unsupported transaction identifier');

$unsupportedAmountTransport = new FakeGeminiTransport();
$unsupportedAmountTransport->addResponse(200, geminiCandidate(json_encode([
    'summary' => 'The amount was USD 99.99.',
    'facts' => [],
    'uncertainties' => [],
    'next_steps' => [],
    'limitations' => [],
])));

assertThrowsGemini(static function () use ($geminiEnv, $unsupportedAmountTransport, $completedData, $inv): void {
    (new GeminiService($geminiEnv, $unsupportedAmountTransport))->explain($completedData, $inv);
}, 'gemini_unsupported_amount', 'Gemini service rejects unsupported amount');

$supportedAmountTransport = new FakeGeminiTransport();
$supportedAmountTransport->addResponse(200, geminiCandidate(json_encode([
    'summary' => 'The amount was USD 10.00.',
    'facts' => [],
    'uncertainties' => [],
    'next_steps' => [],
    'limitations' => [],
])));

$service = new GeminiService($geminiEnv, $supportedAmountTransport);
$ai = $service->explain($completedData, $inv);
assertTrue(($ai['origin'] ?? null) === 'gemini', 'Gemini service accepts supported amount');

// ---------------------------------------------------------------------------
// FallbackExplainer tests.
// ---------------------------------------------------------------------------

$fallback = new FallbackExplainer();

$dataWithFindings = $completedData;
$dataWithFindings['findings'] = $inv['findings'];
$dataWithFindings['next_steps'] = $inv['next_steps'];
$dataWithFindings['investigated_at'] = '2026-01-01T00:06:00Z';

$fallbackResult = $fallback->summarize($dataWithFindings, 'gemini_rate_limited', 'Gemini quota reached.');

assertTrue(($fallbackResult['origin'] ?? null) === 'deterministic_fallback', 'Fallback origin is deterministic');
assertTrue(($fallbackResult['available'] ?? null) === false, 'Fallback is marked unavailable for AI');
assertTrue(($fallbackResult['error_code'] ?? null) === 'gemini_rate_limited', 'Fallback preserves safe error code');
assertTrue(strpos((string) ($fallbackResult['summary'] ?? ''), 'COMPLETED') !== false, 'Fallback summary includes observed status');
assertTrue(count($fallbackResult['next_steps'] ?? []) > 0, 'Fallback includes next steps');
assertTrue(
    in_array('This summary was generated deterministically because Gemini was unavailable or invalid.', $fallbackResult['limitations'] ?? [], true),
    'Fallback includes limitation statement'
);

// ---------------------------------------------------------------------------
// InvestigationWorkflow tests (stateless Phase 2).
// ---------------------------------------------------------------------------

$workflowEnv = Env::fromArray([
    'PAYPAL_ENV' => 'sandbox',
    'PAYPAL_CLIENT_ID' => 'unit-test-client-id',
    'PAYPAL_SECRET' => 'unit-test-secret',
    'PAYPAL_API_BASE' => 'https://api-m.sandbox.paypal.com',
    'GEMINI_API_KEY' => 'unit-test-gemini-key',
    'GEMINI_MODEL' => 'unit-test-model',
    'GEMINI_FALLBACK_MODELS' => '',
    'GEMINI_RESPONSE_MODE' => 'json',
    'GEMINI_API_BASE' => 'https://generativelanguage.googleapis.com/v1beta',
]);

$wfPaypalOk = new FakePayPalTransport();
$wfPaypalOk->addResponse(200, (string) $tokenResponse);
$wfPaypalOk->addResponse(200, json_encode($completedOrder));

$wfGeminiOk = new FakeGeminiTransport();
$wfGeminiOk->addResponse(200, geminiCandidate(json_encode($aiOutput)));

$workflow = new InvestigationWorkflow($workflowEnv, $wfPaypalOk, $wfGeminiOk);
$report = $workflow->run('ORDER123456');

assertTrue(($report['status'] ?? null) === 'COMPLETED', 'Workflow returns PayPal status');
assertTrue(($report['reference'] ?? null) === 'ORDER123456', 'Workflow returns order reference');
assertTrue(is_array($report['findings'] ?? null) && hasFinding($report['findings'], 'paypal_evidence_retrieved', 'confirmed'), 'Workflow attaches deterministic findings');
assertTrue(is_array($report['next_steps'] ?? null) && count($report['next_steps']) > 0, 'Workflow attaches rule next steps');
assertTrue(($report['investigated_at'] ?? null) !== null, 'Workflow sets investigated_at');
assertTrue(($report['ai']['origin'] ?? null) === 'gemini', 'Workflow uses Gemini when available');
assertTrue(($report['ai_available'] ?? null) === true, 'Workflow marks AI available');
assertTrue(($report['report_state'] ?? null) === 'complete', 'Workflow marks report complete');
assertTrue(($report['report_generated_at'] ?? null) !== null, 'Workflow sets report_generated_at');
assertTrue(!array_key_exists('persisted', $report), 'Phase 2 workflow report has no persisted field');
assertTrue(!array_key_exists('investigation_id', $report), 'Phase 2 workflow report has no investigation_id field');
assertTrue(!array_key_exists('persistence_error', $report), 'Phase 2 workflow report has no persistence_error field');

$reportJson = json_encode($report);
assertTrue(
    $reportJson !== false
        && strpos($reportJson, 'unit-test-secret') === false
        && strpos($reportJson, 'unit-test-gemini-key') === false
        && strpos($reportJson, 'fake-access-token') === false,
    'Workflow report does not leak secrets or tokens'
);

$wfPaypalOk2 = new FakePayPalTransport();
$wfPaypalOk2->addResponse(200, (string) $tokenResponse);
$wfPaypalOk2->addResponse(200, json_encode($completedOrder));

$wfGeminiFail = new FakeGeminiTransport();
$wfGeminiFail->addResponse(429, json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED']]));

$workflow = new InvestigationWorkflow($workflowEnv, $wfPaypalOk2, $wfGeminiFail);
$report = $workflow->run('ORDER123456');

assertTrue(($report['status'] ?? null) === 'COMPLETED', 'Workflow still returns PayPal status when Gemini fails');
assertTrue(is_array($report['findings'] ?? null) && count($report['findings']) > 0, 'Workflow still returns findings when Gemini fails');
assertTrue(($report['ai']['origin'] ?? null) === 'deterministic_fallback', 'Workflow falls back when Gemini fails');
assertTrue(($report['ai_available'] ?? null) === false, 'Workflow marks AI unavailable on fallback');
assertTrue(($report['report_state'] ?? null) === 'complete', 'Workflow report still complete when only Gemini fails');
assertTrue(($report['ai']['error_code'] ?? null) === 'gemini_rate_limited', 'Fallback records the Gemini error code');

$wfPaypalOk3 = new FakePayPalTransport();
$wfPaypalOk3->addResponse(200, (string) $tokenResponse);
$wfPaypalOk3->addResponse(200, json_encode($completedOrder));

$wfGeminiBad = new FakeGeminiTransport();
$wfGeminiBad->addResponse(200, geminiCandidate(json_encode([
    'summary' => 'The related order was ORDER999999999999.',
    'facts' => [],
    'uncertainties' => [],
    'next_steps' => [],
    'limitations' => [],
])));

$workflow = new InvestigationWorkflow($workflowEnv, $wfPaypalOk3, $wfGeminiBad);
$report = $workflow->run('ORDER123456');

assertTrue(($report['ai']['origin'] ?? null) === 'deterministic_fallback', 'Workflow rejects unsupported Gemini identifier and falls back');
assertTrue(($report['ai']['error_code'] ?? null) === 'gemini_unsupported_identifier', 'Fallback records unsupported-identifier error code');

$wfPaypalNotFound = new FakePayPalTransport();
$wfPaypalNotFound->addResponse(200, (string) $tokenResponse);
$wfPaypalNotFound->addResponse(404, json_encode(['name' => 'RESOURCE_NOT_FOUND', 'debug_id' => 'test-debug-id']));

$wfGeminiShouldNotBeCalled = new FakeGeminiTransport();

$workflow = new InvestigationWorkflow($workflowEnv, $wfPaypalNotFound, $wfGeminiShouldNotBeCalled);

try {
    $workflow->run('ORDER123456');
    assertTrue(false, 'Workflow throws when required PayPal step fails');
} catch (PayPalApiException $exception) {
    assertTrue($exception->getErrorCode() === 'paypal_order_not_found', 'Workflow propagates PayPal not-found error');
    assertTrue(count($wfGeminiShouldNotBeCalled->postCalls) === 0, 'Workflow does not call Gemini when PayPal step failed');
}

$workflowNoKeyEnv = Env::fromArray([
    'PAYPAL_ENV' => 'sandbox',
    'PAYPAL_CLIENT_ID' => 'unit-test-client-id',
    'PAYPAL_SECRET' => 'unit-test-secret',
    'PAYPAL_API_BASE' => 'https://api-m.sandbox.paypal.com',
    'GEMINI_MODEL' => 'unit-test-model',
]);

$wfPaypalOk4 = new FakePayPalTransport();
$wfPaypalOk4->addResponse(200, (string) $tokenResponse);
$wfPaypalOk4->addResponse(200, json_encode($completedOrder));

$workflow = new InvestigationWorkflow($workflowNoKeyEnv, $wfPaypalOk4, new FakeGeminiTransport());
$report = $workflow->run('ORDER123456');

assertTrue(($report['ai']['origin'] ?? null) === 'deterministic_fallback', 'Workflow falls back when Gemini key is missing');
assertTrue(($report['ai']['error_code'] ?? null) === 'gemini_key_missing', 'Fallback records missing-key error code');
assertTrue(($report['report_state'] ?? null) === 'complete', 'Report is still complete when only the optional AI layer is missing');

// ---------------------------------------------------------------------------
// AuditLogger tests (metadata-only, non-fatal).
// ---------------------------------------------------------------------------

$auditDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorp-audit-' . uniqid('', true);
$auditLogger = new AuditLogger($auditDir, true);

$logged = $auditLogger->log('investigate.complete', [
    'reference' => 'ORDER123456',
    'outcome' => 'complete',
    'ai_origin' => 'gemini',
    'ai_available' => true,
    'duration_ms' => 12,
    'amount' => '10.00',
    'order_status' => 'COMPLETED',
    'summary' => 'This must not be logged',
    'secret' => 'super-secret-value',
    'ip' => '203.0.113.9',
    'body' => ['nested' => 'structure'],
    'captures' => [['id' => 'CAPTURE123']],
]);

assertTrue($logged === true, 'AuditLogger log returns true on success');
assertTrue(is_dir($auditDir), 'AuditLogger creates its base directory');

$auditFiles = glob($auditDir . DIRECTORY_SEPARATOR . 'audit-*.log') ?: [];
assertTrue(count($auditFiles) === 1, 'AuditLogger writes exactly one dated file');
assertTrue((bool) preg_match('/\Aaudit-\d{8}\.log\z/', basename($auditFiles[0])), 'AuditLogger file name is audit-YYYYMMDD.log');
assertTrue($auditLogger->currentFileName() === basename($auditFiles[0]), 'AuditLogger currentFileName matches written file');

$auditLine = trim((string) file_get_contents($auditFiles[0]));
$auditDecoded = json_decode($auditLine, true);

assertTrue(is_array($auditDecoded), 'AuditLogger writes a single JSON line');
assertTrue(($auditDecoded['event'] ?? null) === 'investigate.complete', 'AuditLogger records the event');
assertTrue(isset($auditDecoded['ts']), 'AuditLogger records a timestamp');
assertTrue(($auditDecoded['reference'] ?? null) === 'ORDER123456', 'AuditLogger keeps allow-listed reference');
assertTrue(($auditDecoded['outcome'] ?? null) === 'complete', 'AuditLogger keeps allow-listed outcome');
assertTrue(($auditDecoded['ai_origin'] ?? null) === 'gemini', 'AuditLogger keeps allow-listed ai_origin');
assertTrue(($auditDecoded['duration_ms'] ?? null) === 12, 'AuditLogger keeps allow-listed duration_ms');

foreach (['amount', 'order_status', 'summary', 'secret', 'ip', 'body', 'captures'] as $forbidden) {
    assertTrue(!array_key_exists($forbidden, $auditDecoded), "AuditLogger drops disallowed key '{$forbidden}'");
}

$auditRaw = (string) file_get_contents($auditFiles[0]);
assertTrue(strpos($auditRaw, 'super-secret-value') === false, 'AuditLogger file does not contain a passed secret value');
assertTrue(strpos($auditRaw, 'This must not be logged') === false, 'AuditLogger file does not contain passed free text');
assertTrue(strpos($auditRaw, '203.0.113.9') === false, 'AuditLogger file does not contain an IP address');
assertTrue(strpos($auditRaw, 'CAPTURE123') === false, 'AuditLogger file does not contain nested capture data');

$auditBlocker = tempnam(sys_get_temp_dir(), 'scorp-audit-block-');
$blockedLogger = new AuditLogger($auditBlocker, true);
$blockedResult = $blockedLogger->log('investigate.complete', ['reference' => 'X']);
assertTrue($blockedResult === false, 'AuditLogger log returns false (no throw) when the directory cannot be created');
@unlink($auditBlocker);

$auditDirOff = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scorp-audit-off-' . uniqid('', true);
$offLogger = new AuditLogger($auditDirOff, false);
assertTrue($offLogger->isEnabled() === false, 'AuditLogger disabled mode reports disabled');
assertTrue($offLogger->log('investigate.complete', ['reference' => 'Y']) === true, 'Disabled AuditLogger log returns true without writing');
assertTrue(!is_dir($auditDirOff), 'Disabled AuditLogger does not create its directory');

assertTrue(AuditLogger::isAllowedKey('reference') === true, 'AuditLogger isAllowedKey true for allowed key');
assertTrue(AuditLogger::isAllowedKey('amount') === false, 'AuditLogger isAllowedKey false for disallowed key');
assertTrue(in_array('outcome', AuditLogger::allowedKeys(), true), 'AuditLogger allowedKeys includes outcome');
$elapsed = AuditLogger::elapsedMs(AuditLogger::now());
assertTrue(is_int($elapsed) && $elapsed >= 0, 'AuditLogger elapsedMs returns a non-negative integer');

foreach ($auditFiles as $f) {
    @unlink($f);
}
@rmdir($auditDir);

fwrite(STDOUT, "\n{$tests} assertions, {$failures} failures\n");

exit($failures === 0 ? 0 : 1);