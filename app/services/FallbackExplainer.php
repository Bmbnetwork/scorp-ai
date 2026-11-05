<?php

declare(strict_types=1);

namespace App\Services;

final class FallbackExplainer
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function summarize(array $data, string $errorCode, string $errorMessage): array
    {
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];
        $nextSteps = is_array($data['next_steps'] ?? null) ? $data['next_steps'] : [];

        $confirmed = [];
        $warnings = [];
        $unknowns = [];

        foreach ($findings as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $severity = strtolower(trim((string) ($finding['severity'] ?? '')));
            $title = trim((string) ($finding['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            if ($severity === 'confirmed') {
                $confirmed[] = $title;
            } elseif ($severity === 'warning') {
                $warnings[] = $title;
            } elseif ($severity === 'unknown') {
                $unknowns[] = $title;
            }
        }

        $reference = $this->text($data['reference'] ?? null);
        $status = $this->text($data['status'] ?? null);

        $summary = sprintf(
            'PayPal Sandbox evidence was retrieved for order %s. The observed order status is %s. SCORP AI identified %d confirmed finding(s), %d warning(s), and %d unknown condition(s).',
            $reference,
            $status,
            count($confirmed),
            count($warnings),
            count($unknowns)
        );

        $uncertainties = [];

        foreach (array_slice($warnings, 0, 5) as $warning) {
            $uncertainties[] = 'Warning: ' . $warning;
        }

        foreach (array_slice($unknowns, 0, 5) as $unknown) {
            $uncertainties[] = 'Unknown: ' . $unknown;
        }

        if ($uncertainties === []) {
            $uncertainties[] = 'No warning or unknown condition was produced by the deterministic rules.';
        }

        $facts = $confirmed === []
            ? ['No confirmed finding was produced beyond successful retrieval.']
            : array_slice($confirmed, 0, 8);

        $sanitizedNextSteps = [];

        foreach (array_slice($nextSteps, 0, 8) as $step) {
            if (is_string($step) && trim($step) !== '') {
                $sanitizedNextSteps[] = trim($step);
            }
        }

        if ($sanitizedNextSteps === []) {
            $sanitizedNextSteps[] = 'Review the PayPal evidence and deterministic findings above.';
        }

        return [
            'origin' => 'deterministic_fallback',
            'available' => false,
            'generated_at' => gmdate('c'),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'summary' => $summary,
            'facts' => $facts,
            'uncertainties' => $uncertainties,
            'next_steps' => $sanitizedNextSteps,
            'limitations' => [
                'This summary was generated deterministically because Gemini was unavailable or invalid.',
                'It is not an AI-generated explanation.',
                'PayPal remains the source of truth for returned transaction fields.',
            ],
        ];
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? 'not returned' : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return 'not returned';
    }
}