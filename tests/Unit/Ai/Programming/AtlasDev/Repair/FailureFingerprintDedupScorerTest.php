<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\FailureFingerprintDedupScorer;
use PHPUnit\Framework\TestCase;

final class FailureFingerprintDedupScorerTest extends TestCase
{
    private FailureFingerprintDedupScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new FailureFingerprintDedupScorer();
    }

    public function testExactGateAndErrorScoresOneAsDuplicate(): void
    {
        $fingerprint = [
            'gate' => 'phpunit',
            'normalized_error' => 'undefined method foo',
            'failing_tests' => ['ExampleTest::testFoo'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];

        $result = $this->scorer->scoreDedup($fingerprint, $fingerprint);

        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame('duplicate', $result['verdict']);
    }

    public function testSameGateWithSharedFailingTestButDifferentErrorScoresZeroEightFive(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'undefined method foo',
            'failing_tests' => ['ExampleTest::testFoo', 'ExampleTest::testBar'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];
        $b = [
            'gate' => 'phpunit',
            'normalized_error' => 'undefined method baz',
            'failing_tests' => ['ExampleTest::testBar'],
            'error_class' => 'ValueError',
            'changed_dirs' => ['app/Models'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.85, $result['confidence']);
        $this->assertSame('duplicate', $result['verdict']);
    }

    public function testSameErrorClassWithOverlappingChangedDirScoresZeroSixAsNearDuplicate(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'assertion failed alpha',
            'failing_tests' => ['AlphaTest::testOne'],
            'error_class' => 'AssertionError',
            'changed_dirs' => ['app/Services', 'app/Http'],
        ];
        $b = [
            'gate' => 'phpstan',
            'normalized_error' => 'assertion failed beta',
            'failing_tests' => ['BetaTest::testTwo'],
            'error_class' => 'AssertionError',
            'changed_dirs' => ['app/Http', 'app/Console'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.6, $result['confidence']);
        $this->assertSame('near_duplicate', $result['verdict']);
    }

    public function testSameGateOnlyScoresZeroFourFiveAsDistinct(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'segfault in worker',
            'failing_tests' => ['WorkerTest::testRun'],
            'error_class' => 'RuntimeError',
            'changed_dirs' => ['app/Jobs'],
        ];
        $b = [
            'gate' => 'phpunit',
            'normalized_error' => 'timeout in queue',
            'failing_tests' => ['QueueTest::testDispatch'],
            'error_class' => 'TimeoutError',
            'changed_dirs' => ['app/Queue'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.45, $result['confidence']);
        $this->assertSame('distinct', $result['verdict']);
    }

    public function testDisjointWithPartialTokenOverlapScoresJaccardFractionAsDistinct(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'alpha beta gamma',
            'failing_tests' => ['AlphaTest::testOne'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];
        $b = [
            'gate' => 'phpstan',
            'normalized_error' => 'alpha delta epsilon zeta',
            'failing_tests' => ['BetaTest::testTwo'],
            'error_class' => 'ValueError',
            'changed_dirs' => ['app/Models'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        // intersection {alpha} = 1, union {alpha,beta,gamma,delta,epsilon,zeta} = 6 -> 1/6.
        $this->assertSame(1 / 6, $result['confidence']);
        $this->assertGreaterThan(0.0, $result['confidence']);
        $this->assertLessThan(1.0, $result['confidence']);
        $this->assertSame('distinct', $result['verdict']);
    }

    public function testDisjointWithNoTokenOverlapScoresZeroAsDistinct(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'one two three',
            'failing_tests' => ['OneTest::testA'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];
        $b = [
            'gate' => 'phpstan',
            'normalized_error' => 'four five six',
            'failing_tests' => ['TwoTest::testB'],
            'error_class' => 'ValueError',
            'changed_dirs' => ['app/Models'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame('distinct', $result['verdict']);
    }

    public function testCaseInsensitiveTokenJaccardWhenBothErrorEmptyUnionIsZero(): void
    {
        $a = [
            'gate' => '',
            'normalized_error' => '',
            'failing_tests' => [],
            'error_class' => '',
            'changed_dirs' => [],
        ];
        $b = [
            'gate' => '',
            'normalized_error' => '   ',
            'failing_tests' => [],
            'error_class' => '',
            'changed_dirs' => [],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame('distinct', $result['verdict']);
    }

    public function testTokenJaccardLowercasesBeforeComparing(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'FATAL Error HERE',
            'failing_tests' => ['AlphaTest::testOne'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];
        $b = [
            'gate' => 'phpstan',
            'normalized_error' => 'fatal error here',
            'failing_tests' => ['BetaTest::testTwo'],
            'error_class' => 'ValueError',
            'changed_dirs' => ['app/Models'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        // identical token sets after lowercasing -> jaccard 1.0.
        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame('duplicate', $result['verdict']);
    }

    public function testEmptyGateDoesNotCountAsSameGate(): void
    {
        $a = [
            'gate' => '',
            'normalized_error' => 'shared token',
            'failing_tests' => ['SharedTest::testX'],
            'error_class' => '',
            'changed_dirs' => [],
        ];
        $b = [
            'gate' => '',
            'normalized_error' => 'shared token',
            'failing_tests' => ['SharedTest::testX'],
            'error_class' => '',
            'changed_dirs' => [],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        // both gates empty => rules 1,2,4 cannot fire; error_class empty => rule 3 cannot fire.
        // identical normalized_error tokens => jaccard 1.0.
        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame('duplicate', $result['verdict']);
    }

    public function testScoreIsDeterministicAcrossRepeatedCalls(): void
    {
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'flaky boundary',
            'failing_tests' => ['FlakyTest::testEdge'],
            'error_class' => 'RangeError',
            'changed_dirs' => ['app/Support'],
        ];
        $b = [
            'gate' => 'phpunit',
            'normalized_error' => 'flaky boundary',
            'failing_tests' => ['FlakyTest::testEdge'],
            'error_class' => 'RangeError',
            'changed_dirs' => ['app/Support'],
        ];

        $first = $this->scorer->scoreDedup($a, $b);
        $second = $this->scorer->scoreDedup($a, $b);

        $this->assertSame($first, $second);
        $this->assertSame(1.0, $first['confidence']);
        $this->assertSame('duplicate', $first['verdict']);
    }

    public function testGateMatchOutranksErrorClassDirOverlap(): void
    {
        // Both rule (2) [same gate + shared test] and rule (3) [same error_class + dir overlap]
        // would fire; highest-first ordering must return 0.85, not 0.6.
        $a = [
            'gate' => 'phpunit',
            'normalized_error' => 'left error',
            'failing_tests' => ['RankTest::testShared'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];
        $b = [
            'gate' => 'phpunit',
            'normalized_error' => 'right error',
            'failing_tests' => ['RankTest::testShared'],
            'error_class' => 'TypeError',
            'changed_dirs' => ['app/Services'],
        ];

        $result = $this->scorer->scoreDedup($a, $b);

        $this->assertSame(0.85, $result['confidence']);
        $this->assertSame('duplicate', $result['verdict']);
    }
}
