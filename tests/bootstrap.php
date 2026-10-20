<?php

declare(strict_types=1);

$autoload = dirname(__DIR__).'/vendor/autoload.php';

if (! is_file($autoload)) {
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
}

require $autoload;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Agung\EventIntegration\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', $relativeClass).'.php';

    if (is_file($file)) {
        require $file;
    }
});
