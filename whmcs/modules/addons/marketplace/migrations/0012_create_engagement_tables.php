<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Notifications and preferences, the event → WHMCS email template map,
 * aggregated statistics, privacy requests and versioned legal pages.
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'notifications', 'notification_preferences', 'email_template_map', 'stats_daily',
            'view_buffer', 'privacy_requests', 'legal_pages', 'legal_acceptances',
        ];
    }

    public function up(): void
    {
        $this->create('notifications', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'recipient_type', 16);
            $t->unsignedBigInteger('recipient_id');
            $this->code($t, 'category', 16);
            $this->code($t, 'event', 96);
            $t->string('title', 191);
            $t->text('body')->nullable();
            $t->string('url', 2048)->nullable();
            $this->json($t, 'data');
            $this->timestamp($t, 'read_at');
            $this->createdAt($t);
            $this->index($t, ['recipient_type', 'recipient_id', 'read_at', 'id']);
            $this->index($t, ['created_at']);
        });

        $this->create('notification_preferences', function (Blueprint $t): void {
            $this->code($t, 'recipient_type', 16);
            $t->unsignedBigInteger('recipient_id');
            $this->code($t, 'category', 16);
            $this->code($t, 'channel', 16);
            $t->boolean('enabled')->default(true);
            $this->primary($t, ['recipient_type', 'recipient_id', 'category', 'channel']);
        });

        $this->create('email_template_map', function (Blueprint $t): void {
            $this->code($t, 'event', 96);
            $t->string('whmcs_template_name', 191);
            $this->code($t, 'recipient', 16);
            $this->code($t, 'category', 16);
            $t->boolean('is_enabled')->default(true);
            $this->json($t, 'variables');
            $this->primary($t, ['event']);
        });

        $this->create('stats_daily', function (Blueprint $t): void {
            $t->date('day');
            $this->code($t, 'dimension', 16);
            $t->unsignedBigInteger('dimension_id')->default(0);
            $this->currency($t, 'currency_id');
            $t->unsignedBigInteger('views')->default(0);
            $t->unsignedBigInteger('unique_views')->default(0);
            $t->unsignedBigInteger('orders')->default(0);
            $t->unsignedBigInteger('units')->default(0);
            $this->money($t, 'gross');
            $this->money($t, 'discounts');
            $this->money($t, 'commission');
            $this->money($t, 'fees');
            $this->money($t, 'vendor_net');
            $this->money($t, 'refunds');
            $t->unsignedBigInteger('downloads')->default(0);
            $this->primary($t, ['day', 'dimension', 'dimension_id', 'currency_id']);
            $this->index($t, ['dimension', 'dimension_id', 'day']);
        });

        $this->create('view_buffer', function (Blueprint $t): void {
            $this->ref($t, 'product_id');
            $t->date('day');
            $t->unsignedInteger('views')->default(0);
            $this->primary($t, ['product_id', 'day']);
        });

        $this->create('privacy_requests', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'requested_by_user_id', true);
            $this->code($t, 'type', 16);
            $this->status($t, 'status', 'pending');
            $t->string('file_key', 255)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->text('notes')->nullable();
            $this->timestamp($t, 'requested_at', false);
            $this->timestamp($t, 'processed_at');
            $this->whmcsId($t, 'processed_by_admin_id', true);
            $this->index($t, ['client_id']);
            $this->index($t, ['status', 'requested_at']);
        });

        $this->create('legal_pages', function (Blueprint $t): void {
            $this->id($t);
            $this->slug($t, 'slug', 64);
            $t->unsignedInteger('version');
            $t->string('title', 191);
            $t->mediumText('body_html');
            $t->boolean('requires_acceptance')->default(false);
            $t->boolean('is_template')->default(true);
            $this->timestamp($t, 'published_at');
            $this->whmcsId($t, 'created_by_admin_id', true);
            $this->createdAt($t);
            $this->unique($t, ['slug', 'version']);
        });

        $this->create('legal_acceptances', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'legal_page_id');
            $t->unsignedInteger('version');
            $this->code($t, 'actor_type', 16);
            $t->unsignedBigInteger('actor_id');
            $this->ip($t);
            $this->timestamp($t, 'accepted_at', false);
            $this->index($t, ['actor_type', 'actor_id']);
            $this->index($t, ['legal_page_id', 'version']);
            $this->foreign($t, 'legal_page_id', 'legal_pages');
        });

        $this->addForeign('license_types', 'legal_page_id', 'legal_pages', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('license_types', 'legal_page_id');
        $this->dropAll($this->tables());
    }
};
