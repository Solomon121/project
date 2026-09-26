<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Products, licence tiers (the purchasable SKUs), category/tag links, media,
 * versions, files, denormalised stats, reports and the moderation log.
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'media', 'products', 'product_license_tiers', 'product_categories', 'product_tags',
            'product_versions', 'product_files', 'product_stats', 'product_reports', 'moderation_log',
        ];
    }

    public function up(): void
    {
        $this->create('media', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'owner_type', 32);
            $t->unsignedBigInteger('owner_id');
            $this->code($t, 'kind', 32);
            $this->code($t, 'disk', 32);
            $t->string('storage_key', 255)->charset('ascii')->collation('ascii_bin');
            $t->string('public_url', 2048)->nullable();
            $t->string('mime', 127)->charset('ascii');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('size_bytes');
            $t->string('alt', 255)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $this->createdAt($t);
            $this->index($t, ['owner_type', 'owner_id', 'sort_order']);
            $this->unique($t, ['disk', 'storage_key']);
        });

        $this->create('products', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'vendor_id');
            $this->ref($t, 'product_type_id');
            $this->ref($t, 'primary_category_id');
            $this->slug($t);
            $t->string('title', 191);
            $t->string('short_description', 500)->nullable();
            $t->mediumText('description_html')->nullable();
            $this->status($t, 'status', 'draft');
            $this->status($t, 'pricing_model', 'one_time');
            $this->currency($t, 'listing_currency_id');
            $t->boolean('is_taxable')->default(true);
            $this->ref($t, 'featured_media_id', true);
            $t->string('demo_url', 2048)->nullable();
            $t->string('documentation_url', 2048)->nullable();
            $t->string('video_url', 2048)->nullable();
            $this->ref($t, 'current_version_id', true);
            $t->text('requirements')->nullable();
            $this->json($t, 'compatibility');
            $this->json($t, 'attributes');
            $t->unsignedSmallInteger('support_months')->default(0);
            $t->unsignedSmallInteger('update_months')->default(0);
            $t->boolean('is_featured')->default(false);
            $this->timestamp($t, 'featured_until');
            $t->date('release_date')->nullable();
            $this->timestamp($t, 'published_at');
            $this->timestamp($t, 'last_updated_at');
            $t->text('rejection_reason')->nullable();
            $t->text('suspended_reason')->nullable();
            $t->string('seo_title', 191)->nullable();
            $t->string('seo_description', 320)->nullable();
            $t->string('seo_canonical_url', 2048)->nullable();
            $this->timestamps($t);
            $this->softDeletes($t);
            $this->unique($t, ['slug']);
            $this->index($t, ['status', 'published_at']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['primary_category_id', 'status', 'published_at']);
            $this->index($t, ['product_type_id', 'status']);
            $this->index($t, ['is_featured', 'status']);
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'product_type_id', 'product_types');
            $this->foreign($t, 'primary_category_id', 'categories');
            $this->foreign($t, 'featured_media_id', 'media', 'set null');
        });

        $this->create('product_license_tiers', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'product_id');
            $this->ref($t, 'license_type_id');
            $t->string('name', 128);
            $this->money($t, 'price');
            $this->money($t, 'sale_price', true);
            $this->timestamp($t, 'sale_starts_at');
            $this->timestamp($t, 'sale_ends_at');
            $this->code($t, 'billing_cycle', 32);
            $t->unsignedInteger('max_activations')->nullable();
            $t->unsignedInteger('max_domains')->nullable();
            $t->unsignedSmallInteger('support_months')->default(0);
            $t->unsignedSmallInteger('update_months')->default(0);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $this->timestamps($t);
            $this->index($t, ['product_id', 'is_active', 'sort_order']);
            $this->index($t, ['license_type_id']);
            $this->index($t, ['price']);
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'license_type_id', 'license_types');
        });
        $this->check('product_license_tiers', 'price_non_negative', '`price` >= 0 AND (`sale_price` IS NULL OR `sale_price` >= 0)');

        $this->create('product_categories', function (Blueprint $t): void {
            $this->ref($t, 'product_id');
            $this->ref($t, 'category_id');
            $this->primary($t, ['product_id', 'category_id']);
            $this->index($t, ['category_id']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
            $this->foreign($t, 'category_id', 'categories', 'cascade');
        });

        $this->create('product_tags', function (Blueprint $t): void {
            $this->ref($t, 'product_id');
            $this->ref($t, 'tag_id');
            $this->primary($t, ['product_id', 'tag_id']);
            $this->index($t, ['tag_id']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
            $this->foreign($t, 'tag_id', 'tags', 'cascade');
        });

        $this->create('product_versions', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'product_id');
            $t->string('version', 40)->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('v_major');
            $t->unsignedInteger('v_minor');
            $t->unsignedInteger('v_patch');
            $t->string('v_pre', 20)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->mediumText('changelog_html')->nullable();
            $this->json($t, 'compatibility');
            $t->boolean('is_mandatory')->default(false);
            $t->boolean('is_security')->default(false);
            $this->status($t, 'status', 'pending_review');
            $this->whmcsId($t, 'reviewed_by_admin_id', true);
            $this->timestamp($t, 'reviewed_at');
            $this->timestamp($t, 'released_at');
            $this->whmcsId($t, 'created_by_user_id', true);
            $this->timestamps($t);
            $this->unique($t, ['product_id', 'version']);
            $this->index($t, ['product_id', 'status', 'v_major', 'v_minor', 'v_patch']);
            $this->foreign($t, 'product_id', 'products');
        });

        // Circular link: product -> its current version.
        $this->addForeign('products', 'current_version_id', 'product_versions', 'set null');

        $this->create('product_files', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'product_id');
            $this->ref($t, 'version_id');
            $this->code($t, 'disk', 32);
            $t->string('storage_key', 255)->charset('ascii')->collation('ascii_bin');
            $t->string('original_name', 255);
            $t->string('mime', 127)->charset('ascii');
            $t->string('extension', 16)->charset('ascii');
            $t->unsignedBigInteger('size_bytes');
            $this->hash($t, 'sha256');
            $this->status($t, 'scan_status', 'pending');
            $this->code($t, 'scan_engine', 64, true);
            $this->timestamp($t, 'scanned_at');
            $this->json($t, 'scan_report');
            $t->boolean('is_primary')->default(true);
            $this->whmcsId($t, 'uploaded_by_user_id', true);
            $this->createdAt($t);
            $this->index($t, ['version_id']);
            $this->index($t, ['product_id']);
            $this->index($t, ['scan_status']);
            $this->unique($t, ['disk', 'storage_key']);
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'version_id', 'product_versions');
        });

        $this->create('product_stats', function (Blueprint $t): void {
            $this->ref($t, 'product_id');
            $t->unsignedBigInteger('views')->default(0);
            $t->unsignedBigInteger('downloads')->default(0);
            $t->unsignedBigInteger('sales')->default(0);
            $t->unsignedInteger('favorites')->default(0);
            $t->unsignedInteger('reviews')->default(0);
            $t->decimal('rating_avg', 3, 2)->default('0.00');
            $t->unsignedInteger('rating_count')->default(0);
            $this->money($t, 'revenue_default_ccy');
            $t->unsignedInteger('refund_count')->default(0);
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['product_id']);
            $this->index($t, ['sales']);
            $this->index($t, ['rating_avg']);
            $this->index($t, ['views']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
        });

        $this->create('product_reports', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'product_id');
            $this->whmcsId($t, 'reporter_client_id', true);
            $this->code($t, 'reason', 32);
            $t->text('details')->nullable();
            $this->status($t, 'status', 'open');
            $this->whmcsId($t, 'handled_by_admin_id', true);
            $this->timestamps($t);
            $this->index($t, ['status', 'created_at']);
            $this->index($t, ['product_id']);
            $this->foreign($t, 'product_id', 'products');
        });

        $this->create('moderation_log', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'subject_type', 32);
            $t->unsignedBigInteger('subject_id');
            $this->code($t, 'action', 64);
            $this->whmcsId($t, 'admin_id', true);
            $t->text('notes')->nullable();
            $this->json($t, 'checklist');
            $this->createdAt($t);
            $this->index($t, ['subject_type', 'subject_id', 'created_at']);
        });

        // Category image lives in media (created here).
        $this->addForeign('categories', 'image_media_id', 'media', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('categories', 'image_media_id');
        $this->dropAll($this->tables());
    }
};
