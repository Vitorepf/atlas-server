<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\MechanismNameForge;
use PHPUnit\Framework\TestCase;

/**
 * MechanismNameForge v2 — ORIGINATES the named proprietary mechanism (Eixo 2). These tests are the
 * regression net for the bugs a brutal cross-niche panel found in v1 (modifier-as-core, fake/leaked
 * number, PT-BR Frankenstein, degrading an already-elite name).
 */
class MechanismNameForgeTest extends TestCase
{
    private function best(array $attrs): string
    {
        return (string) (new MechanismNameForge)->forge(new AiMarketingVslAsset($attrs))['best'];
    }

    public function test_drops_modifier_prefix_and_uses_the_real_core(): void
    {
        // v1 produced "The 3-Home Protocol" (grabbed "home" from "at-home"); v2 must get the real core.
        $this->assertSame('The 3-Hormone Reset', $this->best(['mechanism_name' => 'at-home 3-hormone reset']));
    }

    public function test_never_leaks_the_result_number_from_the_promise(): void
    {
        // v1 leaked "5" from "lose 5 lbs" into "The 5-Metabolic". The mechanism has no real count → omit.
        $best = $this->best(['mechanism_name' => 'metabolic switch', 'core_promise' => 'lose 5 lbs in 9 days']);
        $this->assertStringNotContainsString('5', $best);
        $this->assertSame('The Metabolic Switch', $best);
    }

    public function test_omits_number_when_the_mechanism_does_not_enumerate(): void
    {
        // v1 stamped a fake "3" on single-mechanism concepts ("The 3-Allocation"). v2 omits it.
        $best = $this->best(['mechanism_name' => 'the allocation rule', 'niche' => 'finance']);
        $this->assertSame('The Allocation Rule', $best);
        $this->assertDoesNotMatchRegularExpression('/\d/', $best);
    }

    public function test_portuguese_is_not_frankenstein_bilingual(): void
    {
        // v1 produced "The 7-Regra Protocol". v2 must be clean PT with a PT method word and correct article.
        $best = $this->best(['mechanism_name' => 'regra dos 7 envelopes', 'language' => 'pt']);
        $this->assertSame('A Regra dos 7 Envelopes', $best);
        $this->assertStringNotContainsString('Protocol ', $best.' '); // no English method word
        $this->assertStringNotContainsString('ss', $best);            // no forced double-plural
    }

    public function test_preserves_an_already_elite_name(): void
    {
        // v1 degraded "The 3-Hormone Reset" into "The 3-Hormone Protocol". v2 preserves it.
        $this->assertSame('The 3-Hormone Reset', $this->best(['mechanism_name' => 'The 3-Hormone Reset']));
    }

    public function test_reuses_the_inputs_own_method_word(): void
    {
        $this->assertSame('The Reconnection Sequence', $this->best(['mechanism_name' => 'reconnection sequence', 'niche' => 'relationship']));
    }

    public function test_always_yields_a_usable_name_from_thin_assets(): void
    {
        $best = $this->best(['niche' => 'finance']);
        $this->assertNotEmpty($best);
        $this->assertStringNotContainsString(' Reset Reset', $best); // no method==core duplication
    }
}
