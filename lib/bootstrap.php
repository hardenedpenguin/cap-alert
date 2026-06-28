<?php

/**
 * CAP-Alert bootstrap and autoloader.
 *
 * Copyright (C) 2026 Jory A. Pratt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CapAlert\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    $file = __DIR__ . '/' . $relative;
    if (is_file($file)) {
        require $file;
    }
});
