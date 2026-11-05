<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class GeminiApiException extends RuntimeException
{
    private string $errorCode;

    private string $title;

    private int $httpStatus;

    /**
     * @var string[]
     */
    private array $details;

    /**
     * @param string[] $details
     */
    public function __construct(
        string $errorCode,
        string $title,
        string $message,
        int $httpStatus = 0,
        array $details = []
    ) {
        parent::__construct($message);

        $this->errorCode = $errorCode;
        $this->title = $title;
        $this->httpStatus = $httpStatus;
        $this->details = array_values(array_filter(
            $details,
            static fn ($value): bool => is_string($value) && $value !== ''
        ));
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return string[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public static function fromHttp(int $status, string $body): self
    {
        $providerStatus = null;
        $providerMessage = null;

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;

            if (is_array($error)) {
                $providerStatus = self::safeString($error['status'] ?? null, 80);
                $providerMessage = self::safeProviderMessage($error['message'] ?? null);
            }
        }

        $details = array_values(array_filter([
            'Gemini HTTP status: ' . $status,
            $providerStatus !== null ? 'Provider status: ' . $providerStatus : null,
            $providerMessage !== null ? 'Provider message: ' . $providerMessage : null,
        ], static fn ($value): bool => is_string($value) && $value !== ''));

        if ($status === 0) {
            return new self(
                'gemini_network_error',
                'Gemini connection error',
                'The server could not reach the Gemini API.',
                0,
                $details
            );
        }

        if ($status === 400) {
            return new self(
                'gemini_bad_request',
                'Gemini rejected the request',
                'The Gemini API rejected the investigation explanation request.',
                $status,
                $details
            );
        }

        if ($status === 401 || $status === 403) {
            return new self(
                'gemini_authentication_failed',
                'Gemini authentication failed',
                'Check the configured Gemini API key and model access.',
                $status,
                $details
            );
        }

        if ($status === 404) {
            return new self(
                'gemini_model_not_found',
                'Gemini model not found',
                'The configured Gemini model was not found or is not available to this API key.',
                $status,
                $details
            );
        }

        if ($status === 422) {
            return new self(
                'gemini_validation_failed',
                'Gemini validation failed',
                'The Gemini API rejected the explanation request during validation.',
                $status,
                $details
            );
        }

        if ($status === 429) {
            return new self(
                'gemini_rate_limited',
                'Gemini rate limit reached',
                'The Gemini API rate limit or quota was reached.',
                $status,
                $details
            );
        }

        if ($status >= 500) {
            return new self(
                'gemini_service_error',
                'Gemini service error',
                'The Gemini API returned a server-side error.',
                $status,
                $details
            );
        }

        return new self(
            'gemini_api_error',
            'Gemini API error',
            'The Gemini API returned an unexpected HTTP status (' . $status . ').',
            $status,
            $details
        );
    }

    private static function safeString(mixed $value, int $max = 80): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strlen($value) > $max) {
            $value = substr($value, 0, $max) . '...';
        }

        return $value;
    }

    private static function safeProviderMessage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if ($value === '') {
            return null;
        }

        if (strlen($value) > 180) {
            $value = substr($value, 0, 180) . '...';
        }

        return $value;
    }
}