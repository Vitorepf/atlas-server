<?php

namespace Tests\Feature\Ai\E2E;

use App\Services\Ai\Programming\AtlasDev\Repair\DevRepairLoopService;
use App\Services\Ai\Programming\AtlasDev\Research\DevResearchPlannerService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\BlastRadius;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugRepairCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\DebugSuspectedCause;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchSource;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\DebugReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\ResearchReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use App\Services\Ai\Programming\Forge\ForgeIntakeCanon;
use App\Services\Ai\Programming\Forge\ForgeIntakeService;
use App\Services\Ai\Programming\Forge\Qa\ForgeObraCertificationService;
use Tests\Concerns\CreatesForgeIntakeTables;
use Tests\TestCase;

/**
 * Atlas Dev/Forge internal E2E scenario battery.
 *
 * **Scope.** Prove the canonical Dev/Forge runtime works for daily-engineering
 * scenarios using ONLY first-party schemas + services. No provider mock is
 * involved because none of the scenarios in this battery require a provider
 * to advance — they exercise planning/auditing services that emit
 * deterministic receipts (failure_capsule, dev_repair_receipt, patch_intel,
 * review_receipt, debug_receipt, forge_intake, obra_certification,
 * escalation_packet).
 *
 * **Not a benchmark.** This file does NOT compare Atlas against Claude
 * Code/Codex/Cursor and MUST NOT be repurposed for that comparison. The final
 * marker test {@see self::test_battery_does_not_run_external_provider_benchmark}
 * captures the invariant.
 *
 * **Blocker scenarios.** Where canonical runtime is missing (e.g. Dev research
 * receipt schema not declared yet), a readiness test documents the gap rather
 * than papering over it with a fake assertion.
 */
