<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Commission rules and records, refunds, the vendor ledger (wallet_transactions),
 * wallets (balance cache of the ledger), payouts and attachments.
 * See docs/architecture/04-financial-architecture.md.
 *
 * Append-only: commissions, commission_adjustments, wallet_transactions,
 * payout_events, refund_events (DB triggers in migration 0016 where permitted,
 * plus repository-level enforcement).
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'commission_rules', 'commissions', 'refunds', 'refund_events', 'commission_adjustments',
            'wallets', 'payout_batches', 'payouts', 'payout_events', 'wallet_transactions', 'attachments',
        ];
    }

    public function isFinancial(): bool
    {
        return true;
    }

    public function up(): void
    {
        $this->create('commission_rules', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'scope', 16);
            $t->unsignedBigInteger('scope_id')->nullable();
            $this->code($t, 'method', 16);
            $this->rate($t, 'percentage');
            $this->money($t, 'fixed_amount');
            $this->currency($t, 'fixed_currency_id', true);
            $this->money($t, 'min_commission', true);
            $this->money($t, 'max_commission', true);
            $this->json($t, 'tiers');
            $this->json($t, 'fee_policy');
            $t->integer('priority')->default(0);
            $this->timestamp($t, 'starts_at');
            $this->timestamp($t, 'ends_at');
            $t->boolean('is_active')->default(true);
            $this->ref($t, 'superseded_by', true);
            $this->whmcsId($t, 'created_by_admin_id', true);
            $this->createdAt($t);
            $this->index($t, ['scope', 'scope_id', 'is_active']);
            $this->index($t, ['starts_at', 'ends_at']);
            $this->foreign($t, 'superseded_by', 'commission_rules');
        });
        $this->check('commission_rules', 'values_valid',
            '`percentage` >= 0 AND `percentage` <= 100 AND `fixed_amount` >= 0');

        $this->create('commissions', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'order_item_id');
            $this->ref($t, 'vendor_id');
            $this->ref($t, 'rule_id', true);
            $this->currency($t);
            $this->money($t, 'gross_amount');
            $this->money($t, 'discount_vendor_funded');
            $this->money($t, 'net_amount');
            $this->money($t, 'commission_amount');
            $this->money($t, 'processing_fee');
            $this->money($t, 'tax_withheld');
            $this->money($t, 'vendor_net');
            $this->json($t, 'breakdown', false);
            $this->createdAt($t);
            $this->unique($t, ['order_item_id']);
            $this->index($t, ['vendor_id', 'created_at']);
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'rule_id', 'commission_rules');
        });
        // Remainder rule invariant (04 §2.3): the split always sums exactly to net.
        $this->check('commissions', 'split_balances',
            '`commission_amount` + `processing_fee` + `tax_withheld` + `vendor_net` = `net_amount`'
            . ' AND `vendor_net` >= 0 AND `commission_amount` >= 0');

        $this->create('refunds', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $t->string('refund_number', 32)->charset('ascii')->collation('ascii_bin');
            $this->ref($t, 'order_id');
            $this->ref($t, 'order_item_id', true);
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'vendor_id');
            $this->currency($t);
            $this->money($t, 'requested_amount');
            $this->money($t, 'approved_amount', true);
            $this->code($t, 'type', 16);
            $this->code($t, 'reason_code', 32);
            $t->text('reason_text')->nullable();
            $this->status($t, 'status', 'requested');
            $this->timestamp($t, 'eligible_until');
            $this->status($t, 'vendor_decision', 'pending');
            $this->timestamp($t, 'vendor_decided_at');
            $t->text('vendor_note')->nullable();
            $this->whmcsId($t, 'admin_id', true);
            $t->text('admin_note')->nullable();
            $this->code($t, 'auto_rule', 64, true);
            $this->code($t, 'execution_method', 32, true);
            $this->whmcsId($t, 'whmcs_transaction_id', true);
            $this->money($t, 'commission_reversed');
            $this->money($t, 'vendor_debited');
            $this->money($t, 'fees_retained');
            $this->timestamp($t, 'completed_at');
            $this->timestamps($t);
            $this->unique($t, ['refund_number']);
            $this->index($t, ['order_id']);
            $this->index($t, ['order_item_id']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['status', 'created_at']);
            $this->index($t, ['client_id']);
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'vendor_id', 'vendors');
        });

        $this->create('refund_events', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'refund_id');
            $this->code($t, 'from_status', 32, true);
            $this->code($t, 'to_status', 32);
            $this->actor($t);
            $t->text('note')->nullable();
            $this->createdAt($t);
            $this->index($t, ['refund_id', 'created_at']);
            $this->foreign($t, 'refund_id', 'refunds');
        });

        $this->create('commission_adjustments', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'commission_id');
            $this->ref($t, 'refund_id', true);
            $this->code($t, 'type', 32);
            $this->money($t, 'commission_delta');
            $this->money($t, 'fee_delta');
            $this->money($t, 'vendor_net_delta');
            $t->string('reason', 500);
            $this->whmcsId($t, 'admin_id', true);
            $this->createdAt($t);
            $this->index($t, ['commission_id']);
            $this->index($t, ['refund_id']);
            $this->foreign($t, 'commission_id', 'commissions');
            $this->foreign($t, 'refund_id', 'refunds');
        });

        $this->create('wallets', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'vendor_id');
            $this->currency($t);
            $this->money($t, 'available');
            $this->money($t, 'pending');
            $this->money($t, 'reserved');
            $this->money($t, 'paid_total');
            $this->money($t, 'lifetime_earnings');
            $this->money($t, 'refunded_total');
            $this->ref($t, 'last_entry_id', true);
            $this->hash($t, 'last_entry_hash', true);
            $t->unsignedBigInteger('version')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['vendor_id', 'currency_id']);
            $this->foreign($t, 'vendor_id', 'vendors');
        });
        // Pending and reserved can never go negative; available may (vendor debt after refunds).
        $this->check('wallets', 'buckets_valid', '`pending` >= 0 AND `reserved` >= 0 AND `paid_total` >= 0');

        $this->create('payout_batches', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->code($t, 'schedule', 32);
            $this->code($t, 'provider_code', 64, true);
            $this->status($t, 'status', 'draft');
            $t->unsignedInteger('item_count')->default(0);
            $this->money($t, 'total');
            $this->currency($t, 'currency_id', true);
            $this->actor($t, 'created_by');
            $this->timestamp($t, 'completed_at');
            $this->timestamps($t);
            $this->index($t, ['status', 'created_at']);
        });

        $this->create('payouts', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'vendor_id');
            $this->ref($t, 'wallet_id');
            $this->ref($t, 'batch_id', true);
            $this->ref($t, 'payout_method_id');
            $this->code($t, 'provider_code', 64);
            $this->encrypted($t, 'method_snapshot_enc');
            $this->money($t, 'amount');
            $this->money($t, 'fee');
            $this->money($t, 'net_amount');
            $this->currency($t);
            $this->status($t, 'status', 'requested');
            $this->whmcsId($t, 'requested_by_user_id', true);
            $this->timestamp($t, 'requested_at', false);
            $this->whmcsId($t, 'approved_by_admin_id', true);
            $this->timestamp($t, 'approved_at');
            $this->timestamp($t, 'processed_at');
            $t->string('provider_reference', 191)->nullable();
            $this->code($t, 'failure_code', 64, true);
            $t->string('failure_reason', 1000)->nullable();
            $this->hash($t, 'idempotency_key');
            $this->timestamps($t);
            $this->unique($t, ['idempotency_key']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['status', 'requested_at']);
            $this->index($t, ['batch_id']);
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'wallet_id', 'wallets');
            $this->foreign($t, 'batch_id', 'payout_batches');
            $this->foreign($t, 'payout_method_id', 'vendor_payout_methods');
        });
        $this->check('payouts', 'amounts_valid', '`amount` > 0 AND `fee` >= 0 AND `net_amount` = `amount` - `fee`');

        $this->create('payout_events', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'payout_id');
            $this->code($t, 'from_status', 32, true);
            $this->code($t, 'to_status', 32);
            $this->actor($t);
            $t->text('note')->nullable();
            $this->json($t, 'provider_response');
            $this->createdAt($t);
            $this->index($t, ['payout_id', 'created_at']);
            $this->foreign($t, 'payout_id', 'payouts');
        });

        // The ledger. Amount is always positive; direction is given by bucket_from/bucket_to.
        $this->create('wallet_transactions', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'wallet_id');
            $this->ref($t, 'vendor_id');
            $this->currency($t);
            $this->code($t, 'type', 32);
            $this->code($t, 'bucket_from', 16, true);
            $this->code($t, 'bucket_to', 16, true);
            $this->money($t, 'amount');
            $t->decimal('available_after', 19, 4);
            $t->decimal('pending_after', 19, 4);
            $t->decimal('reserved_after', 19, 4);
            $this->ref($t, 'order_id', true);
            $this->ref($t, 'order_item_id', true);
            $this->ref($t, 'product_id', true);
            $this->ref($t, 'refund_id', true);
            $this->ref($t, 'payout_id', true);
            $this->ref($t, 'dispute_id', true);
            $this->ref($t, 'commission_id', true);
            $this->money($t, 'gross_amount', true);
            $this->money($t, 'commission_amount', true);
            $this->money($t, 'fee_amount', true);
            $this->money($t, 'net_amount', true);
            $this->fxRate($t, 'fx_rate');
            $t->string('description', 500);
            $t->string('idempotency_key', 191)->charset('ascii')->collation('ascii_bin');
            $this->timestamp($t, 'release_at');
            $this->actor($t);
            $this->hash($t, 'prev_hash', true);
            $this->hash($t, 'entry_hash');
            $this->createdAt($t);
            $this->unique($t, ['idempotency_key']);
            $this->index($t, ['wallet_id', 'id']);
            $this->index($t, ['vendor_id', 'created_at']);
            $this->index($t, ['type', 'release_at']);
            $this->index($t, ['order_item_id']);
            $this->index($t, ['payout_id']);
            $this->index($t, ['refund_id']);
            $this->foreign($t, 'wallet_id', 'wallets');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'refund_id', 'refunds');
            $this->foreign($t, 'payout_id', 'payouts');
            $this->foreign($t, 'commission_id', 'commissions');
        });
        $this->check('wallet_transactions', 'amount_positive', '`amount` > 0');

        $this->create('attachments', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'owner_type', 32);
            $t->unsignedBigInteger('owner_id');
            $this->code($t, 'disk', 32);
            $t->string('storage_key', 255)->charset('ascii')->collation('ascii_bin');
            $t->string('original_name', 255);
            $t->string('mime', 127)->charset('ascii');
            $t->unsignedBigInteger('size_bytes');
            $this->hash($t, 'sha256');
            $this->status($t, 'scan_status', 'pending');
            $this->actor($t, 'uploaded_by');
            $this->createdAt($t);
            $this->index($t, ['owner_type', 'owner_id']);
            $this->unique($t, ['disk', 'storage_key']);
        });

        $this->addForeign('vendors', 'default_commission_rule_id', 'commission_rules', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('vendors', 'default_commission_rule_id');
        $this->dropAll($this->tables());
    }
};
