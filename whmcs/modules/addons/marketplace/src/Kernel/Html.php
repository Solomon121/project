<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Kernel;

/**
 * Context-aware output escaping for PHP-rendered (admin) views.
 */
final class Html
{
    public static function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
