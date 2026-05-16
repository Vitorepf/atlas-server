<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeViolation;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

final class ScopeGuardReceiptTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_issue_seals_receipt_hash_deterministically(): void
    {
        $a = $this->validPassed();
        $b = $this->validPassed();
        $this->assertSame($a->receiptHash, $b->receiptHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a->receiptHash);
    }

    public function test_hash_excludes_self(): void
    {
        $r = $this->validPassed();
        $expected = CanonicalHasher::hashWithout($r->toCanonicalArray(), 'receipt_hash');
        $this->assertSame($expected, $r->hash());
    }

    public function test_round_trip(): void
    {
        $r = $this->validPassed();
        $rebuilt = ScopeGuardReceipt::fromArray($r->toCanonicalArray());
        $this->assertSame($r->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertContractSurface($r);
    }

    public function test_passed_with_violations_is_rejected(): void
    {
        $v = new ScopeViolation(kind: ScopeViolation::KIND_UNEXPECTED_TOUCH, path: 'p', detail: 'unexpected');
        $this->expectException(InvalidArgumentException::class);
        ScopeGuardReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: new ScopeObserved(gitDiffHash: null, changedFiles: [], changedFilesCount: 0, fileDiffs: []),
            scopeContract: new ScopeContractView(allowedFiles: [], watchedFiles: [], forbiddenFiles: [], expectedMaxFiles: 3),
            violations: [$v],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'should fail',
            userPreExistingChanges: [],
        );
    }

    public function test_forbidden_touch_forces_failed(): void
    {
        $v = new ScopeViolation(kind: ScopeViolation::KIND_FORBIDDEN_TOUCH, path: 'config/secret.php', detail: 'forbidden');
        $this->expectException(InvalidArgumentException::class);
        ScopeGuardReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: new ScopeObserved(gitDiffHash: null, changedFiles: [], changedFilesCount: 0, fileDiffs: []),
            scopeContract: new ScopeContractView(allowedFiles: [], watchedFiles: [], forbiddenFiles: ['config/secret.php'], expectedMaxFiles: 3),
            violations: [$v],
            status: ScopeGuardReceipt::STATUS_NEEDS_REVIEW,
            statusReason: 'should be failed',
            userPreExistingChanges: [],
        );
    }

    public function test_unpreserved_pre_existing_change_forces_failed(): void
    {
        $change = new ScopePreExistingChange(path: 'wip.md', preserved: false);
        $this->expectException(InvalidArgumentException::class);
        ScopeGuardReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            baseline: new ScopeBaseline(gitStatusBefore: 'dirty', gitDiffBeforeHash: 'h'),
            observed: new ScopeObserved(gitDiffHash: null, changedFiles: [], changedFilesCount: 0, fileDiffs: []),
            scopeContract: new ScopeContractView(allowedFiles: [], watchedFiles: [], forbiddenFiles: [], expectedMaxFiles: 3),
            violations: [],
            status: ScopeGuardReceipt::STATUS_NEEDS_REVIEW,
            statusReason: 'should be failed',
            userPreExistingChanges: [$change],
        );
    }

    public function test_unexpected_touch_allows_needs_review(): void
    {
        $v = new ScopeViolation(kind: ScopeViolation::KIND_UNEXPECTED_TOUCH, path: 'p', detail: 'd');
        $r = ScopeGuardReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: new ScopeObserved(gitDiffHash: null, changedFiles: ['p'], changedFilesCount: 1, fileDiffs: [
                new ScopeFileDiff(path: 'p', added: 1, removed: 0, fileHashAfter: 'h'),
            ]),
            scopeContract: new ScopeContractView(allowedFiles: ['a'], watchedFiles: [], forbiddenFiles: [], expectedMaxFiles: 3),
            violations: [$v],
            status: ScopeGuardReceipt::STATUS_NEEDS_REVIEW,
            statusReason: 'unexpected touch',
            userPreExistingChanges: [],
        );
        $this->assertFalse($r->isBlocking());
    }

    private function validPassed(): ScopeGuardReceipt
    {
        return ScopeGuardReceipt::issue(
            runId: 'r1',
            taskContractHash: 'tch-1',
            baseline: new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null),
            observed: new ScopeObserved(
                gitDiffHash: 'deadbeef',
                changedFiles: ['app/Foo.php'],
                changedFilesCount: 1,
                fileDiffs: [new ScopeFileDiff(path: 'app/Foo.php', added: 2, removed: 1, fileHashAfter: 'fhA')],
            ),
            scopeContract: new ScopeContractView(
                allowedFiles: ['app/Foo.php'],
                watchedFiles: [],
                forbiddenFiles: ['config/secret.php'],
                expectedMaxFiles: 3,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'within scope',
            userPreExistingChanges: [],
        );
    }
}
