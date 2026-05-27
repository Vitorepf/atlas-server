<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService;
use RuntimeException;
use Tests\TestCase;

/**
 * Read-only contract tests for the Agentic Engineering OS Area Finding Engine
 * (Slice 2, AP-717). All inputs are synthetic fixtures so the classification
 * core is deterministic and side-effect free; the real gathering seams are only
 * exercised by the source-unavailability test.
 */
class AgenticEngineeringOsFindingEngineServiceTest extends TestCase
{
    private function service(): AgenticEngineeringOsFindingEngineService
    {
        return app(AgenticEngineeringOsFindingEngineService::class);
    }

    /**
     * One synthetic input crafted to trigger every one of the ten finding types.
     *
     * @return array<string,mixed>
     */
    private function allTypesInput(): array
    {
        return [
            'existing_paths' => [], // nothing exists -> every ref check fails
            'docs' => [[
                'path' => 'docs/engineering-knowledge-base/synthetic-doc.md',
                'status' => 'future',
                'requires_evidence' => true,
                'evidence' => ['app/Services/Ai/Synthetic/Missing.php'],
                'related_paths' => ['app/Services/Ai/Synthetic/Gone.php'],
                'repo_paths' => [],
                'depends_on' => [],
                'flows_to' => ['ghost-owner-doc'],
                'required_tests' => ['tests/Unit/Ai/Synthetic/GoneTest.php', 'php artisan something'],
                'quality_gates' => ['gate-a'],
                'has_ap' => false,
                'replay_expected' => true,
                'desktop_expected' => true,
                'dev_forge_routing_expected' => true,
            ]],
            'service_files' => [
                'app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php',
                'app/Services/Ai/SoftwareCompany/NightShiftAreaFocusLoopService.php',
                'app/Services/Ai/Synthetic/SoloService.php',
            ],
            'test_files' => [],
        ];
    }

    public function test_emits_report_schema_and_envelope(): void
    {
        $report = $this->service()->scan([
            'docs' => [],
            'service_files' => [],
            'test_files' => [],
            'existing_paths' => [],
        ]);

        $this->assertSame(AgenticEngineeringOsFindingEngineService::REPORT_SCHEMA, $report['schema_version']);
        foreach (['status', 'mode', 'area_id', 'finding_count', 'findings', 'type_summary', 'route_summary', 'severity_summary', 'source_summary', 'blockers', 'claim_policy', 'report_hash', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing report key {$key}");
        }
        $this->assertSame('read_only', $report['mode']);
        $this->assertSame('agentic_engineering_os', $report['area_id']);
        $this->assertSame('ready', $report['status']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
    }

    public function test_produces_all_ten_finding_types(): void
    {
        $report = $this->service()->scan($this->allTypesInput());

        $summary = $report['type_summary'];
        foreach (AgenticEngineeringOsFindingEngineService::FINDING_TYPES as $type) {
            $this->assertArrayHasKey($type, $summary);
            $this->assertGreaterThanOrEqual(1, $summary[$type], "expected at least one '{$type}' finding");
        }
    }

    public function test_every_finding_carries_the_finding_schema_and_read_only_flags(): void
    {
        $report = $this->service()->scan($this->allTypesInput());

        $this->assertNotEmpty($report['findings']);
        foreach ($report['findings'] as $finding) {
            $this->assertSame(AgenticEngineeringOsFindingEngineService::FINDING_SCHEMA, $finding['schema_version']);
            $this->assertContains($finding['finding_type'], AgenticEngineeringOsFindingEngineService::FINDING_TYPES);
            $this->assertContains($finding['severity'], ['critical', 'high', 'medium', 'low']);
            $this->assertSame($finding['severity'], $finding['risk_level']);
            $this->assertContains($finding['confidence'], ['high', 'medium', 'low']);
            $this->assertIsFloat($finding['confidence_score']);
            $this->assertContains($finding['route_hint'], [
                'self_directed_evolution', 'atlas_dev', 'forge', 'operator_review',
            ]);
            $this->assertFalse($finding['safe_to_autofix']);
            $this->assertTrue($finding['requires_operator_review']);
            $this->assertStringStartsWith('aef_', $finding['finding_id']);
            $this->assertStringStartsWith('sha256:', $finding['finding_hash']);
        }
    }

    public function test_route_hints_match_finding_types(): void
    {
        $byType = [];
        foreach ($this->service()->scan($this->allTypesInput())['findings'] as $finding) {
            $byType[$finding['finding_type']] = $finding['route_hint'];
        }

        $this->assertSame('self_directed_evolution', $byType['self_directed_spec_gap']);
        $this->assertSame('self_directed_evolution', $byType['docs_stale']);
        $this->assertSame('atlas_dev', $byType['missing_test']);
        $this->assertSame('forge', $byType['duplicate_runtime_risk']);
        $this->assertSame('operator_review', $byType['missing_evidence']);
    }

    public function test_sensitive_keyword_escalates_route_to_operator_review(): void
    {
        $report = $this->service()->scan([
            'existing_paths' => [],
            'service_files' => [],
            'test_files' => [],
            'docs' => [[
                'path' => 'docs/engineering-knowledge-base/auth-secrets-doc.md',
                'status' => 'building',
                'has_ap' => false,
            ]],
        ]);

        // self_directed_spec_gap normally routes to self_directed_evolution, but the
        // sensitive keyword (auth/secret) in the title escalates it.
        $finding = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['finding_type'] === 'self_directed_spec_gap',
        ))[0];
        $this->assertSame('operator_review', $finding['route_hint']);
    }

