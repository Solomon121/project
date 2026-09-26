<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

/**
 * One schema integrity finding. Status uses the health-check vocabulary
 * (PASS / WARNING / ERROR, master prompt §98).
 */
final class SchemaCheck
{
    public const PASS = 'PASS';
    public const WARNING = 'WARNING';
    public const ERROR = 'ERROR';

    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $message,
        public readonly string $remediation = '',
    ) {
    }
}
