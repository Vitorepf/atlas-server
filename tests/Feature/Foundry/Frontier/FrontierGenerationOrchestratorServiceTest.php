<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\Foundry\Frontier\Armor\FrontierDecomposerGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDedupPriorArtGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDriftMapperGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierEvidenceBoundGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate;
use App\Services\Ai\Foundry\Frontier\FrontierGenerationOrchestratorService;
use App\Services\Ai\Foundry\Frontier\FrontierProposalToGapCandidateAdapter;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierJudgeService;
use App\Services\Ai\Foundry\FoundryExhaustionRarityGateService;
use App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use Tests\TestCase;

/**
 * Foundry AP-C · orchestrator full-pipeline proof.
 *
 * Drives the REAL armor chain with the labelled fixture generator + a
 * deterministic judge: bad proposals dropped per-invariant with reasons, a good
 * one admitted to the curation inbox as pending_operator_review, and a
 * not_eligible gate refusing generation with NO provider call.
 */
final class FrontierGenerationOrchestratorServiceTest extends TestCase
{
    private const FIXTURE_PROVIDER = DeterministicFixtureFrontierGeneratorService::FIXTURE_PROVIDER;

    private const FIXTURE_MODEL = DeterministicFixtureFrontierGeneratorService::FIXTURE_MODEL;

    // ---------------- gate seam helpers (mirror AP-B gate test) ----------------

    private function budgetOk(int $ceiling = 6000000): array
    {
        return [
            'status' => LoopResourceGovernorService::STATUS_OK,
            'resource_summary' => [
                'provider_calls' => 10,
                'token_estimate' => 1000,
                'headroom' => [
                    'provider_calls' => ['value' => 10, 'hard_ceiling' => $ceiling, 'remaining_to_hard' => $ceiling - 10],
                    'token_estimate' => ['value' => 1000, 'hard_ceiling' => $ceiling, 'remaining_to_hard' => $ceiling - 1000],
                ],
            ],
        ];
    }

    private function backlogBelowFloor(int $packets = 1): array
    {
        return ['status' => BacklogDepthGovernorService::STATUS_BELOW_FLOOR, 'packets_count' => $packets];
    }

    private function zeroAdmissibleRecords(int $n): array
    {
        $records = [];
        for ($i = 0; $i < $n; $i++) {
            $records[] = ['outcome' => 'blocked', 'blockers' => ['backlog_exhausted'], 'admissible_packet_count' => 0];
        }

        return $records;
    }

    /**
     * Build an AP-B gate driven to STATUS_ELIGIBLE via its testing seams. The
     * flag comes from PERSISTENT config (set per-test); the override key is
     * irrelevant because the orchestrator strips it.
     */
    private function eligibleGate(): FoundryExhaustionRarityGateService
    {
        $gate = app(FoundryExhaustionRarityGateService::class);
        $gate->setBudgetReportForTesting($this->budgetOk());
        $gate->setBacklogReportForTesting($this->backlogBelowFloor());

        return $gate;
    }

    private function gateInputEligible(): array
    {
        return ['window_n' => 3, 'ledger_records' => $this->zeroAdmissibleRecords(3)];
    }

    // ---------------- dossier + proposals ----------------

    /** A measured, anchor-bound dossier that satisfies I1 + I9. */
    private function dossier(): array
    {
        $dossier = [
            'schema_version' => 'atlas.foundry.dossier.v1',
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'generated_at' => '2026-05-29T00:00:00+00:00',
            'source_summary' => [],
            'anchor_count' => 1,
            'anchors' => [[
                'anchor_id' => 'anchor:cyc1',
                'anchor_type' => 'cycle_id',
                'anchor_source' => 'area_focus_cycle',
                'source_path' => 'storage/atlas/cycles',
                'anchor_claim' => 'cycle-001',
                'resolved' => true,
                'integrity_status' => 'ok',
                'anchor_hash' => 'sha256:'.str_repeat('a', 8),
            ]],
            'cycle_receipts' => [[
                'cycle_id' => 'cycle-001',
                'subsystem' => 'pipeline_not_proven',
                'schema' => \App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'baseline' => 0.42,
            ]],
            'evidence_packs' => [],
            'plan_completion' => [],
            'blockers' => [],
            'owner_reuse_matrix' => [],
            'claim_policy' => [],
            // Stamp dossier_hash to EXACTLY what the test generator records as
            // generator_input_hash, so I1's anti-tamper check binds cleanly.
            'dossier_hash' => 'sha256:dossier_real',
        ];

        return $dossier;
    }

