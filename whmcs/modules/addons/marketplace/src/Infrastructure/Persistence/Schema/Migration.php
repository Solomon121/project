<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Base class for marketplace migrations.
 *
 * Compatibility: only Schema Builder features available since illuminate/database
 * 7.x are used (WHMCS 8 bundles 7.12), so migrations run on every supported WHMCS.
 *
 * Conventions enforced here (see docs/architecture/03-database-schema.md):
 *  - InnoDB, utf8mb4 / utf8mb4_unicode_ci
 *  - explicit, length-safe index and FK names
 *  - money as DECIMAL(19,4), timestamps as DATETIME(6) UTC
 *  - hashes as CHAR(64) hex in ascii_bin, IPs as VARCHAR(45) text
 *  - WHMCS ids as INT UNSIGNED without FKs (ADR-005)
 */
abstract class Migration
{
    protected DatabaseContext $db;

    public function setContext(DatabaseContext $db): void
    {
        $this->db = $db;
    }

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Short names of the tables this migration creates. Used by the migrator to
     * clean up after a failed run, by the rollback guard and by the schema verifier.
     *
     * @return list<string>
     */
    abstract public function tables(): array;

    /**
     * Financial migrations cannot be rolled back while their tables hold rows,
     * unless the operator explicitly forces it (after exporting the data).
     */
    public function isFinancial(): bool
    {
        return false;
    }

    // ------------------------------------------------------------------ tables

    /**
     * Creates a table. Fails if the table already exists: a table that exists
     * but whose migration was not recorded indicates an inconsistent schema that
     * must not be silently accepted.
     */
    protected function create(string $shortName, Closure $definition): void
    {
        $table = Table::name($shortName);
        $this->db->schema()->create($table, function (Blueprint $t) use ($definition): void {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $definition($t);
        });
    }

    protected function alter(string $shortName, Closure $definition): void
    {
        $this->db->schema()->table(Table::name($shortName), $definition);
    }

    protected function drop(string $shortName): void
    {
        $this->db->schema()->dropIfExists(Table::name($shortName));
    }

