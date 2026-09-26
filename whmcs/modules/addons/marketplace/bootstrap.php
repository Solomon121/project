<?php

declare(strict_types=1);

/**
 * Autoloading for the marketplace module.
 *
 * Our own classes are loaded from src/ (PSR-4, namespace WhmcsMarketplace\).
 * Third-party libraries, when added, are namespace-prefixed into vendor-scoped/
 * (ADR-007) so they can never collide with the libraries bundled with WHMCS.
 * Illuminate database classes are provided by WHMCS itself.
 */

if (!defined('WHMCS_MARKETPLACE_BOOTSTRAPPED')) {
    define('WHMCS_MARKETPLACE_BOOTSTRAPPED', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'WhmcsMarketplace\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    $scoped = __DIR__ . '/vendor-scoped/autoload.php';
    if (is_file($scoped)) {
        require $scoped;
    }
}
