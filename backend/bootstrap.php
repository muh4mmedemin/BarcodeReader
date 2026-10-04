<?php

declare(strict_types=1);

// Composer gerektirmeyen basit PSR-4 autoloader: App\X\Y -> src/X/Y.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone']);

return $config;
