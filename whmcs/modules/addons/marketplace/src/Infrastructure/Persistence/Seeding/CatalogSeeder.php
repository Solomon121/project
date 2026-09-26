<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Seeding;

/**
 * Seeds built-in product types, licence types and (on first install only) the
 * default category tree.
 */
final class CatalogSeeder extends Seeder
{
    public function run(): int
    {
        return $this->seedProductTypes() + $this->seedLicenseTypes() + $this->seedCategories();
    }

    private function seedProductTypes(): int
    {
        $existing = array_flip($this->table('product_types')->pluck('code')->all());
        $inserted = 0;
        $order = 0;
        foreach ($this->config('product_types') as $code => $type) {
            $order += 10;
            if (isset($existing[$code])) {
                continue;
            }
            $this->table('product_types')->insert([
                'code' => $code,
                'family' => $type['family'],
                'handler' => $type['handler'],
                'name' => $type['name'],
                'is_enabled' => true,
                'is_system' => true,
                'allowed_extensions' => json_encode($type['allowed_extensions']),
                'max_file_mb' => $type['max_file_mb'] ?: null,
                'attributes_schema' => json_encode([]),
                'refund_period_days' => $type['refund_period_days'],
                'sort_order' => $order,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            $inserted++;
        }

        return $inserted;
    }

    private function seedLicenseTypes(): int
    {
        $existing = array_flip($this->table('license_types')->pluck('code')->all());
        $inserted = 0;
        $order = 0;
        foreach ($this->config('license_types') as $code => $type) {
            $order += 10;
            if (isset($existing[$code])) {
                continue;
            }
            $this->table('license_types')->insert([
                'code' => $code,
                'name' => $type['name'],
                'description' => $type['description'],
                'provider_code' => 'internal',
                'default_max_activations' => $type['max_activations'],
                'default_max_domains' => $type['max_domains'],
                'is_perpetual' => $type['perpetual'],
                'allows_commercial_use' => $type['commercial'],
                'sort_order' => $order,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * The default tree is only created when there are no categories at all, so
     * categories an administrator deleted are not recreated on upgrade.
     */
    private function seedCategories(): int
    {
        if ($this->table('categories')->exists()) {
            return 0;
        }

        $inserted = 0;
        $order = 0;
        foreach ($this->config('categories') as $slug => $category) {
            $order += 10;
            $parentId = $this->insertCategory(null, '/', 0, $slug, $category['name'], $order);
            $inserted++;
            $childOrder = 0;
            foreach ($category['children'] as $childSlug => $childName) {
                $childOrder += 10;
                $this->insertCategory($parentId, "/{$parentId}/", 1, $childSlug, $childName, $childOrder);
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * Inserts a category and sets its materialised path ("/<ancestors>/<id>/").
     */
    private function insertCategory(?int $parentId, string $parentPath, int $depth, string $slug, string $name, int $order): int
    {
        $id = (int) $this->table('categories')->insertGetId([
            'parent_id' => $parentId,
            'path' => '/',
            'depth' => $depth,
            'slug' => $slug,
            'name' => $name,
            'is_active' => true,
            'sort_order' => $order,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $this->table('categories')->where('id', $id)->update(['path' => $parentPath . $id . '/']);

        return $id;
    }
}
