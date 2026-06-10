<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Organism;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\AtlasOrganismMissionService;
use App\Services\Ai\Organism\AtlasOrganismRegistry;
use App\Services\Ai\Organism\AtlasOrganismService;
use App\Services\Ai\Organism\DomainActuator;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainProposer;
use App\Services\Ai\Organism\DomainValidator;
use App\Services\Ai\Organism\Finance\FinanceDomainActuator;
use App\Services\Ai\Organism\Finance\FinanceDomainProposer;
use App\Services\Ai\Organism\Finance\FinanceDomainValidator;
use App\Services\Ai\Organism\OrganismBrainAnchor;
use App\Services\Ai\Organism\OrganismProposalRecorder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AOBG N4.F3 — the CROSS-DOMAIN MISSION SPINE: an intent SPANS domains.
 *
 * F1 proved the organism FRAMEWORK propose-only; F2 plugged the REAL finance domain in. F3
 * proves the cross-domain MISSION: one intent → reuse the N3 plan-DAG decomposition → route
 * each node to a DOMAIN → ARPTL-govern each crossing → a brain-anchored, honestly-validated
 * DOMAIN PROPOSAL per node → recorded into the brain (cross-domain COMPOUNDING). Every node's
 * actuation gate is requires_operator (the propose-only ceiling).
 *
 * COST: zero provider tokens. The decomposer + router + mesh are deterministic; the finance
 * proposer is the on-machine cost-free path; the registered 2nd domain is a deterministic
 * stub; the brain anchor + recorder are in-memory fakes. sqlite-only, no subprocess.
 *
 * The load-bearing assertions:
 *  - a cross-domain intent → a plan with nodes routed to >1 domain (finance + a 2nd);
 *  - each node a VALIDATED proposal (honest metric — never win-rate) at requires_operator;
 *  - a sensitive domain (finance) is handled on-machine (sensitive + no payload crosses);
 *  - the ARPTL veto is respected — a secret-domain crossing is BLOCKED (no proposal, vetoed);
 *  - cross-domain COMPOUNDING — a 2nd mission's per-domain query SEES the 1st's proposals.
 */
final class AtlasOrganismMissionServiceTest extends TestCase
{
    // ------------------------------------------------------------------
    // In-memory fakes (cost-free, sqlite-safe) — same shape as the F1 test.
    // ------------------------------------------------------------------

    private function fakeAnchor(): OrganismBrainAnchor
    {
        return new class implements OrganismBrainAnchor
        {
            public function anchor(string $intent, array $opts = []): array
            {
                return ['brain_refs' => ['ref:obra:1'], 'reality_graph_paths' => ['ref:obra:1'], 'sources_present' => ['reality_graph']];
            }
        };
    }

    private function captureRecorder(): OrganismProposalRecorder
    {
        return new class implements OrganismProposalRecorder
        {
            /** @var list<array<string,mixed>> */
            public array $recorded = [];

            public function record(DomainProposal $proposal, array $validation): array
            {
                $this->recorded[] = [
                    'ref' => $proposal->ref(),
                    'domain' => $proposal->domain,
                    'sensitive' => $proposal->sensitive,
                    'provider_safe_view' => $proposal->toProviderSafeArray(),
                    'validation' => $validation,
                ];

                return ['recorded' => true, 'node_ref' => 'node:'.$proposal->ref()];
            }

            public function priorProposals(?string $domain = null, int $limit = 10): array
            {
                $out = [];
                foreach (array_reverse($this->recorded) as $r) {
                    if ($domain !== null && $r['domain'] !== $domain) {
                        continue;
                    }
                    $out[] = ['ref' => $r['ref'], 'domain' => $r['domain'], 'sensitive' => $r['sensitive']];
                    if (count($out) >= $limit) {
                        break;
                    }
                }

                return $out;
            }
        };
    }

