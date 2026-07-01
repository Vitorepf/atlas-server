<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationRollbackReceiptComposer;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationRollbackReceiptComposerTest extends TestCase
{
    private function safeWave(): array
    {
        return [
            'wave_id' => 'wave_domain',
            'before_targets' => ['app/Services/Ai/Foo.php'],
            'after_targets' => ['app/Services/Ai/Bar.php'],
            'removed_symbols' => ['Foo::legacyMethod'],
            'replacement_symbols' => ['Bar::method'],
            'proof_refs' => ['tests/Unit/Ai/BarTest.php'],
            'rollback_steps' => ['git revert <sha>'],
            'before_hash' => 'sha1:abc123',
        ];
    }

    public function test_composes_full_receipt_for_safe_wave(): void
    {
        $receipt = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->compose($this->safeWave());

        $this->assertSame('atlas.self_construction.simplification_rollback_receipt.v1', $receipt['schema_version']);
        $this->assertSame('wave_domain', $receipt['wave_id']);
        $this->assertSame(['app/Services/Ai/Foo.php'], $receipt['before_targets']);
        $this->assertSame(['app/Services/Ai/Bar.php'], $receipt['after_targets']);
        $this->assertSame(['Foo::legacyMethod'], $receipt['removed_symbols']);
        $this->assertSame(['Bar::method'], $receipt['replacement_symbols']);
        $this->assertSame(['tests/Unit/Ai/BarTest.php'], $receipt['proof_refs']);
        $this->assertSame(['git revert <sha>'], $receipt['rollback_steps']);
        $this->assertTrue($receipt['reversible']);
        $this->assertSame([], $receipt['blockers']);
    }

    public function test_reversible_false_when_before_hash_missing(): void
    {
        $wave = $this->safeWave();
        $wave['before_hash'] = '';

        $receipt = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->compose($wave);

        $this->assertFalse($receipt['reversible']);
        $this->assertContains('before_hash_missing', $receipt['blockers']);
    }

    public function test_reversible_false_when_replacement_symbols_missing(): void
    {
        $wave = $this->safeWave();
        $wave['replacement_symbols'] = [];

        $receipt = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->compose($wave);

        $this->assertFalse($receipt['reversible']);
        $this->assertContains('replacement_symbols_missing', $receipt['blockers']);
    }

    public function test_reversible_false_when_proof_refs_missing(): void
    {
        $wave = $this->safeWave();
        $wave['proof_refs'] = [];

        $receipt = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->compose($wave);

        $this->assertFalse($receipt['reversible']);
        $this->assertContains('proof_refs_missing', $receipt['blockers']);
    }

    public function test_multiple_missing_fields_all_reported(): void
    {
        $receipt = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->compose([]);

        $this->assertFalse($receipt['reversible']);
        $this->assertSame(
            ['before_hash_missing', 'replacement_symbols_missing', 'proof_refs_missing'],
            $receipt['blockers'],
        );
    }

    // ── checkRollbackPreimage(): fail-closed pre-image sentinel ───────────────

    private function preimageInput(array $overrides = []): array
    {
        return array_merge([
            'action_type' => 'delete',
            'pre_image_refs' => ['sha1:preimage-abc'],
            'touched_files' => ['app/Services/Ai/Foo.php'],
            'replay_gates' => ['php artisan test tests/Unit/Ai/FooTest.php'],
            'restore_steps' => ['git revert <sha>'],
        ], $overrides);
    }

    public function test_destructive_action_with_empty_preimage_refs_is_blocked(): void
    {
        foreach (['merge', 'delete', 'extract'] as $actionType) {
            $result = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage(
                $this->preimageInput(['action_type' => $actionType, 'pre_image_refs' => []]),
            );

            $this->assertTrue($result['blocked'], "action_type={$actionType} should be blocked");
            $this->assertContains('pre_image_refs_missing', $result['reasons']);
            $this->assertNull($result['rollback_receipt_hash']);
        }
    }

    public function test_complete_preimage_input_returns_hash_and_restore_steps(): void
    {
        $result = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage($this->preimageInput());

        $this->assertFalse($result['blocked']);
        $this->assertNotNull($result['rollback_receipt_hash']);
        $this->assertSame(['git revert <sha>'], $result['restore_steps']);
    }

    public function test_receipt_hash_changes_when_touched_files_change(): void
    {
        $first = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage($this->preimageInput());
        $second = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage(
            $this->preimageInput(['touched_files' => ['app/Services/Ai/Bar.php']]),
        );

        $this->assertNotSame($first['rollback_receipt_hash'], $second['rollback_receipt_hash']);
    }

    public function test_receipt_hash_changes_when_replay_gates_change(): void
    {
        $first = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage($this->preimageInput());
        $second = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage(
            $this->preimageInput(['replay_gates' => ['php artisan test tests/Unit/Ai/BarTest.php']]),
        );

        $this->assertNotSame($first['rollback_receipt_hash'], $second['rollback_receipt_hash']);
    }

    public function test_non_destructive_action_with_empty_preimage_refs_is_not_blocked(): void
    {
        $result = (new AtlasSelfConstructionSimplificationRollbackReceiptComposer)->checkRollbackPreimage(
            $this->preimageInput(['action_type' => 'additive_cleanup', 'pre_image_refs' => []]),
        );

        $this->assertFalse($result['blocked']);
    }
}
