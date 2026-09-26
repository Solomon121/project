<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Integration\Database;

use Illuminate\Database\QueryException;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaCheck;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaVerifier;
use WhmcsMarketplace\Infrastructure\Persistence\Table;
use WhmcsMarketplace\Tests\Support\DatabaseTestCase;

final class SchemaIntegrityTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->installer()->install();
    }

    public function testVerifierPassesOnFreshInstall(): void
    {
        $checks = (new SchemaVerifier($this->db, $this->migrator()))->verify();

        foreach ($checks as $check) {
            self::assertSame(SchemaCheck::PASS, $check->status, "{$check->code}: {$check->message}");
        }
    }

    public function testNoForeignKeyReferencesAWhmcsCoreTable(): void
    {
        $rows = $this->db->connection()->select(
            "SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mod\\_marketplace\\_%'"
        );
        self::assertGreaterThan(100, count($rows));
        foreach ($rows as $row) {
            self::assertStringStartsWith('mod_marketplace_', $row->r, "{$row->t} references {$row->r}");
        }
    }

    public function testFinancialForeignKeysNeverCascadeDeletes(): void
    {
        $financial = ['commissions', 'commission_adjustments', 'wallet_transactions', 'wallets', 'payouts',
            'payout_events', 'refunds', 'refund_events', 'order_items', 'orders', 'coupon_usage'];
        $rows = $this->db->connection()->select(
            "SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS c, DELETE_RULE AS d FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (" . implode(',', array_fill(0, count($financial), '?')) . ')',
            array_map([Table::class, 'name'], $financial)
        );
        foreach ($rows as $row) {
            self::assertNotSame('CASCADE', $row->d, "{$row->t}.{$row->c} must not cascade");
        }
    }

    public function testAllConstraintNamesUseTheMarketplacePrefix(): void
    {
        $rows = $this->db->connection()->select(
            "SELECT TABLE_NAME AS t, INDEX_NAME AS i FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mod\\_marketplace\\_%' AND INDEX_NAME <> 'PRIMARY'"
        );
        foreach ($rows as $row) {
            self::assertStringStartsWith('mkp_', $row->i, "Index {$row->i} on {$row->t}");
        }
    }

    /**
     * Indexes the documented hot query paths depend on (03 §12).
     */
    public function testKeyQueryPathIndexesExist(): void
    {
        $expected = [
            'products' => [['status', 'published_at'], ['vendor_id', 'status'], ['primary_category_id', 'status', 'published_at']],
            'order_items' => [['vendor_id', 'created_at'], ['product_id', 'created_at'], ['order_id']],
            'orders' => [['client_id', 'created_at'], ['status', 'created_at']],
            'wallet_transactions' => [['idempotency_key'], ['wallet_id', 'id'], ['type', 'release_at']],
            'licenses' => [['key_hash'], ['client_id', 'status'], ['status', 'expires_at']],
            'license_activations' => [['license_id', 'instance_hash']],
            'download_tokens' => [['token_hash'], ['expires_at']],
            'search_index' => [['status', 'min_price'], ['status', 'rating_avg'], ['status', 'sales']],
            'jobs' => [['queue', 'reserved_at', 'available_at']],
            'webhook_deliveries' => [['status', 'next_attempt_at']],
            'notifications' => [['recipient_type', 'recipient_id', 'read_at', 'id']],
        ];
        foreach ($expected as $table => $indexes) {
            $actual = $this->indexColumns(Table::name($table));
            foreach ($indexes as $columns) {
                self::assertContains($columns, $actual, "{$table} needs an index on (" . implode(', ', $columns) . ')');
            }
        }

        $fulltext = $this->db->connection()->select(
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mod_marketplace_search_index' AND INDEX_TYPE = 'FULLTEXT'"
        );
        self::assertCount(1, $fulltext);
    }

    public function testCheckConstraintsRejectInvalidFinancialData(): void
    {
        $ids = $this->fixtures();

        $this->assertRejected(fn () => $this->table('product_license_tiers')->insert([
            'product_id' => $ids['product'], 'license_type_id' => $ids['license_type'], 'name' => 'Bad',
            'price' => '-1.0000', 'billing_cycle' => 'onetime', 'created_at' => $this->now(),
        ]), 'negative price');

        $this->assertRejected(fn () => $this->table('commissions')->insert([
            'order_item_id' => $ids['order_item'], 'vendor_id' => $ids['vendor'], 'currency_id' => 1,
            'gross_amount' => '100.0000', 'net_amount' => '100.0000', 'commission_amount' => '20.0000',
            'processing_fee' => '0.0000', 'tax_withheld' => '0.0000', 'vendor_net' => '79.9900',
            'breakdown' => '{}', 'created_at' => $this->now(),
        ]), 'commission split that does not sum to net');

        $this->assertRejected(fn () => $this->table('wallets')->where('id', $ids['wallet'])->update(['pending' => '-5.0000']),
            'negative pending balance');

        $this->assertRejected(fn () => $this->table('reviews')->insert([
            'uuid' => $this->ulid(), 'target_type' => 'product', 'product_id' => $ids['product'], 'vendor_id' => $ids['vendor'],
            'client_id' => 1, 'rating' => 6, 'created_at' => $this->now(),
        ]), 'rating above 5');

        $this->assertRejected(fn () => $this->table('coupons')->insert([
            'code' => 'X', 'code_normalized' => 'X', 'scope' => 'marketplace', 'type' => 'percentage', 'value' => '150.0000',
            'funded_by' => 'platform', 'created_at' => $this->now(),
        ]), 'percentage coupon above 100');

        $this->assertRejected(fn () => $this->table('payouts')->insert([
            'uuid' => $this->ulid(), 'vendor_id' => $ids['vendor'], 'wallet_id' => $ids['wallet'],
            'payout_method_id' => $ids['payout_method'], 'provider_code' => 'manual', 'amount' => '100.0000',
            'fee' => '1.0000', 'net_amount' => '100.0000', 'currency_id' => 1, 'requested_at' => $this->now(),
            'idempotency_key' => hash('sha256', 'p1'), 'created_at' => $this->now(),
        ]), 'payout net that does not equal amount minus fee');

        // A balanced commission is accepted.
        $this->table('commissions')->insert([
            'order_item_id' => $ids['order_item'], 'vendor_id' => $ids['vendor'], 'currency_id' => 1,
            'gross_amount' => '100.0000', 'net_amount' => '100.0000', 'commission_amount' => '20.0000',
            'processing_fee' => '3.2000', 'tax_withheld' => '0.0000', 'vendor_net' => '76.8000',
            'breakdown' => '{}', 'created_at' => $this->now(),
        ]);
        self::assertSame(1, $this->table('commissions')->count());
    }

    public function testLedgerIsAppendOnly(): void
    {
        $ids = $this->fixtures();
        $this->table('wallet_transactions')->insert([
            'uuid' => $this->ulid(), 'wallet_id' => $ids['wallet'], 'vendor_id' => $ids['vendor'], 'currency_id' => 1,
            'type' => 'sale', 'bucket_to' => 'pending', 'amount' => '80.0000', 'available_after' => '0.0000',
            'pending_after' => '80.0000', 'reserved_after' => '0.0000', 'description' => 'Sale',
            'idempotency_key' => 'sale:1', 'entry_hash' => hash('sha256', 'e1'), 'created_at' => $this->now(),
        ]);

        $this->assertRejected(fn () => $this->table('wallet_transactions')->update(['amount' => '999.0000']), 'ledger UPDATE', 'append-only');
        $this->assertRejected(fn () => $this->table('wallet_transactions')->delete(), 'ledger DELETE', 'append-only');

        $this->assertRejected(fn () => $this->table('wallet_transactions')->insert([
            'uuid' => $this->ulid(), 'wallet_id' => $ids['wallet'], 'vendor_id' => $ids['vendor'], 'currency_id' => 1,
            'type' => 'sale', 'bucket_to' => 'pending', 'amount' => '80.0000', 'available_after' => '0.0000',
            'pending_after' => '160.0000', 'reserved_after' => '0.0000', 'description' => 'Replay',
            'idempotency_key' => 'sale:1', 'entry_hash' => hash('sha256', 'e2'), 'created_at' => $this->now(),
        ]), 'duplicate idempotency key');

        self::assertSame('80.0000', (string) $this->table('wallet_transactions')->value('amount'));
    }

    public function testAuditLogIsAppendOnly(): void
    {
        $this->table('audit_logs')->insert([
            'uuid' => $this->ulid(), 'actor_type' => 'admin', 'actor_id' => 1, 'action' => 'product.approve',
            'entry_hash' => hash('sha256', 'a'), 'created_at' => $this->now(),
        ]);

        $this->assertRejected(fn () => $this->table('audit_logs')->update(['action' => 'nothing.happened']), 'audit UPDATE', 'append-only');
        $this->assertRejected(fn () => $this->table('audit_logs')->delete(), 'audit DELETE', 'append-only');
    }

    public function testDownloadLogBlocksUpdatesButAllowsRetentionDeletes(): void
    {
        $this->table('downloads')->insert(['client_id' => 1, 'result' => 'success', 'created_at' => $this->now()]);

        $this->assertRejected(fn () => $this->table('downloads')->update(['result' => 'denied']), 'download log UPDATE', 'append-only');
        self::assertSame(1, $this->table('downloads')->where('created_at', '<', '2999-01-01')->delete());
    }

    public function testUtf8mb4RoundTripsFourByteCharacters(): void
    {
        $ids = $this->fixtures();
        $title = 'Ünïcödé 🚀 مرحبا';
        $this->table('products')->where('id', $ids['product'])->update(['title' => $title]);

        self::assertSame($title, $this->table('products')->where('id', $ids['product'])->value('title'));
    }

    public function testOneReviewPerClientPerTarget(): void
    {
        $ids = $this->fixtures();
        $review = [
            'target_type' => 'product', 'product_id' => $ids['product'], 'vendor_id' => $ids['vendor'],
            'client_id' => 5, 'rating' => 5, 'created_at' => $this->now(),
        ];
        $this->table('reviews')->insert($review + ['uuid' => $this->ulid()]);
        // A separate vendor review by the same client is allowed.
        $this->table('reviews')->insert(['target_type' => 'vendor', 'product_id' => null, 'uuid' => $this->ulid()] + $review);

        $this->assertRejected(fn () => $this->table('reviews')->insert($review + ['uuid' => $this->ulid()]), 'duplicate product review');
    }

    public function testVerifierDetectsMissingTablesAndTriggers(): void
    {
        $this->db->connection()->unprepared('DROP TRIGGER `mkp_wallet_transactions_no_update`');
        $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
        $this->db->schema()->drop(Table::name('view_buffer'));
        $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=1');

        $checks = [];
        foreach ((new SchemaVerifier($this->db, $this->migrator()))->verify() as $check) {
            $checks[$check->code] = $check;
        }

        self::assertSame(SchemaCheck::ERROR, $checks['TABLES_MISSING']->status);
        self::assertStringContainsString('mod_marketplace_view_buffer', $checks['TABLES_MISSING']->message);
        self::assertSame(SchemaCheck::WARNING, $checks['APPEND_ONLY_NOT_ENFORCED']->status);
        self::assertStringContainsString('mkp_wallet_transactions_no_update', $checks['APPEND_ONLY_NOT_ENFORCED']->message);
        self::assertSame(SchemaCheck::ERROR, SchemaVerifier::worstStatus(array_values($checks)));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<list<string>> */
    private function indexColumns(string $table): array
    {
        $rows = $this->db->connection()->select(
            'SELECT INDEX_NAME AS i, COLUMN_NAME AS c FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row->i][] = $row->c;
        }

        return array_values($indexes);
    }

    private function assertRejected(callable $write, string $what, ?string $messageContains = null): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            if ($messageContains !== null) {
                self::assertStringContainsString($messageContains, $e->getMessage(), $what);
            }
            $this->addToAssertionCount(1);

            return;
        }
        self::fail("The database accepted {$what}.");
    }

    private function ulid(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(13)), 0, 26));
    }

    /** @return array<string, int> */
    private function fixtures(): array
    {
        $now = $this->now();
        $vendor = (int) $this->table('vendors')->insertGetId([
            'uuid' => $this->ulid(), 'client_id' => 10, 'owner_user_id' => 10, 'username' => 'acme', 'slug' => 'acme',
            'display_name' => 'Acme', 'status' => 'approved', 'created_at' => $now,
        ]);
        $type = (int) $this->table('product_types')->where('code', 'wordpress_theme')->value('id');
        $licenseType = (int) $this->table('license_types')->where('code', 'single_domain')->value('id');
        $category = (int) $this->table('categories')->where('slug', 'wordpress-themes')->value('id');
        $product = (int) $this->table('products')->insertGetId([
            'uuid' => $this->ulid(), 'vendor_id' => $vendor, 'product_type_id' => $type, 'primary_category_id' => $category,
            'slug' => 'theme', 'title' => 'Theme', 'listing_currency_id' => 1, 'created_at' => $now,
        ]);
        $order = (int) $this->table('orders')->insertGetId([
            'uuid' => $this->ulid(), 'order_number' => 'MKP-1', 'client_id' => 20, 'currency_id' => 1,
            'fx_rate_to_default' => '1.00000000', 'idempotency_key' => hash('sha256', 'o1'), 'created_at' => $now,
        ]);
        $item = (int) $this->table('order_items')->insertGetId([
            'uuid' => $this->ulid(), 'order_id' => $order, 'vendor_id' => $vendor, 'product_id' => $product,
            'title_snapshot' => 'Theme', 'billing_cycle' => 'onetime', 'created_at' => $now,
        ]);
        $wallet = (int) $this->table('wallets')->insertGetId([
            'vendor_id' => $vendor, 'currency_id' => 1, 'created_at' => $now,
        ]);
        $method = (int) $this->table('vendor_payout_methods')->insertGetId([
            'vendor_id' => $vendor, 'provider_code' => 'manual', 'label' => 'Bank', 'status' => 'active', 'created_at' => $now,
        ]);

        return [
            'vendor' => $vendor, 'product' => $product, 'license_type' => $licenseType, 'order' => $order,
            'order_item' => $item, 'wallet' => $wallet, 'payout_method' => $method,
        ];
    }
}