    /** A registered non-finance stub domain (marketing) so a mission can span >1 domain cost-free. */
    private function stubProposer(string $domain): DomainProposer
    {
        return new class($domain) implements DomainProposer
        {
            public function __construct(private string $d) {}

            public function propose(string $intent, array $brainContext = [], array $opts = []): DomainProposal
            {
                return DomainProposal::fromArray([
                    'domain' => $this->d,
                    'intent' => $intent,
                    'content' => 'draft for '.$this->d,
                    'rationale' => 'brain anchored stub',
                    'brain_refs' => (array) ($brainContext['brain_refs'] ?? []),
                ]);
            }

            public function domain(): string
            {
                return $this->d;
            }

            public function label(): string
            {
                return 'stub.'.$this->d;
            }
        };
    }

    private function stubValidator(string $domain): DomainValidator
    {
        return new class($domain) implements DomainValidator
        {
            public function __construct(private string $d) {}

            public function validate(DomainProposal $proposal): array
            {
                // An HONEST, non-vanity stub metric (never win-rate).
                return ['metric' => 'reach_estimate', 'value' => 1.0, 'passed' => true, 'method' => 'stub.deterministic'];
            }

            public function domain(): string
            {
                return $this->d;
            }
        };
    }

    private function stubActuator(string $domain): DomainActuator
    {
        return new class($domain) extends AbstractDomainActuator
        {
            public function __construct(private string $d) {}

            public function domain(): string
            {
                return $this->d;
            }
        };
    }

    /** Registry with the REAL finance domain + a registered marketing stub (2nd domain). */
    private function multiDomainRegistry(): AtlasOrganismRegistry
    {
        $registry = new AtlasOrganismRegistry;
        $registry->register(new FinanceDomainProposer, new FinanceDomainValidator(new HonestMetrics), new FinanceDomainActuator);
        $registry->register($this->stubProposer('marketing'), $this->stubValidator('marketing'), $this->stubActuator('marketing'));

        return $registry;
    }

    private function missionService(?OrganismProposalRecorder $recorder = null, ?AtlasOrganismRegistry $registry = null): AtlasOrganismMissionService
    {
        $registry ??= $this->multiDomainRegistry();
        $recorder ??= $this->captureRecorder();
        $organism = new AtlasOrganismService($registry, $this->fakeAnchor(), $recorder);

        return new AtlasOrganismMissionService($organism, $registry, new DeterministicObraDecomposer);
    }

    /** A positive-Sharpe candidate that clears the honest floor (no win-rate involved). */
    private function strongReturns(): array
    {
        $returns = [];
        for ($i = 0; $i < 60; $i++) {
            $returns[] = 0.004 + (($i % 3) - 1) * 0.0003;
        }

        return $returns;
    }

