<?php

declare(strict_types=1);

/**
 * Built-in product types (docs/architecture/10-extensibility.md §2).
 *
 * 'handler' is the ProductTypeInterface implementation code; the classes are
 * implemented in Phase 3. Administrators can add further types in the UI that
 * reuse a handler with their own code, name, attribute schema and file rules.
 */

$archives = ['zip', 'rar', '7z', 'tar', 'gz', 'tgz'];
$images = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];
$docs = ['pdf', 'docx', 'epub', 'txt', 'md'];
$audio = ['mp3', 'wav', 'flac', 'ogg', 'aac'];
$video = ['mp4', 'mov', 'webm', 'mkv'];
$installers = ['exe', 'msi', 'dmg', 'pkg', 'apk', 'ipa', 'appimage', 'deb', 'rpm'];

$t = static fn (string $name, string $family, string $handler, array $ext, int $maxMb, int $refundDays = 14): array => [
    'name' => $name,
    'family' => $family,
    'handler' => $handler,
    'allowed_extensions' => array_values(array_unique($ext)),
    'max_file_mb' => $maxMb,
    'refund_period_days' => $refundDays,
];

return [
    // Digital products
    'php_script' => $t('PHP Script', 'digital', 'script', $archives, 512),
    'js_script' => $t('JavaScript Script', 'digital', 'script', $archives, 512),
    'python_script' => $t('Python Script', 'digital', 'script', $archives, 512),
    'laravel_app' => $t('Laravel Application', 'digital', 'software', $archives, 1024),
    'node_app' => $t('Node.js Application', 'digital', 'software', $archives, 1024),
    'python_app' => $t('Python Application', 'digital', 'software', $archives, 1024),
    'saas_selfhosted' => $t('SaaS Application (self-hosted)', 'digital', 'software', $archives, 2048),
    'software' => $t('Software', 'digital', 'software', array_merge($archives, $installers), 4096),
    'desktop_app' => $t('Desktop Application', 'digital', 'software', array_merge($archives, $installers), 4096),
    'mobile_app' => $t('Mobile Application', 'digital', 'software', array_merge($archives, ['apk', 'aab', 'ipa']), 4096),
    'wordpress_theme' => $t('WordPress Theme', 'digital', 'wordpress', ['zip'], 256),
    'wordpress_plugin' => $t('WordPress Plugin', 'digital', 'wordpress', ['zip'], 256),
    'whmcs_module' => $t('WHMCS Module', 'digital', 'whmcs', ['zip'], 256),
    'whmcs_template' => $t('WHMCS Template', 'digital', 'digital_download', ['zip'], 256),
    'whmcs_addon' => $t('WHMCS Addon', 'digital', 'whmcs', ['zip'], 256),
    'html_template' => $t('HTML Template', 'digital', 'digital_download', ['zip'], 512),
    'website_template' => $t('Website Template', 'digital', 'digital_download', ['zip'], 1024),
    'ui_kit' => $t('UI Kit', 'digital', 'digital_download', array_merge($archives, ['fig', 'sketch', 'xd', 'psd']), 2048),
    'graphics' => $t('Graphics', 'digital', 'digital_download', array_merge($archives, $images, ['psd', 'ai', 'eps']), 2048),
    'logo' => $t('Logo', 'digital', 'digital_download', array_merge($archives, $images, ['ai', 'eps']), 512),
    'icons' => $t('Icons', 'digital', 'digital_download', array_merge($archives, $images), 512),
    'font' => $t('Font', 'digital', 'digital_download', array_merge($archives, ['ttf', 'otf', 'woff', 'woff2']), 256),
    'illustration' => $t('Illustration', 'digital', 'digital_download', array_merge($archives, $images, ['ai', 'eps', 'psd']), 1024),
    '3d_asset' => $t('3D Asset', 'digital', 'digital_download', array_merge($archives, ['obj', 'fbx', 'glb', 'gltf', 'blend', 'stl']), 4096),
    'video_asset' => $t('Video Asset', 'digital', 'digital_download', array_merge($archives, $video), 8192),
    'audio_asset' => $t('Audio Asset', 'digital', 'digital_download', array_merge($archives, $audio), 2048),
    'music' => $t('Music', 'digital', 'digital_download', array_merge($archives, $audio), 2048),
    'stock_asset' => $t('Stock Asset', 'digital', 'digital_download', array_merge($archives, $images, $video), 4096),
    'ebook' => $t('Ebook', 'digital', 'digital_download', ['pdf', 'epub', 'mobi', 'zip'], 512),
    'document' => $t('Document / PDF', 'digital', 'digital_download', array_merge($docs, ['xlsx', 'pptx', 'zip']), 512),
    'source_code' => $t('Source Code', 'digital', 'script', $archives, 2048),
    'api' => $t('API', 'digital', 'software', $archives, 512),
    'code_snippet' => $t('Code Snippet', 'digital', 'script', array_merge($archives, ['txt']), 64),

    // Websites (service-delivery handover with escrow)
    'website_complete' => $t('Complete Website', 'website', 'website', $archives, 8192, 7),
    'website_wordpress' => $t('WordPress Website', 'website', 'website', $archives, 8192, 7),
    'website_whmcs' => $t('WHMCS Website', 'website', 'website', $archives, 8192, 7),
    'website_ecommerce' => $t('E-commerce Website', 'website', 'website', $archives, 8192, 7),
    'website_saas' => $t('SaaS Website', 'website', 'website', $archives, 8192, 7),
    'website_business' => $t('Business Website', 'website', 'website', $archives, 8192, 7),
    'website_blog' => $t('Blog Website', 'website', 'website', $archives, 8192, 7),
    'website_marketplace' => $t('Marketplace Website', 'website', 'website', $archives, 8192, 7),
    'website_agency' => $t('Agency Website', 'website', 'website', $archives, 8192, 7),
    'website_classified' => $t('Classified Website', 'website', 'website', $archives, 8192, 7),
    'web_application' => $t('Custom Web Application', 'website', 'website', $archives, 8192, 7),

    // Domains (native WHMCS domain orders) and aftermarket listings
    'domain_registration' => $t('Domain Registration', 'domain', 'domain', [], 0, 0),
    'domain_transfer' => $t('Domain Transfer', 'domain', 'domain', [], 0, 0),
    'domain_renewal' => $t('Domain Renewal', 'domain', 'domain', [], 0, 0),
    'premium_domain' => $t('Premium Domain', 'domain', 'domain_listing', [], 0, 0),
    'domain_listing' => $t('Domain Listing', 'domain', 'domain_listing', [], 0, 0),
    'domain_auction' => $t('Domain Auction', 'domain', 'domain_listing', [], 0, 0),
    'domain_brokerage' => $t('Domain Brokerage', 'domain', 'service', [], 0, 0),

    // Services
    'service_web_development' => $t('Website Development', 'service', 'service', array_merge($archives, $docs, $images), 2048),
    'service_logo_design' => $t('Logo Design', 'service', 'service', array_merge($archives, $images, $docs), 1024),
    'service_seo' => $t('SEO', 'service', 'service', array_merge($archives, $docs), 512),
    'service_hosting_setup' => $t('Hosting Setup', 'service', 'service', array_merge($archives, $docs), 512),
    'service_server_admin' => $t('Server Administration', 'service', 'service', array_merge($archives, $docs), 512),
    'service_wordpress_dev' => $t('WordPress Development', 'service', 'service', array_merge($archives, $docs), 2048),
    'service_whmcs_dev' => $t('WHMCS Development', 'service', 'service', array_merge($archives, $docs), 2048),
    'service_graphic_design' => $t('Graphic Design', 'service', 'service', array_merge($archives, $images, $docs), 2048),
    'service_marketing' => $t('Marketing', 'service', 'service', array_merge($archives, $docs), 512),
    'service_consulting' => $t('Consulting', 'service', 'service', $docs, 256),
    'service_custom_dev' => $t('Custom Development', 'service', 'service', array_merge($archives, $docs), 2048),
];