    public function test_duplicate_runtime_risk_requires_two_distinct_dirs(): void
    {
        // Same capability stem but only one directory -> no duplicate finding.
        $report = $this->service()->scan([
            'docs' => [],
            'existing_paths' => [],
            'test_files' => ['tests/Unit/Ai/X/AreaFocusLoopReadModelServiceTest.php', 'tests/Unit/Ai/X/NightShiftAreaFocusLoopServiceTest.php'],
            'service_files' => [
                'app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php',
                'app/Services/Ai/NightShift/NightShiftAreaFocusLoopService.php',
            ],
        ]);

        $this->assertSame(0, $report['type_summary']['duplicate_runtime_risk']);
    }

    public function test_duplicate_runtime_risk_detected_across_namespaces(): void
    {
        $report = $this->service()->scan([
            'docs' => [],
            'existing_paths' => [],
            'test_files' => [
                'tests/Unit/Ai/NightShift/AreaFocusLoopReadModelServiceTest.php',
                'tests/Unit/Ai/SoftwareCompany/NightShiftAreaFocusLoopServiceTest.php',
            ],
            'service_files' => [
                'app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php',
                'app/Services/Ai/SoftwareCompany/NightShiftAreaFocusLoopService.php',
            ],
        ]);

        $this->assertSame(1, $report['type_summary']['duplicate_runtime_risk']);
        $dup = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['finding_type'] === 'duplicate_runtime_risk',
        ))[0];
        $this->assertSame('forge', $dup['route_hint']);
        $this->assertSame('high', $dup['severity']);
        $this->assertCount(2, $dup['affected_paths']);
    }

    public function test_missing_test_detected_and_satisfied(): void
    {
        $report = $this->service()->scan([
            'docs' => [],
            'existing_paths' => [],
            'service_files' => ['app/Services/Ai/Foo/BarService.php', 'app/Services/Ai/Foo/BazService.php'],
            'test_files' => ['tests/Unit/Ai/Foo/BarServiceTest.php'],
        ]);

        $missing = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['finding_type'] === 'missing_test',
        ));
        $this->assertCount(1, $missing);
        $this->assertcontains('app/Services/Ai/Foo/BazService.php', $missing[0]['affected_paths']);
    }

    public function test_dedupe_is_deterministic(): void
    {
        $report = $this->service()->scan([
            'docs' => [],
            'existing_paths' => [],
            'test_files' => [],
            // duplicate service file entries must collapse to one missing_test finding.
            'service_files' => [
                'app/Services/Ai/Foo/BarService.php',
                'app/Services/Ai/Foo/BarService.php',
            ],
        ]);

        $this->assertSame(1, $report['type_summary']['missing_test']);
    }

    public function test_report_hash_is_deterministic_for_same_input(): void
    {
        $input = $this->allTypesInput();
        $a = $this->service()->scan($input);
        $b = $this->service()->scan($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['findings'], $b['findings']);
    }

    public function test_unsupported_area_is_blocked(): void
    {
        $report = $this->service()->scan(['area_id' => 'marketing_company']);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['finding_count']);
        $this->assertSame('unsupported_area', $report['blockers'][0]['reason']);
    }

    public function test_claim_policy_enforces_read_only_and_no_new_os(): void
    {
        $policy = $this->service()->scan(['docs' => [], 'service_files' => [], 'test_files' => [], 'existing_paths' => []])['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['writes_code']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['creates_spec']);
        $this->assertFalse($policy['creates_doc']);
        $this->assertFalse($policy['runs_heavy_commands']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['is_new_os']);
        $this->assertTrue($policy['self_directed_evolution_remains_gap_owner']);
        $this->assertTrue($policy['operator_review_required']);
    }

    public function test_limit_caps_finding_count(): void
    {
        $report = $this->service()->scan($this->allTypesInput() + ['limit' => 3]);

        $this->assertSame(3, $report['finding_count']);
    }

    public function test_docs_source_unavailable_becomes_blocker(): void
    {
        $svc = new class extends AgenticEngineeringOsFindingEngineService
        {
            protected function gatherDocs(): array
            {
                throw new RuntimeException('docs unreadable');
            }
        };

        // No `docs` override -> the seam runs and throws -> blocked + blocker.
        $report = $svc->scan(['service_files' => [], 'test_files' => []]);

        $this->assertSame('blocked', $report['status']);
        $reasons = array_map(static fn (array $b): string => $b['reason'], $report['blockers']);
        $this->assertContains('source_unavailable', $reasons);
    }
}
