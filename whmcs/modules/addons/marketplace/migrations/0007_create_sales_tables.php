<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Carts, marketplace order overlay on WHMCS orders/invoices/services, order
 * history, subscriptions (mirror of WHMCS services), entitlements and service
 * delivery workflow.
 *
 * Amounts on orders/order_items are the server-computed values at checkout and
 * are immutable snapshots; WHMCS invoices remain the billing source of truth.
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'carts', 'cart_items', 'orders', 'order_items', 'order_status_history',
            'subscriptions', 'entitlements', 'support_extensions', 'service_deliveries',
        ];
    }

    public function isFinancial(): bool
    {
        return true;
    }

    public function up(): void
    {
        $this->create('carts', function (Blueprint $t): void {
            $this->id($t);
            $this->hash($t, 'token_hash');
            $this->whmcsId($t, 'client_id', true);
            $this->whmcsId($t, 'user_id', true);
            $this->currency($t);
            $t->string('coupon_code', 64)->nullable();
            $this->status($t, 'status', 'active');
            $this->timestamp($t, 'last_activity_at', false);
            $this->hash($t, 'recovery_token_hash', true);
            $this->timestamp($t, 'recovery_sent_at');
            $this->ref($t, 'recovery_discount_coupon_id', true);
            $this->ref($t, 'converted_order_id', true);
            $this->timestamps($t);
            $this->unique($t, ['token_hash']);
            $this->index($t, ['client_id', 'status']);
            $this->index($t, ['status', 'last_activity_at']);
            $this->index($t, ['recovery_token_hash']);
        });

        $this->create('cart_items', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'cart_id');
            $this->ref($t, 'product_id');
            $this->ref($t, 'license_tier_id');
            $t->unsignedSmallInteger('quantity')->default(1);
            $t->boolean('saved_for_later')->default(false);
            $this->json($t, 'options');
            $this->timestamp($t, 'added_at', false);
            $this->unique($t, ['cart_id', 'product_id', 'license_tier_id', 'saved_for_later']);
            $this->index($t, ['product_id']);
            $this->foreign($t, 'cart_id', 'carts', 'cascade');
            $this->foreign($t, 'product_id', 'products', 'cascade');
            $this->foreign($t, 'license_tier_id', 'product_license_tiers', 'cascade');
        });

        $this->create('orders', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $t->string('order_number', 32)->charset('ascii')->collation('ascii_bin');
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'user_id', true);
            $this->whmcsId($t, 'whmcs_order_id', true);
            $this->whmcsId($t, 'whmcs_invoice_id', true);
            $this->currency($t);
            $this->fxRate($t, 'fx_rate_to_default', false);
            $this->money($t, 'subtotal');
            $this->money($t, 'discount_total');
            $this->money($t, 'tax_total');
            $this->money($t, 'total');
            $this->status($t, 'status', 'pending');
            $this->status($t, 'payment_status', 'unpaid');
            $this->status($t, 'fulfillment_status', 'unfulfilled');
            $this->ref($t, 'coupon_id', true);
            $this->ref($t, 'affiliate_account_id', true);
            $this->ref($t, 'referral_id', true);
            $this->ip($t);
            $this->hash($t, 'idempotency_key');
            $this->timestamp($t, 'paid_at');
            $this->timestamp($t, 'completed_at');
            $this->timestamp($t, 'cancelled_at');
            $this->timestamps($t);
            $this->unique($t, ['order_number']);
            $this->unique($t, ['whmcs_order_id']);
            $this->unique($t, ['idempotency_key']);
            $this->index($t, ['client_id', 'created_at']);
            $this->index($t, ['status', 'created_at']);
            $this->index($t, ['whmcs_invoice_id']);
        });

        $this->create('order_items', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'order_id');
            $this->ref($t, 'vendor_id');
            $this->ref($t, 'product_id');
            $this->ref($t, 'license_tier_id', true);
            $this->ref($t, 'version_id_at_purchase', true);
            $this->whmcsId($t, 'whmcs_service_id', true);
            $this->whmcsId($t, 'whmcs_domain_id', true);
            $t->string('title_snapshot', 191);
            $this->json($t, 'tier_snapshot');
            $t->unsignedSmallInteger('quantity')->default(1);
            $this->money($t, 'unit_price');
            $this->money($t, 'discount_amount');
            $this->code($t, 'discount_funded_by', 16, true);
            $this->money($t, 'net_amount');
            $this->money($t, 'tax_amount');
            $this->money($t, 'line_total');
            $this->money($t, 'commission_amount');
            $this->money($t, 'fee_amount');
            $this->money($t, 'vendor_net_amount');
            $this->json($t, 'commission_snapshot');
            $this->code($t, 'billing_cycle', 32);
            $this->status($t, 'status', 'pending');
            $this->status($t, 'fulfillment_status', 'unfulfilled');
            $this->money($t, 'refunded_amount');
            $this->timestamps($t);
            $this->unique($t, ['whmcs_service_id']);
            $this->index($t, ['order_id']);
            $this->index($t, ['vendor_id', 'created_at']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['product_id', 'created_at']);
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'license_tier_id', 'product_license_tiers', 'set null');
            $this->foreign($t, 'version_id_at_purchase', 'product_versions', 'set null');
        });
        $this->check('order_items', 'amounts_non_negative',
            '`unit_price` >= 0 AND `discount_amount` >= 0 AND `net_amount` >= 0 AND `tax_amount` >= 0'
            . ' AND `commission_amount` >= 0 AND `fee_amount` >= 0 AND `vendor_net_amount` >= 0 AND `refunded_amount` >= 0');

        $this->create('order_status_history', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'order_id');
            $this->ref($t, 'order_item_id', true);
            $this->code($t, 'field', 32);
            $this->code($t, 'from_value', 32, true);
            $this->code($t, 'to_value', 32);
            $this->actor($t);
            $t->string('reason', 500)->nullable();
            $this->createdAt($t);
            $this->index($t, ['order_id', 'created_at']);
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'order_item_id', 'order_items');
        });

        $this->create('subscriptions', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'order_item_id');
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'product_id');
            $this->ref($t, 'license_tier_id', true);
            $this->whmcsId($t, 'whmcs_service_id');
            $this->ref($t, 'license_id', true);
            $this->code($t, 'billing_cycle', 32);
            $this->money($t, 'recurring_amount');
            $this->currency($t);
            $this->status($t, 'status', 'active');
            $t->date('next_due_date')->nullable();
            $this->timestamp($t, 'cancel_requested_at');
            $this->timestamp($t, 'ended_at');
            $this->timestamps($t);
            $this->unique($t, ['order_item_id']);
            $this->unique($t, ['whmcs_service_id']);
            $this->index($t, ['client_id', 'status']);
            $this->index($t, ['status', 'next_due_date']);
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'license_tier_id', 'product_license_tiers', 'set null');
        });

        $this->create('entitlements', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'order_item_id', true);
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'product_id');
            $this->ref($t, 'license_id', true);
            $t->boolean('downloads_allowed')->default(true);
            $this->timestamp($t, 'support_expires_at');
            $this->timestamp($t, 'updates_expires_at');
            $t->unsignedInteger('max_downloads')->nullable();
            $t->unsignedInteger('downloads_used')->default(0);
            $this->code($t, 'source', 32);
            $this->status($t, 'status', 'active');
            $this->timestamps($t);
            $this->index($t, ['client_id', 'product_id', 'status']);
            $this->index($t, ['order_item_id']);
            $this->index($t, ['product_id', 'status']);
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'product_id', 'products');
        });

        $this->create('support_extensions', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'entitlement_id');
            $this->ref($t, 'order_item_id');
            $t->unsignedSmallInteger('months');
            $this->timestamp($t, 'previous_support_expires_at');
            $this->timestamp($t, 'new_support_expires_at');
            $this->createdAt($t);
            $this->index($t, ['entitlement_id']);
            $this->unique($t, ['order_item_id']);
            $this->foreign($t, 'entitlement_id', 'entitlements');
            $this->foreign($t, 'order_item_id', 'order_items');
        });

        $this->create('service_deliveries', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'order_item_id');
            $this->ref($t, 'vendor_id');
            $this->whmcsId($t, 'client_id');
            $this->status($t, 'status', 'awaiting_requirements');
            $this->json($t, 'requirements');
            $this->timestamp($t, 'due_at');
            $this->timestamp($t, 'delivered_at');
            $this->timestamp($t, 'accepted_at');
            $this->timestamp($t, 'auto_accept_at');
            $t->unsignedSmallInteger('revisions_used')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['order_item_id']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['status', 'auto_accept_at']);
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'vendor_id', 'vendors');
        });

        $this->addForeign('carts', 'converted_order_id', 'orders', 'set null');
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
