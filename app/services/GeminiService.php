<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use JsonException;

final class GeminiService
{
    private string $apiKey;

    private string $apiBase;

    /**
     * @var string[]
     */
    private array $models = [];

    private bool $autoDiscover = false;

    private int $timeoutSeconds;

    private int $maxOutputTokens;

    private int $maxModelAttempts;

    private int $maxTotalRequests;

    private int $requestsMade = 0;

    private string $responseMode = 'json';

    private GeminiTransport $transport;

    private GeminiPromptBuilder $promptBuilder;

    /**
     * @var string[]|null
     */
    private ?array $discoveredCandidates = null;

    public function __construct(Env $env, ?GeminiTransport $transport = null)
    {
        $apiKey = trim((string) $env->get('GEMINI_API_KEY', ''));

        if ($apiKey === '') {
            throw new GeminiApiException(
                'gemini_key_missing',
                'Gemini not configured',
                'The Gemini API key is not configured.'
            );
        }

        $apiBase = rtrim(trim((string) $env->get('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta')), '/');

        if ($apiBase === '' || !str_starts_with($apiBase, 'https://')) {
            throw new GeminiApiException(
                'gemini_config_invalid',
                'Gemini configuration error',
                'GEMINI_API_BASE must be an HTTPS URL.'
            );
        }

        $timeout = (int) $env->get('GEMINI_TIMEOUT_SECONDS', '45');
        $maxTokens = (int) $env->get('GEMINI_MAX_OUTPUT_TOKENS', '600');
        $maxAttempts = (int) $env->get('GEMINI_MAX_MODEL_ATTEMPTS', '3');
        $maxRequests = (int) $env->get('GEMINI_MAX_TOTAL_REQUESTS', '4');

        $responseMode = $this->normalizeResponseMode(
            (string) $env->get('GEMINI_RESPONSE_MODE', $this->legacyResponseMode($env))
        );

        $primaryRaw = $env->raw('GEMINI_MODEL');

        if ($primaryRaw === null) {
            $primaryRaw = 'auto';
        }

        $primaryRaw = trim($primaryRaw);

        if ($primaryRaw === '') {
            throw new GeminiApiException(
                'gemini_model_missing',
                'Gemini not configured',
                'The Gemini model is not configured.'
            );
        }

        if (strcasecmp($primaryRaw, 'auto') === 0) {
            $this->autoDiscover = true;
            $this->models = [];
        } else {
            $this->autoDiscover = false;

            $primaryParts = preg_split('/[\s,]+/', $primaryRaw) ?: [];

            foreach ($primaryParts as $part) {
                $model = $this->normalizeModel($part);

                if ($model !== '' && !in_array($model, $this->models, true)) {
                    $this->models[] = $model;
                }
            }

            if ($this->models === []) {
                throw new GeminiApiException(
                    'gemini_model_missing',
                    'Gemini not configured',
                    'The Gemini model is not configured.'
                );
            }

            $fallbackRaw = $env->raw('GEMINI_FALLBACK_MODELS');

            if ($fallbackRaw === null) {
                $fallbackRaw = '';
            }

            $fallbackParts = preg_split('/[\s,]+/', trim($fallbackRaw)) ?: [];

            foreach ($fallbackParts as $part) {
                $model = $this->normalizeModel($part);

                if ($model !== '' && !in_array($model, $this->models, true)) {
                    $this->models[] = $model;
                }
            }
        }

        $this->apiKey = $apiKey;
        $this->apiBase = $apiBase;
        $this->timeoutSeconds = max(5, min(90, $timeout));
        $this->maxOutputTokens = max(128, min(2048, $maxTokens));
        $this->maxModelAttempts = max(1, min(6, $maxAttempts));
        $this->maxTotalRequests = max(1, min(8, $maxRequests));
        $this->responseMode = $responseMode;
        $this->transport = $transport ?? new CurlGeminiTransport($this->timeoutSeconds);
        $this->promptBuilder = new GeminiPromptBuilder();

        if (!$this->autoDiscover) {
            $this->models = array_slice($this->models, 0, $this->maxModelAttempts);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $investigation
     *
     * @return array<string, mixed>
     */
    public function explain(array $data, array $investigation): array
    {
        $candidates = $this->autoDiscover
            ? $this->candidateModels()
            : $this->models;

        if ($candidates === []) {
            throw new GeminiApiException(
                'gemini_no_model',
                'No Gemini model configured',
                'No Gemini model was available.'
            );
        }

        $lastException = null;
        $attempted = [];
        $total = count($candidates);

        foreach ($candidates as $index => $model) {
            if ($index >= $this->maxModelAttempts) {
                break;
            }

            try {
                return $this->explainWithModel($model, $data, $investigation);
            } catch (GeminiApiException $exception) {
                $lastException = $exception;

                $attempted[] = $model . ' -> '
                    . $exception->getErrorCode()
                    . ' (HTTP ' . $exception->getHttpStatus() . ')';

                if (
                    $exception->getErrorCode() === 'gemini_request_limit'
                    || !$this->shouldTryNextCandidate($exception, $index, $total)
                ) {
                    break;
                }
            }
        }

        if ($lastException === null) {
            throw new GeminiApiException(
                'gemini_no_model',
                'No Gemini model configured',
                'No Gemini model was available.'
            );
        }

        throw new GeminiApiException(
            $lastException->getErrorCode(),
            $lastException->getTitle(),
            $lastException->getMessage(),
            $lastException->getHttpStatus(),
            array_values(array_unique(array_merge(
                ['Attempted models: ' . implode('; ', $attempted)],
                $lastException->getDetails()
            )))
        );
    }

    /**
     * @return string[]
     */
    private function candidateModels(): array
    {
        if ($this->discoveredCandidates !== null) {
            return $this->discoveredCandidates;
        }

        $available = $this->listModels();

        if ($available === []) {
            throw new GeminiApiException(
                'gemini_no_available_model',
                'No Gemini model available',
                'The Gemini API did not return any model supported for generateContent.'
            );
        }

        $candidates = $this->chooseCandidates($available);

        $this->discoveredCandidates = $candidates;

        return $candidates;
    }

    /**
     * @param string[] $available
     *
     * @return string[]
     */
    private function chooseCandidates(array $available): array
    {
        $unique = array_values(array_unique($available));

        usort($unique, static function (string $left, string $right): int {
            $leftScore = self::modelScore($left);
            $rightScore = self::modelScore($right);

            if ($leftScore === $rightScore) {
                return strcmp($left, $right);
            }

            return $rightScore <=> $leftScore;
        });

        return array_slice($unique, 0, $this->maxModelAttempts);
    }

    private static function modelScore(string $model): int
    {
        $preferred = [
            'gemini-3.6-flash',
            'gemini-3.5-flash',
            'gemini-3.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite',
            'gemini-1.5-flash',
            'gemini-1.5-flash-lite',
            'gemini-flash-lite-latest',
            'gemini-flash-latest',
        ];

        $index = array_search($model, $preferred, true);

        if ($index !== false) {
            return 10000 - ($index * 100);
        }

        $lower = strtolower($model);

        if (str_contains($lower, '3.6-flash')) {
            return 9500;
        }

        if (str_contains($lower, '3.5-flash-lite')) {
            return 9400;
        }

        if (str_contains($lower, '3.5-flash')) {
            return 9300;
        }

        if (str_contains($lower, 'flash-lite')) {
            return 5000;
        }

        if (str_contains($lower, 'lite')) {
            return 4500;
        }

        if (str_contains($lower, 'flash')) {
            return 4000;
        }

        if (str_contains($lower, 'gemini')) {
            return 1000;
        }

        return 0;
    }

    private function shouldTryNextCandidate(
        GeminiApiException $exception,
        int $index,
        int $total
    ): bool {
        if ($index >= $total - 1) {
            return false;
        }

        return in_array(
            $exception->getErrorCode(),
            [
                'gemini_model_not_found',
                'gemini_service_error',
                'gemini_api_error',
                'gemini_rate_limited',
                'gemini_network_error',
                'gemini_no_candidate',
                'gemini_empty_response',
                'gemini_invalid_response',
                'gemini_invalid_output',
                'gemini_invalid_model_list',
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $investigation
     *
     * @return array<string, mixed>
     */
    private function explainWithModel(string $model, array $data, array $investigation): array
    {
        $modes = $this->modeSequence();
        $lastException = null;

        foreach ($modes as $mode) {
            try {
                return $this->request($model, $data, $investigation, $mode);
            } catch (GeminiApiException $exception) {
                $lastException = $exception;

                if (!$this->shouldTryNextMode($exception)) {
                    break;
                }
            }
        }

        throw $lastException ?? new GeminiApiException(
            'gemini_no_mode',
            'No Gemini response mode available',
            'No Gemini response mode was available.'
        );
    }

    /**
     * @return string[]
     */
    private function modeSequence(): array
    {
        if ($this->responseMode === 'schema') {
            return ['schema', 'json'];
        }

        if ($this->responseMode === 'json') {
            return ['json', 'text'];
        }

        return ['text'];
    }

    private function shouldTryNextMode(GeminiApiException $exception): bool
    {
        return in_array(
            $exception->getErrorCode(),
            [
                'gemini_bad_request',
                'gemini_validation_failed',
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $investigation
     *
     * @return array<string, mixed>
     */
    private function request(string $model, array $data, array $investigation, string $mode): array
    {
        if ($this->requestsMade >= $this->maxTotalRequests) {
            throw new GeminiApiException(
                'gemini_request_limit',
                'Gemini request limit reached',
                'SCORP AI stopped retrying Gemini to avoid excessive API usage.'
            );
        }

        $this->requestsMade++;

        $built = $this->promptBuilder->build(
            $data,
            $investigation,
            $this->maxOutputTokens,
            $mode
        );

        $url = $this->apiBase . '/models/' . rawurlencode($model) . ':generateContent';

        $response = $this->transport->postJson($url, [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-goog-api-key: ' . $this->apiKey,
        ], (string) $built['request_body']);

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status === 0) {
            throw new GeminiApiException(
                'gemini_network_error',
                'Gemini connection error',
                'The server could not reach the Gemini API.',
                0,
                array_values(array_filter([(string) ($response['error'] ?? '')]))
            );
        }

        if ($status < 200 || $status >= 300) {
            throw GeminiApiException::fromHttp($status, $body);
        }

        $text = $this->extractResponseText($body);
        $output = $this->decodeModelJson($text);
        $sanitized = $this->validateOutput($output);

        $this->assertGrounded(
            $sanitized,
            (array) ($built['allowed_identifiers'] ?? []),
            (array) ($built['allowed_amounts'] ?? []),
            (array) ($built['allowed_currencies'] ?? [])
        );

        return [
            'origin' => 'gemini',
            'available' => true,
            'model' => $model,
            'response_mode' => (string) ($built['response_mode'] ?? $mode),
            'generated_at' => gmdate('c'),
            'summary' => $sanitized['summary'],
            'facts' => $sanitized['facts'],
            'uncertainties' => $sanitized['uncertainties'],
            'next_steps' => $sanitized['next_steps'],
            'limitations' => $sanitized['limitations'],
        ];
    }

    private function legacyResponseMode(Env $env): string
    {
        $useSchemaRaw = $env->raw('GEMINI_USE_RESPONSE_SCHEMA');

        if ($useSchemaRaw === null) {
            return 'json';
        }

        $useSchema = filter_var(trim($useSchemaRaw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return ($useSchema ?? false) ? 'schema' : 'json';
    }

    private function normalizeResponseMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        if (in_array($mode, ['schema', 'json', 'text'], true)) {
            return $mode;
        }

        return 'json';
    }

    private function canDiscover(): bool
    {
        return method_exists($this->transport, 'getJson');
    }

    /**
     * @return string[]
     */
    private function listModels(): array
    {
        if (!$this->canDiscover()) {
            throw new GeminiApiException(
                'gemini_discovery_unavailable',
                'Gemini model discovery unavailable',
                'The configured Gemini transport does not support model discovery.'
            );
        }

        $models = [];
        $pageToken = null;

        for ($page = 0; $page < 10; $page++) {
            if ($this->requestsMade >= $this->maxTotalRequests) {
                throw new GeminiApiException(
                    'gemini_request_limit',
                    'Gemini request limit reached',
                    'SCORP AI stopped retrying Gemini to avoid excessive API usage.'
                );
            }

            $this->requestsMade++;

            $url = $this->apiBase . '/models?pageSize=100';

            if ($pageToken !== null && $pageToken !== '') {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            $response = $this->transport->getJson($url, [
                'Accept: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ]);

            $status = (int) ($response['status'] ?? 0);
            $body = (string) ($response['body'] ?? '');

            if ($status === 0) {
                throw new GeminiApiException(
                    'gemini_network_error',
                    'Gemini connection error',
                    'The server could not reach the Gemini API.',
                    0,
                    array_values(array_filter([(string) ($response['error'] ?? '')]))
                );
            }

            if ($status < 200 || $status >= 300) {
                throw GeminiApiException::fromHttp($status, $body);
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                throw new GeminiApiException(
                    'gemini_invalid_model_list',
                    'Unexpected Gemini response',
                    'The Gemini API returned an invalid model list response.'
                );
            }

            $entries = $decoded['models'] ?? [];

            if (!is_array($entries)) {
                $entries = [];
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = isset($entry['name']) && is_string($entry['name'])
                    ? trim($entry['name'])
                    : '';

                if ($name === '') {
                    continue;
                }

                $name = $this->normalizeModel($name);

                if ($name === '') {
                    continue;
                }

                $methods = $entry['supportedGenerationMethods'] ?? [];

                if (!is_array($methods)) {
                    $methods = [];
                }

                $supportsGenerateContent = $methods === []
                    || in_array('generateContent', $methods, true);

                if (!$supportsGenerateContent) {
                    continue;
                }

                $models[] = $name;
            }

            $pageToken = isset($decoded['nextPageToken']) && is_string($decoded['nextPageToken'])
                ? trim($decoded['nextPageToken'])
                : null;

            if ($pageToken === null || $pageToken === '') {
                break;
            }
        }

        return array_values(array_unique($models));
    }

    private function normalizeModel(string $model): string
    {
        $model = trim($model);

        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return trim($model);
    }

    private function extractResponseText(string $body): string
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new GeminiApiException(
                'gemini_invalid_response',
                'Unexpected Gemini response',
                'The Gemini API returned an invalid response.'
            );
        }

        if (isset($decoded['promptFeedback']['blockReason']) && is_string($decoded['promptFeedback']['blockReason'])) {
            throw new GeminiApiException(
                'gemini_blocked',
                'Gemini explanation blocked',
                'The Gemini API blocked the explanation request.'
            );
        }

        $candidates = $decoded['candidates'] ?? null;

        if (!is_array($candidates) || $candidates === []) {
            throw new GeminiApiException(
                'gemini_no_candidate',
                'No Gemini explanation returned',
                'The Gemini API did not return an explanation candidate.'
            );
        }

        $first = $candidates[0];

        if (!is_array($first)) {
            throw new GeminiApiException(
                'gemini_invalid_response',
                'Unexpected Gemini response',
                'The Gemini API returned an invalid candidate.'
            );
        }

        $content = $first['content'] ?? null;

        if (!is_array($content)) {
            throw new GeminiApiException(
                'gemini_invalid_response',
                'Unexpected Gemini response',
                'The Gemini API returned a candidate without content.'
            );
        }

        $parts = $content['parts'] ?? null;

        if (!is_array($parts) || $parts === []) {
            throw new GeminiApiException(
                'gemini_invalid_response',
                'Unexpected Gemini response',
                'The Gemini API returned a candidate without text parts.'
            );
        }

        $text = '';

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);

        if ($text === '') {
            throw new GeminiApiException(
                'gemini_empty_response',
                'Empty Gemini explanation',
                'The Gemini API returned an empty explanation.'
            );
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeModelJson(string $text): array
    {
        $json = $this->extractJsonObject($text);

        if ($json === null) {
            throw new GeminiApiException(
                'gemini_invalid_output',
                'Invalid Gemini explanation',
                'The Gemini explanation did not contain a valid JSON object.'
            );
        }

        $decoded = json_decode($json, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);

            if (is_array($inner)) {
                return $inner;
            }
        }

        $sanitized = $this->sanitizeJsonString($json);
        $decoded = json_decode($sanitized, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);

            if (is_array($inner)) {
                return $inner;
            }
        }

        throw new GeminiApiException(
            'gemini_invalid_output',
            'Invalid Gemini explanation',
            'The Gemini explanation was not valid JSON.'
        );
    }

    private function extractJsonObject(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/```(?:json|JSON)?\s*/', '', $text);
        $text = (string) preg_replace('/\s*```/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $text;
        }

        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);

            if (is_array($inner)) {
                return $decoded;
            }
        }

        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\' && $inString) {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
                continue;
            }

            if ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        $end = strrpos($text, '}');

        if ($end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return null;
    }

    private function sanitizeJsonString(string $json): string
    {
        $json = (string) preg_replace('/^\xEF\xBB\xBF/', '', $json);

        $json = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{00A0}"],
            ['"', '"', "'", "'", ' '],
            $json
        );

        $json = (string) preg_replace('/,\s*([}\]])/', '$1', $json);

        return trim($json);
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return array{
     *     summary: string,
     *     facts: string[],
     *     uncertainties: string[],
     *     next_steps: string[],
     *     limitations: string[]
     * }
     */
    private function validateOutput(array $output): array
    {
        $summary = $this->sanitizeString($output['summary'] ?? null, 1200);

        if ($summary === null || $summary === '') {
            throw new GeminiApiException(
                'gemini_invalid_output',
                'Invalid Gemini explanation',
                'The Gemini explanation did not include a summary.'
            );
        }

        return [
            'summary' => $summary,
            'facts' => $this->sanitizeStringArray($output['facts'] ?? null, 12, 500),
            'uncertainties' => $this->sanitizeStringArray($output['uncertainties'] ?? null, 12, 500),
            'next_steps' => $this->sanitizeStringArray($output['next_steps'] ?? null, 12, 500),
            'limitations' => $this->sanitizeStringArray($output['limitations'] ?? null, 12, 500),
        ];
    }

    private function sanitizeString(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength) . '...';
        }

        return $value;
    }

    /**
     * @return string[]
     */
    private function sanitizeStringArray(mixed $value, int $maxItems, int $maxLength): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw new GeminiApiException(
                'gemini_invalid_output',
                'Invalid Gemini explanation',
                'The Gemini explanation contained an invalid list.'
            );
        }

        if (count($value) > $maxItems) {
            throw new GeminiApiException(
                'gemini_invalid_output',
                'Invalid Gemini explanation',
                'The Gemini explanation contained too many list items.'
            );
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new GeminiApiException(
                    'gemini_invalid_output',
                    'Invalid Gemini explanation',
                    'The Gemini explanation contained a non-string list item.'
                );
            }

            $clean = $this->sanitizeString($item, $maxLength);

            if ($clean !== null && $clean !== '') {
                $result[] = $clean;
            }
        }

        return $result;
    }

