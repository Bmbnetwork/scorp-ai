<?php

declare(strict_types=1);

namespace App\Services;

final class CurlGeminiTransport implements GeminiTransport
{
    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds = 35)
    {
        $this->timeoutSeconds = max(5, min(90, $timeoutSeconds));
    }

    /**
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function postJson(string $url, array $headers, string $body): array
    {
        return $this->request('POST', $url, $headers, [
            CURLOPT_POSTFIELDS => $body,
        ]);
    }

    /**
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function getJson(string $url, array $headers): array
    {
        return $this->request('GET', $url, $headers, []);
    }

    /**
     * @param string[] $headers
     * @param array<int, mixed> $extraOptions
     *
     * @return array{status:int, body:string, error?:string}
     */
    private function request(string $method, string $url, array $headers, array $extraOptions): array
    {
        if (!function_exists('curl_init')) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'cURL extension is not available.',
            ];
        }

        $handle = curl_init();

        if ($handle === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Unable to initialize cURL.',
            ];
        }

        $connectTimeout = min(15, $this->timeoutSeconds);

        $defaults = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_USERAGENT => 'SCORP-AI/1.0',
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
        ];

        curl_setopt_array($handle, $defaults);
        curl_setopt_array($handle, $extraOptions);

        $responseBody = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errno !== 0 || $responseBody === false) {
            $message = 'Outbound HTTPS request failed (cURL error ' . $errno . ').';

            if (is_string($error) && trim($error) !== '') {
                $message .= ' Detail: ' . trim($error);
            }

            return [
                'status' => 0,
                'body' => '',
                'error' => $message,
            ];
        }

        return [
            'status' => $status,
            'body' => (string) $responseBody,
        ];
    }
}