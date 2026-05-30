<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use Tests\TestCase;

/**
 * Read-only contract tests for the Area Focus Deep Finding Engine (Slice 11,
 * AP-748). All inputs are synthetic: a `base_report` override injects AP-717
 * structural findings and per-check overrides drive the focus-scoped deep
 * checks, so the enrichment core is deterministic and side-effect free. Record
 * mode is exercised against a temp storage root.
 */
class AreaFocusDeepFindingEngineServiceTest extends TestCase
{
    private function service(): AreaFocusDeepFindingEngineService
    {
        return app(AreaFocusDeepFindingEngineService::class);
    }

    /**
     * A synthetic AP-717 structural report with findings spanning several types
     * so the kind/owner mapping is exercised.
     *
     * @return array<string,mixed>
     */
    private function baseReport(): array
    {
        return [
            'schema_version' => AgenticEngineeringOsFindingEngineService::REPORT_SCHEMA,
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'finding_count' => 4,
            'findings' => [
                $this->structural('missing_evidence', 'critical', 'operator_review', 'Missing evidence for dev flow', ['docs/engineering-knowledge-base/atlas-forge-operating-system.md']),
                $this->structural('missing_test', 'medium', 'atlas_dev', 'Missing test for AreaFocusDevForgeRouterService', ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php']),
                $this->structural('duplicate_runtime_risk', 'high', 'forge', 'Duplicate runtime risk · areafocusloop', ['app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php', 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php']),
                $this->structural('docs_stale', 'low', 'self_directed_evolution', 'Stale references in some-doc', ['docs/engineering-knowledge-base/some-doc.md']),
            ],
        ];
    }

    /**
     * @param  list<string>  $affected
     * @return array<string,mixed>
     */
    private function structural(string $type, string $severity, string $route, string $title, array $affected): array
    {
        $hash = 'sha256:'.hash('sha256', $type.'|'.$title);

        return [
            'schema_version' => AgenticEngineeringOsFindingEngineService::FINDING_SCHEMA,
            'area_id' => 'agentic_engineering_os',
            'finding_type' => $type,
            'title' => $title,
            'detail' => $title.' detail.',
            'severity' => $severity,
            'risk_level' => $severity,
            'confidence' => 'high',
            'confidence_score' => 0.9,
            'route_hint' => $route,
            'evidence_refs' => ['evidence:'.$type],
            'affected_paths' => $affected,
            'recommended_action' => 'Operator review required for '.$type.'.',
            'source' => $type,
            'safe_to_autofix' => false,
            'requires_operator_review' => true,
            'finding_id' => 'aef_'.substr(hash('sha256', $type.$title), 0, 16),
            'finding_hash' => $hash,
            'priority_score' => 300,
        ];
    }

    /**
     * Default deep-check overrides: focus owner docs all present, wiring chain
     * complete — so only the injected structural findings surface.
     *
     * @return array<string,mixed>
     */
    private function quietDeepChecks(bool $skipFactoryBacklogQuality = true): array
    {
        return [
            'skip_factory_backlog_quality' => $skipFactoryBacklogQuality,
            'skip_atlas_dev_factory_runtime_bottlenecks' => true,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => true,
            'focus_owner_docs' => [
                'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md' => true,
                'docs/engineering-knowledge-base/atlas-forge-operating-system.md' => true,
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md' => true,
                'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md' => true,
            ],
            'wiring_chain' => [
                'release_queue' => true,
                'consumption_gate' => true,
                'owner_runtime_execution' => true,
                'owner_sandbox_run' => true,
                'result_bridge' => true,
            ],
        ];
    }

    public function test_scan_returns_structured_report_envelope(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks());

        $this->assertSame(AreaFocusDeepFindingEngineService::REPORT_SCHEMA, $report['schema_version']);
        foreach (['scan_id', 'area_id', 'focus', 'generated_at', 'findings', 'status', 'mode', 'kind_summary', 'owner_summary', 'severity_summary', 'focus_summary', 'claim_policy', 'scan_hash'] as $key) {
            $this->assertArrayHasKey($key, $report, "missing report key {$key}");
        }
        $this->assertSame('agentic_engineering_os', $report['area_id']);
        $this->assertSame('dev_forge', $report['focus']);
        $this->assertSame('dry_run', $report['mode']);
        $this->assertSame('ready', $report['status']);
        $this->assertStringStartsWith('afds_', $report['scan_id']);
        $this->assertStringStartsWith('sha256:', $report['scan_hash']);
        $this->assertSame(4, $report['finding_count']);
    }

    public function test_every_finding_has_required_fields_and_owner_candidate(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks());

        $this->assertNotEmpty($report['findings']);
        foreach ($report['findings'] as $finding) {
            foreach (['finding_id', 'title', 'severity', 'kind', 'owner_candidate', 'evidence_refs', 'affected_files', 'affected_docs', 'why_it_matters', 'proposed_spec_title', 'proposed_next_action', 'confidence', 'auto_execution_allowed', 'operator_review_required', 'spec_seed'] as $key) {
                $this->assertArrayHasKey($key, $finding, "finding missing {$key}");
            }
            $this->assertSame(AreaFocusDeepFindingEngineService::FINDING_SCHEMA, $finding['schema_version']);
            $this->assertContains($finding['severity'], ['critical', 'high', 'medium', 'low']);
            $this->assertContains($finding['kind'], AreaFocusDeepFindingEngineService::KINDS);
            $this->assertContains($finding['owner_candidate'], AreaFocusDeepFindingEngineService::OWNER_CANDIDATES);
            $this->assertFalse($finding['auto_execution_allowed']);
            $this->assertTrue($finding['operator_review_required']);
            $this->assertStringStartsWith('afdf_', $finding['finding_id']);
        }
    }

    public function test_owner_candidate_mapping(): void
    {
        $byKind = [];
        foreach ($this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks())['findings'] as $finding) {
            $byKind[$finding['origin_type']] = $finding['owner_candidate'];
        }

        $this->assertSame('evidence', $byKind['missing_evidence']);
        $this->assertSame('atlas_dev', $byKind['missing_test']);
        $this->assertSame('forge', $byKind['duplicate_runtime_risk']);
        $this->assertSame('self_directed_evolution', $byKind['docs_stale']);
    }

