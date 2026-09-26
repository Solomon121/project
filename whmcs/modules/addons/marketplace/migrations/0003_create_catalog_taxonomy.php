<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Product types, licence types, hierarchical categories and tags.
 * Media, legal pages and commission rules referenced from here are created in
 * later migrations; those links are plain columns resolved by the application.
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['product_types', 'license_types', 'categories', 'tags'];
    }

    public function up(): void
    {
        $this->create('product_types', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'code');
            $this->code($t, 'family', 32);
            $t->string('handler', 191)->charset('ascii')->collation('ascii_bin');
            $t->string('name', 128);
            $t->boolean('is_enabled')->default(true);
            $t->boolean('is_system')->default(false);
            $this->json($t, 'allowed_extensions');
            $t->unsignedInteger('max_file_mb')->nullable();
            $this->json($t, 'attributes_schema');
            $t->unsignedSmallInteger('refund_period_days')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['code']);
            $this->index($t, ['family', 'is_enabled']);
        });

        $this->create('license_types', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'code');
            $t->string('name', 128);
            $t->string('description', 1000)->nullable();
            $this->code($t, 'provider_code', 64);
            $t->unsignedInteger('default_max_activations')->nullable();
            $t->unsignedInteger('default_max_domains')->nullable();
            $t->boolean('is_perpetual')->default(true);
            $t->boolean('allows_commercial_use')->default(false);
            $this->ref($t, 'legal_page_id', true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['code']);
        });

        $this->create('categories', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'parent_id', true);
            $t->string('path', 255)->charset('ascii')->collation('ascii_bin');
            $t->unsignedTinyInteger('depth')->default(0);
            $this->slug($t);
            $t->string('name', 191);
            $t->text('description')->nullable();
            $t->string('icon', 64)->nullable();
            $this->ref($t, 'image_media_id', true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->string('seo_title', 191)->nullable();
            $t->string('seo_description', 320)->nullable();
            $t->unsignedInteger('product_count')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['slug']);
            $this->index($t, ['path']);
            $this->index($t, ['parent_id', 'sort_order']);
            $this->foreign($t, 'parent_id', 'categories');
        });

        $this->create('tags', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'type', 32);
            $this->slug($t);
            $t->string('name', 191);
            $t->unsignedInteger('usage_count')->default(0);
            $this->createdAt($t);
            $this->unique($t, ['type', 'slug']);
            $this->index($t, ['type', 'usage_count']);
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
