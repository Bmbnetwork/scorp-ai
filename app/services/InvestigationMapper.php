<?php

declare(strict_types=1);

namespace App\Services;

final class InvestigationMapper
{
    /**
     * Map a workflow report array to an investigations row (without id).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function toRow(array $data): array
    {
        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : null;
        $ai = is_array($data['ai'] ?? null) ? $data['ai'] : null;

        return [
            'reference' => self::stringOrNull($data['reference'] ?? null, 64),
            'environment' => self::stringOrNull($data['environment'] ?? null, 32),
            'endpoint' => self::stringOrNull($data['endpoint'] ?? null, 191),
            'order_status' => self::stringOrNull($data['status'] ?? null, 64),
            'intent' => self::stringOrNull($data['intent'] ?? null, 64),
            'amount_value' => self::stringOrNull($amount['value'] ?? null, 32),
            'amount_currency' => self::stringOrNull($amount['currency_code'] ?? null, 8),
            'created_at' => self::stringOrNull($data['created_at'] ?? null, 40),
            'updated_at' => self::stringOrNull($data['updated_at'] ?? null, 40),
            'retrieved_at' => self::stringOrNull($data['retrieved_at'] ?? null, 40),
            'investigated_at' => self::stringOrNull($data['investigated_at'] ?? null, 40),
            'report_generated_at' => self::stringOrNull($data['report_generated_at'] ?? null, 40),
            'purchase_unit_count' => self::intOrZero($data['purchase_unit_count'] ?? null),
            'capture_count' => self::intOrZero($data['capture_count'] ?? null),
            'captures_json' => self::jsonOrEmptyArray($data['captures'] ?? []),
            'report_state' => self::stringOrNull($data['report_state'] ?? null, 32),
            'ai_origin' => self::stringOrNull($ai['origin'] ?? null, 40),
            'ai_available' => self::boolToInt($ai['available'] ?? false),
            'ai_model' => self::stringOrNull($ai['model'] ?? null, 80),
            'ai_response_mode' => self::stringOrNull($ai['response_mode'] ?? null, 20),
            'ai_generated_at' => self::stringOrNull($ai['generated_at'] ?? null, 40),
            'ai_summary' => self::textOrNull($ai['summary'] ?? null),
            'ai_facts_json' => self::jsonOrEmptyArray($ai['facts'] ?? []),
            'ai_uncertainties_json' => self::jsonOrEmptyArray($ai['uncertainties'] ?? []),
            'ai_next_steps_json' => self::jsonOrEmptyArray($ai['next_steps'] ?? []),
            'ai_limitations_json' => self::jsonOrEmptyArray($ai['limitations'] ?? []),
            'ai_error_code' => self::stringOrNull($ai['error_code'] ?? null, 80),
            'ai_error_message' => self::textOrNull($ai['error_message'] ?? null),
            'rule_next_steps_json' => self::jsonOrEmptyArray($data['next_steps'] ?? []),
        ];
    }

    /**
     * Map a workflow report's findings to investigation_findings rows.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toFindingRows(array $data): array
    {
        $rows = [];
        $findings = is_array($data['findings'] ?? null) ? array_values($data['findings']) : [];

        foreach ($findings as $index => $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $rows[] = [
                'code' => self::stringOrNull($finding['code'] ?? null, 80),
                'severity' => self::stringOrNull($finding['severity'] ?? null, 20),
                'title' => self::stringOrNull($finding['title'] ?? null, 255),
                'detail' => self::textOrNull($finding['detail'] ?? null),
                'origin' => self::stringOrNull($finding['origin'] ?? 'scorp_ai_rule', 40),
                'evidence_json' => self::jsonOrEmptyArray($finding['evidence'] ?? []),
                'position' => $index,
            ];
        }

        return $rows;
    }

    /**
     * Reconstruct a report array from a stored investigation row + finding rows.
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $findingRows
     *
     * @return array<string, mixed>
     */
    public static function toReport(array $row, array $findingRows): array
    {
        $amountValue = self::nullableTrim($row['amount_value'] ?? null);
        $amountCurrency = self::nullableTrim($row['amount_currency'] ?? null);

        $amount = null;

        if ($amountValue !== null || $amountCurrency !== null) {
            $amount = [
                'value' => $amountValue,
                'currency_code' => $amountCurrency,
            ];
        }

        $aiOrigin = self::nullableTrim($row['ai_origin'] ?? null);

        $ai = null;

        if ($aiOrigin !== null) {
            $ai = [
                'origin' => $aiOrigin,
                'available' => ((int) ($row['ai_available'] ?? 0)) === 1,
                'model' => self::nullableTrim($row['ai_model'] ?? null),
                'response_mode' => self::nullableTrim($row['ai_response_mode'] ?? null),
                'generated_at' => self::nullableTrim($row['ai_generated_at'] ?? null),
                'summary' => self::nullableTrim($row['ai_summary'] ?? null),
                'facts' => self::decodeList($row['ai_facts_json'] ?? null),
                'uncertainties' => self::decodeList($row['ai_uncertainties_json'] ?? null),
                'next_steps' => self::decodeList($row['ai_next_steps_json'] ?? null),
                'limitations' => self::decodeList($row['ai_limitations_json'] ?? null),
                'error_code' => self::nullableTrim($row['ai_error_code'] ?? null),
                'error_message' => self::nullableTrim($row['ai_error_message'] ?? null),
            ];
        }

        $findings = [];

        usort($findingRows, static function (array $a, array $b): int {
            return (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0);
        });

        foreach ($findingRows as $findingRow) {
            $findings[] = [
                'code' => self::nullableTrim($findingRow['code'] ?? null),
                'severity' => self::nullableTrim($findingRow['severity'] ?? null),
                'title' => self::nullableTrim($findingRow['title'] ?? null),
                'detail' => self::nullableTrim($findingRow['detail'] ?? null),
                'origin' => self::nullableTrim($findingRow['origin'] ?? null),
                'evidence' => self::decodeList($findingRow['evidence_json'] ?? null),
            ];
        }

        return [
            'source' => 'paypal',
            'environment' => self::nullableTrim($row['environment'] ?? null),
            'endpoint' => self::nullableTrim($row['endpoint'] ?? null),
            'retrieved_at' => self::nullableTrim($row['retrieved_at'] ?? null),
            'reference' => self::nullableTrim($row['reference'] ?? null),
            'status' => self::nullableTrim($row['order_status'] ?? null),
            'intent' => self::nullableTrim($row['intent'] ?? null),
            'amount' => $amount,
            'created_at' => self::nullableTrim($row['created_at'] ?? null),
            'updated_at' => self::nullableTrim($row['updated_at'] ?? null),
            'purchase_unit_count' => (int) ($row['purchase_unit_count'] ?? 0),
            'capture_count' => (int) ($row['capture_count'] ?? 0),
            'captures' => self::decodeList($row['captures_json'] ?? null),
            'findings' => $findings,
            'next_steps' => self::decodeList($row['rule_next_steps_json'] ?? null),
            'investigated_at' => self::nullableTrim($row['investigated_at'] ?? null),
            'ai' => $ai,
            'ai_available' => ((int) ($row['ai_available'] ?? 0)) === 1,
            'report_state' => self::nullableTrim($row['report_state'] ?? null),
            'report_generated_at' => self::nullableTrim($row['report_generated_at'] ?? null),
        ];
    }

    private static function stringOrNull(mixed $value, int $max): ?string
    {
        $value = self::nullableTrim($value);

        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    private static function textOrNull(mixed $value): ?string
    {
        return self::nullableTrim($value);
    }

    private static function nullableTrim(mixed $value): ?string
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

    private static function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 0;
    }

    private static function boolToInt(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' ? 1 : 0;
    }

    private static function jsonOrEmptyArray(mixed $value): string
    {
        if (!is_array($value)) {
            $value = [];
        }

        $json = json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '[]' : $json;
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeList(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values($decoded);
    }
}