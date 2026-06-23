<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\CopyQualityGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the "does not read like a dumb AI wrote it" gate: copy that talks ABOUT marketing fails (meta),
 * copy with no concrete VSL ammunition fails (generic), and copy that weaves in the real names/enemy/
 * mechanism/pains passes. Deterministic.
 */
class CopyQualityGateTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol with turmeric, green tea, berberine, resveratrol',
            'avatar' => [
                'quem' => 'mulher acima de 40',
                'dores' => ['evita o espelho e fotos', 'medo de diabetes'],
            ],
            'persuasion_devices' => [
                'authority' => ['Melania Trump', 'Dr. Peter Attia', 'FDA'],
                'social_proof' => ['Melissa McCarthy', 'Amy from Naperville'],
                'conspiracy' => 'big pharma hides the cure to keep women dependent on injections',
            ],
        ]);
    }

    public function test_meta_marketing_language_fails(): void
    {
        $bridge = [
            'headline' => 'A new fat-loss conversation',
            'body_sections' => [
                ['heading' => 'Why it works', 'body' => 'This page warms the cold visitor and improves message-match so the audience watches the video. Future pacing drives the click.'],
                ['heading' => 'b', 'body' => 'more'],
                ['heading' => 'c', 'body' => 'more'],
            ],
        ];

        $v = (new CopyQualityGate)->assess($bridge, $this->asset());

        $this->assertSame('meta', $v['verdict']);
        $this->assertFalse($v['passed']);
        $this->assertNotEmpty($v['meta_leaks']);
    }

    public function test_generic_copy_with_no_vsl_ammunition_fails(): void
    {
        $bridge = [
            'headline' => 'A hidden hormone slowdown may be the real reason',
            'body_sections' => [
                ['heading' => 'It is not willpower', 'body' => 'Some women feel their body stopped responding. There may be a deeper metabolic reason involving several signals.'],
                ['heading' => 'A different idea', 'body' => 'A simple routine some have tried.'],
                ['heading' => 'What now', 'body' => 'Watch to learn more.'],
            ],
        ];

        $v = (new CopyQualityGate)->assess($bridge, $this->asset());

        $this->assertSame('generic', $v['verdict']);
        $this->assertLessThan(CopyQualityGate::MIN_CONCRETE_HOOKS, $v['concrete_hooks_count']);
    }

    public function test_concrete_copy_with_real_ammunition_passes(): void
    {
        $bridge = [
            'headline' => 'What Melania Trump Said On TV About a Retatrutide Drops Protocol',
            'subheadline' => 'Dr. Peter Attia and the FDA are part of a story big pharma reportedly tried to bury.',
            'body_sections' => [
                ['heading' => 'The night it went viral', 'body' => 'Melissa McCarthy and Amy from Naperville described the same berberine-and-turmeric drops. Women who evita o espelho finally felt seen.'],
                ['heading' => 'The enemy', 'body' => 'big pharma hides this to keep women on injections — that is the claim Dr. Peter Attia makes.'],
                ['heading' => 'The drops', 'body' => 'green tea, resveratrol, the Triple Hormone Drops Protocol.'],
            ],
        ];

        $v = (new CopyQualityGate)->assess($bridge, $this->asset());

        $this->assertSame('ok', $v['verdict']);
        $this->assertTrue($v['passed']);
        $this->assertGreaterThanOrEqual(CopyQualityGate::MIN_CONCRETE_HOOKS, $v['concrete_hooks_count']);
    }
}