    private function packet(int $n): array
    {
        $source = 'app/Services/Ai/Foundry/Frontier/Sample'.$n.'Service.php';
        $test = 'tests/Unit/Ai/Foundry/Frontier/Sample'.$n.'ServiceTest.php';

        return [
            'packet_id' => 'pkt_'.$n,
            'label' => 'Packet '.$n,
            'objective' => 'Add a measured behavior to Sample'.$n,
            'delivery' => 'Implement one focused method in Sample'.$n.'Service with a unit test.',
            'acceptance_criteria' => ['php artisan test '.$test.' passes'],
            'tests_required' => [$source, $test],
            'owner_candidate' => 'atlas_dev',
            'kind' => 'service',
            'canonical_property_mapping' => [
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => \App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'measured_signal_ref' => 'cycle_receipts[0]',
            ],
        ];
    }

    /** A proposal that survives all six armor invariants. */
    private function goodProposal(): array
    {
        return [
            'proposal_id' => 'prop_good',
            'horizon' => 'near',
            'title' => 'Compose a measured frontier capability',
            'thesis' => 'Reuse the proven owner flow to raise a measured property.',
            'evidence_refs' => [[
                'anchor_id' => 'anchor:cyc1',
                'anchor_hash' => 'sha256:ref',
                'anchor_type' => 'cycle_id',
            ]],
            'why_it_multiplies' => 'compounding reuse',
            'success_metric' => ['operator' => '>=', 'baseline' => 0.42, 'threshold' => 0.6],
            'rollback' => ['rollback_condition' => 'revert if measured property regresses'],
            'risk_level' => 'low',
            'dependencies' => [],
            'proposed_packets' => [$this->packet(1), $this->packet(2), $this->packet(3)],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => [],
        ];
    }

    /** A proposal that fails I1 (cites an anchor not in the dossier). */
    private function unboundProposal(): array
    {
        $p = $this->goodProposal();
        $p['proposal_id'] = 'prop_unbound';
        $p['title'] = 'Unbound citation';
        $p['evidence_refs'] = [['anchor_id' => 'anchor:phantom', 'anchor_hash' => 'x', 'anchor_type' => 'cycle_id']];

        return $p;
    }

    /** A proposal that survives I1 but fails I7 (only 2 packets). */
    private function tooFewPacketsProposal(): array
    {
        $p = $this->goodProposal();
        $p['proposal_id'] = 'prop_thin';
        $p['title'] = 'Too few packets';
        $p['proposed_packets'] = [$this->packet(7), $this->packet(8)];

        return $p;
    }

    /**
     * Build the orchestrator wired with the eligible gate + a fixture generator
     * (canned proposals) + a deterministic accept-majority judge.
     *
     * @param  list<array<string,mixed>>  $cannedProposals
     */
    /**
     * Labelled ('fixture:') test generator that reports generator_input_hash =
     * dossier.dossier_hash so I1's anti-tamper check binds cleanly, and a
     * resolved provider/model DIFFERENT from the judge.
     *
     * @param  list<array<string,mixed>>  $canned
     */
    private function testGenerator(array $canned): \App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort
    {
        return new class($canned) implements \App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort
        {
            /** @param list<array<string,mixed>> $canned */
            public function __construct(private readonly array $canned) {}

            public function generate(array $dossier, int $count, array $context = []): array
            {
                $proposals = array_slice($this->canned, 0, max(0, $count));
                $inputHash = (string) ($dossier['dossier_hash'] ?? '');
                $provenance = [];
                foreach ($proposals as $p) {
                    $provenance[(string) ($p['proposal_id'] ?? '')] = [
                        'generator_label' => 'fixture:orchestrator-test',
                        'generator_input_hash' => $inputHash,
                        'proposal_hash' => \App\Services\Ai\Foundry\Frontier\Ports\FrontierProposalIdentity::of($p),
                    ];
                }

                return [
                    'status' => $proposals === [] ? 'skipped' : 'generated',
                    'proposals' => array_values($proposals),
                    'provenance' => $provenance,
                    'generator_label' => 'fixture:orchestrator-test',
                    'generator_provider_resolved' => 'fixture_generator_provider',
                    'generator_model_resolved' => 'fixture-generator-model',
                    'generator_blocked_reasons' => [],
                    'claim_policy' => ['provider_invoked' => false],
                ];
            }
        };
    }

    private function orchestrator(array $cannedProposals, FoundryExhaustionRarityGateService $gate): FrontierGenerationOrchestratorService
    {
        $generator = $this->testGenerator($cannedProposals);

        // 3-seat panel; the judge accepts all good proposals 3-0 (majority=ceil(3/2)+1=3).
        $seatsByProposalId = [];
        foreach ($cannedProposals as $p) {
            $seatsByProposalId[(string) $p['proposal_id']] = ['accept', 'accept', 'accept'];
        }
        $judge = new DeterministicFixtureFrontierJudgeService(
            $seatsByProposalId,
            'fixture_judge_provider',  // DIFFERENT from the generator's resolved provider/model
            'fixture-judge-model',
        );
        // Three identical-identity seats so the panel polls 3 votes.
        $panel = new FrontierJudgePanelGate([$judge, $judge, $judge]);

        return new FrontierGenerationOrchestratorService(
            $gate,
            $generator,
            app(FrontierEvidenceBoundGate::class),
            app(FrontierDedupPriorArtGate::class),
            $panel,
            app(FrontierDecomposerGate::class),
            app(FrontierDriftMapperGate::class),
            app(FrontierMetricRollbackGate::class),
            app(FrontierProposalToGapCandidateAdapter::class),
            app(SelfDirectedEvolutionCurationInboxService::class),
        );
    }

