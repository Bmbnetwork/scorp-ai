<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

/**
 * Orchestrates the full investigation pipeline:
 *
 *   PayPal retrieval -> deterministic rules -> Gemini explanation (or fallback)
 *
 * PayPal retrieval is the required step. If it fails, this workflow throws and
 * no report is produced. Gemini is an explanation layer only; if it fails, the
 * workflow substitutes a clearly labelled deterministic fallback and still
 * returns a report whose provenance is explicit.
 */
final class InvestigationWorkflow
{
    private Env $env;

    private PayPalService $paypal;

    private InvestigationEngine $engine;

    private FallbackExplainer $fallback;

    private ?GeminiTransport $geminiTransport;

    public function __construct(
        Env $env,
        ?PayPalTransport $paypalTransport = null,
        ?GeminiTransport $geminiTransport = null
    ) {
        $this->env = $env;
        $this->paypal = new PayPalService($env, $paypalTransport);
        $this->engine = new InvestigationEngine();
        $this->fallback = new FallbackExplainer();
        $this->geminiTransport = $geminiTransport;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $reference): array
    {
        // Required step. Exceptions propagate so the caller returns an error,
        // never a fabricated or partial "completed" report.
        $data = $this->paypal->retrieveOrder($reference);

        $investigation = $this->engine->investigate($data);

        $data['findings'] = $investigation['findings'];
        $data['next_steps'] = $investigation['next_steps'];
        $data['investigated_at'] = gmdate('c');

        $data['ai'] = $this->explain($data, $investigation);
        $data['ai_available'] = (bool) ($data['ai']['available'] ?? false);

        // PayPal succeeded, so the report is structurally complete. The
        // ai_available flag communicates whether the AI layer succeeded.
        $data['report_state'] = 'complete';
        $data['report_generated_at'] = gmdate('c');

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $investigation
     *
     * @return array<string, mixed>
     */
    private function explain(array $data, array $investigation): array
    {
        try {
            $gemini = new GeminiService($this->env, $this->geminiTransport);

            return $gemini->explain($data, $investigation);
        } catch (GeminiApiException $exception) {
            $reason = $exception->getTitle()
                . ' [' . $exception->getErrorCode() . ']: '
                . $exception->getMessage();

            $details = $exception->getDetails();

            if ($details !== []) {
                $reason .= ' Details: ' . implode('; ', $details);
            }

            return $this->fallback->summarize(
                $data,
                $exception->getErrorCode(),
                $reason
            );
        } catch (Throwable $exception) {
            return $this->fallback->summarize(
                $data,
                'gemini_unavailable',
                'Gemini could not be used.'
            );
        }
    }
}