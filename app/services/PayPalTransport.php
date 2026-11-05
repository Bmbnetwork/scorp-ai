<?php

declare(strict_types=1);

namespace App\Services;

interface PayPalTransport
{
    /**
     * @param array<string, string> $fields
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function postForm(string $url, array $fields, array $headers): array;

    /**
     * @param string[] $headers
     *
     * @return array{status:int, body:string, error?:string}
     */
    public function get(string $url, array $headers): array;
}