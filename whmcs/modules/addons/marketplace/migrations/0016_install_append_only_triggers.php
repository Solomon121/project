<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\AppendOnlyPolicy;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Database-level enforcement of append-only tables (ADR-004).
 *
 * Creating triggers needs the TRIGGER privilege (and, with binary logging, SUPER
 * or log_bin_trust_function_creators). Many shared WHMCS hosts do not grant
 * these. In that case the migration does not fail the install: it records the
 * degraded state in settings and as a WARNING health event, so the health
 * check reports it, and repository-level enforcement remains in place.
 * Any other database error is re-thrown.
 */
return new class extends Migration {
    /** MySQL/MariaDB errors meaning "not permitted to create triggers". */
    private const PRIVILEGE_ERRORS = [1142, 1227, 1419];

    public function tables(): array
    {
        return [];
    }

    public function up(): void
    {
        $installed = [];
        try {
            foreach (AppendOnlyPolicy::triggers() as $trigger) {
                $this->db->connection()->unprepared($trigger['sql']);
                $installed[] = $trigger['name'];
            }
        } catch (QueryException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($code, self::PRIVILEGE_ERRORS, true)) {
                throw $e;
            }
            foreach ($installed as $name) {
                $this->db->connection()->unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            }
            $this->recordState(false, $e->getMessage());

            return;
        }

        $this->recordState(true, null);
    }

    public function down(): void
    {
        foreach (AppendOnlyPolicy::triggers() as $trigger) {
            $this->db->connection()->unprepared("DROP TRIGGER IF EXISTS `{$trigger['name']}`");
        }
        $this->db->connection()->table(Table::name('settings'))
            ->where('name', AppendOnlyPolicy::SETTING)->delete();
    }

    private function recordState(bool $enforced, ?string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $settings = $this->db->connection()->table(Table::name('settings'));
        $settings->where('name', AppendOnlyPolicy::SETTING)->delete();
        $settings->insert([
            'name' => AppendOnlyPolicy::SETTING,
            'value' => json_encode($enforced),
            'is_encrypted' => false,
            'updated_at' => $now,
        ]);

        if (!$enforced) {
            $this->db->connection()->table(Table::name('health_events'))->insert([
                'channel' => 'database',
                'severity' => 'warning',
                'code' => 'APPEND_ONLY_TRIGGERS_NOT_INSTALLED',
                'message' => 'Append-only triggers could not be installed (insufficient database privileges). '
                    . 'Ledger and audit immutability is enforced by the application only. Grant the TRIGGER '
                    . 'privilege and re-run the database migration to enable database-level enforcement.',
                'context' => json_encode(['error' => substr((string) $reason, 0, 500)]),
                'created_at' => $now,
            ]);
        }
    }
};