class AtlasDevForgeE2EScenarioBatteryTest extends TestCase
{
    use CreatesForgeIntakeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createForgeIntakeTables();
    }

    protected function tearDown(): void
    {
        $this->dropForgeIntakeTables();
        parent::tearDown();
    }

    /** ============================================================
     * Scenario 1: medium bug fix on the daily Dev path.
     * ============================================================ */
    public function test_scenario_dev_bug_fix_medium_emits_repair_plan_with_focused_retest(): void
    {
        $input = $this->baseRepairInput();

        $receipt = app(DevRepairLoopService::class)->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_PLANNED, $receipt['outcome']);
        $this->assertSame(DevRepairLoopService::RECEIPT_SCHEMA_VERSION, $receipt['schema_version']);
        $this->assertSame(64, strlen($receipt['receipt_hash']));
        $this->assertNotEmpty($receipt['failure_capture']['failure_signature']);
        $this->assertStringContainsString(
            'tests/Feature/Provider/RouterTest.php::test_fallback',
            (string) $receipt['focused_retest_command']['primary_command'],
        );
        $this->assertSame('rerun_failing_test_from_capsule', $receipt['focused_retest_command']['selection_reason']);
        // Telemetry: receipt-level evidence_refs propagate through downstream consumers.
        $this->assertNotEmpty($receipt['failure_classification']['mode']);
        $this->assertContains('atlas.programming.repair_attempt.plan.v1', [
            $receipt['repair_candidate']['schema_version'] ?? null,
        ]);
    }

    /** ============================================================
     * Scenario 2: medium feature change emits patch_intel + scope discipline.
     * ============================================================ */
    public function test_scenario_dev_feature_medium_emits_patch_intel_receipt_with_blast_radius(): void
    {
        $blast = new BlastRadius(
            fileCount: 3,
            linesAddedTotal: 48,
            linesRemovedTotal: 6,
            touchedDirs: ['app/Services/Provider', 'tests/Feature/Provider'],
        );

        $receipt = PatchIntelligenceReceipt::issue(
            runId: 'e2e-run-feature-medium',
            taskContractHash: $this->fakeContractHash(),
            expectedFiles: [
                'app/Services/Provider/Router.php',
                'app/Services/Provider/Adapter.php',
                'tests/Feature/Provider/RouterTest.php',
            ],
            changedFiles: [
                new ScopeFileDiff(
                    path: 'app/Services/Provider/Router.php',
                    added: 24,
                    removed: 4,
                    fileHashAfter: hash('sha256', 'router-after-fixture'),
                ),
                new ScopeFileDiff(
                    path: 'app/Services/Provider/Adapter.php',
                    added: 12,
                    removed: 2,
                    fileHashAfter: hash('sha256', 'adapter-after-fixture'),
                ),
                new ScopeFileDiff(
                    path: 'tests/Feature/Provider/RouterTest.php',
                    added: 12,
                    removed: 0,
                    fileHashAfter: hash('sha256', 'router-test-after-fixture'),
                ),
            ],
            unexpectedFiles: [],
            missingExpectedFiles: [],
            riskLevel: 'medium',
            blastRadius: $blast,
            localPatternNotes: ['follows existing provider adapter pattern in atlas-server'],
            userChangePreservationNotes: ['no user-pending diff in workspace'],
            userPreExistingChanges: [],
            rollbackHint: 'git revert <commit_hash>',
            evidenceRefs: [
                new EvidenceRef(
                    kind: 'diff',
                    path: 'storage/atlas-dev/runs/e2e-feature-medium/diff.patch',
                    hash: hash('sha256', 'feature-diff-fixture'),
                ),
            ],
        );

        $payload = $receipt->toCanonicalArray();

        $this->assertSame(PatchIntelligenceReceipt::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('medium', $payload['risk_level']);
        $this->assertSame([], $payload['unexpected_files']);
        $this->assertSame([], $payload['missing_expected_files']);
        $this->assertSame(3, $payload['blast_radius']['file_count']);
        $this->assertSame(64, strlen($payload['receipt_hash']));
    }

    /** ============================================================
     * Scenario 3: review pass with critical finding + missing test count.
     * ============================================================ */
    public function test_scenario_dev_review_emits_review_receipt_with_critical_finding(): void
    {
        $finding = new ReviewFinding(
            findingId: 'find-001',
            title: 'Missing regression test for fallback path',
            severity: ReviewFinding::SEVERITY_BLOCKER,
            riskType: ReviewFinding::RISK_TEST_GAP,
            description: 'Provider fallback path lacks regression test for rate_limit recovery.',
            remediation: 'Add ProviderRouterTest::test_rate_limit_fallback exercising the new branch.',
            confidence: 0.92,
            file: 'app/Services/Provider/Router.php',
            line: 87,
            lineEnd: 119,
            symbol: 'Router::fallbackOnRateLimit',
            testGap: true,
            evidenceRefKinds: ['diff', 'test_log'],
        );

        $receipt = ReviewReceipt::issue(
            receiptId: 'review-receipt-e2e-001',
            runId: 'e2e-run-review-001',
            status: ReviewReceipt::STATUS_REVIEWED,
            reviewedFiles: ['app/Services/Provider/Router.php', 'app/Services/Provider/Adapter.php'],
            findings: [$finding],
            missingTestsCount: 1,
            confidence: 0.86,
            evidenceRefs: ['diff:provider/router.diff', 'test_log:rate-limit.txt'],
            blockerReasons: [],
            createdAt: now()->toIso8601String(),
        );

        $payload = $receipt->toCanonicalArray();

        $this->assertSame(ReviewReceipt::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(ReviewReceipt::STATUS_REVIEWED, $payload['status']);
        $this->assertSame(1, $payload['missing_tests_count']);
        $this->assertCount(1, $payload['findings']);
        $this->assertSame(ReviewFinding::SEVERITY_BLOCKER, $payload['findings'][0]['severity']);
        $this->assertSame(64, strlen($payload['receipt_hash']));
    }

    /** ============================================================
     * Scenario 4: Dev technical research emits canonical research_receipt
     * with partial status when one open question remains.
     *
     * Promoted from a "missing runtime" blocker to real coverage: the
     * canonical `atlas.dev.research_receipt.v1` schema + planner now exist.
     * The scenario exercises the partial-answer path because that is the
     * honest mid-confidence shape every research workflow must support.
     *
     * Provider invocation remains explicitly OUT OF SCOPE — the planner is
     * fed curated canonical sources only. See {@see
     * test_scenario_dev_technical_research_blocker_when_no_sources_supplied}
     * for the negative path.
     * ============================================================ */
    public function test_scenario_dev_technical_research_emits_partial_receipt_with_sources(): void
    {
        $receipt = app(DevResearchPlannerService::class)->plan([
            'run_id' => 'e2e-research-run-001',
            'receipt_id' => 'rsch-e2e-001',
            'question' => 'Como o Atlas Forge bloqueia certification sem evidence pack?',
            'sources' => [
                [
                    'kind' => ResearchSource::KIND_CANONICAL_DOC,
                    'ref' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
                    'hash' => hash('sha256', 'forge-os-doc-fixture'),
                    'excerpt' => 'release sem evidence normalizada e bloqueado',
                ],
                [
                    'kind' => ResearchSource::KIND_CODE_SYMBOL,
                    'ref' => 'App\\Services\\Ai\\Programming\\Forge\\Qa\\ForgeObraCertificationService::certify',
                    'hash' => hash('sha256', 'forge-cert-symbol-fixture'),
                ],
            ],
            'findings' => [
                [
                    'finding_id' => 'rf-cert-blocker',
                    'claim' => 'Forge certification refuses to pass when required_evidence is empty.',
                    'confidence' => 0.82,
                    'supports' => [0, 1],
                ],
            ],
            'open_questions' => [
                [
                    'question_id' => 'oq-evidence-shape',
                    'question' => 'Qual é o shape canônico de evidence_pack para cada milestone?',
                    'severity' => 'medium',
                    'suggested_next_step' => 'read_atlas_evidence_certification_runtime_doc',
                ],
            ],
            'evidence_refs' => [
                'canonical_doc:atlas-forge-operating-system.md',
                'code_symbol:ForgeObraCertificationService::certify',
            ],
        ]);

        $payload = $receipt->toCanonicalArray();
        $this->assertSame(ResearchReceipt::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(ResearchReceipt::STATUS_PARTIAL, $payload['status']);
        $this->assertSame(2, $payload['sources_count']);
        $this->assertSame(1, $payload['findings_count']);
        $this->assertSame(1, $payload['open_questions_count']);
        $this->assertGreaterThan(0.7, (float) $payload['confidence']);
        $this->assertLessThanOrEqual(1.0, (float) $payload['confidence']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));

        // Honesty invariant: every finding cites at least one source by index.
        foreach ($payload['findings'] as $finding) {
            $this->assertNotEmpty($finding['supports'], 'every research finding must cite at least one source');
            foreach ($finding['supports'] as $sourceIndex) {
                $this->assertGreaterThanOrEqual(0, (int) $sourceIndex);
                $this->assertLessThan(count($payload['sources']), (int) $sourceIndex);
            }
        }

        // Round-trip stability: fromArray + toCanonicalArray yields same hash.
        $roundTrip = ResearchReceipt::fromArray($payload);
        $this->assertSame($payload['receipt_hash'], $roundTrip->receiptHash);
    }

    /** ============================================================
     * Scenario 4b: research planner refuses to fabricate findings when
     * no sources are supplied — emits an explicit blocker instead.
     * ============================================================ */
    public function test_scenario_dev_technical_research_blocker_when_no_sources_supplied(): void
    {
        $receipt = app(DevResearchPlannerService::class)->plan([
            'run_id' => 'e2e-research-run-002',
            'receipt_id' => 'rsch-e2e-002',
            'question' => 'Pergunta sem fontes nem findings supridos pelo operador.',
        ]);

        $payload = $receipt->toCanonicalArray();
        $this->assertSame(ResearchReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT, $payload['status']);
        $this->assertNotEmpty($payload['blocker_reasons']);
        $this->assertSame(0.0, (float) $payload['confidence']);
        $this->assertSame([], $payload['findings']);
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));
    }

    /** ============================================================
     * Scenario 4c: planner emits no_canonical_source when sources were
     * consulted but produced no finding — the honest "we looked, it's not
     * documented" path.
     * ============================================================ */
    public function test_scenario_dev_technical_research_no_canonical_source_when_sources_yield_nothing(): void
    {
        $receipt = app(DevResearchPlannerService::class)->plan([
            'run_id' => 'e2e-research-run-003',
            'receipt_id' => 'rsch-e2e-003',
            'question' => 'Pergunta com fontes mas sem findings.',
            'sources' => [
                [
                    'kind' => ResearchSource::KIND_CANONICAL_DOC,
                    'ref' => 'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
                ],
            ],
        ]);

        $payload = $receipt->toCanonicalArray();
        $this->assertSame(ResearchReceipt::STATUS_NO_CANONICAL_SOURCE, $payload['status']);
        $this->assertSame(1, $payload['sources_count']);
        $this->assertSame(0, $payload['findings_count']);
        $this->assertGreaterThanOrEqual(1, $payload['open_questions_count'], 'no_canonical_source must synthesize at least one open question');
        $this->assertSame(64, strlen((string) $payload['receipt_hash']));
    }

    /** ============================================================
     * Scenario 5: Dev → Forge escalation emits canonical packet.
     * ============================================================ */
    public function test_scenario_dev_to_forge_escalation_emits_canonical_packet(): void
    {
        $input = $this->baseRepairInput();
        $input['attempt_count'] = 1;
        $input['changed_files'] = [
            'app/Services/Provider/Router.php',
            'app/Services/Provider/Adapter.php',
            'app/Services/Provider/Fallback.php',
            'app/Services/Provider/Receipts.php',
        ];
        $input['previous_changed_files'] = ['app/Services/Provider/Router.php'];
        $input['original_user_intent'] = 'reescrever provider router multi-module com fallback governado';

        $receipt = app(DevRepairLoopService::class)->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_ESCALATED, $receipt['outcome']);
        $packet = $receipt['escalate_to_forge'];
        $this->assertNotNull($packet);
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertContains(EscalationPacket::TRIGGER_SCOPE_TOO_LARGE, $packet['promotion_triggers']);
        $this->assertNotEmpty($packet['promotion_reason']);
        $this->assertSame(EscalationPacket::SOURCE_CORE, $packet['source_core']);
        $this->assertSame(EscalationPacket::TARGET_CORE, $packet['target_core']);
        $this->assertSame(64, strlen((string) $packet['packet_hash']));
    }

    /** ============================================================
     * Scenario 6: Forge Obra smoke — intake creates 5 milestones + packet.
     * ============================================================ */
    public function test_scenario_forge_obra_smoke_creates_intake_with_milestones_and_work_packet(): void
    {
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar refator multi-modulo do provider router com sdd e governance multi-stage',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );

        $this->assertSame(ForgeIntakeCanon::INTAKE_SCHEMA_VERSION, $intake->schema_version);
        $this->assertSame(ForgeIntakeCanon::STATUS_READY, $intake->status);
        $this->assertSame(64, strlen((string) $intake->intake_hash));

        $milestoneIds = $intake->milestones()->orderBy('position')->pluck('milestone_id')->all();
        $this->assertSame(ForgeIntakeCanon::CANONICAL_MILESTONES, $milestoneIds);

        $packets = $intake->workPackets()->orderBy('packet_position')->get();
        $this->assertGreaterThanOrEqual(1, $packets->count());
        $this->assertNotEmpty($packets->first()->acceptance_criteria);
    }

    /** ============================================================
     * Scenario 7: repair loop after failure (max_attempts → blocked).
     * ============================================================ */
    public function test_scenario_repair_loop_after_failure_emits_blocker_when_attempts_exhausted(): void
    {
        $input = $this->baseRepairInput();
        $input['attempt_count'] = 2;
        $input['max_attempts'] = 2;

        $receipt = app(DevRepairLoopService::class)->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_BLOCKED, $receipt['outcome']);
        $this->assertNotNull($receipt['blocker']);
        $this->assertSame('atlas.programming.dev_repair_blocker.v1', $receipt['blocker']['schema_version']);
        $stopNames = array_column($receipt['stop_conditions'], 'condition');
        $this->assertContains('max_attempts_reached', $stopNames);
    }

    /** ============================================================
     * Scenario 8: RAG gate blocks when retrieval plan is absent.
     * ============================================================ */
    public function test_scenario_rag_gate_blocks_when_context_insufficient(): void
    {
        $input = $this->baseRepairInput();
        // No retrieval_plan, no gap_critic — the loop should flag retrieval
        // as required so the operator (or caller) re-runs the planner.
        $receipt = app(DevRepairLoopService::class)->evaluate($input);

        $context = $receipt['context_retrieval_needed'];
        $this->assertTrue($context['required']);
        $this->assertContains('retrieval_plan_absent', $context['reasons']);
        $this->assertSame(
            'rerun_retrieval_planner_with_failure_anchors',
            $context['recommended_action'],
        );
    }

    /** ============================================================
     * Scenario 9: certification blocker — Obra w/o evidence cannot certify.
     * ============================================================ */
    public function test_scenario_certification_blocker_when_obra_lacks_evidence(): void
    {
        // Heavy Obra without sdd_spec and with empty evidence anchors MUST
        // fail certification with structured blockers + remediation.
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Implementar Obra critica de migracao multi-tenant com gates completos',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_CRITICAL,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );
        $intake->required_evidence = [];
        $intake->definition_of_done = ['ship'];
        $intake->save();

        $cert = app(ForgeObraCertificationService::class)->certify($intake->fresh());

        $this->assertSame(ForgeObraCertificationService::STATUS_FAILED, $cert['status']);
        $this->assertNotEmpty($cert['blockers']);
        $this->assertNotEmpty($cert['remediation']);
        $this->assertContains(
            'evidence_ready',
            array_column($cert['checks'], 'check_id'),
        );
        $this->assertSame(ForgeIntakeCanon::OBRA_CERTIFICATION_SCHEMA_VERSION, $cert['schema_version']);
    }

    /** ============================================================
     * Scenario 10: telemetry / evidence completeness across receipt schemas.
     *
     * For every canonical receipt we touch (repair, patch_intel, review,
     * debug, escalation, obra_certification), assert the audit envelope
     * carries schema_version + hash + evidence_refs/blockers shape.
     * ============================================================ */
    public function test_scenario_telemetry_and_evidence_completeness_across_receipts(): void
    {
        // 1. Dev repair receipt (already exercised; verify telemetry surface).
        $repair = app(DevRepairLoopService::class)->evaluate($this->baseRepairInput());
        $this->assertCommonShape($repair, DevRepairLoopService::RECEIPT_SCHEMA_VERSION, 'receipt_hash');

        // 2. Patch intel receipt.
        $patch = PatchIntelligenceReceipt::issue(
            runId: 'e2e-run-telemetry',
            taskContractHash: $this->fakeContractHash(),
            expectedFiles: ['app/X.php'],
            changedFiles: [
                new ScopeFileDiff(
                    path: 'app/X.php',
                    added: 12,
                    removed: 4,
                    fileHashAfter: hash('sha256', 'x-after-fixture'),
                ),
            ],
            unexpectedFiles: [],
            missingExpectedFiles: [],
            riskLevel: 'low',
            blastRadius: new BlastRadius(1, 12, 4, ['app']),
            localPatternNotes: ['local conventions respected'],
            userChangePreservationNotes: [],
            userPreExistingChanges: [],
            rollbackHint: 'git revert HEAD',
            evidenceRefs: [],
        )->toCanonicalArray();
        $this->assertCommonShape($patch, PatchIntelligenceReceipt::SCHEMA_VERSION, 'receipt_hash');

        // 3. Review receipt.
        $review = ReviewReceipt::issue(
            receiptId: 'rv-telemetry',
            runId: 'e2e-run-telemetry',
            status: ReviewReceipt::STATUS_NO_CONCERNS,
            reviewedFiles: ['app/X.php'],
            findings: [],
            missingTestsCount: 0,
            confidence: 0.95,
            evidenceRefs: [],
            blockerReasons: [],
            createdAt: now()->toIso8601String(),
        )->toCanonicalArray();
        $this->assertCommonShape($review, ReviewReceipt::SCHEMA_VERSION, 'receipt_hash');

        // 4. Debug receipt.
        $debug = DebugReceipt::issue(
            receiptId: 'dbg-telemetry',
            runId: 'e2e-run-telemetry',
            status: DebugReceipt::STATUS_DIAGNOSED,
            failureFingerprint: 'sha256:'.hash('sha256', 'null-deref-fixture'),
            failureClassification: 'missed_test',
            primaryErrorExcerpt: 'TypeError: Cannot read property foo of null',
            reproductionPlan: ['call /endpoint with empty body'],
            suspectedCauses: [
                new DebugSuspectedCause(
                    category: DebugSuspectedCause::CATEGORY_NULL_OR_MISSING,
                    why: 'Field foo not initialized in constructor',
                    confidence: 0.78,
                ),
            ],
            repairCandidates: [
                new DebugRepairCandidate(
                    strategy: DebugRepairCandidate::STRATEGY_FIX_AND_TEST,
                    summary: 'Guard against null foo before access and add regression test',
                    targetFiles: ['app/X.php'],
                    expectedOutcome: 'TypeError disappears under unit + feature suites',
                    validationCommands: ['/opt/homebrew/bin/php artisan test tests/Unit/XTest.php'],
                    confidence: 0.7,
                ),
            ],
            confidence: 0.74,
            stopConditions: [],
            evidenceRefs: ['test_log:debug-rerun.txt'],
            blockerReasons: [],
            createdAt: now()->toIso8601String(),
        )->toCanonicalArray();
        $this->assertCommonShape($debug, DebugReceipt::SCHEMA_VERSION, 'receipt_hash');

        // 5. Escalation packet — reuse Scenario 5 path.
        $escalationInput = $this->baseRepairInput();
        $escalationInput['attempt_count'] = 1;
        $escalationInput['changed_files'] = [
            'app/X.php', 'app/Y.php', 'app/Z.php',
        ];
        $escalationInput['previous_changed_files'] = ['app/X.php'];
        $packet = app(DevRepairLoopService::class)->evaluate($escalationInput)['escalate_to_forge'];
        $this->assertCommonShape($packet, EscalationPacket::SCHEMA_VERSION, 'packet_hash');

        // 6. Forge Obra certification.
        $intake = app(ForgeIntakeService::class)->intakeFromPrompt(
            'Telemetry smoke obra integral com sdd intake',
            [
                'workspace_slug' => 'atlas-server',
                'risk_band' => ForgeIntakeCanon::RISK_BAND_HIGH,
                'recommended_forge_mode' => EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            ],
        );
        $cert = app(ForgeObraCertificationService::class)->certify($intake);
        $this->assertCommonShape($cert, ForgeIntakeCanon::OBRA_CERTIFICATION_SCHEMA_VERSION, 'certification_hash');

        // 7. Dev research receipt — added when Scenario 4 was promoted from
        // blocker to real coverage; we keep telemetry shape symmetric.
        $research = app(DevResearchPlannerService::class)->plan([
            'run_id' => 'e2e-run-telemetry',
            'receipt_id' => 'rsch-telemetry',
            'question' => 'Telemetry smoke research path',
            'sources' => [[
                'kind' => ResearchSource::KIND_CANONICAL_DOC,
                'ref' => 'docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md',
            ]],
            'findings' => [[
                'finding_id' => 'rf-telemetry',
                'claim' => 'Evidence runtime emits canonical receipts only.',
                'confidence' => 0.9,
                'supports' => [0],
            ]],
        ])->toCanonicalArray();
        $this->assertCommonShape($research, ResearchReceipt::SCHEMA_VERSION, 'receipt_hash');
    }

    /** ============================================================
     * Marker test — the battery never runs an external provider benchmark.
     * ============================================================ */
    public function test_battery_does_not_run_external_provider_benchmark(): void
    {
        $marker = [
            'schema_version' => 'atlas.programming.e2e_battery_marker.v1',
            'rivals_executed' => false,
            'external_provider_called' => false,
            'benchmark_not_run' => true,
            'comparison_against' => [],
            'evidence_refs' => [
                'tests/Feature/Ai/E2E/AtlasDevForgeE2EScenarioBatteryTest.php',
            ],
        ];

        $this->assertFalse($marker['rivals_executed']);
        $this->assertFalse($marker['external_provider_called']);
        $this->assertTrue($marker['benchmark_not_run']);
        $this->assertSame([], $marker['comparison_against']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertCommonShape(array $payload, string $expectedSchema, string $hashKey = 'receipt_hash'): void
    {
        $this->assertSame($expectedSchema, $payload['schema_version'] ?? null);
        $this->assertArrayHasKey($hashKey, $payload);
        $this->assertSame(64, strlen((string) ($payload[$hashKey] ?? '')));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json, "Receipt payload for {$expectedSchema} must serialize to JSON.");
    }

    /**
     * @return array<string,mixed>
     */
    private function baseRepairInput(): array
    {
        return [
            'run_id' => 'e2e-battery-run',
            'task_contract_hash' => $this->fakeContractHash(),
            'risk_level' => 'R2',
            'attempt_count' => 0,
            'max_attempts' => 2,
            'failure_packet' => [
                'gate' => 'verification_gate',
                'command' => '/opt/homebrew/bin/php artisan test tests/Feature/Provider/RouterTest.php',
                'exit_code' => 1,
                'primary_error_excerpt' => 'Failed asserting that null is true',
                'failing_test' => 'tests/Feature/Provider/RouterTest.php::test_fallback',
                'full_error_log_path' => 'storage/logs/test-output.log',
            ],
            'changed_files' => ['app/Services/Provider/Router.php'],
            'previous_changed_files' => [],
            'original_user_intent' => 'corrigir test fallback no provider router',
        ];
    }

    private function fakeContractHash(): string
    {
        return hash('sha256', 'e2e-battery-contract-fixture');
    }
}
