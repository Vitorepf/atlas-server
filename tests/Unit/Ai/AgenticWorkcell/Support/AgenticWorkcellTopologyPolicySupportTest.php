<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticWorkcell\Support;

use App\Services\Ai\AgenticWorkcell\AtlasAgenticWorkcellRuntimeService;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport as Support;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AAWR topology/domain/risk residual — no I/O, no host service, no DB.
 *
 * Explicit path proof: AtlasAgenticWorkcellRuntimeService imports Support and no
 * longer declares the peeled private topology-policy methods.
 */
final class AgenticWorkcellTopologyPolicySupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/AgenticWorkcell/Support/AgenticWorkcellTopologyPolicySupport.php';

    private const HOST_PATH = 'app/Services/Ai/AgenticWorkcell/AtlasAgenticWorkcellRuntimeService.php';

    /** @var list<string> */
    private const PEELED = [
        'claimPolicy',
        'objective',
        'normalizeDomain',
        'classifyDomain',
        'flowForDomain',
        'complexityScore',
        'riskScore',
        'chooseTopology',
        'status',
        'executionOrderTopologyMap',
        'riskBand',
        'scoreTopology',
        'sanitizePayload',
        'circuitBreakerReceipt',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'objective',
        'normalizeDomain',
        'classifyDomain',
        'flowForDomain',
        'complexityScore',
        'riskScore',
        'chooseTopology',
        'status',
        'executionOrderTopologyMap',
        'riskBand',
        'scoreTopology',
        'sanitizePayload',
        'circuitBreakerReceipt',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport;',
            $hostSrc,
            'Host must import AgenticWorkcellTopologyPolicySupport',
        );
        foreach ([
            'AgenticWorkcellTopologyPolicySupport::objective',
            'AgenticWorkcellTopologyPolicySupport::normalizeDomain',
            'AgenticWorkcellTopologyPolicySupport::classifyDomain',
            'AgenticWorkcellTopologyPolicySupport::flowForDomain',
            'AgenticWorkcellTopologyPolicySupport::complexityScore',
            'AgenticWorkcellTopologyPolicySupport::riskScore',
            'AgenticWorkcellTopologyPolicySupport::chooseTopology',
            'AgenticWorkcellTopologyPolicySupport::status',
            'AgenticWorkcellTopologyPolicySupport::executionOrderTopologyMap',
            'AgenticWorkcellTopologyPolicySupport::riskBand',
            'AgenticWorkcellTopologyPolicySupport::scoreTopology',
            'AgenticWorkcellTopologyPolicySupport::sanitizePayload',
            'AgenticWorkcellTopologyPolicySupport::circuitBreakerReceipt',
            'AgenticWorkcellTopologyPolicySupport::claimPolicy',
            'AgenticWorkcellTopologyPolicySupport::TOPOLOGIES',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        foreach (self::PEELED_HOST_PRIVATES as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }

        $this->assertStringNotContainsString(
            'private const TOPOLOGIES',
            $hostSrc,
            'Host must not retain private TOPOLOGIES const after peel',
        );
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function claim_policy_is_planning_only_and_never_executes(): void
    {
        $policy = Support::claimPolicy();
        $this->assertTrue($policy['planning_only']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['agents_spawned']);
        $this->assertFalse($policy['external_execution_performed']);
        $this->assertTrue($policy['requires_areg_budget']);
        $this->assertTrue($policy['requires_independent_verification']);
        $this->assertTrue($policy['does_not_bypass_dev_or_forge']);
    }

    #[Test]
    public function objective_and_domain_classification_normalize_aliases(): void
    {
        $this->assertSame('AAWR workcell objective', Support::objective([]));
        $this->assertSame('ship forge runtime', Support::objective(['objective' => 'ship forge runtime']));
        $this->assertSame('from prompt', Support::objective(['prompt' => 'from prompt']));
        $this->assertSame('from text', Support::objective(['input_text' => 'from text']));

        $this->assertSame('programming', Support::normalizeDomain('dev'));
        $this->assertSame('programming', Support::normalizeDomain('code'));
        $this->assertSame('programming', Support::normalizeDomain('software'));
        $this->assertSame('strategy', Support::normalizeDomain('strategic'));
        $this->assertSame('finance', Support::normalizeDomain('finance'));

        $this->assertSame('programming', Support::classifyDomain('fix bug no forge runtime'));
        $this->assertSame('research', Support::classifyDomain('pesquisa de mercado e paper'));
        $this->assertSame('finance', Support::classifyDomain('invest na carteira'));
        $this->assertSame('marketing', Support::classifyDomain('campanha de copy'));
        $this->assertSame('strategy', Support::classifyDomain('decisao estrategica'));
        $this->assertSame('conversation', Support::classifyDomain('hello there'));
    }

    #[Test]
    public function flow_for_domain_picks_programming_task_and_domain_defaults(): void
    {
        $this->assertSame('atlas_forge', Support::flowForDomain('programming', ['task' => 'forge']));
        $this->assertSame('atlas_debug', Support::flowForDomain('programming', ['task' => 'debug']));
        $this->assertSame('atlas_review', Support::flowForDomain('programming', ['task' => 'review']));
        $this->assertSame('atlas_dev', Support::flowForDomain('programming', []));
        $this->assertSame('atlas_research', Support::flowForDomain('research', []));
        $this->assertSame('atlas_finance', Support::flowForDomain('finance', []));
        $this->assertSame('atlas_marketing', Support::flowForDomain('marketing', []));
        $this->assertSame('atlas_strategy', Support::flowForDomain('strategy', []));
        $this->assertSame('atlas_conversation', Support::flowForDomain('conversation', []));
    }

    #[Test]
    public function complexity_and_risk_scores_are_bounded_and_signal_sensitive(): void
    {
        $low = Support::complexityScore('short goal', 'conversation');
        $this->assertSame(3, $low);

        $boosted = Support::complexityScore(
            'enterprise obra multi autonom completo robusto research mercado certificacao meses',
            'research',
        );
        $this->assertGreaterThanOrEqual(7, $boosted);
        $this->assertLessThanOrEqual(10, $boosted);

        $this->assertSame(5, Support::riskScore('x', 'programming', ['e1'], []));
        $this->assertSame(6, Support::riskScore('x', 'programming', [], []));
        $this->assertSame(7, Support::riskScore('x', 'finance', ['e1'], []));
        $this->assertSame(10, Support::riskScore('x', 'conversation', [], [
            'external_execution_requested' => true,
        ]));
    }

    #[Test]
    public function choose_topology_respects_force_areg_block_and_domain_signals(): void
    {
        $this->assertSame(
            'tournament',
            Support::chooseTopology('obj', 'conversation', 'atlas_dev', 3, 3, ['topology' => 'tournament'], []),
        );
        $this->assertSame(
            'critic_chain',
            Support::chooseTopology('obj', 'conversation', 'atlas_dev', 3, 3, [], [
                'path' => AtlasRuntimeEfficiencyGovernorService::PATH_BLOCKED,
            ]),
        );
        $this->assertSame(
            'critic_chain',
            Support::chooseTopology('obj', 'conversation', 'atlas_dev', 3, 10, [], []),
        );
        $this->assertSame(
            'tool_builder_loop',
            Support::chooseTopology('build a new tool capability', 'programming', 'atlas_dev', 4, 4, [], []),
        );
        $this->assertSame(
            'forge_milestone_crew',
            Support::chooseTopology('obj', 'programming', 'atlas_forge', 4, 4, [], []),
        );
        $this->assertSame(
            'mapreduce_research',
            Support::chooseTopology('deep dive', 'research', 'atlas_research', 4, 4, [], []),
        );
        $this->assertSame(
            'red_blue_team',
            Support::chooseTopology('obj', 'strategy', 'atlas_strategy', 4, 4, [], []),
        );
        $this->assertSame(
            'lead_workers',
            Support::chooseTopology('obj', 'conversation', 'atlas_conversation', 8, 3, [], []),
        );
        $this->assertSame(
            'parallel_scouts',
            Support::chooseTopology('obj', 'conversation', 'atlas_conversation', 6, 3, [], []),
        );
        $this->assertSame(
            'solo_agent',
            Support::chooseTopology('obj', 'conversation', 'atlas_conversation', 3, 3, [], []),
        );
    }

    #[Test]
    public function status_risk_band_and_execution_order_map_are_deterministic(): void
    {
        $this->assertSame(
            AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED,
            Support::status('solo_agent', 10, [], ['external_execution_requested' => true]),
        );
        $this->assertSame(
            AtlasAgenticWorkcellRuntimeService::STATUS_WATCH,
            Support::status('solo_agent', 7, [], []),
        );
        $this->assertSame(
            AtlasAgenticWorkcellRuntimeService::STATUS_BLOCKED,
            Support::status('critic_chain', 10, ['e'], []),
        );
        $this->assertSame(
            AtlasAgenticWorkcellRuntimeService::STATUS_READY,
            Support::status('solo_agent', 3, [], []),
        );

        $this->assertSame('R0', Support::riskBand(1));
        $this->assertSame('R1', Support::riskBand(3));
        $this->assertSame('R2', Support::riskBand(5));
        $this->assertSame('R3', Support::riskBand(7));
        $this->assertSame('R4', Support::riskBand(9));
        $this->assertSame('R5', Support::riskBand(10));

        $this->assertSame('solo_agent', Support::executionOrderTopologyMap('single'));
        $this->assertSame('tournament', Support::executionOrderTopologyMap('candidate_set'));
        $this->assertSame('lead_workers', Support::executionOrderTopologyMap('workcell'));
        $this->assertSame('critic_chain', Support::executionOrderTopologyMap('DAG'));
        $this->assertSame('parallel_scouts', Support::executionOrderTopologyMap('portfolio'));
        $this->assertSame('unsupported', Support::executionOrderTopologyMap('unknown'));
    }

    #[Test]
    public function score_topology_applies_domain_bonus_and_penalties(): void
    {
        $research = Support::scoreTopology('mapreduce_research', 'mapreduce_research', 'research', 'atlas_research', 5, 4);
        $this->assertTrue($research['selected']);
        $this->assertSame(0.98, $research['predicted_quality']);
        $this->assertGreaterThan(0.5, $research['utility_score']);

        $soloHighRisk = Support::scoreTopology('solo_agent', 'solo_agent', 'finance', 'atlas_finance', 9, 9);
        $this->assertLessThan(
            Support::scoreTopology('solo_agent', 'solo_agent', 'conversation', 'atlas_conversation', 3, 2)['utility_score'],
            $soloHighRisk['utility_score'],
        );

        $forge = Support::scoreTopology('forge_milestone_crew', 'lead_workers', 'programming', 'atlas_forge', 5, 4);
        $this->assertFalse($forge['selected']);
        $this->assertSame(1.02, $forge['predicted_quality']);
    }

    #[Test]
    public function sanitize_payload_and_circuit_breaker_receipt_are_pure_shapers(): void
    {
        $clean = Support::sanitizePayload([
            'ok' => true,
            'raw_prompt' => 'secret-prompt',
            'provider_raw_output' => 'raw',
            'secret' => 'x',
            'credential' => 'y',
            'keep' => 'me',
        ]);
        $this->assertSame(['ok' => true, 'keep' => 'me'], $clean);

        $this->assertSame([], Support::circuitBreakerReceipt(null));
        $this->assertSame([], Support::circuitBreakerReceipt('nope'));

        $receipt = Support::circuitBreakerReceipt([
            'failure_fingerprint' => ' fp ',
            'evidence_delta_rounds' => 2,
            'candidate_id' => 'c1',
            'reason' => 'stop',
            'status' => 'open',
            'decision_hash' => 'dh',
            'noise' => 'drop',
        ]);
        $this->assertSame([
            'failure_fingerprint' => 'fp',
            'evidence_delta_rounds' => 2,
            'approach_id' => 'c1',
            'terminal_reason' => 'stop',
            'status' => 'open',
            'decision_hash' => 'dh',
        ], $receipt);
    }

    #[Test]
    public function support_source_has_no_io_or_di_seams(): void
    {
        $root = dirname(__DIR__, 5);
        $src = (string) file_get_contents($root.'/'.self::SUPPORT_PATH);

        foreach ([
            'app(',
            'base_path(',
            'config(',
            'file_exists(',
            'file_get_contents(',
            'Schema::',
            'DB::',
            'Storage::',
            'now(',
            'CarbonImmutable',
            'AtlasAgenticWorkcell::',
            'DatabaseTableAvailability',
        ] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "Support must remain pure; found seam: {$needle}",
            );
        }
    }

    #[Test]
    public function topologies_catalog_covers_all_ten_aawr_shapes(): void
    {
        $this->assertSame([
            'solo_agent',
            'lead_workers',
            'parallel_scouts',
            'debate_council',
            'tournament',
            'red_blue_team',
            'mapreduce_research',
            'forge_milestone_crew',
            'critic_chain',
            'tool_builder_loop',
        ], Support::TOPOLOGIES);
    }
}
