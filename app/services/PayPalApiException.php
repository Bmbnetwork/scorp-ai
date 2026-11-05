<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class PayPalApiException extends RuntimeException
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
        $name = null;
        $debugId = null;

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $name = self::safeString($decoded['name'] ?? null);
            $debugId = self::safeString($decoded['debug_id'] ?? null);
        }

        $details = array_values(array_filter([
            'PayPal HTTP status: ' . $status,
            $name !== null ? 'PayPal error: ' . $name : null,
            $debugId !== null ? 'Debug ID: ' . $debugId : null,
        ], static fn ($value): bool => is_string($value) && $value !== ''));

        if ($status === 0) {
            return new self(
                'paypal_network_error',
                'PayPal connection error',
                'The server could not reach PayPal Sandbox.',
                0,
                $details
            );
        }

        if ($status === 400) {
            return new self(
                'paypal_bad_request',
                'PayPal rejected the request',
                'PayPal Sandbox rejected the request as malformed.',
                $status,
                $details
            );
        }

        if ($status === 401) {
            return new self(
                'paypal_authentication_failed',
                'PayPal authentication failed',
                'Check the configured PayPal Sandbox client ID and secret.',
                $status,
                $details
            );
        }

        if ($status === 403) {
            return new self(
                'paypal_forbidden',
                'PayPal access forbidden',
                'PayPal Sandbox refused the request for this credential set.',
                $status,
                $details
            );
        }

        if ($status === 404) {
            return new self(
                'paypal_order_not_found',
                'Order not found',
                'PayPal Sandbox did not return an order for that reference.',
                $status,
                $details
            );
        }

        if ($status === 422) {
            return new self(
                'paypal_validation_failed',
                'PayPal validation failed',
                'PayPal Sandbox rejected the submitted reference or request.',
                $status,
                $details
            );
        }

        if ($status === 429) {
            return new self(
                'paypal_rate_limited',
                'PayPal rate limit reached',
                'PayPal Sandbox rate limiting was triggered. Try again later.',
                $status,
                $details
            );
        }

        if ($status >= 500) {
            return new self(
                'paypal_service_error',
                'PayPal service error',
                'PayPal Sandbox returned a server-side error.',
                $status,
                $details
            );
        }

        return new self(
            'paypal_api_error',
            'PayPal API error',
            'PayPal Sandbox returned an unexpected error.',
            $status,
            $details
        );
    }

    private static function safeString(mixed $value, int $max = 120): ?string
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
}