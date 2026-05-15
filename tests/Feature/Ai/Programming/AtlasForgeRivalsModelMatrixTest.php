<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModelMatrix;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Model Matrix contract tests.
 *
 * Three rules are canon and must be enforced at the matrix level (so that
 * preflight blocks any invalid combo before reaching the provider):
 *   - `fair` mode requires the same model on both arms.
 *   - `auto` is only valid in `full_power`.
 *   - `codex` cannot be used as the Atlas arm (rival-only).
 */
final class AtlasForgeRivalsModelMatrixTest extends TestCase
{
    public function test_fair_mode_requires_same_model_on_both_arms(): void
    {
        $matrix = app(AtlasForgeRivalsModelMatrix::class);

        $different = $matrix->validate('fair', 'claude_opus', 'claude_sonnet');
        $this->assertFalse($different['ok']);
        $this->assertContains('fair_mode_requires_same_model_on_both_arms', $different['blockers']);

        $same = $matrix->validate('fair', 'claude_sonnet', 'claude_sonnet');
        $this->assertTrue($same['ok'], 'fair mode with same model on both arms must pass: '.implode(',', $same['blockers']));
    }

    public function test_auto_model_only_valid_in_full_power(): void
    {
        $matrix = app(AtlasForgeRivalsModelMatrix::class);

        $bad = $matrix->validate('fair', 'auto', 'auto');
        $this->assertFalse($bad['ok']);
        $this->assertContains('auto_only_valid_in_full_power_mode', $bad['blockers']);

        $good = $matrix->validate('full_power', 'auto', 'auto');
        $this->assertTrue($good['ok'], 'auto/auto in full_power must pass: '.implode(',', $good['blockers']));
    }

    public function test_codex_cannot_be_atlas_arm(): void
    {
        $matrix = app(AtlasForgeRivalsModelMatrix::class);

        $bad = $matrix->validate('fair', 'codex', 'codex');
        $this->assertFalse($bad['ok']);
        $this->assertContains('atlas_arm_cannot_use_rival_only_model:codex', $bad['blockers']);
    }

    public function test_sonnet_opus_codex_all_accepted_as_rival(): void
    {
        $matrix = app(AtlasForgeRivalsModelMatrix::class);

        foreach (['claude_sonnet', 'claude_opus', 'codex'] as $rival) {
            $atlas = $rival === 'codex' ? 'claude_sonnet' : $rival;
            $mode = $rival === 'codex' ? 'full_power' : 'fair';
            $r = $matrix->validate($mode, $atlas, $rival);
            $this->assertTrue($r['ok'], "rival={$rival} atlas={$atlas} mode={$mode} should pass: ".implode(',', $r['blockers']));
        }
    }
}
