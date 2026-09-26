<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Optional affiliate and referral modules. The tables always exist (empty
 * tables cost nothing and keep the schema uniform); the features are enabled
 * or disabled by settings.
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['affiliate_accounts', 'affiliate_clicks', 'affiliate_conversions', 'referrals'];
    }

    public function isFinancial(): bool
    {
        return true;
    }

    public function up(): void
    {
        $this->create('affiliate_accounts', function (Blueprint $t): void {
            $this->id($t);
            $this->whmcsId($t, 'client_id');
            $this->slug($t, 'code', 32);
            $this->status($t, 'status', 'pending');
            $this->code($t, 'rate_type', 16);
            $this->rate($t, 'rate');
            $t->unsignedSmallInteger('cookie_days')->default(30);
            $this->money($t, 'balance_pending');
            $this->money($t, 'balance_available');
            $this->currency($t);
            $this->timestamps($t);
            $this->unique($t, ['client_id']);
            $this->unique($t, ['code']);
        });

        $this->create('affiliate_clicks', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'affiliate_account_id');
            $t->string('landing_url', 2048)->nullable();
            $this->hash($t, 'ip_hash', true);
            $this->hash($t, 'ua_hash', true);
            $t->string('referrer', 2048)->nullable();
            $this->createdAt($t);
            $this->index($t, ['affiliate_account_id', 'created_at']);
            $this->foreign($t, 'affiliate_account_id', 'affiliate_accounts');
        });

        $this->create('affiliate_conversions', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'affiliate_account_id');
            $this->ref($t, 'order_id');
            $this->ref($t, 'order_item_id');
            $this->money($t, 'amount');
            $this->money($t, 'commission');
            $this->currency($t);
            $this->status($t, 'status', 'pending');
            $this->timestamps($t);
            $this->unique($t, ['order_item_id', 'affiliate_account_id']);
            $this->index($t, ['affiliate_account_id', 'status']);
            $this->foreign($t, 'affiliate_account_id', 'affiliate_accounts');
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'order_item_id', 'order_items');
        });

        $this->create('referrals', function (Blueprint $t): void {
            $this->id($t);
            $this->whmcsId($t, 'referrer_client_id');
            $this->whmcsId($t, 'referee_client_id', true);
            $this->slug($t, 'invite_code', 32);
            $this->status($t, 'status', 'invited');
            $this->code($t, 'reward_type', 16, true);
            $this->money($t, 'reward_amount', true);
            $this->currency($t, 'currency_id', true);
            $this->timestamp($t, 'rewarded_at');
            $this->timestamps($t);
            $this->unique($t, ['referee_client_id']);
            $this->unique($t, ['invite_code']);
            $this->index($t, ['referrer_client_id']);
        });

        $this->addForeign('orders', 'affiliate_account_id', 'affiliate_accounts', 'set null');
        $this->addForeign('orders', 'referral_id', 'referrals', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('orders', 'affiliate_account_id');
        $this->dropForeignIfExists('orders', 'referral_id');
        $this->dropAll($this->tables());
    }
};
