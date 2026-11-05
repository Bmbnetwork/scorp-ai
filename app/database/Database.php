<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Env;
use PDO;
use PDOException;

final class Database
{
    private string $host;

    private int $port;

    private string $name;

    private string $user;

    private string $password;

    private ?PDO $pdo = null;

    private function __construct(string $host, int $port, string $name, string $user, string $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->name = $name;
        $this->user = $user;
        $this->password = $password;
    }

    public static function fromEnv(Env $env): self
    {
        $host = trim((string) $env->get('DB_HOST', '127.0.0.1'));
        $port = (int) $env->get('DB_PORT', '3306');
        $name = trim((string) $env->get('DB_NAME', ''));
        $user = trim((string) $env->get('DB_USER', ''));
        $password = (string) $env->get('DB_PASS', '');

        if ($host === '') {
            $host = '127.0.0.1';
        }

        if ($port <= 0 || $port > 65535) {
            $port = 3306;
        }

        if ($name === '') {
            throw new DatabaseException(
                'database_not_configured',
                'The local history database is not configured. Set DB_NAME in .env.'
            );
        }

        return new self($host, $port, $name, $user, $password);
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->name
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO($this->dsn(), $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            // Safe to surface locally: contains user/host and whether a password
            // was sent, but never the password itself.
            throw new DatabaseException(
                'database_connection_failed',
                'The local history database could not be reached. Cause: ' . $exception->getMessage()
            );
        }

        return $this->pdo;
    }
}