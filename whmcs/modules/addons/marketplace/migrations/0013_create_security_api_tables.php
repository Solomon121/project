<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Audit log (append-only, hash-chained), API keys, nonces (replay protection),
 * user tokens and link codes for the WordPress account bridge, webhooks,
 * security events and fraud rules/flags.
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'audit_logs', 'api_keys', 'api_nonces', 'user_tokens', 'link_codes',
            'webhook_endpoints', 'webhook_deliveries', 'security_events', 'fraud_rules', 'fraud_flags',
        ];
    }

    public function up(): void
    {
        $this->create('audit_logs', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->actor($t, 'actor', false);
            $this->whmcsId($t, 'on_behalf_client_id', true);
            $this->code($t, 'action', 96);
            $this->code($t, 'subject_type', 32, true);
            $t->string('subject_id', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $this->ip($t);
            $t->string('user_agent', 255)->nullable();
            $this->code($t, 'request_id', 32, true);
            $this->code($t, 'batch_id', 32, true);
            $this->json($t, 'before');
            $this->json($t, 'after');
            $this->hash($t, 'prev_hash', true);
            $this->hash($t, 'entry_hash');
            $this->createdAt($t);
            $this->index($t, ['subject_type', 'subject_id', 'created_at']);
            $this->index($t, ['actor_type', 'actor_id', 'created_at']);
            $this->index($t, ['action', 'created_at']);
            $this->index($t, ['created_at']);
        });

        $this->create('api_keys', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $t->string('name', 128);
            $this->code($t, 'owner_type', 16);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('key_id', 32)->charset('ascii')->collation('ascii_bin');
            $this->encrypted($t, 'secret_enc');
            $this->encrypted($t, 'previous_secret_enc');
            $this->timestamp($t, 'previous_secret_expires_at');
            $this->json($t, 'scopes', false);
            $this->json($t, 'allowed_ips');
            $this->json($t, 'redirect_uris');
            $t->unsignedInteger('rate_limit_per_min')->default(600);
            $this->timestamp($t, 'last_used_at');
            $this->ip($t, 'last_used_ip');
            $this->timestamp($t, 'expires_at');
            $this->timestamp($t, 'revoked_at');
            $this->whmcsId($t, 'created_by_admin_id', true);
            $this->timestamps($t);
            $this->unique($t, ['key_id']);
            $this->index($t, ['owner_type', 'owner_id']);
        });

        $this->create('api_nonces', function (Blueprint $t): void {
            $t->string('key_id', 32)->charset('ascii')->collation('ascii_bin');
            $this->hash($t, 'nonce_hash');
            $this->timestamp($t, 'expires_at', false);
            $this->primary($t, ['key_id', 'nonce_hash']);
            $this->index($t, ['expires_at']);
        });

        $this->create('user_tokens', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'api_key_id');
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'user_id');
            $this->hash($t, 'family_id');
            $this->hash($t, 'access_hash');
            $this->hash($t, 'refresh_hash');
            $this->json($t, 'scopes', false);
            $this->timestamp($t, 'access_expires_at', false);
            $this->timestamp($t, 'refresh_expires_at', false);
            $this->timestamp($t, 'rotated_at');
            $this->timestamp($t, 'revoked_at');
            $this->timestamp($t, 'last_used_at');
            $this->createdAt($t);
            $this->unique($t, ['access_hash']);
            $this->unique($t, ['refresh_hash']);
            $this->index($t, ['user_id']);
            $this->index($t, ['family_id']);
            $this->index($t, ['refresh_expires_at']);
            $this->foreign($t, 'api_key_id', 'api_keys', 'cascade');
        });

        $this->create('link_codes', function (Blueprint $t): void {
            $this->hash($t, 'code_hash');
            $this->ref($t, 'api_key_id');
            $this->whmcsId($t, 'user_id');
            $this->whmcsId($t, 'client_id');
            $t->string('redirect_uri', 2048);
            $t->string('pkce_challenge', 128)->charset('ascii')->collation('ascii_bin');
            $this->json($t, 'scopes', false);
            $this->timestamp($t, 'expires_at', false);
            $this->timestamp($t, 'used_at');
            $this->primary($t, ['code_hash']);
            $this->index($t, ['expires_at']);
            $this->foreign($t, 'api_key_id', 'api_keys', 'cascade');
        });

        $this->create('webhook_endpoints', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'api_key_id', true);
            $t->string('url', 2048);
            $this->encrypted($t, 'secret_enc');
            $this->json($t, 'events', false);
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('consecutive_failures')->default(0);
            $this->timestamp($t, 'disabled_at');
            $this->timestamps($t);
            $this->index($t, ['is_active']);
            $this->foreign($t, 'api_key_id', 'api_keys', 'set null');
        });

        $this->create('webhook_deliveries', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'endpoint_id');
            $this->code($t, 'event_uuid', 26);
            $this->code($t, 'event', 96);
            $this->json($t, 'payload', false);
            $t->unsignedSmallInteger('attempt')->default(0);
            $this->status($t, 'status', 'pending');
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->string('response_excerpt', 1024)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $this->timestamp($t, 'next_attempt_at');
            $this->timestamp($t, 'delivered_at');
            $this->timestamps($t);
            $this->unique($t, ['endpoint_id', 'event_uuid']);
            $this->index($t, ['status', 'next_attempt_at']);
            $this->index($t, ['created_at']);
            $this->foreign($t, 'endpoint_id', 'webhook_endpoints', 'cascade');
        });

        $this->create('security_events', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'type', 32);
            $this->actor($t);
            $this->ip($t);
            $this->json($t, 'detail');
            $this->createdAt($t);
            $this->index($t, ['type', 'created_at']);
            $this->index($t, ['ip', 'created_at']);
        });

        $this->create('fraud_rules', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'code');
            $this->json($t, 'config');
            $this->code($t, 'action', 16);
            $t->boolean('is_active')->default(true);
            $this->timestamps($t);
            $this->unique($t, ['code']);
        });

        $this->create('fraud_flags', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'subject_type', 32);
            $t->unsignedBigInteger('subject_id');
            $this->code($t, 'rule_code', 64);
            $t->decimal('score', 6, 3)->default('0.000');
            $this->status($t, 'status', 'open');
            $this->json($t, 'detail');
            $this->whmcsId($t, 'handled_by_admin_id', true);
            $this->timestamps($t);
            $this->index($t, ['subject_type', 'subject_id']);
            $this->index($t, ['status', 'created_at']);
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