    /**
     * Drops tables in reverse order of creation with FK checks temporarily
     * disabled, so tables that reference each other can be removed together.
     *
     * @param list<string> $shortNames
     */
    protected function dropAll(array $shortNames): void
    {
        $connection = $this->db->connection();
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (array_reverse($shortNames) as $shortName) {
                $this->drop($shortName);
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    protected function statement(string $sql): void
    {
        $this->db->connection()->statement($sql);
    }

    // ----------------------------------------------------------------- columns

    protected function id(Blueprint $t): void
    {
        $t->bigIncrements('id');
    }

    /** Public identifier (ULID, 26 chars Crockford base32). */
    protected function uuid(Blueprint $t, string $column = 'uuid'): void
    {
        $t->char($column, 26)->charset('ascii')->collation('ascii_bin');
        $this->unique($t, [$column]);
    }

    protected function money(Blueprint $t, string $column, bool $nullable = false): void
    {
        $col = $t->decimal($column, 19, 4);
        $nullable ? $col->nullable() : $col->default('0.0000');
    }

    /** Percentage or ratio with 4 decimal places (e.g. 20.0000 for 20 %). */
    protected function rate(Blueprint $t, string $column, bool $nullable = false): void
    {
        $col = $t->decimal($column, 7, 4);
        $nullable ? $col->nullable() : $col->default('0.0000');
    }

    protected function fxRate(Blueprint $t, string $column, bool $nullable = true): void
    {
        $col = $t->decimal($column, 19, 8);
        $nullable ? $col->nullable() : $col->default('1.00000000');
    }

    protected function currency(Blueprint $t, string $column = 'currency_id', bool $nullable = false): void
    {
        $col = $t->unsignedInteger($column);
        if ($nullable) {
            $col->nullable();
        }
    }

    /** Reference to a WHMCS-owned row (tblclients, tblusers, tbladmins, tblorders, …). No FK. */
    protected function whmcsId(Blueprint $t, string $column, bool $nullable = false): void
    {
        $col = $t->unsignedInteger($column);
        if ($nullable) {
            $col->nullable();
        }
    }

    /** Foreign-key column pointing at a marketplace table's BIGINT id. */
    protected function ref(Blueprint $t, string $column, bool $nullable = false): void
    {
        $col = $t->unsignedBigInteger($column);
        if ($nullable) {
            $col->nullable();
        }
    }

    protected function status(Blueprint $t, string $column, string $default): void
    {
        $t->string($column, 32)->charset('ascii')->collation('ascii_bin')->default($default);
    }

    /** Machine code / enum-like value. */
    protected function code(Blueprint $t, string $column, int $length = 64, bool $nullable = false): void
    {
        $col = $t->string($column, $length)->charset('ascii')->collation('ascii_bin');
        if ($nullable) {
            $col->nullable();
        }
    }

    protected function slug(Blueprint $t, string $column = 'slug', int $length = 191): void
    {
        $t->string($column, $length)->charset('ascii')->collation('ascii_bin');
    }

    /** SHA-256 / HMAC-SHA256 as 64 hex characters. */
    protected function hash(Blueprint $t, string $column, bool $nullable = false): void
    {
        $col = $t->char($column, 64)->charset('ascii')->collation('ascii_bin');
        if ($nullable) {
            $col->nullable();
        }
    }

    protected function ip(Blueprint $t, string $column = 'ip'): void
    {
        $t->string($column, 45)->charset('ascii')->collation('ascii_bin')->nullable();
    }

    protected function json(Blueprint $t, string $column, bool $nullable = true): void
    {
        $col = $t->json($column);
        if ($nullable) {
            $col->nullable();
        }
    }

    /** Ciphertext produced by the KeyRing (sodium secretbox, with key id prefix). */
    protected function encrypted(Blueprint $t, string $column): void
    {
        $t->binary($column)->nullable();
    }

    protected function timestamp(Blueprint $t, string $column, bool $nullable = true): void
    {
        $col = $t->dateTime($column, 6);
        if ($nullable) {
            $col->nullable();
        }
    }

    /** created_at (required, set by the application in UTC) + updated_at. */
    protected function timestamps(Blueprint $t): void
    {
        $this->timestamp($t, 'created_at', false);
        $this->timestamp($t, 'updated_at');
    }

    /** created_at only: for append-only tables. */
    protected function createdAt(Blueprint $t): void
    {
        $this->timestamp($t, 'created_at', false);
    }

    protected function softDeletes(Blueprint $t): void
    {
        $this->timestamp($t, 'deleted_at');
    }

    /** Actor who performed an action: admin, user, vendor_member, system, api_key. */
    protected function actor(Blueprint $t, string $prefix = 'actor', bool $nullable = true): void
    {
        $this->code($t, $prefix . '_type', 32, $nullable);
        $col = $t->unsignedBigInteger($prefix . '_id');
        $col->nullable();
    }

    // ------------------------------------------------------------ constraints

    /** @param list<string> $columns */
    protected function index(Blueprint $t, array $columns): void
    {
        $t->index($columns, IdentifierName::for('ix', $t->getTable(), $columns));
    }

    /** @param list<string> $columns */
    protected function unique(Blueprint $t, array $columns): void
    {
        $t->unique($columns, IdentifierName::for('uq', $t->getTable(), $columns));
    }

    /** @param list<string> $columns */
    protected function primary(Blueprint $t, array $columns): void
    {
        $t->primary($columns, IdentifierName::for('pk', $t->getTable(), $columns));
    }

    /**
     * FK to a marketplace table. Default RESTRICT (never cascade into financial data).
     */
    protected function foreign(
        Blueprint $t,
        string $column,
        string $referencedShortName,
        string $onDelete = 'restrict',
        string $referencedColumn = 'id'
    ): void {
        $t->foreign($column, IdentifierName::for('fk', $t->getTable(), [$column]))
            ->references($referencedColumn)
            ->on(Table::name($referencedShortName))
            ->onDelete($onDelete);
    }

    /**
     * InnoDB FULLTEXT index. Added with raw DDL for compatibility with older
     * schema builders that lack fullText().
     *
     * @param list<string> $columns
     */
    protected function fullText(string $shortName, array $columns): void
    {
        $table = Table::name($shortName);
        $name = IdentifierName::for('ft', $table, $columns);
        $cols = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $this->statement("ALTER TABLE `{$table}` ADD FULLTEXT INDEX `{$name}` ({$cols})");
    }

    /**
     * Adds an FK from a table owned by an earlier migration to a table created by
     * this one. The owning migration's down() must call dropForeignIfExists().
     */
    protected function addForeign(
        string $shortName,
        string $column,
        string $referencedShortName,
        string $onDelete = 'restrict'
    ): void {
        $this->alter($shortName, function (Blueprint $t) use ($column, $referencedShortName, $onDelete): void {
            $this->foreign($t, $column, $referencedShortName, $onDelete);
        });
    }

    protected function dropForeignIfExists(string $shortName, string $column): void
    {
        $table = Table::name($shortName);
        if (!$this->db->schema()->hasTable($table)) {
            return;
        }
        $name = IdentifierName::for('fk', $table, [$column]);
        $exists = $this->db->connection()->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
                AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
            [$table, $name]
        );
        if ((int) $exists->c > 0) {
            $this->statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }

    protected function check(string $shortName, string $name, string $expression): void
    {
        $table = Table::name($shortName);
        $constraint = IdentifierName::for('ck', $table, [$name]);
        $this->statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$expression})");
    }
}
