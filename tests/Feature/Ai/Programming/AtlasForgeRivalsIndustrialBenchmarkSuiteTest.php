<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsIndustrialBenchmarkSuiteService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasForgeRivalsIndustrialBenchmarkSuiteTest extends TestCase
{
    public function test_industrial_presets_exist_with_required_counts(): void
    {
        $payload = $this->invoke('industrial-suite');

        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.forge.rivals.industrial_benchmark_suite.v1', $payload['schema_version']);
        $this->assertSame(50, $payload['presets']['industrial-50']['count']);
        $this->assertSame(100, $payload['presets']['industrial-100']['count']);
        $this->assertSame(200, $payload['presets']['industrial-200']['count']);

        foreach ([
            'ambiguous-bugs',
            'multi-day-refactors',
            'incident-response',
            'product-security-migrations',
            'statistical-repeat',
        ] as $preset) {
            $this->assertGreaterThanOrEqual(50, $payload['presets'][$preset]['count']);
            $this->assertTrue($payload['presets'][$preset]['ok']);
        }
    }

    public function test_cases_action_exposes_industrial_case_metadata(): void
    {
        $payload = $this->invoke('cases', ['--case-set' => 'industrial-50']);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(50, $payload['count']);
        $case = $payload['cases'][0];

        foreach ([
            'case_id',
            'category',
            'difficulty',
            'task_type',
            'objective',
            'acceptance_criteria',
            'evidence_requirements',
            'invalid_if',
            'expected_changed_files',
            'scoring_dimensions',
            'oracle',
            'hidden_oracle_metadata',
        ] as $field) {
            $this->assertArrayHasKey($field, $case);
            $this->assertNotEmpty($case[$field]);
        }

        $this->assertSame('atlas-forge-rivals-industrial-benchmark-suite-v1', $case['industrial_suite']);
        $this->assertContains('missing_evidence_pack', $case['invalid_if']);
        $this->assertContains('missing_replay', $case['invalid_if']);
        $this->assertContains('missing_case_scorecard', $case['invalid_if']);
    }

    public function test_specialized_presets_identify_required_domains(): void
    {
        $ambiguous = $this->invoke('cases', ['--case-set' => 'ambiguous-bugs']);
        $multiDay = $this->invoke('cases', ['--case-set' => 'multi-day-refactors']);
        $incident = $this->invoke('cases', ['--case-set' => 'incident-response']);
        $productSecurityMigration = $this->invoke('cases', ['--case-set' => 'product-security-migrations']);

        $this->assertSame(50, $ambiguous['count']);
        foreach ($ambiguous['cases'] as $case) {
            $this->assertContains('ambiguous_bug', $case['industrial_domains']);
        }

        $this->assertSame(50, $multiDay['count']);
        foreach ($multiDay['cases'] as $case) {
            $this->assertContains('multi_day_task', $case['industrial_domains']);
        }

        $this->assertSame(50, $incident['count']);
        foreach ($incident['cases'] as $case) {
            $this->assertContains('incident_rollback', $case['industrial_domains']);
        }

        $domains = [];
        foreach ($productSecurityMigration['cases'] as $case) {
            $domains = array_merge($domains, $case['industrial_domains']);
        }
        $this->assertContains('product', $domains);
        $this->assertContains('security', $domains);
        $this->assertContains('migration', $domains);
    }

    public function test_quick_and_release_cannot_pass_as_industrial_strong_claims(): void
    {
        $suite = app(AtlasForgeRivalsIndustrialBenchmarkSuiteService::class);

        $quick = $suite->strongClaimReadiness([
            'preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_QUICK,
            'valid_cases' => 3,
        ]);
        $release = $suite->strongClaimReadiness([
            'preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE,
            'valid_cases' => 40,
        ]);

        $this->assertFalse($quick['ready_for_strong_benchmark_claim']);
        $this->assertContains('minimum_50_valid_cases', $quick['missing_gates']);
        $this->assertFalse($release['ready_for_strong_benchmark_claim']);
        $this->assertContains('minimum_50_valid_cases', $release['missing_gates']);
    }

    public function test_strong_benchmark_claim_blocks_without_replay_evidence_scorecard_repetition_and_confidence(): void
    {
        $payload = $this->invoke('industrial-suite', ['--preset' => 'statistical-repeat']);
        $claim = $payload['claim_readiness'];

        $this->assertFalse($claim['ready_for_strong_benchmark_claim']);
        $this->assertContains('evidence_pack_complete', $claim['missing_gates']);
        $this->assertContains('replay_green', $claim['missing_gates']);
        $this->assertContains('scorecard_per_case', $claim['missing_gates']);
        $this->assertContains('statistical_repetition_when_required', $claim['missing_gates']);
        $this->assertContains('confidence_explicit', $claim['missing_gates']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertFalse($payload['external_rivals_certification_unlocked']);
    }

    public function test_statistical_repeat_declares_repetition_metadata(): void
    {
        $payload = $this->invoke('cases', ['--case-set' => 'statistical-repeat']);

        $this->assertGreaterThanOrEqual(50, $payload['count']);
        foreach ($payload['cases'] as $case) {
            $this->assertSame(3, $case['statistical_repeat']['required_repetitions']);
            $this->assertContains('flakiness_repeat', $case['industrial_domains']);
        }
    }

    public function test_run_battery_dry_run_accepts_industrial_preset_without_provider_call(): void
    {
        $payload = $this->invoke('run-battery', [
            '--preset' => 'industrial-50',
            '--mode' => 'local_fake',
            '--dry-run' => true,
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('industrial-50', $payload['preset']);
        $this->assertSame('industrial-50', $payload['case_set']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_industrial_certification_is_in_audit_and_keeps_external_certification_blocked(): void
    {
        $payload = $this->invoke('audit');

        $this->assertArrayHasKey(
            'atlas_forge_rivals_industrial_benchmark_suite_certification',
            $payload['certifications'],
        );
        $cert = $payload['certifications']['atlas_forge_rivals_industrial_benchmark_suite_certification'];
        $this->assertSame('available', $cert['status']);
        $this->assertFalse($cert['external_rivals_certification_unlocked']);
        $this->assertFalse($cert['external_provider_call']);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function invoke(string $action, array $options = []): array
    {
        Artisan::call('atlas:forge:rivals', array_merge([
            'action' => $action,
            '--json' => true,
        ], $options));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, "{$action} --json must emit JSON");

        return $payload;
    }
}