    /**
     * @param array{
     *     summary: string,
     *     facts: string[],
     *     uncertainties: string[],
     *     next_steps: string[],
     *     limitations: string[]
     * } $output
     * @param string[] $allowedIdentifiers
     * @param string[] $allowedAmounts
     * @param string[] $allowedCurrencies
     */
    private function assertGrounded(array $output, array $allowedIdentifiers, array $allowedAmounts, array $allowedCurrencies): void
    {
        $text = implode("\n", array_merge(
            [$output['summary']],
            $output['facts'],
            $output['uncertainties'],
            $output['next_steps'],
            $output['limitations']
        ));

        $identifierMap = [];

        foreach ($allowedIdentifiers as $identifier) {
            $identifierMap[strtoupper((string) $identifier)] = true;
        }

        if (preg_match_all('/\b[A-Z0-9_-]{13,}\b/', $text, $matches) === 1) {
            foreach (array_unique($matches[0]) as $token) {
                if (!isset($identifierMap[strtoupper((string) $token)])) {
                    throw new GeminiApiException(
                        'gemini_unsupported_identifier',
                        'Unsupported Gemini identifier',
                        'The Gemini explanation referenced a transaction identifier not present in the supplied evidence.'
                    );
                }
            }
        }

        $amountMap = [];

        foreach ($allowedAmounts as $amount) {
            $normalized = $this->normalizeAmount((string) $amount);

            if ($normalized !== '') {
                $amountMap[$normalized] = true;
            }
        }

        $currencyMap = [];

        foreach ($allowedCurrencies as $currency) {
            $currency = strtoupper(trim((string) $currency));

            if ($currency !== '') {
                $currencyMap[$currency] = true;
            }
        }

        $patterns = [
            '/\b(USD|EUR|GBP|CAD|AUD|NZD|JPY)\s*([0-9]+(?:[.,][0-9]{1,2})?)\b/i',
            '/\b([0-9]+(?:[.,][0-9]{1,2})?)\s*(USD|EUR|GBP|CAD|AUD|NZD|JPY)\b/i',
            '/\$([0-9]+(?:[.,][0-9]{1,2})?)/',
        ];

        foreach ($patterns as $patternIndex => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                if (count($match) < 2) {
                    continue;
                }

                if ($patternIndex === 0) {
                    $currency = strtoupper((string) ($match[1] ?? ''));
                    $amount = (string) ($match[2] ?? '');
                } elseif ($patternIndex === 1) {
                    $amount = (string) ($match[1] ?? '');
                    $currency = strtoupper((string) ($match[2] ?? ''));
                } else {
                    $amount = (string) ($match[1] ?? '');
                    $currency = 'USD';
                }

                $normalizedAmount = $this->normalizeAmount($amount);

                if ($normalizedAmount === '' || !isset($amountMap[$normalizedAmount])) {
                    throw new GeminiApiException(
                        'gemini_unsupported_amount',
                        'Unsupported Gemini amount',
                        'The Gemini explanation referenced an amount not present in the supplied evidence.'
                    );
                }

                if (!isset($currencyMap[$currency])) {
                    throw new GeminiApiException(
                        'gemini_unsupported_amount',
                        'Unsupported Gemini currency',
                        'The Gemini explanation referenced a currency not present in the supplied evidence.'
                    );
                }
            }
        }
    }

    private function normalizeAmount(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));

        if (!is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }
}