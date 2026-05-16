<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class FailureCapsuleTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_issue_produces_deterministic_signature(): void
    {
        $a = FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'InvalidArgumentException: workspace must not be null',
            fullErrorLogPath: 'storage/atlas-dev/receipts/r1/failure_capsule.1.log',
            failingTest: 'tests/Foo::test_a',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            decision: FailureCapsule::DECISION_RETRY,
        );
        $b = FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'InvalidArgumentException:   workspace must not be null',
            fullErrorLogPath: 'storage/atlas-dev/receipts/r1/failure_capsule.1.log',
            failingTest: 'tests/Foo::test_a',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            decision: FailureCapsule::DECISION_RETRY,
        );

        // Whitespace differences normalize to same signature.
        $this->assertSame($a->failureSignature, $b->failureSignature);
    }

    public function test_round_trip_with_contract_surface(): void
    {
        $capsule = $this->makeBasic();
        $rebuilt = FailureCapsule::fromArray($capsule->toCanonicalArray());
        $this->assertSame($capsule->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertContractSurface($capsule);
    }

    public function test_post_hoc_review_preserves_capsule_hash(): void
    {
        $capsule = $this->makeBasic();
        $reviewed = $capsule->withPostHocReview('atlas', true, ['scope_explosion'], '2026-05-16T12:00:00Z');

        $this->assertSame($capsule->capsuleHash, $reviewed->capsuleHash);
        $this->assertSame($capsule->hash(), $reviewed->hash());
        $this->assertSame('atlas', $reviewed->postHocReviewer);
        $this->assertSame('2026-05-16T12:00:00Z', $reviewed->postHocReviewedAt);
        $this->assertTrue($reviewed->shouldHaveEscalated);
    }

    public function test_escalate_decision_requires_signal_delta(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: 'g',
            command: null,
            exitCode: 1,
            primaryErrorExcerpt: 'err',
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: null,
            changedFiles: [],
            decision: FailureCapsule::DECISION_ESCALATE,
            escalationSignalDelta: [],
        );
    }

    public function test_oversized_excerpt_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: 'g',
            command: null,
            exitCode: 1,
            primaryErrorExcerpt: str_repeat('x', 5000),
            fullErrorLogPath: null,
            failingTest: null,
            diffHash: null,
            changedFiles: [],
            decision: FailureCapsule::DECISION_RETRY,
        );
    }

    public function test_signature_of_helper_is_deterministic(): void
    {
        $sig1 = FailureCapsule::signatureOf('g', "abc\ndef");
        $sig2 = FailureCapsule::signatureOf('g', 'abc def');
        $this->assertSame($sig1, $sig2);
    }

    private function makeBasic(): FailureCapsule
    {
        return FailureCapsule::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            attemptIndex: 1,
            gate: 'verification_gate',
            command: 'composer test',
            exitCode: 1,
            primaryErrorExcerpt: 'TypeError: bad argument',
            fullErrorLogPath: 'storage/atlas-dev/receipts/r1/failure_capsule.1.log',
            failingTest: 'tests/Foo::a',
            diffHash: 'dh',
            changedFiles: ['app/Foo.php'],
            decision: FailureCapsule::DECISION_RETRY,
        );
    }
}
