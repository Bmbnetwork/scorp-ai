<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    /**
     * @var array<string, string>
     */
    private array $values;

    private bool $useProcessEnv;

    private ?string $sourcePath;

    /**
     * @param array<string, string> $values
     */
    private function __construct(
        array $values = [],
        bool $useProcessEnv = true,
        ?string $sourcePath = null
    ) {
        $this->values = $values;
        $this->useProcessEnv = $useProcessEnv;
        $this->sourcePath = $sourcePath;
    }

    public static function fromFile(string $path): self
    {
        $values = [];

        if (is_readable($path)) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $values = self::parseString($contents);
            }
        }

        return new self($values, true, $path);
    }

    /**
     * @param array<string, string> $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values, false, null);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->values)) {
            $raw = trim((string) $this->values[$key]);

            return $raw === '' ? $default : $raw;
        }

        if ($this->useProcessEnv) {
            $raw = getenv($key);

            if ($raw !== false) {
                $raw = trim($raw);

                return $raw === '' ? $default : $raw;
            }
        }

        return $default;
    }

    /**
     * Returns the raw value, including an empty string when the key exists.
     *
     * This is needed for optional settings such as GEMINI_FALLBACK_MODELS=
     * where an empty value should mean “no fallback models”.
     */
    public function raw(string $key): ?string
    {
        if (array_key_exists($key, $this->values)) {
            return (string) $this->values[$key];
        }

        if ($this->useProcessEnv) {
            $raw = getenv($key);

            if ($raw !== false) {
                return (string) $raw;
            }
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /**
     * @return array<string, string>
     */
    public static function parseString(string $content): array
    {
        $values = [];
        $lines = preg_split('/\R/', $content);

        if ($lines === false) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (strncmp($line, 'export ', 7) === 0) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');

            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            $length = strlen($value);

            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];

                if (
                    ($first === '"' && $last === '"')
                    || ($first === "'" && $last === "'")
                ) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }
}