<?php

declare(strict_types=1);

namespace App\Services;

interface GeminiTransport
{
    /**
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function postJson(string $url, array $headers, string $body): array;
}