    // ---------------- tests ----------------

    public function test_full_pipeline_admits_good_proposal_and_drops_bad_ones_per_invariant(): void
    {
        config()->set('atlas.software_company_stewardship.frontier_mode', true);

        $orchestrator = $this->orchestrator(
            [$this->goodProposal(), $this->unboundProposal(), $this->tooFewPacketsProposal()],
            $this->eligibleGate(),
        );

        $result = $orchestrator->run([
            'area_id' => 'agentic_engineering_os',
            'count' => 3,
            'dossier' => $this->dossier(),
            'fixture_authorized' => true,
            'gate_input' => $this->gateInputEligible(),
        ]);

        $this->assertSame(FrontierGenerationOrchestratorService::STATUS_GENERATED, $result['status']);
        $this->assertSame('eligible', $result['gate_status']);
        $this->assertTrue($result['frontier_mode']);
        $this->assertSame(3, $result['proposals_generated']);

        // Exactly the good proposal survived and was admitted.
        $this->assertSame(1, $result['survivors_count']);
        $this->assertNotNull($result['curation_inbox']);
        $this->assertSame(1, $result['curation_inbox']['item_count']);
        $item = $result['curation_inbox']['items'][0];
        $this->assertSame('pending_operator_review', $item['status']);
        $this->assertFalse($item['autoapproval_allowed']);
        $this->assertFalse($item['external_side_effect_allowed']);
        $this->assertSame('foundry_frontier', $item['source_owner']);
        $this->assertStringStartsWith('sha256:', $item['candidate_hash']);

        // Two drops with machine-readable reasons at the correct stages.
        $byStage = [];
        foreach ($result['drops'] as $drop) {
            $byStage[$drop['drop_stage']] = $drop;
            $this->assertNotSame('', $drop['reason']);
            $this->assertArrayHasKey('proposal_id', $drop);
        }
        $this->assertArrayHasKey('I1', $byStage);
        $this->assertSame(FrontierEvidenceBoundGate::REASON_CITED_ANCHOR_NOT_IN_DOSSIER, $byStage['I1']['reason']);
        $this->assertSame('prop_unbound', $byStage['I1']['proposal_id']);

        $this->assertArrayHasKey('I7', $byStage);
        $this->assertSame(FrontierDecomposerGate::DROP_DECOMPOSITION_OUT_OF_BOUNDS, $byStage['I7']['reason']);
        $this->assertSame('prop_thin', $byStage['I7']['proposal_id']);

        // PROPOSAL-ONLY claim policy: zero canonical/code/merge/execution write.
        $cp = $result['claim_policy'];
        $this->assertTrue($cp['proposal_only']);
        $this->assertFalse($cp['mutates_repo']);
        $this->assertFalse($cp['canonical_doc_write_allowed']);
        $this->assertFalse($cp['merge_allowed']);
        $this->assertFalse($cp['executed']);
        $this->assertFalse($cp['autoapproval_allowed']);
    }

    public function test_judge_panel_refute_drops_at_i3(): void
    {
        config()->set('atlas.software_company_stewardship.frontier_mode', true);

        $good = $this->goodProposal();
        $generator = $this->testGenerator([$good]);
        // Only 1 accept of 3 seats => below majority => I3 majority_refute.
        $judge = new DeterministicFixtureFrontierJudgeService(
            ['prop_good' => ['accept', 'refute', 'refute']],
            'fixture_judge_provider',
            'fixture-judge-model',
        );
        $panel = new FrontierJudgePanelGate([$judge, $judge, $judge]);

        $orchestrator = new FrontierGenerationOrchestratorService(
            $this->eligibleGate(),
            $generator,
            app(FrontierEvidenceBoundGate::class),
            app(FrontierDedupPriorArtGate::class),
            $panel,
            app(FrontierDecomposerGate::class),
            app(FrontierDriftMapperGate::class),
            app(FrontierMetricRollbackGate::class),
            app(FrontierProposalToGapCandidateAdapter::class),
            app(SelfDirectedEvolutionCurationInboxService::class),
        );

        $result = $orchestrator->run([
            'dossier' => $this->dossier(),
            'fixture_authorized' => true,
            'gate_input' => $this->gateInputEligible(),
        ]);

        $this->assertSame(FrontierGenerationOrchestratorService::STATUS_GENERATED, $result['status']);
        $this->assertSame(0, $result['survivors_count']);
        $this->assertNull($result['curation_inbox']);
        $this->assertSame('I3', $result['drops'][0]['drop_stage']);
        // Below the accept-majority => default-refute family (the panel refused).
        $this->assertContains($result['drops'][0]['reason'], [
            FrontierJudgePanelGate::DROP_MAJORITY_REFUTE,
            FrontierJudgePanelGate::DROP_DEFAULT_REFUTE,
        ]);
    }

