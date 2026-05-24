<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionInput;
use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\FocusedTestCommand;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\TestSelectionReceipt;
use PHPUnit\Framework\TestCase;

class TestSelectionIntelligenceServiceTest extends TestCase
{
    public function test_selects_convention_test_when_it_exists_with_high_confidence(): void
    {
        $existing = ['tests/Unit/FooTest.php'];

        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-1',
            taskContractHash: 'task-hash-1',
            changedFiles: ['app/Services/Foo.php'],
            fileExists: $this->fileProbe($existing),
        ));

        $this->assertCount(1, $receipt->focusedTestCommands);
        $cmd = $receipt->focusedTestCommands[0];
        $this->assertStringContainsString('tests/Unit/FooTest.php', $cmd->command);
        $this->assertSame(FocusedTestCommand::CONFIDENCE_HIGH, $cmd->confidence);
        $this->assertSame(TestSelectionReceipt::CONFIDENCE_HIGH, $receipt->overallConfidence);
        $this->assertNull($receipt->skippedTestsReason);
    }

    public function test_no_convention_match_produces_skipped_reason_and_low_confidence(): void
    {
        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-2',
            taskContractHash: 'task-hash-2',
            changedFiles: ['app/Services/Foo.php'],
            fileExists: $this->fileProbe([]),
        ));

        $this->assertSame([], $receipt->focusedTestCommands);
        $this->assertNotNull($receipt->skippedTestsReason);
        $this->assertStringContainsString('no_convention_match', $receipt->skippedTestsReason);
        $this->assertSame(TestSelectionReceipt::CONFIDENCE_LOW, $receipt->overallConfidence);
    }

    public function test_test_file_change_selects_itself_directly(): void
    {
        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-3',
            taskContractHash: 'task-hash-3',
            changedFiles: ['tests/Feature/Foo/BarTest.php'],
            fileExists: $this->fileProbe([]),
        ));

        $this->assertCount(1, $receipt->focusedTestCommands);
        $this->assertStringContainsString('tests/Feature/Foo/BarTest.php', $receipt->focusedTestCommands[0]->command);
        $this->assertSame('changed_test_file:tests/Feature/Foo/BarTest.php', $receipt->focusedTestCommands[0]->reason);
    }

    public function test_high_risk_adds_module_suite_with_medium_confidence(): void
    {
        $existing = ['tests/Unit/FooTest.php'];

        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-4',
            taskContractHash: 'task-hash-4',
            changedFiles: ['app/Services/Ai/Programming/AtlasDev/Foo.php'],
            riskLevel: PatchIntelligenceReceipt::RISK_HIGH,
            fileExists: $this->fileProbe($existing),
        ));

        $reasons = array_map(static fn (FocusedTestCommand $c): string => $c->reason, $receipt->focusedTestCommands);
        $this->assertContains('risk_level_high_wider_safety_net', $reasons);

        $moduleCmd = collect($receipt->focusedTestCommands)
            ->first(static fn (FocusedTestCommand $c): bool => str_contains($c->reason, 'risk_level_'));
        $this->assertNotNull($moduleCmd);
        $this->assertSame(FocusedTestCommand::CONFIDENCE_MEDIUM, $moduleCmd->confidence);
        $this->assertStringContainsString('tests/Unit/Ai/Programming/AtlasDev', $moduleCmd->command);

        // Overall confidence capped at medium for high-risk patches.
        $this->assertSame(TestSelectionReceipt::CONFIDENCE_MEDIUM, $receipt->overallConfidence);
    }

    public function test_no_files_changed_requires_skipped_reason(): void
    {
        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-5',
            taskContractHash: 'task-hash-5',
            changedFiles: [],
            fileExists: $this->fileProbe([]),
        ));

        $this->assertSame([], $receipt->focusedTestCommands);
        $this->assertNotNull($receipt->skippedTestsReason);
        $this->assertStringContainsString('no_changed_files', $receipt->skippedTestsReason);
    }

    public function test_partial_convention_match_caps_confidence_at_medium(): void
    {
        $existing = ['tests/Unit/AlphaTest.php']; // covers Alpha, not Beta

        $receipt = $this->service()->select(new TestSelectionInput(
            runId: 'run-6',
            taskContractHash: 'task-hash-6',
            changedFiles: ['app/Services/Alpha.php', 'app/Services/Beta.php'],
            fileExists: $this->fileProbe($existing),
        ));

        $this->assertCount(1, $receipt->focusedTestCommands);
        $this->assertSame(TestSelectionReceipt::CONFIDENCE_MEDIUM, $receipt->overallConfidence);
        $this->assertStringContainsString('partial_convention_match', $receipt->skippedTestsReason ?? '');
        $this->assertStringContainsString('Beta', $receipt->skippedTestsReason ?? '');
    }

    public function test_receipt_json_is_canonical_and_stable(): void
    {
        $input = new TestSelectionInput(
            runId: 'run-7',
            taskContractHash: 'task-hash-7',
            changedFiles: ['app/Services/Foo.php'],
            expectedTests: ['tests/Unit/FooTest.php'],
            fileExists: $this->fileProbe(['tests/Unit/FooTest.php']),
        );

        $first = $this->service()->select($input);
        $second = $this->service()->select($input);

        $this->assertSame($first->toJson(), $second->toJson());
        $this->assertSame($first->receiptHash, $second->receiptHash);
        // Round-trip stability via fromArray.
        $roundTrip = TestSelectionReceipt::fromArray($first->toCanonicalArray());
        $this->assertSame($first->toJson(), $roundTrip->toJson());
    }

    private function service(): TestSelectionIntelligenceService
    {
        return new TestSelectionIntelligenceService;
    }

    /**
     * @param  list<string>  $existingPaths
     */
    private function fileProbe(array $existingPaths): \Closure
    {
        $set = array_flip($existingPaths);

        return static fn (string $path): bool => array_key_exists($path, $set);
    }
}
