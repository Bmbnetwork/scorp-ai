<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Support\OrderNormalizer;
use App\Support\ReferenceValidator;
use JsonException;

final class PayPalService
{
    private string $baseUrl;

    private string $clientId;

    private string $secret;

    private PayPalTransport $transport;

    private string $environment;

    public function __construct(Env $env, ?PayPalTransport $transport = null)
    {
        $environment = strtolower(trim((string) $env->get('PAYPAL_ENV', 'sandbox')));

        if ($environment !== 'sandbox') {
            throw new PayPalApiException(
                'paypal_environment_not_sandbox',
                'Sandbox required',
                'Phase 2 only supports PayPal Sandbox.'
            );
        }

        $baseUrl = rtrim(trim((string) $env->get('PAYPAL_API_BASE', 'https://api-m.sandbox.paypal.com')), '/');

        if ($baseUrl === '' || !str_starts_with($baseUrl, 'https://')) {
            throw new PayPalApiException(
                'paypal_config_invalid',
                'PayPal configuration error',
                'PAYPAL_API_BASE must be an HTTPS URL.'
            );
        }

        $clientId = trim((string) $env->get('PAYPAL_CLIENT_ID', ''));
        $secret = trim((string) $env->get('PAYPAL_SECRET', ''));

        if ($clientId === '' || $secret === '') {
            throw new PayPalApiException(
                'paypal_credentials_missing',
                'PayPal configuration error',
                'PayPal Sandbox client ID and secret are missing.'
            );
        }

        $this->baseUrl = $baseUrl;
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->environment = $environment;
        $this->transport = $transport ?? new CurlPayPalTransport();
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieveOrder(string $orderId): array
    {
        $validation = ReferenceValidator::validate($orderId);

        if (($validation['ok'] ?? false) !== true) {
            throw new PayPalApiException(
                'invalid_reference',
                'Check the transaction reference',
                (string) ($validation['message'] ?? 'The submitted reference is not valid.'),
                422
            );
        }

        $token = $this->getAccessToken();

        $response = $this->getOrder($orderId, $token);
        $this->assertTransportOk($response);

        if ((int) ($response['status'] ?? 0) === 401) {
            $token = $this->getAccessToken();
            $response = $this->getOrder($orderId, $token);
            $this->assertTransportOk($response);
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            throw PayPalApiException::fromHttp($status, $body);
        }

        $data = $this->decodeJson(
            $body,
            'paypal_invalid_order_response',
            'Unexpected PayPal response',
            'PayPal returned an invalid order response.'
        );

        return OrderNormalizer::normalize(
            $orderId,
            $data,
            $this->environment,
            '/v2/checkout/orders/{order_id}'
        );
    }

    private function getAccessToken(): string
    {
        $authHeader = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->secret);

        $response = $this->transport->postForm(
            $this->baseUrl . '/v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            [
                $authHeader,
                'Accept: application/json',
            ]
        );

        $this->assertTransportOk($response);

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');

        if ($status !== 200) {
            throw PayPalApiException::fromHttp($status, $body);
        }

        $data = $this->decodeJson(
            $body,
            'paypal_invalid_token_response',
            'Unexpected PayPal response',
            'PayPal returned an invalid access-token response.'
        );

        $token = $data['access_token'] ?? null;

        if (!is_string($token) || trim($token) === '') {
            throw new PayPalApiException(
                'paypal_authentication_failed',
                'PayPal authentication failed',
                'PayPal did not return a usable access token.',
                502
            );
        }

        return trim($token);
    }

    /**
     * @return array{status:int, body:string, error?:string}
     */
    private function getOrder(string $orderId, string $token): array
    {
        $url = $this->baseUrl . '/v2/checkout/orders/' . rawurlencode($orderId);

        return $this->transport->get($url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
    }

    /**
     * @param array{status:int, body:string, error?:string} $response
     */
    private function assertTransportOk(array $response): void
    {
        if ((int) ($response['status'] ?? 0) !== 0) {
            return;
        }

        $error = (string) ($response['error'] ?? '');

        throw new PayPalApiException(
            'paypal_network_error',
            'PayPal connection error',
            'The server could not reach PayPal Sandbox.',
            0,
            array_filter([$error])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body, string $code, string $title, string $message): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayPalApiException($code, $title, $message, 502);
        }

        if (!is_array($decoded)) {
            throw new PayPalApiException($code, $title, $message, 502);
        }

        return $decoded;
    }
}