    public function test_not_eligible_gate_refuses_generation_with_no_provider_call(): void
    {
        config()->set('atlas.software_company_stewardship.frontier_mode', true);

        // Backlog OK (work available) => gate returns not_eligible.
        $gate = app(FoundryExhaustionRarityGateService::class);
        $gate->setBudgetReportForTesting($this->budgetOk());
        $gate->setBacklogReportForTesting(['status' => BacklogDepthGovernorService::STATUS_OK, 'packets_count' => 12]);

        // A generator that would explode if ever called (proves no provider call).
        $generator = new class implements \App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort
        {
            public function generate(array $dossier, int $count, array $context = []): array
            {
                throw new \RuntimeException('generator must NOT run on not_eligible');
            }
        };

        $orchestrator = new FrontierGenerationOrchestratorService(
            $gate,
            $generator,
            app(FrontierEvidenceBoundGate::class),
            app(FrontierDedupPriorArtGate::class),
            new FrontierJudgePanelGate([]),
            app(FrontierDecomposerGate::class),
            app(FrontierDriftMapperGate::class),
            app(FrontierMetricRollbackGate::class),
            app(FrontierProposalToGapCandidateAdapter::class),
            app(SelfDirectedEvolutionCurationInboxService::class),
        );

        $result = $orchestrator->run([
            'dossier' => $this->dossier(),
            'fixture_authorized' => true,
            'gate_input' => $this->gateInputEligible(),
        ]);

        $this->assertSame(FrontierGenerationOrchestratorService::STATUS_SKIPPED, $result['status']);
        $this->assertSame('not_eligible', $result['gate_status']);
        $this->assertSame(0, $result['proposals_generated']);
        $this->assertNull($result['curation_inbox']);
    }

    public function test_frontier_mode_off_skips_generation(): void
    {
        config()->set('atlas.software_company_stewardship.frontier_mode', false);

        $generator = new class implements \App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort
        {
            public function generate(array $dossier, int $count, array $context = []): array
            {
                throw new \RuntimeException('generator must NOT run with frontier_mode off');
            }
        };

        // Even a would-be-eligible gate: with config off the gate itself returns skipped,
        // and the orchestrator never reaches generation.
        $orchestrator = new FrontierGenerationOrchestratorService(
            app(FoundryExhaustionRarityGateService::class),
            $generator,
            app(FrontierEvidenceBoundGate::class),
            app(FrontierDedupPriorArtGate::class),
            new FrontierJudgePanelGate([]),
            app(FrontierDecomposerGate::class),
            app(FrontierDriftMapperGate::class),
            app(FrontierMetricRollbackGate::class),
            app(FrontierProposalToGapCandidateAdapter::class),
            app(SelfDirectedEvolutionCurationInboxService::class),
        );

        $result = $orchestrator->run([
            'dossier' => $this->dossier(),
            'fixture_authorized' => true,
            // Even if a caller tries to flip eligibility, the orchestrator strips it.
            'gate_input' => ['exhaustion_rarity_gate_enabled' => true] + $this->gateInputEligible(),
        ]);

        $this->assertSame(FrontierGenerationOrchestratorService::STATUS_SKIPPED, $result['status']);
        $this->assertFalse($result['frontier_mode']);
        $this->assertSame(0, $result['proposals_generated']);
        $this->assertNull($result['curation_inbox']);
    }

    public function test_unauthorized_fixture_generator_is_refused_as_real_source(): void
    {
        config()->set('atlas.software_company_stewardship.frontier_mode', true);

        $orchestrator = $this->orchestrator([$this->goodProposal()], $this->eligibleGate());

        $result = $orchestrator->run([
            'dossier' => $this->dossier(),
            'fixture_authorized' => false, // not authorized => fixture refused
            'gate_input' => $this->gateInputEligible(),
        ]);

        $this->assertSame(FrontierGenerationOrchestratorService::STATUS_BLOCKED, $result['status']);
        $this->assertSame(FrontierGenerationOrchestratorService::DROP_FIXTURE_NOT_AUTHORIZED, $result['reason']);
        $this->assertNull($result['curation_inbox']);
    }
}
