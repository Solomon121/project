<?php

declare(strict_types=1);

/**
 * Built-in licence types (docs/architecture/05-licensing-and-downloads.md §1).
 * null limits mean "unlimited". Vendors pick one per licence tier and may
 * override the limits on the tier.
 */
return [
    'single_domain' => [
        'name' => 'Single Domain',
        'description' => 'Use on one production domain.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => true, 'commercial' => true,
    ],
    'multi_domain' => [
        'name' => 'Multiple Domains',
        'description' => 'Use on a fixed number of production domains.',
        'max_activations' => 5, 'max_domains' => 5, 'perpetual' => true, 'commercial' => true,
    ],
    'unlimited' => [
        'name' => 'Unlimited Domains',
        'description' => 'Use on any number of domains owned by the licensee.',
        'max_activations' => null, 'max_domains' => null, 'perpetual' => true, 'commercial' => true,
    ],
    'personal' => [
        'name' => 'Personal',
        'description' => 'Personal, non-commercial use.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => true, 'commercial' => false,
    ],
    'commercial' => [
        'name' => 'Commercial',
        'description' => 'Use in one commercial project.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => true, 'commercial' => true,
    ],
    'extended' => [
        'name' => 'Extended',
        'description' => 'Commercial use including in end products that are sold.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => true, 'commercial' => true,
    ],
    'developer' => [
        'name' => 'Developer',
        'description' => 'Use in unlimited client projects built by the licensee.',
        'max_activations' => null, 'max_domains' => null, 'perpetual' => true, 'commercial' => true,
    ],
    'subscription' => [
        'name' => 'Subscription',
        'description' => 'Valid while the subscription is active.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => false, 'commercial' => true,
    ],
    'lifetime' => [
        'name' => 'Lifetime',
        'description' => 'Perpetual licence with lifetime updates.',
        'max_activations' => 1, 'max_domains' => 1, 'perpetual' => true, 'commercial' => true,
    ],
];
