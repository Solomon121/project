<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Seeding;

/**
 * Seeds the permission catalogue and the default staff roles.
 *
 * A permission that did not exist before is granted to every default role that
 * lists it. Existing roles are never re-granted permissions that an
 * administrator removed.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): int
    {
        $config = $this->config('permissions');
        $inserted = 0;

        $existingPermissions = $this->table('permissions')->pluck('id', 'code')->all();
        $newPermissionCodes = [];
        foreach ($config['permissions'] as $group => $codes) {
            foreach ($codes as $code) {
                if (isset($existingPermissions[$code])) {
                    continue;
                }
                $existingPermissions[$code] = (int) $this->table('permissions')->insertGetId([
                    'code' => $code,
                    'group' => $group,
                    'description' => null,
                ]);
                $newPermissionCodes[$code] = true;
                $inserted++;
            }
        }

        $existingRoles = $this->table('roles')->pluck('id', 'code')->all();
        foreach ($config['roles'] as $code => $role) {
            $roleIsNew = !isset($existingRoles[$code]);
            if ($roleIsNew) {
                $existingRoles[$code] = (int) $this->table('roles')->insertGetId([
                    'code' => $code,
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system' => true,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]);
                $inserted++;
            }

            $rows = [];
            foreach ($role['grants'] as $permissionCode) {
                if (!isset($existingPermissions[$permissionCode])) {
                    throw new \UnexpectedValueException(
                        "Role {$code} grants unknown permission {$permissionCode}."
                    );
                }
                if ($roleIsNew || isset($newPermissionCodes[$permissionCode])) {
                    $rows[] = [
                        'role_id' => $existingRoles[$code],
                        'permission_id' => $existingPermissions[$permissionCode],
                    ];
                }
            }
            if ($rows !== []) {
                $this->table('role_permissions')->insertOrIgnore($rows);
                $inserted += count($rows);
            }
        }

        return $inserted;
    }
}
