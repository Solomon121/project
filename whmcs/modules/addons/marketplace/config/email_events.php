<?php

declare(strict_types=1);

/**
 * Marketplace notification events mapped to WHMCS email templates (ADR-011).
 *
 * The WHMCS templates themselves are created in tblemailtemplates by the WHMCS
 * integration installer (Phase 3); this map only tells the marketplace which
 * template to send for which event, and to whom.
 */
return [
    'vendor.application_received' => ['template' => 'Marketplace: Vendor Application Received', 'recipient' => 'vendor', 'category' => 'system'],
    'vendor.approved' => ['template' => 'Marketplace: Vendor Approved', 'recipient' => 'vendor', 'category' => 'system'],
    'vendor.rejected' => ['template' => 'Marketplace: Vendor Rejected', 'recipient' => 'vendor', 'category' => 'system'],
    'admin.vendor_application' => ['template' => 'Marketplace: New Vendor Application', 'recipient' => 'admin', 'category' => 'system'],
    'product.submitted' => ['template' => 'Marketplace: Product Submitted', 'recipient' => 'admin', 'category' => 'product'],
    'product.approved' => ['template' => 'Marketplace: Product Approved', 'recipient' => 'vendor', 'category' => 'product'],
    'product.rejected' => ['template' => 'Marketplace: Product Rejected', 'recipient' => 'vendor', 'category' => 'product'],
    'product.update_available' => ['template' => 'Marketplace: Product Update Available', 'recipient' => 'client', 'category' => 'product'],
    'order.confirmation' => ['template' => 'Marketplace: Order Confirmation', 'recipient' => 'client', 'category' => 'order'],
    'order.paid' => ['template' => 'Marketplace: Payment Confirmation', 'recipient' => 'client', 'category' => 'order'],
    'order.vendor_sale' => ['template' => 'Marketplace: New Sale', 'recipient' => 'vendor', 'category' => 'order'],
    'download.available' => ['template' => 'Marketplace: Download Available', 'recipient' => 'client', 'category' => 'order'],
    'license.issued' => ['template' => 'Marketplace: License Issued', 'recipient' => 'client', 'category' => 'license'],
    'license.expiring' => ['template' => 'Marketplace: License Expiring', 'recipient' => 'client', 'category' => 'license'],
    'refund.requested' => ['template' => 'Marketplace: Refund Requested', 'recipient' => 'vendor', 'category' => 'refund'],
    'refund.result' => ['template' => 'Marketplace: Refund Result', 'recipient' => 'client', 'category' => 'refund'],
    'payout.requested' => ['template' => 'Marketplace: Payout Requested', 'recipient' => 'admin', 'category' => 'payout'],
    'payout.completed' => ['template' => 'Marketplace: Payout Completed', 'recipient' => 'vendor', 'category' => 'payout'],
    'payout.method_changed' => ['template' => 'Marketplace: Payout Method Changed', 'recipient' => 'vendor', 'category' => 'security'],
    'review.created' => ['template' => 'Marketplace: New Review', 'recipient' => 'vendor', 'category' => 'review'],
    'dispute.opened' => ['template' => 'Marketplace: New Dispute', 'recipient' => 'vendor', 'category' => 'dispute'],
    'dispute.resolved' => ['template' => 'Marketplace: Dispute Resolved', 'recipient' => 'client', 'category' => 'dispute'],
];
