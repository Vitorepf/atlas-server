<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Personalization;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroWorkerPreferenceRegistryTest extends TestCase
{
    // ── AC: malformed preference keys or non-list task families are rejected ──

    public function test_register_rejects_non_scalar_max_files(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $accepted = $registry->register('w', ['max_files' => ['nested' => 'array']]);

        $this->assertFalse($accepted);
        $this->assertSame(AtlasMaestroWorkerPreferenceRegistry::DEFAULT_PROFILE, $registry->inspect('w'));
    }

    public function test_register_rejects_non_list_task_families(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $accepted = $registry->register('w', ['task_families' => ['a' => 'refactor', 'b' => 'bugfix']]);

        $this->assertFalse($accepted);
    }

    public function test_register_rejects_task_families_containing_non_strings(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $accepted = $registry->register('w', ['task_families' => ['refactor', 42]]);

        $this->assertFalse($accepted);
    }

    public function test_register_accepts_valid_list_task_families(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $accepted = $registry->register('w', ['task_families' => ['refactor', 'bugfix']]);

        $this->assertTrue($accepted);
        $this->assertSame(['refactor', 'bugfix'], $registry->inspect('w')['task_families']);
    }

    public function test_validate_reports_all_reasons_for_multiple_malformed_keys(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $result = $registry->validate(['max_files' => ['x'], 'tier' => ['y']]);

        $this->assertFalse($result['valid']);
        $this->assertContains('max_files_must_be_scalar', $result['reasons']);
        $this->assertContains('tier_must_be_scalar', $result['reasons']);
    }

    public function test_config_seeded_malformed_entry_is_skipped_not_crashed(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'good' => ['max_files' => 5],
            'bad' => ['max_files' => ['nested']],
        ]);

        $this->assertSame(5, $registry->inspect('good')['max_files']);
        $this->assertSame(AtlasMaestroWorkerPreferenceRegistry::DEFAULT_PROFILE, $registry->inspect('bad'));
    }

    // ── AC: registerWithMerge preserves existing valid preferences while adding new families ──

    public function test_register_with_merge_preserves_existing_task_families_and_adds_new(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'w' => ['task_families' => ['refactor'], 'priority' => 'medium', 'source' => 'config'],
        ]);

        $accepted = $registry->registerWithMerge('w', ['task_families' => ['bugfix'], 'priority' => 'medium', 'source' => 'config']);

        $this->assertTrue($accepted);
        $this->assertSame(['bugfix', 'refactor'], $registry->inspect('w')['task_families']);
    }

    public function test_register_with_merge_union_survives_even_when_incoming_priority_loses(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'w' => ['max_files' => 10, 'task_families' => ['refactor'], 'priority' => 'high', 'source' => 'operator'],
        ]);

        $registry->registerWithMerge('w', ['max_files' => 1, 'task_families' => ['bugfix'], 'priority' => 'low', 'source' => 'runtime']);

        $r = $registry->inspect('w');
        $this->assertSame(10, $r['max_files']); // higher priority scalar field kept
        $this->assertSame(['bugfix', 'refactor'], $r['task_families']); // family still added
    }

    public function test_register_with_merge_rejects_malformed_incoming_and_preserves_existing(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'w' => ['max_files' => 5, 'task_families' => ['refactor']],
        ]);

        $accepted = $registry->registerWithMerge('w', ['task_families' => 'not-a-list']);

        $this->assertFalse($accepted);
        $this->assertSame(['refactor'], $registry->inspect('w')['task_families']);
        $this->assertSame(5, $registry->inspect('w')['max_files']);
    }

    public function test_register_with_merge_no_existing_entry_creates_with_given_families(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $registry->registerWithMerge('fresh', ['task_families' => ['observability']]);

        $this->assertSame(['observability'], $registry->inspect('fresh')['task_families']);
    }

    // ── AC: inspect returns provenance and normalized preferences without leaking raw data ──

    public function test_inspect_includes_provenance(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'w' => ['source' => 'operator', 'priority' => 'high', 'registered_at' => 1000],
        ]);

        $provenance = $registry->inspect('w')['provenance'];

        $this->assertSame('operator', $provenance['source']);
        $this->assertSame('high', $provenance['priority']);
        $this->assertSame(1000, $provenance['registered_at']);
    }

    public function test_inspect_never_leaks_unknown_raw_keys(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([
            'w' => ['max_files' => 3, 'secret_provider_token' => 'sk-should-never-appear'],
        ]);

        $r = $registry->inspect('w');

        $this->assertArrayNotHasKey('secret_provider_token', $r);
        $this->assertStringNotContainsString('sk-should-never-appear', json_encode($r));
    }

    public function test_default_profile_has_no_provenance_key(): void
    {
        $registry = new AtlasMaestroWorkerPreferenceRegistry([]);

        $this->assertArrayNotHasKey('provenance', $registry->inspect('never-registered'));
    }
}
