<?php

declare(strict_types=1);

namespace App\Support;

final class RateLimiter
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function allow(string $key, int $maxRequests, int $windowSeconds): bool
    {
        if ($maxRequests <= 0 || $windowSeconds <= 0) {
            return true;
        }

        if (!$this->ensureDirectory()) {
            return true;
        }

        $hash = hash('sha256', $key);
        $file = $this->directory . DIRECTORY_SEPARATOR . $hash . '.json';

        $handle = @fopen($file, 'c+');

        if ($handle === false) {
            return true;
        }

        if (!@flock($handle, LOCK_EX)) {
            fclose($handle);

            return true;
        }

        $contents = @stream_get_contents($handle);
        $now = time();
        $record = [
            'count' => 0,
            'reset' => $now + $windowSeconds,
        ];

        if (is_string($contents) && $contents !== '') {
            $decoded = json_decode($contents, true);

            if (is_array($decoded)) {
                $reset = (int) ($decoded['reset'] ?? 0);
                $count = (int) ($decoded['count'] ?? 0);

                if ($reset > $now) {
                    $record = [
                        'count' => $count,
                        'reset' => $reset,
                    ];
                }
            }
        }

        if ($record['count'] >= $maxRequests) {
            $this->writeRecord($handle, $record);
            @flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }

        $record['count']++;
        $this->writeRecord($handle, $record);

        @flock($handle, LOCK_UN);
        fclose($handle);

        return true;
    }

    /**
     * @param resource $handle
     * @param array{count:int, reset:int} $record
     */
    private function writeRecord($handle, array $record): void
    {
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, (string) json_encode($record));
        @fflush($handle);
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        return @mkdir($this->directory, 0700, true);
    }
}