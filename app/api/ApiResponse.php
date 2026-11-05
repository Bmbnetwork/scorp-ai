<?php

declare(strict_types=1);

namespace App\Api;

final class ApiResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function json(bool $ok, array $payload, int $status = 200): void
    {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        $encoded = json_encode(
            array_merge(['ok' => $ok], $payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($encoded === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }

            echo '{"ok":false,"error":{"code":"serialization_error","title":"Response error","message":"Unable to encode response.","details":[]}}';
            return;
        }

        echo $encoded;
    }

    /**
     * @param string[] $details
     */
    public static function error(
        string $code,
        string $title,
        string $message,
        array $details = [],
        int $status = 400
    ): void {
        self::json(false, [
            'error' => [
                'code' => $code,
                'title' => $title,
                'message' => $message,
                'details' => array_values($details),
            ],
        ], $status);
    }
}