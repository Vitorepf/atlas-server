<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AtlasDecide\DecisionReceiptFailurePatternMiner;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck;
use PHPUnit\Framework\TestCase;

/**
 * MAXK-09 — adversarial certification of MAXK-05/06/07/08.
 *
 * Every forgery the family defends against is probed here. If any adversarial
 * input passes, the check returns `alert` and the suite goes red — the
 * regression that reopens the boolean-forgeable gate at
 * `AtlasAutonomousLearningApplier::decideCandidate()` (the concrete mutation
 * MAXK-05 gates) fails the check.
 *
 * @covers \App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck
 */
final class Maxk09AutonomyLadderAdversarialWatchdogTest extends TestCase
{
    public function test_id_pins_to_maxk09_family(): void
    {
        $this->assertSame('maxk-09.autonomy_ladder_adversarial', $this->makeCheck()->id());
    }

    public function test_all_adversarial_probes_are_refused_on_green_baseline(): void
    {
        $result = $this->makeCheck()->run();

        $this->assertSame(AtlasWatchdogCheckResult::STATUS_OK, $result->status, 'green baseline must return ok');
        $this->assertSame([], $result->evidence['violations']);
        $this->assertSame([], $result->evidence['errors']);
        $this->assertSame(9, $result->evidence['probe_count'], 'probe count is the pinned surface');
        $this->assertSame(9, $result->evidence['refused_count']);
        $this->assertSame(
            'AtlasAutonomousLearningApplier::decideCandidate:pass',
            $result->evidence['source']['gates_mutation'],
            'MAXK-09 must name the concrete gated mutation, not generic gate logic',
        );
    }

    public function test_probe_ids_cover_maxk05_through_maxk08(): void
    {
        $result = $this->makeCheck()->run();
        $ids = array_map(static fn (array $probe): string => (string) $probe['id'], $result->evidence['probes']);

        $expected = [
            'maxk05.signature_forged_boolean',
            'maxk05.signature_receipt_missing',
            'maxk05.signature_nonce_reused',
            'maxk06.metrics_authority_missing',
            'maxk06.metrics_authority_tampered',
            'maxk07.privacy_sensitive_shrinks',
            'maxk07.reversal_rate_high_shrinks_to_draft',
            'maxk07.never_exceeds_ceiling',
            'maxk08.miner_report_only',
        ];
        $this->assertSame(sort($expected) ? $expected : $expected, $this->sorted($ids));
    }

    public function test_evidence_is_provider_safe_shape_only(): void
    {
        $result = $this->makeCheck()->run();
        $json = (string) json_encode($result->evidence, JSON_UNESCAPED_SLASHES);

        // Provider-safe by construction: the evidence must not carry raw
        // prompts or context; probes only carry small identifiers/reasons.
        $this->assertStringNotContainsString('prompt', strtolower($json));
        $this->assertStringNotContainsString('context', strtolower($json));
        $this->assertStringNotContainsString('markdown', strtolower($json));
    }

    private function makeCheck(): AutonomyLadderAdversarialWatchdogCheck
    {
        return new AutonomyLadderAdversarialWatchdogCheck(
            new AtlasAutonomyLadderRuntimeService,
            new DecisionReceiptFailurePatternMiner,
        );
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function sorted(array $items): array
    {
        sort($items);

        return array_values($items);
    }
}