    private function createMissionTables(): void
    {
        if (! Schema::hasTable('atlas_organism_missions')) {
            Schema::create('atlas_organism_missions', function ($t): void {
                $t->string('id', 200)->primary();
                $t->string('intent', 1000);
                $t->string('workspace_id', 160)->index();
                $t->string('anchor_domain', 40)->default('engineering');
                $t->string('status', 24)->default('commissioned')->index();
                $t->json('meta')->default('{}');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_organism_nodes')) {
            Schema::create('atlas_organism_nodes', function ($t): void {
                $t->string('id', 240)->primary();
                $t->string('mission_id', 200)->index();
                $t->integer('seq');
                $t->string('title', 300);
                $t->text('request');
                $t->string('domain', 40)->index();
                $t->boolean('sensitive')->default(false);
                $t->string('outcome', 16)->default('proposed');
                $t->string('veto_reason', 200)->nullable();
                $t->string('proposal_ref', 240)->nullable();
                $t->json('validation')->default('{}');
                $t->string('actuation_gate', 32)->default('requires_operator');
                $t->json('depends_on')->default('[]');
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_organism_nodes');
        Schema::dropIfExists('atlas_organism_missions');
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) A cross-domain intent → a plan spanning >1 domain, each a validated
    //    propose-only proposal.
    // ------------------------------------------------------------------

    public function test_a_cross_domain_intent_spans_more_than_one_domain_each_a_validated_proposal(): void
    {
        $svc = $this->missionService();

        $mission = $svc->commission(
            'find a BTC/USDT trade idea on the market; then draft a marketing campaign for the audience',
            [
                'anchor_domain' => 'engineering',
                'persist' => false,
                'node_opts' => [
                    'find a BTC/USDT trade idea on the market' => ['payload' => ['daily_returns' => $this->strongReturns(), 'instrument' => 'BTC/USDT spot']],
                ],
            ],
        );

        // The plan SPANS more than one domain (the whole point of N4.F3).
        $this->assertGreaterThanOrEqual(2, count($mission['nodes']));
        $this->assertContains('finance', $mission['domains_spanned']);
        $this->assertContains('marketing', $mission['domains_spanned']);
        $this->assertGreaterThanOrEqual(2, count($mission['domains_spanned']));

        // Each node is a PROPOSED, honestly-validated proposal at requires_operator.
        $byDomain = [];
        foreach ($mission['nodes'] as $node) {
            $this->assertSame('requires_operator', $node['actuation_gate']);
            if ($node['outcome'] === AtlasOrganismMissionService::OUTCOME_PROPOSED) {
                $byDomain[$node['domain']] = $node;
                // honest metric, NEVER win-rate.
                $this->assertStringNotContainsString('win_rate', strtolower((string) ($node['validation']['metric'] ?? '')));
            }
        }
        $this->assertArrayHasKey('finance', $byDomain, 'a finance node must be proposed');
        $this->assertArrayHasKey('marketing', $byDomain, 'a marketing node must be proposed');

        // The finance node ran the REAL honest metric and cleared the floor.
        $this->assertSame('annualized_sharpe', $byDomain['finance']['validation']['metric']);
        $this->assertTrue($byDomain['finance']['validation']['passed']);

        // The whole mission carries the propose-only ceiling.
        $this->assertSame(AbstractDomainActuator::CEILING, $mission['ceiling']);
        $this->assertSame('requires_operator', $mission['actuation_gate']);
    }

    // ------------------------------------------------------------------
    // 2) SENSITIVE domain handled on-machine: the finance node is sensitive and
    //    no payload/secret ever crosses to the provider-safe proposal view.
    // ------------------------------------------------------------------

    public function test_sensitive_domain_node_is_handled_on_machine_no_payload_crosses(): void
    {
        $svc = $this->missionService();

        $mission = $svc->commission('find a crypto trade idea', [
            'anchor_domain' => 'engineering',
            'persist' => false,
            'node_opts' => [
                'find a crypto trade idea' => ['payload' => ['daily_returns' => $this->strongReturns(), 'secret_keys' => 'DO-NOT-LEAK']],
            ],
        ]);

        $finance = null;
        foreach ($mission['nodes'] as $node) {
            if ($node['domain'] === 'finance') {
                $finance = $node;
            }
        }
        $this->assertNotNull($finance, 'the crypto idea must route to finance');
        $this->assertTrue($finance['sensitive'], 'finance is sensitive — stays on-machine');

        // No payload / secret anywhere in the provider-safe mission node.
        $encoded = json_encode($finance) ?: '';
        $this->assertStringNotContainsString('DO-NOT-LEAK', $encoded);
        $this->assertStringNotContainsString('secret_keys', $encoded);
        $this->assertStringNotContainsString('daily_returns', $encoded);
    }

    // ------------------------------------------------------------------
    // 3) ARPTL veto respected: a SECRET-class cross-sensitive crossing is BLOCKED — no
    //    proposal crosses. anchor=finance (sensitive) → a health node (sensitive) ⇒ BOTH
    //    endpoints sensitive ⇒ secret class ⇒ vetoed (health is not in finance's trusted
    //    secret cluster). This is the load-bearing "secret-domain crossing blocked".
    // ------------------------------------------------------------------

    public function test_arptl_veto_blocks_a_secret_domain_crossing(): void
    {
        $svc = $this->missionService();

        $mission = $svc->commission('review the patient health diagnosis', [
            'anchor_domain' => 'finance',
            'persist' => false,
        ]);

        $health = null;
        foreach ($mission['nodes'] as $node) {
            if ($node['domain'] === 'health') {
                $health = $node;
            }
        }
        $this->assertNotNull($health, 'the diagnosis node must route to health');
        $this->assertTrue($health['sensitive'], 'health is sensitive');
        $this->assertSame(AtlasOrganismMissionService::OUTCOME_VETOED, $health['outcome'], 'finance→health at secret class must be ARPTL-vetoed');
        $this->assertNotEmpty($health['veto_reason']);
        $this->assertStringContainsString('secret_only_crosses_within_trusted_cluster', (string) $health['veto_reason']);

        // A VETOED node generated NO proposal — nothing crossed to a domain/provider.
        $this->assertArrayNotHasKey('proposal', $health);
        $this->assertSame('requires_operator', $health['actuation_gate']);
    }

    // ------------------------------------------------------------------
    // 3b) A health node (sensitive) crossing to a consumer audience is vetoed at
    //     sensitive class — and an allowed sensitive crossing (engineering→finance)
    //     still proposes (the veto is targeted, not a blanket block).
    // ------------------------------------------------------------------

    public function test_allowed_sensitive_crossing_still_proposes_while_audience_crossing_vetoes(): void
    {
        // engineering(anchor, non-sensitive) → finance(sensitive) ⇒ sensitive class ⇒ ALLOWED.
        $svc = $this->missionService();
        $mission = $svc->commission('find a market trade idea', [
            'anchor_domain' => 'engineering',
            'persist' => false,
            'node_opts' => ['find a market trade idea' => ['payload' => ['daily_returns' => $this->strongReturns()]]],
        ]);
        $finance = array_values(array_filter($mission['nodes'], static fn ($n): bool => $n['domain'] === 'finance'));
        $this->assertNotEmpty($finance);
        $this->assertSame(AtlasOrganismMissionService::OUTCOME_PROPOSED, $finance[0]['outcome'], 'engineering→finance is an allowed sensitive crossing');

        // health(anchor) → marketing(audience) ⇒ sensitive class ⇒ VETOED.
        $svc2 = $this->missionService();
        $mission2 = $svc2->commission('draft a marketing campaign for the audience', [
            'anchor_domain' => 'health',
            'persist' => false,
        ]);
        $marketing = array_values(array_filter($mission2['nodes'], static fn ($n): bool => $n['domain'] === 'marketing'));
        $this->assertNotEmpty($marketing);
        $this->assertSame(AtlasOrganismMissionService::OUTCOME_VETOED, $marketing[0]['outcome'], 'health→marketing (consumer audience) must be vetoed');
    }

    // ------------------------------------------------------------------
    // 4) CROSS-DOMAIN COMPOUNDING: a 2nd mission's per-domain propose SEES the 1st's
    //    proposals (the M× across width). The shared recorder carries it across missions.
    // ------------------------------------------------------------------

    public function test_cross_domain_compounding_second_mission_sees_first_missions_proposals(): void
    {
        $recorder = $this->captureRecorder();
        $svc = $this->missionService($recorder);

        // Mission 1: a finance + a marketing node → 2 proposals recorded into the brain.
        $first = $svc->commission('find a trade idea on the market; then draft a marketing campaign', [
            'persist' => false,
            'node_opts' => ['find a trade idea on the market' => ['payload' => ['daily_returns' => $this->strongReturns()]]],
        ]);
        $firstProposed = array_values(array_filter($first['nodes'], static fn ($n): bool => $n['outcome'] === AtlasOrganismMissionService::OUTCOME_PROPOSED));
        $this->assertGreaterThanOrEqual(2, count($firstProposed));
        // The first mission's finance node saw NO prior finance proposals.
        $firstFinance = array_values(array_filter($firstProposed, static fn ($n): bool => $n['domain'] === 'finance'))[0];
        $this->assertSame(0, $firstFinance['brain']['prior_proposals_seen']);

        // Mission 2 (SAME recorder = the persistent brain): a new finance node now SEES the
        // 1st mission's finance proposal — cross-mission, cross-domain compounding.
        $second = $svc->commission('find another trade idea on the market', [
            'persist' => false,
            'node_opts' => ['find another trade idea on the market' => ['payload' => ['daily_returns' => $this->strongReturns()]]],
        ]);
        $secondFinance = array_values(array_filter($second['nodes'], static fn ($n): bool => $n['domain'] === 'finance'))[0];
        $this->assertGreaterThanOrEqual(1, $secondFinance['brain']['prior_proposals_seen'], 'the 2nd mission must see the 1st mission proposals (compounding)');
    }

    // ------------------------------------------------------------------
    // 5) PERSIST + status: the mission persists provider-safe (no payload) and reads back.
    // ------------------------------------------------------------------

    public function test_mission_persists_provider_safe_and_status_reads_it_back(): void
    {
        $this->createMissionTables();
        $svc = $this->missionService();

        $mission = $svc->commission('find a crypto trade idea; then draft a marketing campaign', [
            'workspace' => 'ws-1',
            'persist' => true,
            'node_opts' => ['find a crypto trade idea' => ['payload' => ['daily_returns' => $this->strongReturns(), 'secret_keys' => 'DO-NOT-LEAK']]],
        ]);

        $status = $svc->status($mission['mission_id']);
        $this->assertNotNull($status);
        $this->assertSame($mission['mission_id'], $status['mission_id']);
        $this->assertSame(count($mission['nodes']), $status['node_count']);
        $this->assertContains('finance', $status['domains_spanned']);

        // Persisted rows are provider-safe: NO payload/secret anywhere in the read-back.
        $encoded = json_encode($status) ?: '';
        $this->assertStringNotContainsString('DO-NOT-LEAK', $encoded);
        $this->assertStringNotContainsString('secret_keys', $encoded);
        $this->assertStringNotContainsString('daily_returns', $encoded);

        // Every persisted node carries the propose-only gate.
        foreach ($status['nodes'] as $node) {
            $this->assertSame('requires_operator', $node['actuation_gate']);
        }
    }

    // ------------------------------------------------------------------
    // 6) An unregistered (but allowed) domain is honest no_handler — never fabricated.
    // ------------------------------------------------------------------

    public function test_a_routed_but_unregistered_domain_is_no_handler_not_fabricated(): void
    {
        // Only finance registered; a design node has no proposer.
        $registry = new AtlasOrganismRegistry;
        $registry->register(new FinanceDomainProposer, new FinanceDomainValidator(new HonestMetrics), new FinanceDomainActuator);
        $svc = $this->missionService(null, $registry);

        $mission = $svc->commission('produce a ux design mockup', ['persist' => false]);

        $design = array_values(array_filter($mission['nodes'], static fn ($n): bool => $n['domain'] === 'design'));
        $this->assertNotEmpty($design, 'the mockup must route to design');
        $this->assertSame(AtlasOrganismMissionService::OUTCOME_NO_HANDLER, $design[0]['outcome']);
        $this->assertArrayNotHasKey('proposal', $design[0]);
        $this->assertSame('requires_operator', $design[0]['actuation_gate']);
    }

    // ------------------------------------------------------------------
    // 7) Empty intent is refused (never a fabricated empty mission).
    // ------------------------------------------------------------------

    public function test_empty_intent_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->missionService()->commission('   ', ['persist' => false]);
    }
}
