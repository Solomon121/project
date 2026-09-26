<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Domain aftermarket listings and bids (escrowed sale flow, 01 §5).
 * Registrations/transfers/renewals are native WHMCS domain orders and need no tables.
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['domain_listings', 'domain_bids'];
    }

    public function up(): void
    {
        $this->create('domain_listings', function (Blueprint $t): void {
            $this->id($t);
            $this->uuid($t);
            $this->ref($t, 'vendor_id');
            $t->string('domain', 253)->charset('ascii')->collation('ascii_general_ci');
            $t->string('tld', 63)->charset('ascii')->collation('ascii_general_ci');
            $this->code($t, 'listing_type', 16);
            $this->money($t, 'price', true);
            $this->money($t, 'min_offer', true);
            $this->money($t, 'reserve_price', true);
            $this->currency($t);
            $this->timestamp($t, 'auction_ends_at');
            $this->status($t, 'status', 'draft');
            $this->hash($t, 'ownership_token_hash', true);
            $this->timestamp($t, 'ownership_verified_at');
            $this->code($t, 'transfer_method', 32, true);
            $this->whmcsId($t, 'whmcs_domain_id', true);
            $this->whmcsId($t, 'buyer_client_id', true);
            $this->ref($t, 'winning_bid_id', true);
            $this->ref($t, 'order_item_id', true);
            $this->timestamps($t);
            $this->index($t, ['domain', 'status']);
            $this->index($t, ['status', 'auction_ends_at']);
            $this->index($t, ['vendor_id', 'status']);
            $this->index($t, ['tld', 'status']);
            $this->foreign($t, 'vendor_id', 'vendors');
            $this->foreign($t, 'order_item_id', 'order_items');
        });

        $this->create('domain_bids', function (Blueprint $t): void {
            $this->id($t);
            $this->ref($t, 'listing_id');
            $this->whmcsId($t, 'client_id');
            $this->money($t, 'amount');
            $this->ip($t);
            $this->createdAt($t);
            $this->index($t, ['listing_id', 'amount']);
            $this->index($t, ['client_id']);
            $this->foreign($t, 'listing_id', 'domain_listings');
        });

        $this->addForeign('domain_listings', 'winning_bid_id', 'domain_bids');
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
