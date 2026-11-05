<?php

declare(strict_types=1);

namespace App\Services;

final class ReportExporter
{
    /**
     * Lossless JSON export: the exact report array the UI renders when a saved
     * investigation is opened. No secrets are present in a report by design.
     *
     * @param array<string, mixed> $report
     */
    public function toJson(array $report): string
    {
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }

    /**
     * Human-readable, sectioned CSV. JSON remains the lossless format.
     *
     * Sections (separated by a blank line):
     *   REPORT            Field,Value
     *   FINDINGS          Index,Code,Severity,Title,Detail
     *   EVIDENCE          Finding,Label,Value,Source
     *   RULE NEXT STEPS   Step
     *   EXPLANATION       Field,Value   (omitted entirely when there is no AI block)
     *   FACTS             Item
     *   UNCERTAINTIES     Item
     *   SUGGESTED NEXT STEPS  Item
     *   LIMITATIONS       Item
     *
     * @param array<string, mixed> $report
     */
    public function toCsv(array $report): string
    {
        $sections = [];

        $sections[] = $this->reportSection($report);
        $sections[] = $this->findingsSection($report);
        $sections[] = $this->evidenceSection($report);
        $sections[] = $this->listSection(['Step'], $this->stringList($report['next_steps'] ?? []));

        $ai = is_array($report['ai'] ?? null) ? $report['ai'] : null;

        if ($ai !== null) {
            $sections[] = $this->explanationSection($ai, $report);
            $sections[] = $this->listSection(['Item'], $this->stringList($ai['facts'] ?? []));
            $sections[] = $this->listSection(['Item'], $this->stringList($ai['uncertainties'] ?? []));
            $sections[] = $this->listSection(['Item'], $this->stringList($ai['next_steps'] ?? []));
            $sections[] = $this->listSection(['Item'], $this->stringList($ai['limitations'] ?? []));
        }

        $rendered = array_map(static function (array $rows): string {
            return implode("\r\n", array_map(
                static fn (array $row): string => implode(',', array_map(
                    static fn ($cell): string => self::csvField($cell),
                    $row
                )),
                $rows
            ));
        }, $sections);

        return implode("\r\n\r\n", $rendered);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function reportSection(array $report): array
    {
        $amount = is_array($report['amount'] ?? null) ? $report['amount'] : null;
        $amountText = '';

        if ($amount !== null) {
            $value = self::scalar($amount['value'] ?? null);
            $currency = self::scalar($amount['currency_code'] ?? null);
            $amountText = trim($value . ' ' . $currency);
        }

        $ai = is_array($report['ai'] ?? null) ? $report['ai'] : null;

        $rows = [
            ['Field', 'Value'],
            ['Investigation ID', self::scalar($report['investigation_id'] ?? null)],
            ['Reference', self::scalar($report['reference'] ?? null)],
            ['Environment', self::scalar($report['environment'] ?? null)],
            ['Endpoint', self::scalar($report['endpoint'] ?? null)],
            ['Order status', self::scalar($report['status'] ?? null)],
            ['Intent', self::scalar($report['intent'] ?? null)],
            ['Amount', $amountText],
            ['Purchase units', self::intScalar($report['purchase_unit_count'] ?? null)],
            ['Captures', self::intScalar($report['capture_count'] ?? null)],
            ['Report state', self::scalar($report['report_state'] ?? null)],
            ['AI origin', self::scalar($ai['origin'] ?? null)],
            ['AI available', self::boolScalar(self::aiAvailable($report, $ai))],
            ['AI model', self::scalar($ai['model'] ?? null)],
            ['Retrieved at', self::scalar($report['retrieved_at'] ?? null)],
            ['Investigated at', self::scalar($report['investigated_at'] ?? null)],
            ['Report generated at', self::scalar($report['report_generated_at'] ?? null)],
        ];

        $savedAt = self::scalar($report['saved_at'] ?? null);

        if ($savedAt !== '') {
            $rows[] = ['Saved at (history)', $savedAt];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function findingsSection(array $report): array
    {
        $rows = [['Index', 'Code', 'Severity', 'Title', 'Detail']];
        $findings = is_array($report['findings'] ?? null) ? array_values($report['findings']) : [];
        $index = 0;

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $index++;

            $rows[] = [
                $index,
                self::scalar($finding['code'] ?? null),
                self::scalar($finding['severity'] ?? null),
                self::scalar($finding['title'] ?? null),
                self::scalar($finding['detail'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function evidenceSection(array $report): array
    {
        $rows = [['Finding', 'Label', 'Value', 'Source']];
        $findings = is_array($report['findings'] ?? null) ? array_values($report['findings']) : [];
        $index = 0;

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $index++;
            $evidence = is_array($finding['evidence'] ?? null) ? array_values($finding['evidence']) : [];

            foreach ($evidence as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $rows[] = [
                    $index,
                    self::scalar($entry['label'] ?? null),
                    self::scalar($entry['value'] ?? null),
                    self::scalar($entry['source'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function explanationSection(array $ai, array $report): array
    {
        $available = self::aiAvailable($report, $ai);

        return [
            ['Field', 'Value'],
            ['Origin', self::scalar($ai['origin'] ?? null)],
            ['Label', self::explanationLabel(self::scalar($ai['origin'] ?? null), $available)],
            ['Available', self::boolScalar($available)],
            ['Model', self::scalar($ai['model'] ?? null)],
            ['Generated at', self::scalar($ai['generated_at'] ?? null)],
            ['Summary', self::scalar($ai['summary'] ?? null)],
            ['Error code', self::scalar($ai['error_code'] ?? null)],
            ['Error message', self::scalar($ai['error_message'] ?? null)],
        ];
    }

    /**
     * @param array<int, string> $header
     * @param string[] $items
     *
     * @return array<int, array<int, mixed>>
     */
    private function listSection(array $header, array $items): array
    {
        $rows = [$header];

        foreach ($items as $item) {
            $rows[] = [$item];
        }

        return $rows;
    }

    /**
     * Honest label. Mirrors the UI so a CSV reader sees the same truth.
     */
    public static function explanationLabel(?string $origin, bool $available): string
    {
        if ($origin === 'gemini' && $available === true) {
            return 'Gemini AI';
        }

        if ($origin === 'deterministic_fallback') {
            return 'Deterministic fallback (not AI)';
        }

        return 'Unknown';
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed>|null $ai
     */
    private static function aiAvailable(array $report, ?array $ai): bool
    {
        if ($ai !== null && array_key_exists('available', $ai)) {
            return $ai['available'] === true;
        }

        return ($report['ai_available'] ?? null) === true;
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach (array_values($value) as $item) {
            $text = self::scalar($item);

            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '' : $json;
    }

    private static function intScalar(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (string) (int) $value;
        }

        return '0';
    }

    private static function boolScalar(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * RFC 4180 escaping: wrap in quotes when the value contains a comma,
     * double-quote, CR or LF; double internal quotes.
     */
    private static function csvField(mixed $value): string
    {
        $text = self::scalar($value);

        if ($text === '') {
            return '';
        }

        if (preg_match('/[",\r\n]/', $text) === 1) {
            return '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }
}