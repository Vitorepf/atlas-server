<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AdvisorWitnessResolver;
use App\Services\Ai\EngineeringKernel\Spec\DivergenceStatus;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\OracleReport;
use App\Services\Ai\EngineeringKernel\Spec\SovereignSpecFloor;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecOracle;
use App\Services\Ai\EngineeringKernel\Spec\SpecReceipt;
use App\Services\Ai\EngineeringKernel\Spec\SpecShadowProvider;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use PHPUnit\Framework\TestCase;

/**
 * Slice 7 — the ORACLE-OF-THE-ORACLE. A fixed corpus of KNOWN-BAD spec drafts the adversary MUST NOT
 * freeze, and KNOWN-GOOD drafts it MUST freeze. This — not self-dogfood — is what proves the gate
 * discriminates; it fails loudly the day a future edit weakens any floor into a rubber stamp.
 * Wiper-safe: pure floor, ports faked, zero DB.
 */
final class SpecAdversaryGoldenCorpusTest extends TestCase
{
    private const GOOD_AC = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid email address', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
    ];

    private const ANCHORED_GOAL = 'adicionar validação em EmailValidator.php';

    /**
     * @return array<string,array{spec:array,intent:array,lane:TrustLevel,red:?array,div:DivergenceStatus}>
     */
    private function knownBad(): array
    {
        return [
            'tautological_green_on_noop' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Dev, 'red' => [], 'div' => DivergenceStatus::NotRequired,
            ],
            'e2_off_no_behavioral_criteria' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => [
                    ['id' => 'ac_cmd', 'description' => 'command exits 0', 'verification' => 'command', 'verification_ref' => 'c', 'is_backstop' => true],
                ]],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Dev, 'red' => [], 'div' => DivergenceStatus::NotRequired,
            ],
            'substring_verb_collision' => [
                'spec' => ['intent_text' => 'o sistema removerá caches em CacheManager.php', 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => 'o sistema removerá caches em CacheManager.php', 'recognized_verbs' => ['remover']],
                'lane' => TrustLevel::Dev, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::NotRequired,
            ],
            'vacuous_criterion' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => [
                    ['id' => 'ac_x', 'description' => 'ok', 'verification' => 'test', 'verification_ref' => 't1', 'case_class' => 'happy'],
                ]],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Dev, 'red' => ['ac_x'], 'div' => DivergenceStatus::NotRequired,
            ],
            'zero_anchor_goal' => [
                'spec' => ['intent_text' => 'faz o negócio funcionar', 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => 'faz o negócio funcionar', 'recognized_verbs' => []],
                'lane' => TrustLevel::Dev, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::NotRequired,
            ],
            'unmeasured_oracle' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Dev, 'red' => null, 'div' => DivergenceStatus::NotRequired,
            ],
            'autonomous_unwitnessed' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Autonomos, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::Unavailable,
            ],
            'shadow_diverged' => [
                'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
                'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
                'lane' => TrustLevel::Dev, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::Diverged,
            ],
        ];
    }

    public function test_every_known_bad_spec_is_never_frozen(): void
    {
        foreach ($this->knownBad() as $name => $c) {
            $verdict = $this->contest($c);
            self::assertNotSame(SpecVerdict::FREEZE, $verdict->status, "KNOWN-BAD '{$name}' must NOT freeze (got {$verdict->status})");
        }
    }

    public function test_a_known_good_spec_freezes(): void
    {
        $verdict = $this->contest([
            'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
            'lane' => TrustLevel::Dev, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::NotRequired,
        ]);

        self::assertSame(SpecVerdict::FREEZE, $verdict->status, 'gaps: '.implode(',', $verdict->gaps));
    }

    public function test_receipt_seals_deterministically_and_binds_the_frozen_hash(): void
    {
        $c = [
            'spec' => ['intent_text' => self::ANCHORED_GOAL, 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => self::ANCHORED_GOAL, 'recognized_verbs' => ['adicionar']],
            'lane' => TrustLevel::Dev, 'red' => ['ac_behavior_add'], 'div' => DivergenceStatus::NotRequired,
        ];
        $verdict = $this->contest($c);
        $receipt = SpecReceipt::seal($verdict, TrustLevel::Dev);

        self::assertSame(SpecReceipt::SCHEMA, $receipt['schema_version']);
        self::assertSame(SovereignSpecFloor::FLOOR_VERSION, $receipt['floor_version']);
        self::assertSame($verdict->provenance->frozenHash, $receipt['provenance']['frozen_hash']);
        self::assertSame($receipt['receipt_hash'], SpecReceipt::seal($this->contest($c), TrustLevel::Dev)['receipt_hash']);
    }

    /**
     * @param  array{spec:array,intent:array,lane:TrustLevel,red:?array,div:DivergenceStatus}  $c
     */
    private function contest(array $c): SpecVerdict
    {
        $oracle = new class($c['red']) implements SpecOracle
        {
            public function __construct(private ?array $red) {}

            public function probe(SpecDraft $draft): OracleReport
            {
                return $this->red === null ? OracleReport::unmeasured() : OracleReport::executional($this->red);
            }
        };
        $shadow = new class($c['div']) implements SpecShadowProvider
        {
            public function __construct(private DivergenceStatus $div) {}

            public function compare(SpecDraft $draft, IntentEnvelope $intent): DivergenceStatus
            {
                return $this->div;
            }
        };

        return (new SovereignSpecFloor($oracle, new AdvisorWitnessResolver($shadow)))
            ->contest(SpecDraft::fromArray($c['spec']), IntentEnvelope::fromArray($c['intent']), $c['lane']);
    }
}
