<?php

declare(strict_types=1);

/**
 * Legal page placeholders (master prompt §86). These are deliberately NOT legal
 * text: they are empty templates that the operator must replace with wording
 * reviewed for their own jurisdiction. They are seeded with is_template = 1 and
 * unpublished, and the setup wizard blocks launch until they are edited.
 */
$placeholder = static fn (string $title): string =>
    '<p><strong>Template: replace before publishing.</strong></p>'
    . '<p>This ' . htmlspecialchars($title, ENT_QUOTES) . ' page is a placeholder provided by the marketplace '
    . 'software. It is not legal advice and contains no legal terms. Have this page written or reviewed by a '
    . 'qualified professional for your jurisdiction and business before enabling the marketplace.</p>';

return [
    'marketplace-terms' => ['title' => 'Marketplace Terms', 'requires_acceptance' => true, 'body' => $placeholder('Marketplace Terms')],
    'vendor-agreement' => ['title' => 'Vendor Agreement', 'requires_acceptance' => true, 'body' => $placeholder('Vendor Agreement')],
    'refund-policy' => ['title' => 'Refund Policy', 'requires_acceptance' => false, 'body' => $placeholder('Refund Policy')],
    'privacy-policy' => ['title' => 'Privacy Policy', 'requires_acceptance' => false, 'body' => $placeholder('Privacy Policy')],
    'acceptable-use' => ['title' => 'Acceptable Use Policy', 'requires_acceptance' => false, 'body' => $placeholder('Acceptable Use Policy')],
    'licensing-terms' => ['title' => 'Licensing Terms', 'requires_acceptance' => false, 'body' => $placeholder('Licensing Terms')],
    'copyright-dmca' => ['title' => 'Copyright / DMCA Policy', 'requires_acceptance' => false, 'body' => $placeholder('Copyright / DMCA Policy')],
];
