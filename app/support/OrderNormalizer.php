<?php

declare(strict_types=1);

namespace App\Support;

final class OrderNormalizer
{
    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public static function normalize(
        string $reference,
        array $response,
        string $environment,
        string $endpoint
    ): array {
        $purchaseUnits = self::listFromArray($response['purchase_units'] ?? null);

        $firstUnit = $purchaseUnits[0] ?? [];

        if (!is_array($firstUnit)) {
            $firstUnit = [];
        }

        $captures = [];

        foreach ($purchaseUnits as $unit) {
            if (!is_array($unit)) {
                continue;
            }

            $payments = $unit['payments'] ?? null;

            if (!is_array($payments)) {
                continue;
            }

            $unitCaptures = self::listFromArray($payments['captures'] ?? null);

            foreach ($unitCaptures as $capture) {
                if (is_array($capture)) {
                    $captures[] = self::normalizeCapture($capture);
                }
            }
        }

        return [
            'source' => 'paypal',
            'environment' => $environment,
            'endpoint' => $endpoint,
            'retrieved_at' => gmdate('c'),
            'reference' => $reference,
            'status' => self::nullableString($response['status'] ?? null),
            'intent' => self::nullableString($response['intent'] ?? null),
            'amount' => self::normalizeAmount($firstUnit['amount'] ?? null),
            'created_at' => self::nullableString($response['create_time'] ?? null),
            'updated_at' => self::nullableString($response['update_time'] ?? null),
            'purchase_unit_count' => count($purchaseUnits),
            'capture_count' => count($captures),
            'captures' => $captures,
        ];
    }

    private static function normalizeAmount(mixed $amount): ?array
    {
        if (!is_array($amount)) {
            return null;
        }

        $value = self::nullableString($amount['value'] ?? null);
        $currency = self::nullableString($amount['currency_code'] ?? null);

        if ($value === null && $currency === null) {
            return null;
        }

        return [
            'value' => $value,
            'currency_code' => $currency,
        ];
    }

    /**
     * @param array<string, mixed> $capture
     *
     * @return array<string, mixed>
     */
    private static function normalizeCapture(array $capture): array
    {
        $normalized = [
            'id' => self::nullableString($capture['id'] ?? null),
            'status' => self::nullableString($capture['status'] ?? null),
            'amount' => self::normalizeAmount($capture['amount'] ?? null),
            'created_at' => self::nullableString($capture['create_time'] ?? null),
            'updated_at' => self::nullableString($capture['update_time'] ?? null),
        ];

        $sellerProtection = $capture['seller_protection'] ?? null;

        if (is_array($sellerProtection)) {
            $status = self::nullableString($sellerProtection['status'] ?? null);

            if ($status !== null) {
                $normalized['seller_protection_status'] = $status;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, mixed>
     */
    private static function listFromArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }
}