<?php

declare(strict_types=1);

namespace App\Services;

final class HttpChecker
{
    /**
     * Performs a safe outbound HTTPS connectivity check.
     *
     * This method intentionally does not send credentials and does not return
     * response bodies. It only reports whether TLS/HTTP connectivity was observed.
     *
     * @return array{ok: bool, status: int, message: string}
     */
    public function check(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'cURL extension is not available.',
            ];
        }

        $parsed = parse_url($url);

        if (
            !is_array($parsed)
            || ($parsed['scheme'] ?? '') !== 'https'
            || empty($parsed['host'])
        ) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Only valid HTTPS URLs may be checked.',
            ];
        }

        $handle = curl_init();

        if ($handle === false) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'Unable to initialize cURL.',
            ];
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data): int {
                return strlen($data);
            },
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROGRESS => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: SCORP-AI-Phase0/1.0',
            ],
        ]);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errno !== 0 || $response === false) {
            return [
                'ok' => false,
                'status' => $status,
                'message' => 'Outbound HTTPS request failed (cURL error ' . $errno . ').',
            ];
        }

        if ($status === 0) {
            return [
                'ok' => false,
                'status' => 0,
                'message' => 'No HTTP response was received.',
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'message' => 'TLS/HTTP connectivity observed (HTTP ' . $status . ').',
        ];
    }
}