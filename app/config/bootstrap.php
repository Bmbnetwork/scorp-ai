<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $prefixLength = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLength) !== 0) {
        return;
    }

    $relativeClass = substr($class, $prefixLength);
    $relativePath = str_replace('\\', '/', $relativeClass);
    $baseDir = dirname(__DIR__) . '/';

    $parts = explode('/', $relativePath);
    $className = array_pop($parts);
    $directoryExact = implode('/', $parts);
    $directoryLower = strtolower($directoryExact);

    $candidates = [
        $baseDir . $relativePath . '.php',
        $baseDir . $directoryLower . '/' . $className . '.php',
        $baseDir . strtolower($relativePath) . '.php',
    ];

    foreach (array_unique($candidates) as $file) {
        if (is_file($file)) {
            require $file;

            return;
        }
    }
});

$rootDir = dirname(__DIR__, 2);

return \App\Config\Env::fromFile($rootDir . '/.env');