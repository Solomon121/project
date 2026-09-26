<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Licences, activations, licence history, blacklist, transfers, download tokens
 * and the download log. See docs/architecture/05-licensing-and-downloads.md.
 *
 * Licence keys are never stored in plaintext: key_hash (HMAC with a KeyRing
 * pepper) for lookup, key_enc for owner display. Download tokens are stored as
 * SHA-256 hashes only.
 */
return new class extends Migration {
    public function tables(): array
    {
        return [
            'licenses', 'license_activations', 'license_events', 'license_blacklist',
            'license_transfers', 'download_tokens', 'downloads',
        ];
    }

    public function up(): void
    {
        $this->create('licenses', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->hash($t, 'key_hash');
            $this->encrypted($t, 'key_enc');
            $t->string('key_prefix', 12)->charset('ascii')->collation('ascii_bin');
            $this->ref($t, 'product_id');
            $this->ref($t, 'order_item_id', true);
            $this->whmcsId($t, 'client_id');
            $this->ref($t, 'vendor_id');
            $this->ref($t, 'license_type_id');
            $this->code($t, 'provider_code', 64);
            $this->status($t, 'status', 'pending');
            $t->unsignedInteger('max_activations')->nullable();
            $t->unsignedInteger('max_domains')->nullable();
            $t->unsignedInteger('activation_count')->default(0);
            $this->json($t, 'allowed_ips');
            $this->timestamp($t, 'starts_at');
            $this->timestamp($t, 'expires_at');
            $this->ref($t, 'parent_license_id', true);
            $t->string('status_reason', 500)->nullable();
            $this->timestamps($t);
            $this->unique($t, ['key_hash']);
            $this->index($t, ['client_id', 'status']);
            $this->index($t, ['product_id', 'status']);
            $this->index($t, ['status', 'expires_at']);
            $this->index($t, ['order_item_id']);
            $this->index($t, ['vendor_id', 'status']);
            $this->foreign($t, 'product_id', 'products');
            $this->foreign($t, 'order_item_id', 'order_items');
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'license_type_id', 'license_types');
            $this->foreign($t, 'parent_license_id', 'licenses');
        });

        $this->create('license_activations', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'license_id');
            $this->hash($t, 'instance_hash');
            $t->string('domain', 253)->charset('ascii')->collation('ascii_general_ci')->nullable();
            $this->ip($t);
            $this->hash($t, 'fingerprint_hash', true);
            $t->string('software_version', 40)->nullable();
            $this->code($t, 'environment', 16);
            $this->status($t, 'status', 'active');
            $this->timestamp($t, 'activated_at', false);
            $this->timestamp($t, 'last_seen_at');
            $this->timestamp($t, 'deactivated_at');
            $this->code($t, 'deactivated_by', 32, true);
            $this->unique($t, ['license_id', 'instance_hash']);
            $this->index($t, ['license_id', 'status']);
            $this->index($t, ['domain']);
            $this->foreign($t, 'license_id', 'licenses');
        });

        $this->create('license_events', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'license_id');
            $this->code($t, 'event', 32);
            $this->actor($t);
            $this->ip($t);
            $this->json($t, 'data');
            $this->createdAt($t);
            $this->index($t, ['license_id', 'created_at']);
            $this->index($t, ['event', 'created_at']);
            $this->foreign($t, 'license_id', 'licenses');
        });

        $this->create('license_blacklist', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'type', 16);
            $this->hash($t, 'value_hash');
            $this->ref($t, 'product_id', true);
            $t->string('reason', 500);
            $this->whmcsId($t, 'created_by_admin_id', true);
            $this->createdAt($t);
            $this->timestamp($t, 'expires_at');
            $this->unique($t, ['type', 'value_hash', 'product_id']);
            $this->foreign($t, 'product_id', 'products', 'cascade');
        });

        $this->create('license_transfers', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'license_id');
            $this->ref($t, 'new_license_id', true);
            $this->whmcsId($t, 'from_client_id');
            $this->whmcsId($t, 'to_client_id');
            $this->status($t, 'status', 'requested');
            $this->ref($t, 'fee_order_id', true);
            $this->whmcsId($t, 'decided_by_admin_id', true);
            $this->timestamps($t);
            $this->timestamp($t, 'completed_at');
            $this->index($t, ['license_id']);
            $this->index($t, ['status', 'created_at']);
            $this->foreign($t, 'license_id', 'licenses');
            $this->foreign($t, 'new_license_id', 'licenses');
            $this->foreign($t, 'fee_order_id', 'orders');
        });

        $this->create('download_tokens', function (Blueprint $t): void {
            $this->id($t);
            $this->hash($t, 'token_hash');
            $this->whmcsId($t, 'client_id');
            $this->whmcsId($t, 'user_id', true);
            $this->ref($t, 'entitlement_id');
            $this->ref($t, 'product_file_id');
            $this->ip($t, 'ip_bind');
            $t->unsignedSmallInteger('max_uses')->default(3);
            $t->unsignedSmallInteger('use_count')->default(0);
            $this->timestamp($t, 'expires_at', false);
            $this->createdAt($t);
            $this->unique($t, ['token_hash']);
            $this->index($t, ['expires_at']);
            $this->index($t, ['client_id', 'created_at']);
            $this->foreign($t, 'entitlement_id', 'entitlements', 'cascade');
            $this->foreign($t, 'product_file_id', 'product_files', 'cascade');
        });

        // Download log. Append-only for updates; old rows may be purged by retention.
        $this->create('downloads', function (Blueprint $t): void {
            $this->id($t);
            $this->whmcsId($t, 'client_id', true);
            $this->whmcsId($t, 'user_id', true);
            $this->ref($t, 'product_id', true);
            $this->ref($t, 'product_file_id', true);
            $this->ref($t, 'version_id', true);
            $this->ref($t, 'entitlement_id', true);
            $this->ref($t, 'token_id', true);
            $this->ip($t);
            $t->string('user_agent', 255)->nullable();
            $this->code($t, 'result', 16);
            $this->code($t, 'reason', 64, true);
            $t->unsignedBigInteger('bytes_sent')->default(0);
            $this->createdAt($t);
            $this->index($t, ['client_id', 'created_at']);
            $this->index($t, ['product_id', 'created_at']);
            $this->index($t, ['ip', 'created_at']);
            $this->index($t, ['entitlement_id', 'created_at']);
        });

        $this->addForeign('subscriptions', 'license_id', 'licenses', 'set null');
        $this->addForeign('entitlements', 'license_id', 'licenses', 'set null');
    }

    public function down(): void
    {
        $this->dropForeignIfExists('subscriptions', 'license_id');
        $this->dropForeignIfExists('entitlements', 'license_id');
        $this->dropAll($this->tables());
    }
};
