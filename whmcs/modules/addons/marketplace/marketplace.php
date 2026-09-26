<?php

/**
 * WHMCS Multi-Vendor Digital Marketplace — addon module entry point.
 *
 * This file only adapts WHMCS's addon module function contract to the
 * marketplace application. Business logic lives in src/.
 *
 * @see https://developers.whmcs.com/addon-modules/
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

use WHMCS\Database\Capsule;
use WhmcsMarketplace\Application\Installer;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaCheck;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaVerifier;
use WhmcsMarketplace\Infrastructure\Persistence\WhmcsDatabaseContext;
use WhmcsMarketplace\Kernel\Html;
use WhmcsMarketplace\Kernel\ModuleInfo;

/**
 * Module metadata and the few settings WHMCS itself must hold.
 * All other configuration lives in the marketplace settings system.
 */
function marketplace_config(): array
{
    return [
        'name' => ModuleInfo::NAME,
        'description' => 'Multi-vendor digital marketplace: vendors, digital products, licensing, '
            . 'secure downloads, commissions and payouts, with a WordPress storefront.',
        'version' => ModuleInfo::VERSION,
        'author' => ModuleInfo::AUTHOR,
        'language' => 'english',
        'fields' => [
            'remove_data_on_deactivate' => [
                'FriendlyName' => 'Remove data on deactivate',
                'Type' => 'yesno',
                'Description' => 'Delete ALL marketplace tables when the module is deactivated. '
                    . 'Leave unticked to keep data (recommended).',
            ],
            'remove_data_confirmation' => [
                'FriendlyName' => 'Removal confirmation',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'To delete data that includes financial records (orders, ledger, payouts), '
                    . 'also type: ' . Installer::REMOVE_DATA_CONFIRMATION,
            ],
        ],
    ];
}

function marketplace_activate(): array
{
    if (version_compare(PHP_VERSION, ModuleInfo::MIN_PHP, '<')) {
        return [
            'status' => 'error',
            'description' => 'The marketplace requires PHP ' . ModuleInfo::MIN_PHP . ' or later.',
        ];
    }

    try {
        $result = marketplace_installer()->install();
    } catch (\Throwable $e) {
        marketplace_log_failure('activation', $e);

        return [
            'status' => 'error',
            'description' => 'Marketplace installation failed: ' . $e->getMessage()
                . ' The failed step was rolled back automatically where possible; the message above says'
                . ' if manual inspection is needed. See the activity log for details.',
        ];
    }

    return [
        'status' => 'success',
        'description' => sprintf(
            'Marketplace installed: %d migration(s) applied, %d default record(s) created. '
            . 'Open Addons > Marketplace to continue setup.',
            count($result['migrated']),
            array_sum($result['seeded'])
        ),
    ];
}

/**
 * Keeps all data unless the administrator explicitly opted in to removal.
 * Financial records are only removed when the typed confirmation matches.
 */
function marketplace_deactivate(): array
{
    $settings = Capsule::table('tbladdonmodules')
        ->where('module', ModuleInfo::SYSTEM_NAME)
        ->pluck('value', 'setting')
        ->all();

    $removeData = in_array($settings['remove_data_on_deactivate'] ?? '', ['on', 'yes', '1'], true);
    if (!$removeData) {
        return [
            'status' => 'success',
            'description' => 'Marketplace deactivated. All marketplace data has been kept.',
        ];
    }

    $force = trim((string) ($settings['remove_data_confirmation'] ?? '')) === Installer::REMOVE_DATA_CONFIRMATION;

    try {
        marketplace_installer()->removeAllData($force);
    } catch (\Throwable $e) {
        marketplace_log_failure('deactivation', $e);

        return [
            'status' => 'error',
            'description' => 'Marketplace data was NOT removed: ' . $e->getMessage(),
        ];
    }

    return [
        'status' => 'success',
        'description' => 'Marketplace deactivated and all marketplace tables removed.',
    ];
}

/**
 * Called by WHMCS on first access after the module files are updated.
 */
function marketplace_upgrade(array $vars): void
{
    try {
        marketplace_installer()->upgrade();
    } catch (\Throwable $e) {
        marketplace_log_failure('upgrade from ' . ($vars['version'] ?? 'unknown'), $e);
        throw $e;
    }
}

/**
 * Admin area. Phase 2 provides the installation and schema status page; the
 * full admin console is built in Phase 7.
 */
function marketplace_output(array $vars): void
{
    $installer = marketplace_installer();
    $migrator = $installer->migrator();
    $status = $migrator->status();
    $checks = (new SchemaVerifier(new WhmcsDatabaseContext(), $migrator))->verify();

    $badge = static function (string $s): string {
        $class = ['PASS' => 'success', 'WARNING' => 'warning', 'ERROR' => 'danger'][$s] ?? 'default';

        return '<span class="label label-' . $class . '">' . Html::e($s) . '</span>';
    };

    echo '<h2>Marketplace ' . Html::e(ModuleInfo::VERSION) . '</h2>';
    echo '<p>Overall database status: '
        . $badge(SchemaVerifier::worstStatus($checks)) . '</p>';

    echo '<h3>Schema health</h3><table class="table table-striped"><thead><tr>'
        . '<th>Status</th><th>Check</th><th>Details</th><th>Remediation</th></tr></thead><tbody>';
    foreach ($checks as $check) {
        /** @var SchemaCheck $check */
        echo '<tr><td>' . $badge($check->status) . '</td><td><code>' . Html::e($check->code) . '</code></td>'
            . '<td>' . Html::e($check->message) . '</td><td>' . Html::e($check->remediation) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h3>Migrations</h3><table class="table table-condensed"><thead><tr>'
        . '<th>Migration</th><th>Applied</th><th>Batch</th></tr></thead><tbody>';
    foreach ($status as $row) {
        echo '<tr><td><code>' . Html::e($row['migration']) . '</code></td>'
            . '<td>' . ($row['ran'] ? 'Yes' : 'Pending') . '</td>'
            . '<td>' . Html::e($row['batch']) . '</td></tr>';
    }
    echo '</tbody></table>';
}

function marketplace_sidebar(array $vars): string
{
    return '<div class="sidebar-header">Marketplace</div>'
        . '<p class="small" style="padding:0 10px">Version ' . Html::e(ModuleInfo::VERSION) . '</p>';
}

// --------------------------------------------------------------------- helpers

function marketplace_installer(): Installer
{
    return new Installer(new WhmcsDatabaseContext(), ModuleInfo::migrationsDir(), ModuleInfo::configDir());
}

/**
 * Records a failure in the WHMCS activity log (visible to admins). The message
 * is the exception message only: no stack traces or credentials.
 */
function marketplace_log_failure(string $operation, \Throwable $e): void
{
    if (function_exists('logActivity')) {
        logActivity('Marketplace ' . $operation . ' failed: ' . get_class($e) . ': ' . $e->getMessage());
    }
}
