<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Verifies the live database against what the migrations define. Used by the
 * admin health check, the CLI (`schema:verify`) and the test suite.
 */
final class SchemaVerifier
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly Migrator $migrator,
    ) {
    }

    /**
     * @return list<SchemaCheck>
     */
    public function verify(): array
    {
        $checks = [];
        $checks[] = $this->checkPendingMigrations();

        $live = $this->liveTables();
        $expected = array_map([Table::class, 'name'], $this->migrator->ownedTables());

        $missing = array_values(array_diff($expected, array_keys($live)));
        $checks[] = $missing === []
            ? new SchemaCheck(SchemaCheck::PASS, 'TABLES_PRESENT', count($expected) . ' marketplace tables present.')
            : new SchemaCheck(
                SchemaCheck::ERROR,
                'TABLES_MISSING',
                'Missing tables: ' . implode(', ', $missing),
                'Run the marketplace database migration (Addons > Marketplace, or bin/marketplace migrate).'
            );

        $unexpected = array_values(array_diff(array_keys($live), $expected));
        if ($unexpected !== []) {
            $checks[] = new SchemaCheck(
                SchemaCheck::WARNING,
                'TABLES_UNEXPECTED',
                'Tables with the marketplace prefix that no migration defines: ' . implode(', ', $unexpected),
                'These may be left over from a development build or another module. Review before removing.'
            );
        }

        $badEngine = [];
        $badCollation = [];
        foreach ($live as $table => $info) {
            if (!in_array($table, $expected, true)) {
                continue;
            }
            if (strcasecmp((string) $info['engine'], 'InnoDB') !== 0) {
                $badEngine[] = "{$table} ({$info['engine']})";
            }
            if (!str_starts_with((string) $info['collation'], 'utf8mb4')) {
                $badCollation[] = "{$table} ({$info['collation']})";
            }
        }
        $checks[] = $badEngine === []
            ? new SchemaCheck(SchemaCheck::PASS, 'ENGINE_INNODB', 'All marketplace tables use InnoDB.')
            : new SchemaCheck(SchemaCheck::ERROR, 'ENGINE_NOT_INNODB', 'Non-InnoDB tables: ' . implode(', ', $badEngine),
                'Convert with ALTER TABLE … ENGINE=InnoDB; transactions and foreign keys require InnoDB.');
        $checks[] = $badCollation === []
            ? new SchemaCheck(SchemaCheck::PASS, 'CHARSET_UTF8MB4', 'All marketplace tables use utf8mb4.')
            : new SchemaCheck(SchemaCheck::ERROR, 'CHARSET_NOT_UTF8MB4', 'Non-utf8mb4 tables: ' . implode(', ', $badCollation),
                'Convert with ALTER TABLE … CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci.');

        $checks[] = $this->checkForeignKeys($expected);
        $checks[] = $this->checkAppendOnlyTriggers();

        return $checks;
    }

    /**
     * @param list<SchemaCheck> $checks
     */
    public static function worstStatus(array $checks): string
    {
        $statuses = array_map(static fn (SchemaCheck $c): string => $c->status, $checks);
        if (in_array(SchemaCheck::ERROR, $statuses, true)) {
            return SchemaCheck::ERROR;
        }

        return in_array(SchemaCheck::WARNING, $statuses, true) ? SchemaCheck::WARNING : SchemaCheck::PASS;
    }

    private function checkPendingMigrations(): SchemaCheck
    {
        $pending = $this->migrator->pending();

        return $pending === []
            ? new SchemaCheck(SchemaCheck::PASS, 'MIGRATIONS_CURRENT', 'All migrations have been applied.')
            : new SchemaCheck(
                SchemaCheck::WARNING,
                'MIGRATIONS_PENDING',
                count($pending) . ' pending migration(s): ' . implode(', ', $pending),
                'Open the marketplace admin page (migrations run automatically) or run bin/marketplace migrate.'
            );
    }

    /**
     * Every FK from a marketplace table must reference an existing marketplace table.
     *
     * @param list<string> $expected
     */
    private function checkForeignKeys(array $expected): SchemaCheck
    {
        $rows = $this->db->connection()->select(
            'SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS c, REFERENCED_TABLE_NAME AS r
               FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [self::prefixPattern()]
        );
        $broken = [];
        foreach ($rows as $row) {
            if (!in_array($row->r, $expected, true)) {
                $broken[] = "{$row->t}.{$row->c} -> {$row->r}";
            }
        }

        return $broken === []
            ? new SchemaCheck(SchemaCheck::PASS, 'FOREIGN_KEYS_VALID', count($rows) . ' foreign keys reference marketplace tables only.')
            : new SchemaCheck(SchemaCheck::ERROR, 'FOREIGN_KEYS_INVALID', 'Foreign keys referencing unknown tables: ' . implode(', ', $broken),
                'Re-run the migration or contact support; foreign keys must not reference WHMCS core tables.');
    }

    private function checkAppendOnlyTriggers(): SchemaCheck
    {
        $expected = array_column(AppendOnlyPolicy::triggers(), 'name');
        $present = array_map(
            static fn (object $r): string => (string) $r->n,
            $this->db->connection()->select(
                'SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE ?',
                ['mkp\\_%']
            )
        );
        $missing = array_values(array_diff($expected, $present));

        return $missing === []
            ? new SchemaCheck(SchemaCheck::PASS, 'APPEND_ONLY_ENFORCED', 'Ledger and audit tables are protected by database triggers.')
            : new SchemaCheck(
                SchemaCheck::WARNING,
                'APPEND_ONLY_NOT_ENFORCED',
                'Append-only triggers missing: ' . implode(', ', $missing)
                    . '. Immutability is enforced by the application only.',
                'Grant the TRIGGER privilege to the WHMCS database user, then roll back and re-apply migration 0016.'
            );
    }

    /**
     * @return array<string, array{engine:string|null, collation:string|null}>
     */
    private function liveTables(): array
    {
        $rows = $this->db->connection()->select(
            'SELECT TABLE_NAME AS t, ENGINE AS e, TABLE_COLLATION AS c
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [self::prefixPattern()]
        );
        $tables = [];
        foreach ($rows as $row) {
            $tables[(string) $row->t] = ['engine' => $row->e, 'collation' => $row->c];
        }

        return $tables;
    }

    /** LIKE pattern matching the marketplace prefix literally (underscores escaped). */
    private static function prefixPattern(): string
    {
        return str_replace('_', '\\_', Table::PREFIX) . '%';
    }
}
