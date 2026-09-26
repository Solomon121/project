<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Which tables are append-only, and how strictly.
 *
 * IMMUTABLE: rows can never be updated or deleted (financial records, evidence,
 *            audit trail). Only DROP TABLE (explicit, forced uninstall) removes them.
 * NO_UPDATE: rows can never be updated, but old rows may be deleted by the
 *            configured retention policy (high-volume logs).
 */
final class AppendOnlyPolicy
{
    public const SETTING = 'database.append_only_triggers';
    public const IMMUTABLE = 'immutable';
    public const NO_UPDATE = 'no_update';

    /** @return array<string, string> short table name => level */
    public static function tables(): array
    {
        return [
            'commissions' => self::IMMUTABLE,
            'commission_adjustments' => self::IMMUTABLE,
            'wallet_transactions' => self::IMMUTABLE,
            'payout_events' => self::IMMUTABLE,
            'refund_events' => self::IMMUTABLE,
            'dispute_messages' => self::IMMUTABLE,
            'domain_bids' => self::IMMUTABLE,
            'audit_logs' => self::IMMUTABLE,
            'downloads' => self::NO_UPDATE,
            'license_events' => self::NO_UPDATE,
        ];
    }

    /**
     * @return list<array{name:string, table:string, operation:string, sql:string}>
     */
    public static function triggers(): array
    {
        $triggers = [];
        foreach (self::tables() as $short => $level) {
            $operations = $level === self::IMMUTABLE ? ['UPDATE', 'DELETE'] : ['UPDATE'];
            foreach ($operations as $operation) {
                $table = Table::name($short);
                $name = 'mkp_' . $short . '_no_' . strtolower($operation);
                $message = "{$table} is append-only: {$operation} is not permitted";
                $triggers[] = [
                    'name' => $name,
                    'table' => $table,
                    'operation' => $operation,
                    'sql' => "CREATE TRIGGER `{$name}` BEFORE {$operation} ON `{$table}` FOR EACH ROW "
                        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'",
                ];
            }
        }

        return $triggers;
    }
}
