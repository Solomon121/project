<?php

declare(strict_types=1);

/**
 * Default category tree (master prompt §20). Seeded only on first install;
 * administrators can edit or add unlimited categories afterwards.
 * Keys are slugs; children slugs are prefixed with the parent slug for uniqueness.
 */
return [
    'websites' => ['name' => 'Websites', 'children' => [
        'websites-wordpress' => 'WordPress',
        'websites-ecommerce' => 'E-commerce',
        'websites-saas' => 'SaaS',
        'websites-business' => 'Business',
    ]],
    'software' => ['name' => 'Software', 'children' => [
        'software-php' => 'PHP',
        'software-laravel' => 'Laravel',
        'software-nodejs' => 'Node.js',
        'software-python' => 'Python',
        'software-desktop' => 'Desktop',
    ]],
    'graphics' => ['name' => 'Graphics', 'children' => [
        'graphics-logos' => 'Logos',
        'graphics-icons' => 'Icons',
        'graphics-illustrations' => 'Illustrations',
        'graphics-ui-kits' => 'UI Kits',
    ]],
    'scripts' => ['name' => 'Scripts', 'children' => [
        'scripts-php' => 'PHP',
        'scripts-javascript' => 'JavaScript',
        'scripts-python' => 'Python',
        'scripts-laravel' => 'Laravel',
    ]],
    'wordpress' => ['name' => 'WordPress', 'children' => [
        'wordpress-themes' => 'Themes',
        'wordpress-plugins' => 'Plugins',
    ]],
    'whmcs' => ['name' => 'WHMCS', 'children' => [
        'whmcs-modules' => 'Modules',
        'whmcs-templates' => 'Templates',
        'whmcs-addons' => 'Addons',
    ]],
    'domains' => ['name' => 'Domains', 'children' => []],
    'services' => ['name' => 'Services', 'children' => []],
    'ebooks-documents' => ['name' => 'Ebooks & Documents', 'children' => []],
    'audio-video' => ['name' => 'Audio & Video', 'children' => []],
];
