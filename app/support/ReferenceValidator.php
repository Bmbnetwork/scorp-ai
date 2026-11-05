<?php

declare(strict_types=1);

namespace App\Support;

final class ReferenceValidator
{
    private const MIN_LENGTH = 6;
    private const MAX_LENGTH = 64;

    /**
     * Validates a PayPal order or transaction reference before it is used
     * in any later server-side API request.
     *
     * Phase 1 intentionally does not contact PayPal. This validator exists so
     * the interface can reject obviously unsafe or malformed input locally.
     *
     * @return array{ok: bool, value: string, code: string, message: string}
     */
    public static function validate(string $raw): array
    {
        $value = trim($raw);

        if ($value === '') {
            return [
                'ok' => false,
                'value' => '',
                'code' => 'required',
                'message' => 'Enter a PayPal Sandbox order or transaction reference.',
            ];
        }

        if (strlen($value) < self::MIN_LENGTH) {
            return [
                'ok' => false,
                'value' => $value,
                'code' => 'too_short',
                'message' => 'The reference must be at least ' . self::MIN_LENGTH . ' characters long.',
            ];
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return [
                'ok' => false,
                'value' => $value,
                'code' => 'too_long',
                'message' => 'The reference must not exceed ' . self::MAX_LENGTH . ' characters.',
            ];
        }

        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return [
                'ok' => false,
                'value' => $value,
                'code' => 'invalid_characters',
                'message' => 'Use only letters, numbers, hyphens, and underscores.',
            ];
        }

        return [
            'ok' => true,
            'value' => $value,
            'code' => '',
            'message' => '',
        ];
    }
}