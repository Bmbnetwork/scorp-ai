<?php

declare(strict_types=1);

namespace App\Services;

final class CurlPayPalTransport implements PayPalTransport
{
    /**
     * @param array<string, string> $fields
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function postForm(string $url, array $fields, array $headers): array
    {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        return $this->request('POST', $url, $headers, [
            CURLOPT_POSTFIELDS => http_build_query($fields),
        ]);
    }

    /**
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function get(string $url, array $headers): array
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

        $defaults = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_USERAGENT => 'SCORP-AI/1.0',
        ];

        curl_setopt_array($handle, $defaults);
        curl_setopt_array($handle, $extraOptions);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errno !== 0 || $body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Outbound HTTPS request failed (cURL error ' . $errno . ').',
            ];
        }

        return [
            'status' => $status,
            'body' => (string) $body,
        ];
    }
}