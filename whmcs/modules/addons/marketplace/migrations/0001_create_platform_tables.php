<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Platform infrastructure: settings, idempotency, outbox, job queue, cron runs,
 * DB cache and rate-limit fallbacks, health events.
 * (The migrations table itself is owned by the Migrator.)
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['settings', 'processed_events', 'outbox', 'jobs', 'cron_runs', 'cache', 'rate_limits', 'health_events'];
    }

    public function up(): void
    {
        $this->create('settings', function (Blueprint $t): void {
            $t->string('name', 191)->charset('ascii')->collation('ascii_bin');
            $t->longText('value')->nullable();
            $t->boolean('is_encrypted')->default(false);
            $this->whmcsId($t, 'updated_by_admin_id', true);
            $this->timestamp($t, 'updated_at');
            $this->primary($t, ['name']);
        });

        $this->create('processed_events', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'source', 32);
            $this->code($t, 'event', 64);
            $t->string('reference', 191)->charset('ascii')->collation('ascii_bin');
            $this->timestamp($t, 'processed_at', false);
            $this->unique($t, ['source', 'event', 'reference']);
        });

        $this->create('outbox', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->code($t, 'event', 96);
            $this->code($t, 'aggregate_type', 64);
            $t->string('aggregate_id', 64)->charset('ascii')->collation('ascii_bin');
            $this->json($t, 'payload', false);
            $this->timestamp($t, 'occurred_at', false);
            $this->timestamp($t, 'dispatched_at');
            $t->unsignedSmallInteger('attempts')->default(0);
            $this->code($t, 'claim_token', 32, true);
            $this->timestamp($t, 'claimed_at');
            $this->index($t, ['dispatched_at', 'id']);
        });

        $this->create('jobs', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'queue', 64);
            $t->string('handler', 191)->charset('ascii')->collation('ascii_bin');
            $this->json($t, 'payload', false);
            $t->string('unique_key', 191)->charset('ascii')->collation('ascii_bin')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('max_attempts')->default(5);
            $this->timestamp($t, 'available_at', false);
            $this->timestamp($t, 'reserved_at');
            $this->code($t, 'reserved_by', 64, true);
            $t->text('last_error')->nullable();
            $this->timestamp($t, 'failed_at');
            $this->createdAt($t);
            $this->index($t, ['queue', 'reserved_at', 'available_at']);
            $this->index($t, ['failed_at']);
            $this->unique($t, ['unique_key']);
        });

        $this->create('cron_runs', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'task', 96);
            $this->timestamp($t, 'started_at', false);
            $this->timestamp($t, 'finished_at');
            $this->status($t, 'status', 'running');
            $t->unsignedInteger('items_processed')->default(0);
            $t->text('error')->nullable();
            $this->index($t, ['task', 'started_at']);
        });

        $this->create('cache', function (Blueprint $t): void {
            $this->hash($t, 'cache_key');
            $t->longText('value');
            $t->string('tags', 255)->nullable();
            $this->timestamp($t, 'expires_at');
            $this->primary($t, ['cache_key']);
            $this->index($t, ['expires_at']);
        });

        $this->create('rate_limits', function (Blueprint $t): void {
            $this->hash($t, 'bucket');
            $t->decimal('tokens', 10, 3);
            $this->timestamp($t, 'updated_at', false);
            $this->primary($t, ['bucket']);
            $this->index($t, ['updated_at']);
        });

        $this->create('health_events', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'channel', 32);
            $this->code($t, 'severity', 16);
            $this->code($t, 'code', 96);
            $t->string('message', 1000);
            $this->json($t, 'context');
            $this->createdAt($t);
            $this->index($t, ['channel', 'created_at']);
            $this->index($t, ['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
