<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Denormalised search index (default DatabaseSearchProvider, InnoDB FULLTEXT)
 * and curated collections.
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['search_index', 'collections', 'collection_products'];
    }

    public function up(): void
    {
        $this->create('search_index', function (Blueprint $t): void {
            $this->ref($t, 'product_id');
            $t->string('title', 191);
            $t->text('keywords')->nullable();
            $t->mediumText('body')->nullable();
            $this->status($t, 'status', 'draft');
            $t->string('category_path', 255)->charset('ascii')->collation('ascii_bin');
            $this->ref($t, 'product_type_id');
            $this->ref($t, 'vendor_id');
            $this->money($t, 'min_price');
            $this->currency($t);
            $t->boolean('is_free')->default(false);
            $t->boolean('is_featured')->default(false);
            $t->decimal('rating_avg', 3, 2)->default('0.00');
            $t->unsignedBigInteger('sales')->default(0);
            $this->timestamp($t, 'published_at');
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['product_id']);
            $this->index($t, ['status', 'category_path']);
            $this->index($t, ['status', 'min_price']);
            $this->index($t, ['status', 'rating_avg']);
            $this->index($t, ['status', 'sales']);
            $this->index($t, ['status', 'published_at']);
            $this->index($t, ['status', 'product_type_id']);
            $this->index($t, ['status', 'vendor_id']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
        });
        $this->fullText('search_index', ['title', 'keywords', 'body']);

        $this->create('collections', function (Blueprint $t): void {
            $this->id($t);
            $this->slug($t);
            $t->string('title', 191);
            $t->text('description')->nullable();
            $this->code($t, 'type', 16);
            $this->json($t, 'rules');
            $t->boolean('is_featured')->default(false);
            $t->boolean('is_active')->default(true);
            $this->timestamp($t, 'starts_at');
            $this->timestamp($t, 'ends_at');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $this->timestamps($t);
            $this->unique($t, ['slug']);
            $this->index($t, ['is_active', 'sort_order']);
        });

        $this->create('collection_products', function (Blueprint $t): void {
            $this->ref($t, 'collection_id');
            $this->ref($t, 'product_id');
            $t->unsignedInteger('position')->default(0);
            $this->primary($t, ['collection_id', 'product_id']);
            $this->index($t, ['collection_id', 'position']);
            $this->index($t, ['product_id']);
            $this->foreign($t, 'collection_id', 'collections', 'cascade');
            $this->foreign($t, 'product_id', 'products', 'cascade');
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
