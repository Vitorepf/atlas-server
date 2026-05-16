<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\ScopeGuard;
use App\Services\Ai\Programming\AtlasDev\Gate\WorktreeBaseline;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeViolation;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

final class ScopeGuardTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function guard(): ScopeGuard
    {
        return new ScopeGuard();
    }

    private function diff(array $files, ?string $body = null): DiffParseResult
    {
        $body ??= $this->fakeDiff($files);

        return DiffParseResult::patch($body, $files);
    }

    private function fakeDiff(array $files): string
    {
        $diff = '';
        foreach ($files as $file) {
            $diff .= "--- a/{$file}\n+++ b/{$file}\n@@ -1,1 +1,1 @@\n-old\n+new\n";
        }

        return $diff;
    }

    public function test_passed_when_only_allowed_files_touched(): void
    {
        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: $this->diff(['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php']),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_PASSED, $receipt->status);
        $this->assertSame([], $receipt->violations);
        $this->assertSame(1, $receipt->observed->changedFilesCount);
    }

    public function test_failed_when_forbidden_file_touched(): void
    {
        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: $this->diff([
                'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
                'vendor/laravel/framework/Bad.php',
            ]),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_FAILED, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_FORBIDDEN_TOUCH, $kinds);
    }

    public function test_needs_review_when_watched_file_touched(): void
    {
        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture([
                'watched_files' => ['app/Console/**'],
                'max_files_changed' => 4,
            ]),
            diffResult: $this->diff([
                'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
                'app/Console/Kernel.php',
            ]),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_NEEDS_REVIEW, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_WATCHED_TOUCH, $kinds);
    }

    public function test_failed_when_max_files_exceeded(): void
    {
        $files = [
            'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
            'app/Services/Ai/Cli/Other.php',
            'app/Services/Ai/Cli/Another.php',
        ];

        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['app/Services/Ai/Cli/**'],
                'max_files_changed' => 1,
            ]),
            diffResult: $this->diff($files),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_FAILED, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_EXCEEDED_MAX_FILES, $kinds);
    }

    public function test_failed_when_unexpected_touch_is_outside_allowed(): void
    {
        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture([
                'allowed_files' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            ]),
            diffResult: $this->diff(['app/Services/Ai/Other/Thing.php']),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_NEEDS_REVIEW, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_UNEXPECTED_TOUCH, $kinds);
    }

    public function test_failed_when_pre_existing_user_change_was_not_preserved(): void
    {
        $baseline = new WorktreeBaseline(
            gitStatusBefore: ' M app/user-work.php',
            gitDiffBeforeHash: 'deadbeef',
            preExistingChanges: [
                new ScopePreExistingChange('app/user-work.php', preserved: false),
            ],
        );

        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: $this->diff(['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php']),
            baseline: $baseline,
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_FAILED, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_PRE_EXISTING_CHANGE, $kinds);
    }

    public function test_records_preserved_pre_existing_change_even_without_violation(): void
    {
        $baseline = new WorktreeBaseline(
            gitStatusBefore: ' M app/user-work.php',
            gitDiffBeforeHash: 'feed',
            preExistingChanges: [
                new ScopePreExistingChange('app/user-work.php', preserved: true),
            ],
        );

        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: $this->diff(['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php']),
            baseline: $baseline,
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_PASSED, $receipt->status);
        $this->assertSame(['app/user-work.php'], array_map(
            fn (ScopePreExistingChange $c) => $c->path,
            $receipt->userPreExistingChanges,
        ));
    }

    public function test_diff_overlap_with_preserved_pre_existing_marks_needs_review(): void
    {
        $baseline = new WorktreeBaseline(
            gitStatusBefore: ' M app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
            gitDiffBeforeHash: 'beef',
            preExistingChanges: [
                new ScopePreExistingChange('app/Services/Ai/Cli/AtlasCliDevWorkflowService.php', preserved: true),
            ],
        );

        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: $this->diff(['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php']),
            baseline: $baseline,
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_NEEDS_REVIEW, $receipt->status);
        $kinds = array_map(fn (ScopeViolation $v) => $v->kind, $receipt->violations);
        $this->assertContains(ScopeViolation::KIND_PRE_EXISTING_CHANGE, $kinds);
    }

    public function test_no_changed_files_means_passed_with_zero_violations(): void
    {
        $receipt = $this->guard()->check(
            envelope: $this->envelopeFixture(),
            taskContract: $this->taskContractFixture(),
            diffResult: DiffParseResult::noPatchNeeded('nothing to do'),
        );

        $this->assertSame(ScopeGuardReceipt::STATUS_PASSED, $receipt->status);
        $this->assertSame(0, $receipt->observed->changedFilesCount);
    }
}
