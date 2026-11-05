<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use Throwable;

/**
 * Metadata-only audit log for local development.
 *
 * Guarantees:
 *  - Only allow-listed scalar keys are ever written. Free text, amounts,
 *    statuses, findings, AI content, secrets, tokens, request bodies and IP
 *    addresses cannot reach the log even if a caller passes them by mistake.
 *  - Writing never throws. On any failure log() returns false so a failed
 *    audit write can never invalidate a completed investigation.
 *  - Records are appended as single-line JSON to a dated file under var/audit,
 *    which is git-ignored. Dated files bound growth naturally; old files may be
 *    deleted by the operator at any time.
 */
final class AuditLogger
{
    /**
     * The only context keys that may be persisted. Anything else is dropped.
     */
    private const ALLOWED_KEYS = [
        'reference',
        'outcome',
        'error_code',
        'duration_ms',
        'request_id',
        'http_status',
        'format',
        'id',
        'persisted',
        'ai_origin',
        'ai_available',
        'step',
        'count',
        'limit',
        'window',
    ];

    private const MAX_STRING_LENGTH = 191;

    private const MAX_CONTEXT_KEYS = 32;

    private string $baseDir;

    private bool $enabled;

    public function __construct(string $baseDir, bool $enabled = true)
    {
        $this->baseDir = rtrim($baseDir, "/\\");
        $this->enabled = $enabled;
    }

    public static function fromRoot(string $rootDir, bool $enabled = true): self
    {
        return new self($rootDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'audit', $enabled);
    }

    public static function fromEnv(Env $env, string $rootDir): self
    {
        $enabledRaw = $env->raw('AUDIT_LOG_ENABLED');

        if ($enabledRaw === null) {
            $enabled = true;
        } else {
            $enabled = filter_var(trim($enabledRaw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        }

        $dirRaw = $env->raw('AUDIT_LOG_DIR');
        $dir = is_string($dirRaw) && trim($dirRaw) !== '' ? trim($dirRaw) : null;

        if ($dir === null) {
            return self::fromRoot($rootDir, $enabled);
        }

        return new self($dir, $enabled);
    }

    public static function now(): float
    {
        return microtime(true);
    }

    public static function elapsedMs(float $start): int
    {
        $ms = (int) round((microtime(true) - $start) * 1000);

        return $ms < 0 ? 0 : $ms;
    }

    /**
     * @return string[]
     */
    public static function allowedKeys(): array
    {
        return self::ALLOWED_KEYS;
    }

    public static function isAllowedKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_KEYS, true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function currentFileName(): string
    {
        return 'audit-' . gmdate('Ymd') . '.log';
    }

    public function currentFilePath(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $this->currentFileName();
    }

    /**
     * Append one audit record. Returns true on success (or when disabled),
     * false on any failure. Never throws.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): bool
    {
        if (!$this->enabled) {
            return true;
        }

        try {
            $record = [
                'ts' => gmdate('c'),
                'event' => $this->safeEvent($event),
            ];

            foreach ($this->sanitizeContext($context) as $key => $value) {
                $record[$key] = $value;
            }

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($line === false) {
                return false;
            }

            $path = $this->currentFilePath();

            if (!$this->ensureDir()) {
                return false;
            }

            $written = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);

            return $written !== false;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function safeEvent(string $event): string
    {
        $event = trim($event);

        if ($event === '') {
            return 'audit.event';
        }

        if (mb_strlen($event) > 64) {
            $event = mb_substr($event, 0, 64);
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar|null>
     */
    private function sanitizeContext(array $context): array
    {
        $result = [];
        $count = 0;

        foreach ($context as $key => $value) {
            if ($count >= self::MAX_CONTEXT_KEYS) {
                break;
            }

            if (!is_string($key) || !self::isAllowedKey($key)) {
                continue;
            }

            $coerced = $this->coerceScalar($value);

            if ($coerced === null && $value !== null) {
                // Non-scalar (array/object) or unsupported: drop entirely.
                continue;
            }

            $result[$key] = $coerced;
            $count++;
        }

        return $result;
    }

    private function coerceScalar(mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }

            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_STRING_LENGTH);
            }

            return $value;
        }

        // arrays, objects, resources: not allowed in the audit log.
        return null;
    }

    private function ensureDir(): bool
    {
        if ($this->baseDir === '') {
            return false;
        }

        if (is_dir($this->baseDir)) {
            return true;
        }

        if (!@mkdir($this->baseDir, 0775, true) && !is_dir($this->baseDir)) {
            return false;
        }

        return is_dir($this->baseDir);
    }
}