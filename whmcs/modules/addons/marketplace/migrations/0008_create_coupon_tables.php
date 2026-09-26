<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Marketplace coupons (applied server-side before AddOrder; WHMCS promo codes
 * are not used for marketplace items, see 01 §4.4).
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['coupons', 'coupon_targets', 'coupon_usage'];
    }

    public function isFinancial(): bool
    {
        return true;
    }

    public function up(): void
    {
        $this->create('coupons', function (Blueprint $t): void {
            $this->id($t);
            $t->string('code', 64);
            $t->string('code_normalized', 64)->charset('ascii')->collation('ascii_bin');
            $this->code($t, 'scope', 16);
            $this->ref($t, 'vendor_id', true);
            $this->code($t, 'type', 16);
            $this->money($t, 'value');
            $this->currency($t, 'currency_id', true);
            $this->money($t, 'max_discount', true);
            $this->money($t, 'min_purchase', true);
            $t->boolean('first_order_only')->default(false);
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('usage_count')->default(0);
            $t->unsignedInteger('per_customer_limit')->nullable();
            $this->code($t, 'funded_by', 16);
            $t->decimal('vendor_share_pct', 5, 2)->default('0.00');
            $this->timestamp($t, 'starts_at');
            $this->timestamp($t, 'expires_at');
            $t->boolean('is_active')->default(true);
            $this->actor($t, 'created_by');
            $this->timestamps($t);
            $this->softDeletes($t);
            $this->unique($t, ['code_normalized']);
            $this->index($t, ['vendor_id', 'is_active']);
            $this->index($t, ['is_active', 'expires_at']);
            $this->foreign($t, 'vendor_id', 'vendors');
        });
        $this->check('coupons', 'value_valid',
            '`value` >= 0 AND (`type` <> \'percentage\' OR `value` <= 100)'
            . ' AND `vendor_share_pct` >= 0 AND `vendor_share_pct` <= 100');

        $this->create('coupon_targets', function (Blueprint $t): void {
            $this->ref($t, 'coupon_id');
            $this->code($t, 'target_type', 16);
            $t->unsignedBigInteger('target_id');
            $this->primary($t, ['coupon_id', 'target_type', 'target_id']);
            $this->index($t, ['target_type', 'target_id']);
            $this->foreign($t, 'coupon_id', 'coupons', 'cascade');
        });

        $this->create('coupon_usage', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'coupon_id');
            $this->ref($t, 'order_id');
            $this->whmcsId($t, 'client_id');
            $this->money($t, 'discount_amount');
            $this->currency($t);
            $this->createdAt($t);
            $this->unique($t, ['coupon_id', 'order_id']);
            $this->index($t, ['coupon_id', 'client_id']);
            $this->foreign($t, 'coupon_id', 'coupons');
            $this->foreign($t, 'order_id', 'orders');
        });

        $this->addForeign('orders', 'coupon_id', 'coupons');
        $this->addForeign('carts', 'recovery_discount_coupon_id', 'coupons', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('orders', 'coupon_id');
        $this->dropForeignIfExists('carts', 'recovery_discount_coupon_id');
        $this->dropAll($this->tables());
    }
};
