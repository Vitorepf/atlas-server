<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiQualitativeLevelsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
    }

    public function test_command_reports_qualitative_level_read_model_as_json(): void
    {
        $exit = Artisan::call('atlas:ai:qualitative-levels', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame(24, $payload['hours']);
        $this->assertSame('atlas.qualitative_levels.v1', data_get($payload, 'qualitative_levels.schema_version'));
        $this->assertSame('P1', data_get($payload, 'qualitative_levels.current_level'));
        $this->assertSame('P2', data_get($payload, 'qualitative_levels.next_level'));
        $this->assertTrue(data_get($payload, 'qualitative_levels.rules.read_model_only'));
        $this->assertTrue(data_get($payload, 'qualitative_levels.rules.no_behavior_change'));
        $this->assertSame('atlas.qualitative_levels.p6_presence_readiness.v1', data_get($payload, 'qualitative_levels.advanced_readiness.p6_presence_eclipse.schema_version'));
        $this->assertSame('started_not_promotable', data_get($payload, 'qualitative_levels.advanced_readiness.p6_presence_eclipse.status'));
        $this->assertFalse(data_get($payload, 'qualitative_levels.advanced_readiness.p6_presence_eclipse.promotion_allowed'));
        $this->assertContains('no_surveillance_default', data_get($payload, 'qualitative_levels.advanced_readiness.p6_presence_eclipse.implemented_evidence.safety_properties', []));
        $this->assertContains('broader_environment_presence_surfaces', data_get($payload, 'qualitative_levels.advanced_readiness.p6_presence_eclipse.missing_evidence', []));
        $this->assertSame('atlas.qualitative_levels.p7_longitudinal_readiness.v1', data_get($payload, 'qualitative_levels.advanced_readiness.p7_longitudinal_memory.schema_version'));
        $this->assertSame('roadmap_only_not_promotable', data_get($payload, 'qualitative_levels.advanced_readiness.p7_longitudinal_memory.status'));
        $this->assertFalse(data_get($payload, 'qualitative_levels.advanced_readiness.p7_longitudinal_memory.promotion_allowed'));
        $this->assertContains('years_scale_history', data_get($payload, 'qualitative_levels.advanced_readiness.p7_longitudinal_memory.missing_evidence', []));
        $this->assertContains(
            'Qualitative levels require Evidence Ledger signals.',
            data_get($payload, 'qualitative_levels.next_level_blockers'),
        );
        $this->assertSame('missing', data_get($payload, 'qualitative_levels.missing_gates.0.status'));
    }

    public function test_command_human_output_includes_gates(): void
    {
        $exit = Artisan::call('atlas:ai:qualitative-levels', [
            '--hours' => 24,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Qualitative Levels', $output);
        $this->assertStringContainsString('documentation_governance', $output);
        $this->assertStringContainsString('evidence_ledger_available', $output);
        $this->assertStringContainsString('Read model only', $output);
    }
}
