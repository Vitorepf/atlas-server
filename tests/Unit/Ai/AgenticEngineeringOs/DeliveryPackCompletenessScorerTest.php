<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\DeliveryPackCompletenessScorer;
use InvalidArgumentException;
use Tests\TestCase;

final class DeliveryPackCompletenessScorerTest extends TestCase
{
    private DeliveryPackCompletenessScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new DeliveryPackCompletenessScorer();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function composition(array $overrides = []): array
    {
        return array_merge([
            'changed_files' => 3,
            'test_evidence' => ['tests/Feature/ExampleTest.php'],
            'no_test_reason' => '',
            'evidence_hashes' => ['sha256:aa'],
            'risk_register_present' => true,
            'receipt_present' => true,
            'delivery_hash' => 'sha256:signed-delivery',
        ], $overrides);
    }

    public function testSchemaFieldIsPresentAndVersioned(): void
    {
        $result = $this->scorer->score($this->composition());

        self::assertSame('atlas.aaeos.delivery_pack_completeness.v1', $result['schema']);
    }

    public function testRuleOneUnsignedHashFailsEvenWithEveryFactorTrue(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 4,
            'test_evidence' => ['t1', 't2'],
            'evidence_hashes' => ['h1', 'h2'],
            'risk_register_present' => true,
            'receipt_present' => true,
            'delivery_hash' => '',
        ]));

        self::assertSame('failed', $result['status']);
        self::assertContains('missing_signed_delivery_hash', $result['blockers']);
        self::assertFalse($result['hash_signed']);
        self::assertTrue($result['factors']['files_have_evidence']);
        self::assertTrue($result['factors']['tests_present']);
        self::assertTrue($result['factors']['evidence_present']);
        self::assertTrue($result['factors']['receipt_present']);
        self::assertTrue($result['factors']['risk_register_present']);
    }

    public function testRuleTwoChangedFilesWithoutEvidenceHashesFails(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 2,
            'evidence_hashes' => [],
        ]));

        self::assertSame('failed', $result['status']);
        self::assertContains('evidence_hashes_required_for_changes', $result['blockers']);
        self::assertFalse($result['factors']['files_have_evidence']);
        self::assertFalse($result['factors']['evidence_present']);
    }

    public function testRuleThreeCompletePackPasses(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 5,
            'test_evidence' => ['suite-green'],
            'evidence_hashes' => ['sha256:bb'],
            'receipt_present' => true,
            'risk_register_present' => true,
            'delivery_hash' => 'sha256:full',
        ]));

        self::assertSame(1.0, $result['ratio']);
        self::assertSame('passed', $result['status']);
        self::assertSame([], $result['blockers']);
    }

    public function testRuleFourMissingTestsWithoutReasonNeedsReview(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 3,
            'evidence_hashes' => ['sha256:cc'],
            'receipt_present' => true,
            'risk_register_present' => true,
            'test_evidence' => [],
            'no_test_reason' => '',
            'delivery_hash' => 'sha256:signed',
        ]));

        self::assertSame('needs_review', $result['status']);
        self::assertNotSame('failed', $result['status']);
        self::assertNotSame('passed', $result['status']);
        self::assertFalse($result['factors']['tests_present']);
        self::assertSame(0.8, $result['ratio']);
        self::assertTrue($result['ratio'] < 1.0);
    }

    public function testRuleFiveZeroChangedFilesHasIndependentFactors(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 0,
            'evidence_hashes' => [],
            'test_evidence' => ['probe'],
            'receipt_present' => true,
            'risk_register_present' => true,
            'delivery_hash' => 'sha256:signed',
        ]));

        self::assertTrue($result['factors']['files_have_evidence']);
        self::assertFalse($result['factors']['evidence_present']);
        self::assertSame(0.8, $result['ratio']);
        self::assertSame('needs_review', $result['status']);
    }

    public function testEmptyCompositionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->scorer->score([]);
    }

    public function testMissingKeyThrows(): void
    {
        $composition = $this->composition();
        unset($composition['delivery_hash']);

        $this->expectException(InvalidArgumentException::class);

        $this->scorer->score($composition);
    }

    public function testMissingReceiptKeyThrows(): void
    {
        $composition = $this->composition();
        unset($composition['receipt_present']);

        $this->expectException(InvalidArgumentException::class);

        $this->scorer->score($composition);
    }

    public function testRatioGeneralisesWithThreeSatisfiedFactors(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 0,
            'evidence_hashes' => [],
            'test_evidence' => ['t'],
            'receipt_present' => true,
            'risk_register_present' => false,
            'delivery_hash' => 'sha256:signed',
        ]));

        // files_have_evidence(true, no files) + tests_present(true)
        // + receipt_present(true) = 3 of 5 => 0.6.
        self::assertSame(0.6, $result['ratio']);
        self::assertSame('needs_review', $result['status']);
        self::assertSame([], $result['blockers']);
        self::assertFalse($result['factors']['risk_register_present']);
        self::assertFalse($result['factors']['evidence_present']);
    }

    public function testRatioGeneralisesWithSingleSatisfiedFactor(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 0,
            'evidence_hashes' => [],
            'test_evidence' => [],
            'no_test_reason' => '',
            'receipt_present' => false,
            'risk_register_present' => false,
            'delivery_hash' => 'sha256:signed',
        ]));

        // only files_have_evidence is true (no files) => 1 of 5 => 0.2.
        self::assertSame(0.2, $result['ratio']);
        self::assertSame('needs_review', $result['status']);
        self::assertFalse($result['factors']['tests_present']);
        self::assertFalse($result['factors']['receipt_present']);
        self::assertFalse($result['factors']['risk_register_present']);
    }

    public function testBothHardGatesStackInBlockers(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 7,
            'evidence_hashes' => [],
            'delivery_hash' => '   ',
        ]));

        self::assertSame('failed', $result['status']);
        self::assertContains('missing_signed_delivery_hash', $result['blockers']);
        self::assertContains('evidence_hashes_required_for_changes', $result['blockers']);
        self::assertFalse($result['hash_signed']);
        self::assertSame(2, count($result['blockers']));
    }

    public function testWhitespaceOnlyDeliveryHashIsTreatedAsUnsigned(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 0,
            'evidence_hashes' => ['h'],
            'delivery_hash' => "\t  \n",
        ]));

        self::assertFalse($result['hash_signed']);
        self::assertSame('failed', $result['status']);
        self::assertContains('missing_signed_delivery_hash', $result['blockers']);
    }

    public function testFilesHaveEvidenceTrueWhenChangedFilesPositiveAndHashesPresent(): void
    {
        $result = $this->scorer->score($this->composition([
            'changed_files' => 9,
            'evidence_hashes' => ['only-one'],
            'test_evidence' => ['t'],
            'receipt_present' => true,
            'risk_register_present' => true,
            'delivery_hash' => 'sha256:signed',
        ]));

        self::assertTrue($result['factors']['files_have_evidence']);
        self::assertTrue($result['factors']['evidence_present']);
        self::assertSame(1.0, $result['ratio']);
        self::assertSame('passed', $result['status']);
    }
}
