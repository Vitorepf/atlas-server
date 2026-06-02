<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L7PromotionExecutor;
use PHPUnit\Framework\TestCase;

final class L7PromotionExecutorTest extends TestCase
{
    private L7PromotionExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = new L7PromotionExecutor();
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'from_level' => 'L6',
            'to_level' => 'L7',
            'request_validated' => true,
            'operator_signature' => 'op-sig-abc',
            'architect_signature' => 'arch-sig-xyz',
            'trust_ledger_score' => 0.97,
            'invariant_breach_count' => 0,
            'rollback_window_seconds' => 600,
        ];
    }

    public function testValidatedSignedRequestAppliesPromotionToL7(): void
    {
        $result = $this->executor->execute($this->validRequest());

        $this->assertSame('atlas.autonomy.promotion.executed.v1', $result['schema_version']);
        $this->assertTrue($result['applied']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(['operator_signature', 'architect_signature'], $result['required_signatures']);

        $this->assertArrayHasKey('promotion_receipt', $result);
        $this->assertSame('L6', $result['promotion_receipt']['from_level']);
        $this->assertSame('L7', $result['promotion_receipt']['to_level']);
        $this->assertTrue($result['promotion_receipt']['applied']);
    }

    public function testMissingArchitectSignatureBlocksWithoutStateChange(): void
    {
        $request = $this->validRequest();
        unset($request['architect_signature']);

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('needs_human_signature', $result['blockers']);
        // No state change: current_level stays at the request's from_level (L6).
        $this->assertSame('L6', $result['current_level']);
        $this->assertSame('L6', $result['promotion_receipt']['current_level']);
    }

    public function testMissingOperatorSignatureBlocksWithNeedsHumanSignature(): void
    {
        $request = $this->validRequest();
        $request['operator_signature'] = '   ';

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('needs_human_signature', $result['blockers']);
        $this->assertSame('L6', $result['current_level']);
        $this->assertFalse($result['promotion_receipt']['operator_signature_present']);
    }

    public function testRollbackWindowIsRequired(): void
    {
        $request = $this->validRequest();
        unset($request['rollback_window_seconds']);

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('rollback_window_missing', $result['blockers']);
        $this->assertNull($result['promotion_receipt']['rollback_window_seconds']);
    }

    public function testZeroRollbackWindowDoesNotSatisfyRequirement(): void
    {
        $request = $this->validRequest();
        $request['rollback_window_seconds'] = 0;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('rollback_window_missing', $result['blockers']);
    }

    public function testTrustBelowThresholdBlocks(): void
    {
        $request = $this->validRequest();
        $request['trust_ledger_score'] = 0.949;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('trust_below_threshold', $result['blockers']);
        $this->assertSame('L6', $result['current_level']);
    }

    public function testTrustExactlyAtThresholdIsAllowed(): void
    {
        $request = $this->validRequest();
        $request['trust_ledger_score'] = 0.95;

        $result = $this->executor->execute($request);

        $this->assertTrue($result['applied']);
        $this->assertNotContains('trust_below_threshold', $result['blockers']);
        $this->assertSame('L7', $result['current_level']);
    }

    public function testInvariantBreachBlocksPromotion(): void
    {
        $request = $this->validRequest();
        $request['invariant_breach_count'] = 1;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invariant_breach', $result['blockers']);
        $this->assertSame(1, $result['promotion_receipt']['invariant_breach_count']);
    }

    public function testNumericStringInvariantBreachCountStillBlocks(): void
    {
        // A breach count arriving as a numeric string (e.g. from a JSON/HTTP
        // payload) must NOT be silently read as zero breaches. The sovereign
        // breach gate fails closed: 5 breaches block the promotion.
        $request = $this->validRequest();
        $request['invariant_breach_count'] = '5';

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invariant_breach', $result['blockers']);
        $this->assertSame(5, $result['promotion_receipt']['invariant_breach_count']);
    }

    public function testFloatInvariantBreachCountStillBlocks(): void
    {
        $request = $this->validRequest();
        $request['invariant_breach_count'] = 2.9;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invariant_breach', $result['blockers']);
        $this->assertSame(2, $result['promotion_receipt']['invariant_breach_count']);
    }

    public function testUnparseableInvariantBreachCountFailsClosed(): void
    {
        // A present but unparseable breach signal must take the safe path and block,
        // never accidentally promote by being treated as zero breaches.
        $request = $this->validRequest();
        $request['invariant_breach_count'] = ['unexpected' => 'shape'];

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invariant_breach', $result['blockers']);
    }

    public function testHugeFloatInvariantBreachCountFailsClosedWithoutWrap(): void
    {
        // A breach count arriving as a float beyond the representable int range
        // (e.g. a malformed/garbage payload) must NOT wrap to a negative int and
        // get floored to "zero breaches" — that would fail OPEN and promote on a
        // breach signal. It saturates to a large positive count and blocks.
        $request = $this->validRequest();
        $request['invariant_breach_count'] = 9.9e18;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invariant_breach', $result['blockers']);
        $this->assertSame('L6', $result['current_level']);
        $this->assertGreaterThan(0, $result['promotion_receipt']['invariant_breach_count']);

        // A non-finite breach signal (±INF / NaN) is unparseable-as-a-count and
        // also fails closed rather than reading as zero breaches.
        $infRequest = $this->validRequest();
        $infRequest['invariant_breach_count'] = INF;

        $inf = $this->executor->execute($infRequest);

        $this->assertFalse($inf['applied']);
        $this->assertContains('invariant_breach', $inf['blockers']);
        $this->assertGreaterThan(0, $inf['promotion_receipt']['invariant_breach_count']);
    }

    public function testUnvalidatedRequestIsBlocked(): void
    {
        $request = $this->validRequest();
        unset($request['request_validated']);

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('invalid_request', $result['blockers']);
        $this->assertSame('L6', $result['current_level']);
    }

    public function testWrongLevelPairIsBlocked(): void
    {
        $request = $this->validRequest();
        $request['from_level'] = 'L5';
        $request['to_level'] = 'L6';

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('not_l6_to_l7', $result['blockers']);
    }

    public function testPresentNonStringLevelDoesNotFailOpenIntoDefault(): void
    {
        // A level field present as a NON-STRING (e.g. an int rung from a JSON
        // payload) must NOT be silently coerced to the expected default and slip
        // past the sovereign rung guard: `from_level => 5` means "L5" (a wrong
        // source rung), and promoting it as if it were L6 would fail OPEN.
        $request = $this->validRequest();
        $request['from_level'] = 5;
        $request['to_level'] = 6;

        $result = $this->executor->execute($request);

        $this->assertFalse($result['applied']);
        $this->assertContains('not_l6_to_l7', $result['blockers']);
        // No state change: stays at the (defaulted) from_level, never L7.
        $this->assertNotSame('L7', $result['current_level']);

        // An array-shaped level is likewise malformed and must block, not default.
        $arrayLevels = $this->validRequest();
        $arrayLevels['from_level'] = ['L6'];
        $arrayLevels['to_level'] = ['L7'];

        $arrayResult = $this->executor->execute($arrayLevels);

        $this->assertFalse($arrayResult['applied']);
        $this->assertContains('not_l6_to_l7', $arrayResult['blockers']);
    }

    public function testAbsentLevelsStillDefaultToL6ToL7Rung(): void
    {
        // A genuinely absent (or explicit-null) level still defaults so the
        // receipt names the L6 -> L7 rung — only present-but-malformed levels
        // fail the guard.
        $request = $this->validRequest();
        unset($request['from_level'], $request['to_level']);

        $result = $this->executor->execute($request);

        $this->assertTrue($result['applied']);
        $this->assertNotContains('not_l6_to_l7', $result['blockers']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertSame('L6', $result['promotion_receipt']['from_level']);
    }

    public function testProductModeNotificationEmittedOnlyWhenApplied(): void
    {
        $applied = $this->executor->execute($this->validRequest());
        $this->assertTrue($applied['applied']);
        $this->assertArrayHasKey('product_mode_notification', $applied['promotion_receipt']);
        $this->assertSame(
            'l7_promotion_applied_operator_notice',
            $applied['promotion_receipt']['product_mode_notification'],
        );

        $blockedRequest = $this->validRequest();
        unset($blockedRequest['operator_signature']);
        $blocked = $this->executor->execute($blockedRequest);

        $this->assertFalse($blocked['applied']);
        $this->assertArrayNotHasKey('product_mode_notification', $blocked['promotion_receipt']);
    }

    public function testRequiredSignaturesContractIsAlwaysPresentAndTyped(): void
    {
        $result = $this->executor->execute(['from_level' => 'L6', 'to_level' => 'L7']);

        $this->assertSame(['operator_signature', 'architect_signature'], $result['required_signatures']);
        foreach ($result['required_signatures'] as $signature) {
            $this->assertIsString($signature);
        }
    }

    public function testTrustScoreIsClampedToUnitUpperBound(): void
    {
        $request = $this->validRequest();
        $request['trust_ledger_score'] = 1.7;

        $result = $this->executor->execute($request);

        // A 0..1 score must never exceed 1.0 for any input.
        $this->assertLessThanOrEqual(1.0, $result['promotion_receipt']['trust_ledger_score']);
        $this->assertSame(1.0, $result['promotion_receipt']['trust_ledger_score']);
        $this->assertTrue($result['applied']);
    }

    public function testNonFiniteTrustScoreFailsClosedAndKeepsReceiptInUnitBounds(): void
    {
        // NaN slips past both the unit clamp (NaN is neither <0 nor >1) and the
        // `< threshold` gate (every NaN comparison is false). It must NOT fail
        // open into a promotion, and the receipt score must stay within 0..1.
        $nanRequest = $this->validRequest();
        $nanRequest['trust_ledger_score'] = NAN;

        $nan = $this->executor->execute($nanRequest);

        $this->assertFalse($nan['applied']);
        $this->assertContains('trust_below_threshold', $nan['blockers']);
        $score = $nan['promotion_receipt']['trust_ledger_score'];
        $this->assertFalse(is_nan($score));
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);

        // +INF is not "perfect trust": a non-finite score blocks too.
        $infRequest = $this->validRequest();
        $infRequest['trust_ledger_score'] = INF;

        $inf = $this->executor->execute($infRequest);

        $this->assertFalse($inf['applied']);
        $this->assertContains('trust_below_threshold', $inf['blockers']);
        $this->assertLessThanOrEqual(1.0, $inf['promotion_receipt']['trust_ledger_score']);
    }

    public function testNegativeTrustScoreIsClampedToZeroAndBlocks(): void
    {
        $request = $this->validRequest();
        $request['trust_ledger_score'] = -3.0;

        $result = $this->executor->execute($request);

        $this->assertGreaterThanOrEqual(0.0, $result['promotion_receipt']['trust_ledger_score']);
        $this->assertSame(0.0, $result['promotion_receipt']['trust_ledger_score']);
        $this->assertFalse($result['applied']);
        $this->assertContains('trust_below_threshold', $result['blockers']);
    }

    public function testMultipleDeficienciesAccumulateAllBlockers(): void
    {
        $result = $this->executor->execute([
            'from_level' => 'L6',
            'to_level' => 'L7',
            // unvalidated, no signatures, low trust, breach present, no rollback window
            'trust_ledger_score' => 0.10,
            'invariant_breach_count' => 2,
        ]);

        $this->assertFalse($result['applied']);
        $this->assertSame(
            [
                'invalid_request',
                'needs_human_signature',
                'trust_below_threshold',
                'invariant_breach',
                'rollback_window_missing',
            ],
            $result['blockers'],
        );
        $this->assertSame('L6', $result['current_level']);
    }

    public function testDeterministicForIdenticalInput(): void
    {
        $request = $this->validRequest();

        $first = $this->executor->execute($request);
        $second = $this->executor->execute($request);

        $this->assertSame($first, $second);
    }

    public function testGeneralisesToNovelValidRequestNotInOtherCases(): void
    {
        // Inputs deliberately distinct from every other test case.
        $result = $this->executor->execute([
            'from_level' => 'l6',
            'to_level' => 'l7',
            'validation_status' => 'VALIDATED',
            'operator_signature' => 'novel-operator-7731',
            'architect_signature' => 'novel-architect-9920',
            'trust_ledger_score' => 0.9612,
            'invariant_breach_count' => 0,
            'rollback_window_seconds' => 1234,
        ]);

        $this->assertTrue($result['applied']);
        $this->assertSame('L7', $result['current_level']);
        $this->assertSame([], $result['blockers']);
        $this->assertEqualsWithDelta(0.9612, $result['promotion_receipt']['trust_ledger_score'], 1e-9);
        $this->assertSame(1234, $result['promotion_receipt']['rollback_window_seconds']);
        $this->assertArrayHasKey('product_mode_notification', $result['promotion_receipt']);
    }
}
