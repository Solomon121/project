<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\AppendOnlyPolicy;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\IdentifierName;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

final class IdentifierNameTest extends TestCase
{
    public function testShortNamesAreReadable(): void
    {
        self::assertSame(
            'mkp_products_status_published_at_ix',
            IdentifierName::for('ix', 'mod_marketplace_products', ['status', 'published_at'])
        );
    }

    public function testLongNamesAreTruncatedToMysqlLimitAndStayUnique(): void
    {
        $a = IdentifierName::for('ix', 'mod_marketplace_license_activations', ['license_id', 'instance_hash', 'status', 'environment']);
        $b = IdentifierName::for('ix', 'mod_marketplace_license_activations', ['license_id', 'instance_hash', 'status', 'environment2']);

        self::assertLessThanOrEqual(64, strlen($a));
        self::assertLessThanOrEqual(64, strlen($b));
        self::assertNotSame($a, $b);
        self::assertSame($a, IdentifierName::for('ix', 'mod_marketplace_license_activations', ['license_id', 'instance_hash', 'status', 'environment']));
    }

    public function testTableNamesArePrefixedAndValidated(): void
    {
        self::assertSame('mod_marketplace_orders', Table::name('orders'));

        $this->expectException(\InvalidArgumentException::class);
        Table::name('orders; DROP TABLE tblclients');
    }

    public function testTriggerDefinitionsRespectMysqlLimits(): void
    {
        foreach (AppendOnlyPolicy::triggers() as $trigger) {
            self::assertLessThanOrEqual(64, strlen($trigger['name']), $trigger['name']);
            self::assertMatchesRegularExpression("/MESSAGE_TEXT = '([^']{1,128})'$/", $trigger['sql']);
        }
    }

    public function testImmutableTablesBlockUpdateAndDelete(): void
    {
        $ops = [];
        foreach (AppendOnlyPolicy::triggers() as $trigger) {
            $ops[$trigger['table']][] = $trigger['operation'];
        }

        self::assertSame(['UPDATE', 'DELETE'], $ops['mod_marketplace_wallet_transactions']);
        self::assertSame(['UPDATE', 'DELETE'], $ops['mod_marketplace_audit_logs']);
        self::assertSame(['UPDATE'], $ops['mod_marketplace_downloads']);
    }
}
