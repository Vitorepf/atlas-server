<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AtlasMaestroWorkerPreferenceRegistryTest extends TestCase
{
    public function test_inspect_returns_declared_record_from_config(): void
    {
        Config::set('atlas.maestro.personalization.workers', [
            'codex' => ['max_files' => 10, 'max_loc' => 1200, 'tier' => 'multi_file_large'],
            'sonnet' => ['max_files' => 2, 'max_loc' => 200, 'tier' => 'small_scope'],
        ]);

        $registry = new AtlasMaestroWorkerPreferenceRegistry();
        $codex = $registry->inspect('codex');

        self::assertSame(10, $codex['max_files']);
        self::assertSame(1200, $codex['max_loc']);
        self::assertSame('multi_file_large', $codex['tier']);
    }

    public function test_unknown_client_id_returns_neutral_default_byte_identically(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $a = $registry->inspect('never-registered-1');
        $b = $registry->inspect('never-registered-2');

        self::assertSame(AtlasMaestroWorkerPreferenceRegistry::DEFAULT_PROFILE, $a);
        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_register_overrides_existing_entry(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry(['codex' => ['max_files' => 1, 'max_loc' => 10, 'tier' => 't']]);
        $registry->register('codex', ['max_files' => 20, 'max_loc' => 5000, 'tier' => 'updated']);

        $r = $registry->inspect('codex');
        self::assertSame(20, $r['max_files']);
        self::assertSame('updated', $r['tier']);
    }

    public function test_all_returns_declared_entries_sorted_by_client_id(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'zeta' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 't'],
            'alpha' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 't'],
            'mu' => ['max_files' => 1, 'max_loc' => 1, 'tier' => 't'],
        ]);

        self::assertSame(['alpha', 'mu', 'zeta'], array_keys($registry->all()));
    }

    public function test_registry_never_branches_on_platform_strings_in_source(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Maestro/Personalization/AtlasMaestroWorkerPreferenceRegistry.php'));
        foreach (['MarketingDomain', 'Aaeos', 'Forge\\', 'claude_code', 'codex_oauth', 'openai'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "registry must not reference {$forbidden}");
        }
    }

    public function test_inspect_normalizes_partial_records_to_full_profile(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'partial' => ['max_files' => 5], // tier + max_loc omitted
        ]);
        $r = $registry->inspect('partial');

        self::assertSame(5, $r['max_files']);
        self::assertSame(AtlasMaestroWorkerPreferenceRegistry::DEFAULT_PROFILE['max_loc'], $r['max_loc']);
        self::assertSame(AtlasMaestroWorkerPreferenceRegistry::DEFAULT_PROFILE['tier'], $r['tier']);
    }
}