    public function test_kind_mapping(): void
    {
        $byOrigin = [];
        foreach ($this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks())['findings'] as $finding) {
            $byOrigin[$finding['origin_type']] = $finding['kind'];
        }

        $this->assertSame('risk', $byOrigin['missing_evidence']);
        $this->assertSame('test', $byOrigin['missing_test']);
        $this->assertSame('risk', $byOrigin['duplicate_runtime_risk']);
        $this->assertSame('doc', $byOrigin['docs_stale']);
    }

    public function test_findings_are_ordered_by_severity_then_focus(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks());
        $severities = array_map(static fn (array $f): string => (string) $f['severity'], $report['findings']);

        // critical (missing_evidence) must rank first; low (docs_stale) last.
        $this->assertSame('critical', $severities[0]);
        $this->assertSame('low', $severities[array_key_last($severities)]);
    }

    public function test_dedupe_collapses_identical_findings(): void
    {
        $base = $this->baseReport();
        // Append an exact duplicate of the first structural finding.
        $base['findings'][] = $base['findings'][0];

        $report = $this->service()->scan(['base_report' => $base] + $this->quietDeepChecks());

        $missingEvidence = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['origin_type'] === 'missing_evidence',
        ));
        $this->assertCount(1, $missingEvidence);
    }

    public function test_spec_seed_is_self_directed_evolution_compatible(): void
    {
        $finding = $this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks())['findings'][0];
        $seed = $finding['spec_seed'];

        $this->assertSame(AreaFocusDeepFindingEngineService::SPEC_SEED_SCHEMA, $seed['schema_version']);
        $this->assertSame($finding['finding_hash'], $seed['candidate_hash']);
        $this->assertStringStartsWith('gapc_', $seed['candidate_id']);
        foreach (['gap_kind', 'source_owner', 'title', 'rationale', 'risk_level', 'evidence_refs', 'owner_doc_refs'] as $key) {
            $this->assertArrayHasKey($key, $seed, "spec_seed missing {$key}");
        }
        $this->assertTrue($seed['proposal_only']);
    }

    public function test_focus_owner_doc_missing_is_detected(): void
    {
        $overrides = $this->quietDeepChecks();
        $overrides['focus_owner_docs']['docs/engineering-knowledge-base/atlas-forge-operating-system.md'] = false;

        $report = $this->service()->scan(['base_report' => ['findings' => []]] + $overrides);

        $docFinding = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['origin_type'] === 'focus_owner_doc_missing',
        ));
        $this->assertCount(1, $docFinding);
        $this->assertSame('doc', $docFinding[0]['kind']);
        $this->assertSame('self_directed_evolution', $docFinding[0]['owner_candidate']);
        $this->assertSame('high', $docFinding[0]['severity']);
    }

    public function test_handoff_executor_wiring_gap_is_detected(): void
    {
        $overrides = $this->quietDeepChecks();
        $overrides['wiring_chain']['result_bridge'] = false;

        $report = $this->service()->scan(['base_report' => ['findings' => []]] + $overrides);

        $wiring = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['origin_type'] === 'handoff_executor_wiring_gap',
        ));
        $this->assertCount(1, $wiring);
        $this->assertSame('gap', $wiring[0]['kind']);
        $this->assertSame('forge', $wiring[0]['owner_candidate']);
    }

    public function test_complete_wiring_chain_yields_no_gap(): void
    {
        $report = $this->service()->scan(['base_report' => ['findings' => []]] + $this->quietDeepChecks());

        $wiring = array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['origin_type'] === 'handoff_executor_wiring_gap',
        );
        $this->assertCount(0, $wiring);
        $this->assertTrue($report['source_summary']['wiring_chain']['chain_complete']);
    }

    public function test_max_findings_caps_and_flags(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport(), 'max_findings' => 2] + $this->quietDeepChecks());

        $this->assertSame(2, $report['finding_count']);
        $this->assertTrue($report['capped']);
    }

    public function test_unsupported_area_is_blocked(): void
    {
        $report = $this->service()->scan(['area_id' => 'marketing_company']);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame(0, $report['finding_count']);
        $this->assertSame('unsupported_area', $report['blockers'][0]['reason']);
    }

    public function test_unsupported_focus_is_blocked(): void
    {
        $report = $this->service()->scan(['focus' => 'marketing_flow']);

        $this->assertSame('blocked', $report['status']);
        $this->assertSame('unsupported_focus', $report['blockers'][0]['reason']);
    }

    public function test_scan_hash_is_deterministic_for_same_input(): void
    {
        $input = ['base_report' => $this->baseReport()] + $this->quietDeepChecks();
        $a = $this->service()->scan($input);
        $b = $this->service()->scan($input);

        $this->assertSame($a['scan_hash'], $b['scan_hash']);
        $this->assertSame($a['scan_id'], $b['scan_id']);
        $this->assertSame($a['findings'], $b['findings']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $dir = sys_get_temp_dir().'/atlas_deep_scan_test_'.uniqid();
        $service = $this->service();
        $service->setStorageRootForTesting($dir);

        $report = $service->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks());

        $this->assertSame('dry_run', $report['mode']);
        $this->assertArrayNotHasKey('record', $report);
        $this->assertFileDoesNotExist($service->scanFilePath('agentic_engineering_os'));
    }

    public function test_record_mode_appends_idempotently(): void
    {
        $dir = sys_get_temp_dir().'/atlas_deep_scan_test_'.uniqid();
        $service = $this->service();
        $service->setStorageRootForTesting($dir);

        $input = ['base_report' => $this->baseReport(), 'record' => true] + $this->quietDeepChecks();

        $first = $service->scan($input);
        $this->assertSame('record', $first['mode']);
        $this->assertTrue($first['record']['recorded']);

        $path = $service->scanFilePath('agentic_engineering_os');
        $this->assertFileExists($path);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        // Same input -> same scan_id -> idempotent, no duplicate append.
        $second = $service->scan($input);
        $this->assertFalse($second['record']['recorded']);
        $this->assertTrue($second['record']['idempotent']);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        // Replay by id round-trips the recorded scan.
        $replayed = $service->replay($first['scan_id']);
        $this->assertNotNull($replayed);
        $this->assertSame($first['scan_id'], $replayed['scan_id']);

        @unlink($path);
        @rmdir($dir);
    }

    public function test_claim_policy_enforces_read_only_and_no_provider(): void
    {
        $policy = $this->service()->scan(['base_report' => ['findings' => []]] + $this->quietDeepChecks())['claim_policy'];

        $this->assertTrue($policy['read_only_over_repo']);
        $this->assertFalse($policy['writes_code']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['opens_branch']);
        $this->assertFalse($policy['drafts_spec']);
        $this->assertFalse($policy['auto_execution_allowed']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['is_new_os']);
        $this->assertTrue($policy['composes_ap717_structural_engine']);
        $this->assertTrue($policy['self_directed_evolution_remains_gap_owner']);
    }

    public function test_factory_backlog_rejects_docs_only_and_missing_evidence(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport()] + $this->quietDeepChecks(false));
        $reasons = array_column($report['factory_backlog_quality']['rejections'] ?? [], 'rejection_reason');

        $this->assertContains('factory_backlog_rejects_docs_or_low_leverage_evidence', $reasons);
        $this->assertGreaterThanOrEqual(2, $report['factory_backlog_quality']['rejected_count']);
    }

    public function test_factory_backlog_enriches_executable_missing_test_candidate(): void
    {
        $missingTest = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for AtlasAreaFocusLoopReadModelService',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$missingTest]],
        ] + $this->quietDeepChecks(false));

        $this->assertCount(1, $report['findings']);
        $finding = $report['findings'][0];
        $this->assertTrue($finding['factory_execution_ready']);
        $this->assertSame('atlas_dev', $finding['owner_candidate']);
        $this->assertNotEmpty($finding['allowed_files']);
        $this->assertNotEmpty($finding['tests_required']);
        $this->assertNotEmpty($finding['acceptance']);
        $this->assertStringContainsString('php artisan test', $finding['proposed_next_action']);
        $this->assertGreaterThan(0, $finding['roi_score']);
        $this->assertGreaterThan(0, $finding['execution_readiness_score']);
        $this->assertGreaterThan(0, $finding['factory_leverage_score']);
    }

    public function test_factory_backlog_rejects_already_covered_missing_test(): void
    {
        $covered = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for AreaFocusDevForgeRouterService',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeRouterService.php'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$covered]],
        ] + $this->quietDeepChecks(false));

        $this->assertSame([], $report['findings']);
        $this->assertSame(
            'factory_backlog_rejects_already_covered_by_test',
            $report['factory_backlog_quality']['rejections'][0]['rejection_reason'] ?? null,
        );
    }

    public function test_factory_backlog_rejects_interface_only_false_positive(): void
    {
        $interfaceFinding = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for AreaFocusBranchSandboxMaterializer',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializer.php'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$interfaceFinding]],
        ] + $this->quietDeepChecks(false));

        $this->assertSame(0, $report['finding_count']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(
            1,
            $report['source_summary']['structural_engine']['suppressed_interface_missing_test_count'] ?? null,
        );
    }

    public function test_structural_interface_missing_test_suppressed_before_factory_backlog(): void
    {
        $interfaceFinding = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for AreaFocusBranchSandboxMaterializer',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializer.php'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$interfaceFinding]],
        ] + $this->quietDeepChecks());

        $this->assertSame(0, $report['finding_count']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(
            1,
            $report['source_summary']['structural_engine']['suppressed_interface_missing_test_count'] ?? null,
        );
    }

    public function test_owner_flow_interface_missing_test_suppressed_when_stewardship_service_is_tested(): void
    {
        $interfaceFinding = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for OwnerSandboxRuntimeRunner',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/OwnerSandboxRuntimeRunner.php'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$interfaceFinding]],
        ] + $this->quietDeepChecks(false));

        $this->assertSame(0, $report['finding_count']);
        $this->assertSame([], $report['findings']);
        $this->assertSame(
            1,
            $report['source_summary']['structural_engine']['suppressed_interface_missing_test_count'] ?? null,
        );
    }

    public function test_factory_backlog_promotes_high_roi_candidate_above_docs_stale(): void
    {
        $good = $this->structural(
            'missing_test',
            'medium',
            'atlas_dev',
            'Missing test for AtlasAreaFocusLoopReadModelService',
            ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtlasAreaFocusLoopReadModelService.php'],
        );
        $docs = $this->structural(
            'docs_stale',
            'low',
            'self_directed_evolution',
            'Stale references in some-doc',
            ['docs/engineering-knowledge-base/some-doc.md'],
        );

        $report = $this->service()->scan([
            'base_report' => ['findings' => [$docs, $good]],
        ] + $this->quietDeepChecks(false));

        $this->assertCount(1, $report['findings']);
        $this->assertSame('missing_test', $report['findings'][0]['origin_type']);
    }

    public function test_factory_runtime_coverage_sweep_emits_executable_missing_test_candidates(): void
    {
        // Deterministic + loop-proof fixture: a concrete, untested factory file we
        // create and remove here. Pointing the sweep at real repo classes was stale —
        // the loop legitimately wrote tests for some and the engine correctly suppresses
        // interface-only files (AtlasForgeProviderInvocationDriver is an interface), so
        // those real paths now yield zero findings. A self-owned concrete file guarantees
        // exactly one genuine missing-test candidate regardless of repo evolution.
        $rel = 'app/Services/Ai/Programming/AtlasFactoryRuntimeCoverageProbeService.php';
        $abs = base_path($rel);
        file_put_contents($abs, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services\\Ai\\Programming;\n\nfinal class AtlasFactoryRuntimeCoverageProbeService\n{\n    public function handle(): bool\n    {\n        return true;\n    }\n}\n");

        try {
            $report = $this->service()->scan([
                'base_report' => ['findings' => []],
                'skip_factory_backlog_quality' => false,
                'skip_factory_runtime_coverage' => false,
                'skip_strategic_multiplier_backlog' => true,
                'factory_runtime_coverage_files' => [$rel],
            ] + $this->quietDeepChecks());
        } finally {
            @unlink($abs);
        }

        $this->assertGreaterThanOrEqual(1, $report['finding_count']);
        $this->assertSame('factory_runtime_coverage_sweep', $report['findings'][0]['origin']);
        $this->assertSame('missing_test', $report['findings'][0]['origin_type']);
        $this->assertTrue($report['findings'][0]['auto_execution_allowed']);
        $this->assertFalse($report['findings'][0]['operator_review_required']);
        $this->assertNotEmpty($report['findings'][0]['allowed_files']);
        $this->assertNotEmpty($report['findings'][0]['tests_required']);
        $this->assertStringContainsString('php artisan test', $report['findings'][0]['proposed_next_action']);
        // The single self-owned concrete fixture is a valid runtime candidate, so none are skipped.
        $this->assertSame(0, $report['source_summary']['factory_runtime_coverage']['skipped_non_runtime_count']);
    }

    public function test_factory_runtime_coverage_sweep_recurses_into_nested_runtime_directories(): void
    {
        $nestedRuntime = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchBridge.php';
        $expectedTest = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchBridgeTest.php';

        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
            'factory_runtime_coverage_files' => [$nestedRuntime],
        ] + $this->quietDeepChecks());

        $coverage = $report['source_summary']['factory_runtime_coverage'] ?? [];
        $this->assertTrue($coverage['recursive_scan'] ?? false);
        $this->assertSame(1, $coverage['nested_candidate_count'] ?? null);

        $finding = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => str_contains(
                implode(',', $f['affected_files'] ?? []),
                'ForgeOwnerRuntimeDispatchBridge.php',
            ),
        ));
        $this->assertCount(1, $finding);
        $this->assertSame('factory_runtime_coverage_sweep', $finding[0]['origin']);
        $this->assertTrue($finding[0]['factory_execution_ready'] ?? false);
        $this->assertContains($expectedTest, $finding[0]['tests_required'] ?? []);
        $this->assertStringContainsString($expectedTest, $finding[0]['proposed_next_action'] ?? '');
    }

    public function test_factory_runtime_coverage_discovery_surfaces_nested_candidates_without_override(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => true,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
        ] + $this->quietDeepChecks());

        $coverage = $report['source_summary']['factory_runtime_coverage'] ?? [];
        $this->assertTrue($coverage['recursive_scan'] ?? false);
        $this->assertSame('recursive', $coverage['discovery_mode'] ?? null);
        $this->assertGreaterThan(0, $coverage['candidate_count'] ?? 0);
        $this->assertGreaterThan(0, $coverage['nested_candidate_count'] ?? 0);
        $this->assertGreaterThan(0, $coverage['executable_emitted_count'] ?? 0);
        $this->assertGreaterThan(
            0,
            count(array_filter(
                $report['findings'],
                static fn (array $f): bool => str_contains((string) ($f['source_ref'] ?? ''), '/OwnerFlow/')
                    || str_contains(implode(',', $f['affected_files'] ?? []), '/OwnerFlow/'),
            )),
        );
    }

    public function test_factory_runtime_coverage_replenishes_dev_forge_roots_after_terminal_starvation(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
            'terminal_backlog_state_hash' => 'ec7740946157',
            'terminal_backlog_rejection_reasons' => [
                'review_locked_existing_branch',
                'terminal_locked_existing_failure',
                'terminal_unlock_candidate_locked',
                'duplicate_candidate_key_in_pass',
                'factory_max_rejects_forge_without_live_authority',
                'no_executable_candidates_after_selection_pass',
                'review_locked_existing_branch',
                'terminal_locked_existing_failure',
            ],
        ] + $this->quietDeepChecks());

        $coverage = $report['source_summary']['factory_runtime_coverage'] ?? [];
        $this->assertTrue($coverage['terminal_backlog_replenishment'] ?? false);
        $this->assertSame('ec7740946157', $coverage['terminal_backlog_state_hash'] ?? null);
        $this->assertSame(8, $coverage['terminal_backlog_rejection_reason_count'] ?? null);
        $this->assertGreaterThan(0, $coverage['replenished_root_count'] ?? 0);
        $this->assertGreaterThan(5, $coverage['root_count'] ?? 0);
        $this->assertContains(
            'app/Services/Ai/ProgrammingRuntime/',
            $coverage['replenished_roots'] ?? [],
        );

        $programmingRuntimeFindings = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => str_contains(
                implode(',', $f['affected_files'] ?? []),
                'ProgrammingRuntime/',
            ),
        ));
        $this->assertNotEmpty($programmingRuntimeFindings);
        $finding = $programmingRuntimeFindings[0];
        $this->assertSame('factory_runtime_coverage_sweep', $finding['origin']);
        $this->assertTrue($finding['terminal_backlog_replenishment'] ?? false);
        $this->assertSame('ec7740946157', $finding['terminal_backlog_state_hash'] ?? null);
        $this->assertTrue($finding['factory_execution_ready'] ?? false);
        $this->assertNotEmpty($finding['allowed_files']);
        $this->assertNotEmpty($finding['tests_required']);
        $this->assertGreaterThan(0, $finding['factory_leverage_score'] ?? 0);
        $this->assertGreaterThan(5000, $finding['factory_priority_score'] ?? 0);

        $capped = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
            'max_findings' => 12,
            'terminal_backlog_state_hash' => 'ec7740946157',
            'terminal_backlog_rejection_reasons' => [
                'no_executable_candidates_after_selection_pass',
            ],
        ] + $this->quietDeepChecks());

        $cappedReplenishment = array_values(array_filter(
            $capped['findings'],
            static fn (array $f): bool => ($f['terminal_backlog_replenishment'] ?? false) === true,
        ));
        $this->assertNotEmpty($cappedReplenishment);
    }

    public function test_factory_runtime_coverage_without_terminal_starvation_skips_replenishment_roots(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => true,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
        ] + $this->quietDeepChecks());

        $coverage = $report['source_summary']['factory_runtime_coverage'] ?? [];
        $this->assertFalse($coverage['terminal_backlog_replenishment'] ?? true);
        $this->assertSame(0, $coverage['replenished_root_count'] ?? null);
        $this->assertSame([], $coverage['replenished_roots'] ?? null);

        $programmingRuntimeFindings = array_filter(
            $report['findings'],
            static fn (array $f): bool => str_contains(
                implode(',', $f['affected_files'] ?? []),
                'ProgrammingRuntime/',
            ),
        );
        $this->assertCount(0, $programmingRuntimeFindings);
    }

    public function test_factory_runtime_coverage_emits_actionable_nested_paths_for_factory_max(): void
    {
        $nestedRuntime = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchBridge.php';
        $expectedTest = 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ForgeOwnerRuntimeDispatchBridgeTest.php';

        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => false,
            'skip_strategic_multiplier_backlog' => true,
            'factory_runtime_coverage_files' => [$nestedRuntime],
        ] + $this->quietDeepChecks());

        $this->assertCount(1, $report['findings']);
        $finding = $report['findings'][0];
        $this->assertSame('factory_runtime_coverage_sweep', $finding['origin']);
        $this->assertSame($nestedRuntime, $finding['allowed_files'][0]);
        $this->assertContains($expectedTest, $finding['tests_required']);
        $this->assertTrue($finding['factory_execution_ready']);
        $this->assertStringContainsString($expectedTest, $finding['proposed_next_action']);
        $this->assertStringNotContainsString('docs/engineering-knowledge-base', implode(',', $finding['allowed_files']));
    }

    public function test_strategic_multiplier_backlog_is_ordered_and_executable(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => false,
        ] + $this->quietDeepChecks());

        $this->assertGreaterThanOrEqual(5, $report['source_summary']['strategic_multiplier_backlog']['emitted_count']);
        $this->assertNotEmpty($report['source_summary']['strategic_multiplier_backlog']['order']);
        $this->assertNotEmpty($report['findings']);

        $first = $report['findings'][0];
        $this->assertArrayHasKey('multiplier_tier', $first);
        $this->assertArrayHasKey('multiplier_order', $first);
        $this->assertArrayHasKey('multiplier_jump', $first);
        $this->assertTrue($first['factory_execution_ready']);
        $this->assertTrue($first['auto_execution_allowed']);
        $this->assertNotEmpty($first['allowed_files']);
        $this->assertNotEmpty($first['tests_required']);
    }

    public function test_atlas_dev_factory_runtime_bottleneck_scan_surfaces_provider_routing_risk(): void
    {
        $ownerRunner = 'app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php';

        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => true,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => true,
            'skip_atlas_dev_factory_runtime_bottlenecks' => false,
        ] + $this->quietDeepChecks());

        $bottlenecks = $report['source_summary']['atlas_dev_factory_runtime_bottlenecks'] ?? [];
        $this->assertFalse($bottlenecks['skipped'] ?? true);
        $this->assertSame('static_analysis', $bottlenecks['discovery_mode'] ?? null);
        $this->assertGreaterThan(0, $bottlenecks['emitted_count'] ?? 0);
        $this->assertGreaterThan(0, $bottlenecks['signal_counts']['provider_routing_risk'] ?? 0);

        $routing = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin_type'] ?? '') === 'provider_routing_risk'
                && str_contains(implode(',', $f['affected_files'] ?? []), 'StewardshipOwnerSandboxRuntimeRunnerService.php'),
        ));
        $this->assertCount(1, $routing);
        $finding = $routing[0];
        $this->assertSame('atlas_dev_factory_runtime_bottleneck_scan', $finding['origin']);
        $this->assertSame('risk', $finding['kind']);
        $this->assertSame('atlas_dev', $finding['owner_candidate']);
        $this->assertSame('high', $finding['severity']);
        $this->assertContains($ownerRunner, $finding['affected_files'] ?? []);
        $this->assertStringContainsString('Atlas Decide', $finding['why_it_matters'] ?? '');
    }

    public function test_atlas_dev_factory_runtime_bottleneck_scan_does_not_flag_budget_guarded_loops(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => true,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => true,
            'skip_atlas_dev_factory_runtime_bottlenecks' => false,
        ] + $this->quietDeepChecks());

        $executionBottlenecks = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin_type'] ?? '') === 'execution_bottleneck'
                && str_contains(implode(',', $f['affected_files'] ?? []), 'Reliable24hLoopRunnerService.php'),
        ));
        $this->assertCount(0, $executionBottlenecks);
    }

    public function test_atlas_dev_factory_runtime_bottleneck_scan_accepts_factory_backlog_with_tests(): void
    {
        $report = $this->service()->scan([
            'base_report' => ['findings' => []],
            'skip_factory_backlog_quality' => false,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => true,
            'skip_atlas_dev_factory_runtime_bottlenecks' => false,
        ] + $this->quietDeepChecks());

        $routing = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin_type'] ?? '') === 'provider_routing_risk',
        ));
        $this->assertNotEmpty($routing);
        $finding = $routing[0];
        $this->assertTrue($finding['factory_execution_ready'] ?? false);
        $this->assertNotEmpty($finding['allowed_files']);
        $this->assertNotEmpty($finding['tests_required']);
        $this->assertStringContainsString('php artisan test', $finding['proposed_next_action'] ?? '');
    }
}
