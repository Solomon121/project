<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;

/**
 * Staff role-based access control (docs/architecture/09-permissions.md).
 * Admin identities are WHMCS admins (tbladmins.id, no FK).
 */
return new class extends Migration {
    public function tables(): array
    {
        return ['roles', 'permissions', 'role_permissions', 'admin_roles'];
    }

    public function up(): void
    {
        $this->create('roles', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'code');
            $t->string('name', 128);
            $t->string('description', 500)->nullable();
            $t->boolean('is_system')->default(false);
            $this->timestamps($t);
            $this->unique($t, ['code']);
        });

        $this->create('permissions', function (Blueprint $t): void {
            $this->id($t);
            $this->code($t, 'code', 96);
            $this->code($t, 'group', 64);
            $t->string('description', 500)->nullable();
            $this->unique($t, ['code']);
        });

        $this->create('role_permissions', function (Blueprint $t): void {
            $this->ref($t, 'role_id');
            $this->ref($t, 'permission_id');
            $this->primary($t, ['role_id', 'permission_id']);
            $this->index($t, ['permission_id']);
            $this->foreign($t, 'role_id', 'roles', 'cascade');
            $this->foreign($t, 'permission_id', 'permissions', 'cascade');
        });

        $this->create('admin_roles', function (Blueprint $t): void {
            $this->whmcsId($t, 'admin_id');
            $this->ref($t, 'role_id');
            $this->whmcsId($t, 'granted_by_admin_id', true);
            $this->createdAt($t);
            $this->primary($t, ['admin_id', 'role_id']);
            $this->index($t, ['role_id']);
            $this->foreign($t, 'role_id', 'roles');
        });
    }

    public function down(): void
    {
        $this->dropAll($this->tables());
    }
};
