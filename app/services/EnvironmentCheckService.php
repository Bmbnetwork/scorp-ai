<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

final class EnvironmentCheckService
{
    private const MIN_PHP_VERSION = '8.0.0';

    /**
     * @var string[]
     */
    private const REQUIRED_EXTENSIONS = [
        'curl',
        'openssl',
        'json',
        'mbstring',
    ];

    /**
     * @var string[]
     */
    private const OPTIONAL_EXTENSIONS = [
        'pdo_mysql',
    ];

    /**
     * @var array<string, array{label: string, url: string}>
     */
    private const DEFAULT_ENDPOINTS = [
        'paypal_sandbox_api' => [
            'label' => 'PayPal Sandbox API host',
            'url' => 'https://api-m.sandbox.paypal.com',
        ],
        'gemini_api' => [
            'label' => 'Google Gemini API host',
            'url' => 'https://generativelanguage.googleapis.com',
        ],
    ];

    private Env $env;

    /**
     * @var callable(string): array{ok: bool, status: int, message: string}
     */
    private $httpChecker;

    /**
     * @param callable(string): array{ok: bool, status: int, message: string}|null $httpChecker
     */
    public function __construct(Env $env, ?callable $httpChecker = null)
    {
        $this->env = $env;
        $this->httpChecker = $httpChecker ?? static function (string $url): array {
            return (new HttpChecker())->check($url);
        };
    }

    /**
     * @return array{
     *     generated_at: string,
     *     php_version: string,
     *     sapi: string,
     *     overall: string,
     *     checks: array<int, array{id: string, label: string, status: string, detail: string, required: bool}>
     * }
     */
    public function run(bool $viaWebRequest = false): array
    {
        $checks = [];

        $this->addPhpVersionCheck($checks);
        $this->addExtensionChecks($checks);
        $this->addWebRequestCheck($checks, $viaWebRequest);
        $this->addConfigSourceCheck($checks);
        $this->addCredentialChecks($checks);
        $this->addOutboundChecks($checks);

        return [
            'generated_at' => gmdate('c'),
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'overall' => $this->determineOverallStatus($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<int, array{id: string, label: string, status: string, detail: string, required: bool}> $checks
     */
    private function addCheck(
        array &$checks,
        string $id,
        string $label,
        string $status,
        string $detail,
        bool $required
    ): void {
        $checks[] = [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'required' => $required,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addPhpVersionCheck(array &$checks): void
    {
        $passed = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');

        $this->addCheck(
            $checks,
            'php_version',
            'PHP version',
            $passed ? 'pass' : 'fail',
            $passed
                ? 'PHP ' . PHP_VERSION . ' detected. Minimum required: ' . self::MIN_PHP_VERSION . '.'
                : 'PHP ' . PHP_VERSION . ' detected. Minimum required: ' . self::MIN_PHP_VERSION . '.',
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addExtensionChecks(array &$checks): void
    {
        $missingRequired = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $missingRequired[] = $extension;
            }
        }

        $missingOptional = [];

        foreach (self::OPTIONAL_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $missingOptional[] = $extension;
            }
        }

        if ($missingRequired !== []) {
            $this->addCheck(
                $checks,
                'php_extensions',
                'Required PHP extensions',
                'fail',
                'Missing required PHP extensions: ' . implode(', ', $missingRequired) . '.',
                true
            );

            return;
        }

        if ($missingOptional !== []) {
            $this->addCheck(
                $checks,
                'php_extensions',
                'PHP extensions',
                'warn',
                'Required extensions are available. Optional extension(s) missing: '
                    . implode(', ', $missingOptional)
                    . '. These may be needed in later phases.',
                true
            );

            return;
        }

        $this->addCheck(
            $checks,
            'php_extensions',
            'PHP extensions',
            'pass',
            'Required PHP extensions are available.',
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addWebRequestCheck(array &$checks, bool $viaWebRequest): void
    {
        if ($viaWebRequest) {
            $this->addCheck(
                $checks,
                'web_request',
                'Web server delivery',
                'pass',
                'HTTP request was processed by PHP (SAPI: ' . PHP_SAPI . ').',
                true
            );

            return;
        }

        $this->addCheck(
            $checks,
            'web_request',
            'Web server delivery',
            'warn',
            'CLI context: HTTP delivery not verified. Start a local web server and open public/index.php.',
            false
        );
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addConfigSourceCheck(array &$checks): void
    {
        $path = $this->env->sourcePath();

        if ($path !== null && is_readable($path)) {
            $this->addCheck(
                $checks,
                'config_source',
                'Local configuration file',
                'pass',
                'A readable local .env file was found.',
                false
            );

            return;
        }

        $this->addCheck(
            $checks,
            'config_source',
            'Local configuration file',
            'warn',
            'No readable .env file was found. The checker will use process environment variables if available.',
            false
        );
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addCredentialChecks(array &$checks): void
    {
        $paypalConfigured =
            $this->env->has('PAYPAL_CLIENT_ID')
            && $this->env->has('PAYPAL_SECRET');

        $this->addCheck(
            $checks,
            'paypal_credentials',
            'PayPal Sandbox credentials',
            $paypalConfigured ? 'pass' : 'fail',
            $paypalConfigured
                ? 'PayPal client ID and secret are configured. Values are not displayed.'
                : 'Missing PayPal client ID or secret. Copy .env.example to .env and configure them locally.',
            true
        );

        $geminiConfigured = $this->env->has('GEMINI_API_KEY');

        $this->addCheck(
            $checks,
            'gemini_credentials',
            'Gemini API key',
            $geminiConfigured ? 'pass' : 'fail',
            $geminiConfigured
                ? 'Gemini API key is configured. Value is not displayed.'
                : 'Missing Gemini API key. Copy .env.example to .env and configure it locally.',
            true
        );

        $paypalEnv = strtolower((string) $this->env->get('PAYPAL_ENV', 'sandbox'));

        $this->addCheck(
            $checks,
            'paypal_environment',
            'PayPal environment setting',
            $paypalEnv === 'sandbox' ? 'pass' : 'warn',
            $paypalEnv === 'sandbox'
                ? 'PAYPAL_ENV is set to sandbox.'
                : 'PAYPAL_ENV is not set to sandbox. Sandbox is required for this hackathon build.',
            false
        );
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function addOutboundChecks(array &$checks): void
    {
        foreach (self::DEFAULT_ENDPOINTS as $key => $endpoint) {
            $urlKey = 'HTTPS_CHECK_' . strtoupper($key);
            $url = (string) $this->env->get($urlKey, $endpoint['url']);

            try {
                $checker = $this->httpChecker;
                $response = $checker($url);
            } catch (\Throwable $e) {
                $response = [
                    'ok' => false,
                    'status' => 0,
                    'message' => 'Outbound check encountered an internal error.',
                ];
            }

            $this->addCheck(
                $checks,
                'outbound_' . $key,
                $endpoint['label'],
                ($response['ok'] ?? false) ? 'pass' : 'fail',
                (string) ($response['message'] ?? 'Outbound check failed.'),
                true
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     */
    private function determineOverallStatus(array $checks): string
    {
        $overall = 'pass';

        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'fail');
            $required = (bool) ($check['required'] ?? true);

            if ($status === 'fail') {
                if ($required) {
                    return 'fail';
                }

                $overall = 'warn';
            }

            if ($status === 'warn') {
                $overall = 'warn';
            }
        }

        return $overall;
    }
}