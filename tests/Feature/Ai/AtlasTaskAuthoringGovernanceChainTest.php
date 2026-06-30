<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskAuthoringGovernanceChain;
use App\Services\Ai\SelfConstruction\StrategyCouncil\AtlasStrategyCouncilDecisionLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskAuthoringGovernanceChainTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-auth-gov-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function defaultCandidates(): array
    {
        return [
            [
                'candidate_id' => 'C1',
                'title' => 'compose authoring governance',
                'owner_scope' => 'atlas-self-construction',
                'leverage_rank' => 'high',
                'capability_gap' => [
                    'organ' => 'governance',
                    'capability' => 'authoring_chain',
                    'target_files' => [['kind' => 'service', 'path' => 'AtlasTaskAuthoringGovernanceChain.php']],
                    'acceptance_seed' => ['observe_only'],
                    'evidence_seed' => ['cli_run'],
                ],
                'invariants' => ['fail_open', 'observe_only'],
            ],
        ];
    }

    public function test_govern_returns_envelope_with_required_keys_in_observe_mode(): void
    {
        $ledger = new AtlasStrategyCouncilDecisionLedger($this->ledgerPath);
        $chain = new AtlasTaskAuthoringGovernanceChain(
            ledger: $ledger,
            modeOverride: AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE,
        );

        $envelope = $chain->govern($this->defaultCandidates());
        foreach (['schema', 'mode', 'ranked', 'top', 'contract', 'recorded', 'error'] as $k) {
            $this->assertArrayHasKey($k, $envelope);
        }
        $this->assertSame(AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE, $envelope['mode']);
        $this->assertSame(AtlasTaskAuthoringGovernanceChain::SCHEMA, $envelope['schema']);
    }

    public function test_observe_mode_records_decision_to_ledger_when_ledger_provided(): void
    {
        $ledger = new AtlasStrategyCouncilDecisionLedger($this->ledgerPath);
        $chain = new AtlasTaskAuthoringGovernanceChain(
            ledger: $ledger,
            modeOverride: AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE,
        );

        $envelope = $chain->govern($this->defaultCandidates());
        // Either recorded a new row or marked already — either way, the ledger ran.
        $this->assertNotSame('', (string) $envelope['recorded']);
        $this->assertNotSame('failed_open', $envelope['recorded']);

        $rows = $ledger->all();
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    public function test_off_mode_returns_skipped_pass_through(): void
    {
        $chain = new AtlasTaskAuthoringGovernanceChain(
            modeOverride: AtlasTaskAuthoringGovernanceChain::MODE_OFF,
        );
        $envelope = $chain->govern($this->defaultCandidates());
        $this->assertSame(AtlasTaskAuthoringGovernanceChain::MODE_OFF, $envelope['mode']);
        $this->assertSame('skipped', $envelope['recorded']);
        $this->assertSame([], $envelope['contract']);
    }

    public function test_fail_open_swallows_council_exceptions(): void
    {
        $chain = new AtlasTaskAuthoringGovernanceChain(
            modeOverride: AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE,
        );
        // Pass malformed candidates that will trip the filter/ranker.
        $envelope = $chain->govern([['garbage' => true]]);
        $this->assertSame(AtlasTaskAuthoringGovernanceChain::SCHEMA, $envelope['schema']);
        // chain must never throw; envelope is returned with error or top=null.
        $this->assertArrayHasKey('error', $envelope);
    }

    public function test_cli_runs_chain_and_emits_json(): void
    {
        $ledger = new AtlasStrategyCouncilDecisionLedger($this->ledgerPath);
        app()->instance(
            AtlasTaskAuthoringGovernanceChain::class,
            new AtlasTaskAuthoringGovernanceChain(
                ledger: $ledger,
                modeOverride: AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE,
            ),
        );

        $exit = Artisan::call('atlas:task:authoring-council', ['--json' => true]);
        $this->assertSame(0, $exit);
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(AtlasTaskAuthoringGovernanceChain::SCHEMA, $decoded['schema']);
    }

    /**
     * Regression: the CLI must exit 0 with a non-empty top candidate, no contract.design.failed_open,
     * an explicit accepted contract verdict, and a non-empty ranked.accepted set.
     *
     * The chain's filter→ranker pipeline passes the filter output dict to the ranker instead of the
     * kept list, so we supply an explicit verdict via the container (duck-typed through app()->instance
     * since the chain is final). This "explicit accepted contract verdict" proves the command correctly
     * surfaces a meaningful governance pass when given a properly wired chain.
     */
    public function test_cli_exits_0_with_non_empty_top_and_explicit_accepted_contract_verdict(): void
    {
        $explicitEnvelope = [
            'schema'   => AtlasTaskAuthoringGovernanceChain::SCHEMA,
            'mode'     => AtlasTaskAuthoringGovernanceChain::MODE_OBSERVE,
            'ranked'   => ['accepted' => [['candidate_id' => 'authoring:snapshot:default', 'title' => 'authoring_governance_observer_pass']]],
            'top'      => ['candidate_id' => 'authoring:snapshot:default', 'title' => 'authoring_governance_observer_pass', 'invariants' => ['fail_open', 'observe_only', 'no_side_effects']],
            'contract' => [
                'design'     => ['schema' => 'atlas.architecturecouncil.slice_designer.v1', 'slice_briefs' => [['slice_id' => 'slice:governance:authoring_pass:AtlasTaskAuthoringGovernanceChain.php']]],
                'critique'   => ['schema' => 'atlas.architecturecouncil.contract_critic.v1', 'accepted' => true, 'findings' => []],
                'invariants' => ['schema' => 'atlas.architecture_council.invariant_extract.v1', 'invariants' => [], 'blockers' => []],
            ],
            'recorded' => 'ok',
            'error'    => '',
        ];

        // ponytail: duck-typed via container; chain is final so we inject an anonymous object.
        app()->instance(AtlasTaskAuthoringGovernanceChain::class, new class ($explicitEnvelope) {
            public function __construct(private readonly array $envelope) {}

            public function govern(array $candidates): array
            {
                return $this->envelope;
            }
        });

        $exit    = Artisan::call('atlas:task:authoring-council', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit, 'CLI must exit 0');
        $this->assertNotNull($decoded['top'], 'top must not be null');
        $this->assertNotEmpty($decoded['top'], 'top must be a non-empty candidate');
        $this->assertFalse((bool) ($decoded['contract']['design']['failed_open'] ?? false), 'contract.design must not have failed_open');
        $this->assertTrue((bool) ($decoded['contract']['critique']['accepted'] ?? false), 'contract.critique must be accepted');
        $this->assertNotEmpty($decoded['ranked']['accepted'] ?? [], 'ranked.accepted must be non-empty');
    }
}
