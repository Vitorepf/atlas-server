<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\MechanismTeaseForge;
use App\Services\Ai\MarketingDomain\Content\WatchThroughLeakDetector;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic safety net for the bridge's #1 line (the mechanism tease): it must open a loop and
 * pull to the video, WITHOUT revealing the answer (no reveal markers) — a real provider-free tease.
 */
class MechanismTeaseForgeTest extends TestCase
{
    public function test_forges_a_structurally_sound_tease_en(): void
    {
        $tease = (new MechanismTeaseForge)->forge(new AiMarketingVslAsset(['target_geo' => 'US', 'language' => 'en']));

        $this->assertGreaterThan(20, str_word_count($tease));
        $this->assertStringContainsStringIgnoringCase('presentation', $tease); // pulls to the video
        $this->assertFalse((new MechanismTeaseForge)->reveals($tease));        // teases, never reveals
        // and it is not a premature reveal by structure (no opening-third reveal/CTA leak)
        $this->assertSame([], (new WatchThroughLeakDetector)->detect(str_repeat($tease.' ', 2))['flaws']);
    }

    public function test_forges_in_portuguese_for_a_br_asset(): void
    {
        $tease = (new MechanismTeaseForge)->forge(new AiMarketingVslAsset(['target_geo' => 'BR', 'language' => 'pt']));

        $this->assertStringContainsStringIgnoringCase('apresentação', $tease);
        $this->assertFalse((new MechanismTeaseForge)->reveals($tease));
    }

    public function test_reveals_detects_a_literal_reveal(): void
    {
        $forge = new MechanismTeaseForge;
        $this->assertTrue($forge->reveals('Here\'s how it works: the trigger is a hormone.'));
        $this->assertTrue($forge->reveals('O segredo é uma gota sublingual.'));
        $this->assertFalse($forge->reveals('The full step-by-step is in the presentation above.'));
    }
}
