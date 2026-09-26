<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Reviews, votes, reports, wishlists, disputes (+ append-only messages) and
 * intellectual-property complaints.
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['reviews', 'review_votes', 'review_reports', 'wishlists', 'disputes', 'dispute_messages', 'ip_complaints'];
    }

    public function up(): void
    {
        $this->create('reviews', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->code($t, 'target_type', 16);
            $this->ref($t, 'product_id', true);
            $this->ref($t, 'vendor_id');
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'user_id', true);
            $this->ref($t, 'order_item_id', true);
            $t->unsignedTinyInteger('rating');
            $t->string('title', 191)->nullable();
            $t->text('body')->nullable();
            $this->status($t, 'status', 'pending');
            $t->boolean('is_verified_purchase')->default(false);
            $t->unsignedInteger('helpful_count')->default(0);
            $t->unsignedInteger('unhelpful_count')->default(0);
            $t->unsignedInteger('report_count')->default(0);
            $t->text('vendor_response')->nullable();
            $this->timestamp($t, 'vendor_responded_at');
            $this->whmcsId($t, 'moderated_by_admin_id', true);
            $this->timestamp($t, 'moderated_at');
            $t->decimal('abuse_score', 4, 3)->default('0.000');
            // Generated key so a client can review a product once, and a vendor once.
            $t->unsignedBigInteger('target_key')->storedAs('IF(`target_type` = \'product\', `product_id`, `vendor_id`)');
            $this->timestamps($t);
            $this->softDeletes($t);
            $this->unique($t, ['target_type', 'target_key', 'client_id']);
            $this->index($t, ['product_id', 'status', 'created_at']);
            $this->index($t, ['vendor_id', 'status', 'created_at']);
            $this->index($t, ['status', 'created_at']);
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'order_item_id', 'order_items', 'set null');
        });
        $this->check('reviews', 'rating_range', '`rating` BETWEEN 1 AND 5');

        $this->create('review_votes', function (Blueprint $t): void {
            $this->ref($t, 'review_id');
            $this->whmcsId($t, 'client_id');
            $t->tinyInteger('vote');
            $this->createdAt($t);
            $this->primary($t, ['review_id', 'client_id']);
            $this->foreign($t, 'review_id', 'reviews', 'cascade');
        });
        $this->check('review_votes', 'vote_value', '`vote` IN (-1, 1)');

        $this->create('review_reports', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'review_id');
            $this->whmcsId($t, 'client_id');
            $this->code($t, 'reason', 32);
            $t->text('details')->nullable();
            $this->status($t, 'status', 'open');
            $this->whmcsId($t, 'handled_by_admin_id', true);
            $this->timestamps($t);
            $this->unique($t, ['review_id', 'client_id']);
            $this->index($t, ['status', 'created_at']);
            $this->foreign($t, 'review_id', 'reviews', 'cascade');
        });

        $this->create('wishlists', function (Blueprint $t): void {
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'product_id');
            $t->boolean('notify_price_drop')->default(true);
            $t->boolean('notify_updates')->default(true);
            $this->money($t, 'price_at_add', true);
            $this->currency($t, 'currency_id', true);
            $this->createdAt($t);
            $this->primary($t, ['client_id', 'product_id']);
            $this->index($t, ['product_id']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
        });

        $this->create('disputes', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $t->string('dispute_number', 32)->charset('ascii')->collation('ascii_bin');
            $this->ref($t, 'order_id');
            $this->ref($t, 'order_item_id');
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'vendor_id');
            $this->code($t, 'reason_code', 32);
            $this->status($t, 'status', 'open');
            $this->code($t, 'resolution', 32, true);
            $this->money($t, 'resolution_amount', true);
            $this->ref($t, 'refund_id', true);
            $this->whmcsId($t, 'assigned_admin_id', true);
            $this->whmcsId($t, 'whmcs_ticket_id', true);
            $this->timestamp($t, 'vendor_deadline_at');
            $this->timestamp($t, 'opened_at', false);
            $this->timestamp($t, 'resolved_at');
            $this->timestamp($t, 'closed_at');
            $this->timestamp($t, 'updated_at');
            $this->unique($t, ['dispute_number']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['status', 'vendor_deadline_at']);
            $this->index($t, ['client_id']);
            $this->index($t, ['order_item_id']);
            $this->foreign($t, 'order_id', 'orders');
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'refund_id', 'refunds');
        });

        $this->create('dispute_messages', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'dispute_id');
            $this->code($t, 'author_type', 16);
            $t->unsignedBigInteger('author_id')->nullable();
            $t->text('body');
            $t->boolean('is_internal')->default(false);
            $this->createdAt($t);
            $this->index($t, ['dispute_id', 'created_at']);
            $this->foreign($t, 'dispute_id', 'disputes');
        });

        $this->create('ip_complaints', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'product_id');
            $t->string('complainant_name', 191);
            $t->string('complainant_email', 191);
            $t->string('rights_owner', 191);
            $t->text('work_description');
            $this->json($t, 'infringing_urls');
            $t->boolean('statement_good_faith')->default(false);
            $t->boolean('statement_accuracy')->default(false);
            $t->string('signature', 191);
            $this->status($t, 'status', 'submitted');
            $t->text('vendor_response')->nullable();
            $this->json($t, 'counter_notice');
            $this->whmcsId($t, 'handled_by_admin_id', true);
            $this->timestamps($t);
            $this->timestamp($t, 'resolved_at');
            $this->index($t, ['product_id']);
            $this->index($t, ['status', 'created_at']);
            $this->foreign($t, 'product_id', 'products');
        });

        $this->addForeign('wallet_transactions', 'dispute_id', 'disputes');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('wallet_transactions', 'dispute_id');
        $this->dropAll($this->tables());
    }
};
