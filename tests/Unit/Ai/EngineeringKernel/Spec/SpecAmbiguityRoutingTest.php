<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\ClarificationSink;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SpecAmbiguityProducer;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 4 — the deterministic ambiguity PRODUCER (the channel Atlas had a consumer but no producer
 * for) + routing unresolved findings to the clarification queue. Wiper-safe: pure producer, sink faked.
 */
final class SpecAmbiguityRoutingTest extends TestCase
{
    private const GOOD_AC = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid email address', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
    ];

    public function test_producer_flags_a_goal_with_no_concrete_anchor(): void
    {
        $findings = SpecAmbiguityProducer::findings(
            SpecDraft::fromArray(['intent_text' => 'faz o negócio funcionar', 'acceptance_criteria' => self::GOOD_AC]),
            IntentEnvelope::fromArray(['raw_goal' => 'faz o negócio funcionar']),
        );

        self::assertContains('goal_has_no_concrete_anchor', $findings);
    }

    public function test_producer_flags_a_substring_verb_collision(): void
    {
        // 'remover' is a substring of 'removerá' but never a whole word => collision
        $findings = SpecAmbiguityProducer::findings(
            SpecDraft::fromArray(['intent_text' => 'o sistema removerá caches em CacheManager.php', 'acceptance_criteria' => self::GOOD_AC]),
            IntentEnvelope::fromArray(['raw_goal' => 'o sistema removerá caches em CacheManager.php', 'recognized_verbs' => ['remover']]),
        );

        self::assertContains('verb_collision:remover', $findings);
    }

    public function test_producer_flags_a_vacuous_criterion(): void
    {
        $findings = SpecAmbiguityProducer::findings(
            SpecDraft::fromArray([
                'intent_text' => 'ajustar Foo.php',
                'acceptance_criteria' => [['id' => 'ac_x', 'description' => 'ok', 'verification' => 'test', 'verification_ref' => 't']],
            ]),
            IntentEnvelope::fromArray(['raw_goal' => 'ajustar Foo.php']),
        );

        self::assertContains('vacuous_criterion:ac_x', $findings);
    }

    public function test_an_answered_finding_no_longer_holds(): void
    {
        // no anchor -> finding, BUT the operator already answered it (cache) -> resolved -> not held
        $floor = new AtlasSpecGateAdapter($this->oracle(OracleReport::executional(['ac_behavior_add'])));

        $verdict = $floor->contestDevSpec([
            'spec' => ['intent_text' => 'faz funcionar', 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => 'faz funcionar', 'elicited_answers' => ['goal_has_no_concrete_anchor' => 'app/Foo.php']],
        ], TrustLevel::Dev);

        self::assertNotContains('ambiguity_resolved', $verdict->gaps);
    }

    public function test_adapter_routes_unresolved_findings_to_the_clarification_sink(): void
    {
        $sink = new class implements ClarificationSink
        {
            /** @var list<string> */
            public array $received = [];

            public function enqueue(array $findings, IntentEnvelope $intent): void
            {
                $this->received = $findings;
            }
        };
        $adapter = new AtlasSpecGateAdapter($this->oracle(OracleReport::executional(['ac_behavior_add'])), null, 0, $sink);

        $verdict = $adapter->contestDevSpec([
            'spec' => ['intent_text' => 'faz funcionar', 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => 'faz funcionar'],
        ], TrustLevel::Dev);

        self::assertSame(SpecVerdict::HOLD, $verdict->status);
        self::assertContains('goal_has_no_concrete_anchor', $sink->received, 'the finding was enqueued for the operator');
    }

    private function oracle(OracleReport $report): SpecOracle
    {
        return new class($report) implements SpecOracle
        {
            public function __construct(private OracleReport $report) {}

            public function probe(SpecDraft $draft): OracleReport
            {
                return $this->report;
            }
        };
    }
}
