<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Vendors are WHMCS client accounts (client_id) managed by WHMCS users
 * (vendor_members.user_id). No WHMCS personal data is duplicated here; business
 * and tax details are encrypted (see 08 §4).
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'vendors', 'vendor_profiles', 'vendor_business', 'vendor_documents', 'vendor_verifications',
            'vendor_members', 'vendor_stats', 'vendor_followers', 'vendor_payout_methods',
        ];
    }

    public function up(): void
    {
        $this->create('vendors', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'owner_user_id');
            $this->slug($t, 'username', 64);
            $this->slug($t);
            $t->string('display_name', 191);
            $this->status($t, 'status', 'pending');
            $this->status($t, 'verification_level', 'unverified');
            $this->currency($t, 'payout_currency_id', true);
            $this->ref($t, 'default_commission_rule_id', true);
            $t->unsignedInteger('agreement_page_version')->nullable();
            $this->timestamp($t, 'agreement_accepted_at');
            $this->json($t, 'application');
            $this->whmcsId($t, 'approved_by_admin_id', true);
            $this->timestamp($t, 'approved_at');
            $t->text('status_reason')->nullable();
            $this->timestamps($t);
            $this->unique($t, ['client_id']);
            $this->unique($t, ['username']);
            $this->unique($t, ['slug']);
            $this->index($t, ['status', 'created_at']);
            $this->index($t, ['owner_user_id']);
        });

        $this->create('vendor_profiles', function (Blueprint $t): void {
            $this->ref($t, 'vendor_id');
            $t->mediumText('bio_html')->nullable();
            $t->string('website', 2048)->nullable();
            $t->string('location', 191)->nullable();
            $t->char('country_code', 2)->charset('ascii')->nullable();
            $this->ref($t, 'avatar_media_id', true);
            $this->ref($t, 'cover_media_id', true);
            $this->json($t, 'social');
            $t->string('support_email', 191)->nullable();
            $t->string('support_url', 2048)->nullable();
            $t->unsignedInteger('avg_response_minutes')->nullable();
            $t->string('seo_title', 191)->nullable();
            $t->string('seo_description', 320)->nullable();
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['vendor_id']);
            $this->foreign($t, 'vendor_id', 'vendors', 'cascade');
        });

        $this->create('vendor_business', function (Blueprint $t): void {
            $this->ref($t, 'vendor_id');
            $this->code($t, 'business_type', 32);
            $this->encrypted($t, 'legal_name_enc');
            $this->encrypted($t, 'registration_no_enc');
            $this->encrypted($t, 'tax_id_enc');
            $this->encrypted($t, 'vat_number_enc');
            $this->hash($t, 'vat_number_hash', true);
            $this->encrypted($t, 'address_enc');
            $t->char('country_code', 2)->charset('ascii')->nullable();
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['vendor_id']);
            $this->index($t, ['vat_number_hash']);
            $this->foreign($t, 'vendor_id', 'vendors', 'cascade');
        });

        $this->create('vendor_documents', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'vendor_id');
            $this->code($t, 'type', 32);
            $this->code($t, 'disk', 32);
            $t->string('storage_key', 255)->charset('ascii')->collation('ascii_bin');
            $t->string('mime', 127)->charset('ascii');
            $t->unsignedBigInteger('size_bytes');
            $this->hash($t, 'sha256');
            $this->status($t, 'status', 'submitted');
            $this->whmcsId($t, 'reviewed_by_admin_id', true);
            $this->timestamp($t, 'reviewed_at');
            $t->text('notes')->nullable();
            $this->timestamp($t, 'purge_after');
            $this->createdAt($t);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['purge_after']);
            $this->foreign($t, 'vendor_id', 'vendors');
        });

        $this->create('vendor_verifications', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'vendor_id');
            $this->code($t, 'level_from', 32);
            $this->code($t, 'level_to', 32);
            $this->code($t, 'method', 32);
            $this->json($t, 'evidence');
            $this->whmcsId($t, 'decided_by_admin_id', true);
            $this->timestamp($t, 'decided_at');
            $this->timestamp($t, 'expires_at');
            $this->createdAt($t);
            $this->index($t, ['vendor_id', 'created_at']);
            $this->foreign($t, 'vendor_id', 'vendors');
        });

        $this->create('vendor_members', function (Blueprint $t): void {
            $this->ref($t, 'vendor_id');
            $this->whmcsId($t, 'user_id');
            $this->code($t, 'role', 32);
            $this->json($t, 'permissions');
            $this->whmcsId($t, 'invited_by_user_id', true);
            $this->timestamps($t);
            $this->primary($t, ['vendor_id', 'user_id']);
            $this->index($t, ['user_id']);
            $this->foreign($t, 'vendor_id', 'vendors', 'cascade');
        });

        $this->create('vendor_stats', function (Blueprint $t): void {
            $this->ref($t, 'vendor_id');
            $t->unsignedInteger('products_published')->default(0);
            $t->unsignedBigInteger('sales')->default(0);
            $this->money($t, 'revenue_default_ccy');
            $t->decimal('rating_avg', 3, 2)->default('0.00');
            $t->unsignedInteger('rating_count')->default(0);
            $t->unsignedInteger('followers')->default(0);
            $t->decimal('refund_rate', 5, 4)->default('0.0000');
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['vendor_id']);
            $this->index($t, ['sales']);
            $this->index($t, ['rating_avg']);
            $this->foreign($t, 'vendor_id', 'vendors', 'cascade');
        });

        $this->create('vendor_followers', function (Blueprint $t): void {
            $this->ref($t, 'vendor_id');
            $this->whmcsId($t, 'client_id');
            $t->boolean('notify_new_products')->default(true);
            $this->createdAt($t);
            $this->primary($t, ['vendor_id', 'client_id']);
            $this->index($t, ['client_id']);
            $this->foreign($t, 'vendor_id', 'vendors', 'cascade');
        });

        $this->create('vendor_payout_methods', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'vendor_id');
            $this->code($t, 'provider_code', 64);
            $t->string('label', 128);
            $this->encrypted($t, 'details_enc');
            $this->hash($t, 'details_fingerprint', true);
            $t->boolean('is_default')->default(false);
            $this->status($t, 'status', 'pending_verification');
            $this->timestamp($t, 'verified_at');
            $this->timestamp($t, 'usable_from');
            $this->timestamps($t);
            $this->index($t, ['vendor_id', 'status']);
            $this->foreign($t, 'vendor_id', 'vendors');
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
