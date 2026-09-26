<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Seeding;

/**
 * Seeds default settings, the default global commission rule, the event → email
 * template map and the legal page placeholders.
 */
final class ConfigurationSeeder extends Seeder
{
    public function run(): int
    {
        return $this->seedSettings()
            + $this->seedGlobalCommissionRule()
            + $this->seedEmailTemplateMap()
            + $this->seedLegalPages();
    }

    private function seedSettings(): int
    {
        $existing = array_flip($this->table('settings')->pluck('name')->all());
        $rows = [];
        foreach ($this->config('settings') as $name => $definition) {
            if (isset($existing[$name])) {
                continue;
            }
            $isSecret = $definition['type'] === 'secret';
            $rows[] = [
                'name' => $name,
                // Secrets start unset; they are encrypted by the KeyRing when an admin provides them.
                'value' => $isSecret ? null : json_encode($definition['default']),
                'is_encrypted' => $isSecret,
                'updated_by_admin_id' => null,
                'updated_at' => $this->now(),
            ];
        }
        if ($rows !== []) {
            $this->table('settings')->insert($rows);
        }

        return count($rows);
    }

    private function seedGlobalCommissionRule(): int
    {
        if ($this->table('commission_rules')->where('scope', 'global')->exists()) {
            return 0;
        }

        $settings = $this->config('settings');
        $this->table('commission_rules')->insert([
            'scope' => 'global',
            'scope_id' => null,
            'method' => 'percentage',
            'percentage' => $settings['commission.default_percentage']['default'],
            'fixed_amount' => '0.0000',
            'tiers' => json_encode([]),
            'fee_policy' => json_encode(['bearer' => $settings['commission.processing_fee_bearer']['default']]),
            'priority' => 0,
            'is_active' => true,
            'created_at' => $this->now(),
        ]);

        return 1;
    }

    private function seedEmailTemplateMap(): int
    {
        $existing = array_flip($this->table('email_template_map')->pluck('event')->all());
        $rows = [];
        foreach ($this->config('email_events') as $event => $map) {
            if (isset($existing[$event])) {
                continue;
            }
            $rows[] = [
                'event' => $event,
                'whmcs_template_name' => $map['template'],
                'recipient' => $map['recipient'],
                'category' => $map['category'],
                'is_enabled' => true,
                'variables' => json_encode([]),
            ];
        }
        if ($rows !== []) {
            $this->table('email_template_map')->insert($rows);
        }

        return count($rows);
    }

    private function seedLegalPages(): int
    {
        $existing = array_flip($this->table('legal_pages')->distinct()->pluck('slug')->all());
        $inserted = 0;
        foreach ($this->config('legal_pages') as $slug => $page) {
            if (isset($existing[$slug])) {
                continue;
            }
            $this->table('legal_pages')->insert([
                'slug' => $slug,
                'version' => 1,
                'title' => $page['title'],
                'body_html' => $page['body'],
                'requires_acceptance' => $page['requires_acceptance'],
                'is_template' => true,
                'published_at' => null,
                'created_at' => $this->now(),
            ]);
            $inserted++;
        }

        return $inserted;
    }
}
