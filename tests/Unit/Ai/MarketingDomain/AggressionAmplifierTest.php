<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\AggressionAmplifier;
use App\Services\Ai\MarketingDomain\Content\ConversionAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Locks the closed loop: the auditor x-rays a page across all 9 libraries; the amplifier picks the top
 * missing high-leverage patterns and injects concrete elite snippets into the bridge slots, then re-audits
 * to PROVE the lift. This is the verifier-driven amplifier — measure → inject → re-measure.
 */
class AggressionAmplifierTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'persuasion_devices' => ['authority' => ['Melania Trump'], 'conspiracy' => ['big pharma hides it']],
            'metrics' => ['result_claims' => ['63 lbs em 2 meses']],
        ]);
    }

    private function weakBridge(): array
    {
        return [
            'headline' => 'A new way to lose weight',
            'subheadline' => 'Try our product',
            'lead_paragraph' => 'We have a supplement that can help.',
            'body_sections' => [['heading' => 'About', 'body' => 'It is natural and effective.']],
            'cta_blocks' => [['label' => 'Buy now']],
        ];
    }

    public function test_auditor_returns_unified_xray_across_all_libraries(): void
    {
        $audit = (new ConversionAuditor)->audit('Buy our product.');

        $this->assertArrayHasKey('overall_score', $audit);
        $this->assertArrayHasKey('grade', $audit);
        $this->assertArrayHasKey('by_library', $audit);

        foreach (['angle_big_idea', 'awareness_sophistication', 'persuasion', 'cognitive_bias',
            'offer_architecture', 'objection', 'hook_lead', 'narrative_voice',
            'funnel_sequence', 'visual_persuasion'] as $lib) {
            $this->assertArrayHasKey($lib, $audit['by_library'], "Missing library {$lib}");
        }
    }

    public function test_amplifier_lifts_a_weak_bridge_across_multiple_dimensions(): void
    {
        $out = (new AggressionAmplifier)->amplify($this->weakBridge(), $this->asset(), ['until' => 'killer', 'max_iterations' => 3]);

        $this->assertGreaterThan($out['before']['overall_score'], $out['after']['overall_score'],
            'Overall score must rise after amplification');
        $this->assertGreaterThanOrEqual(5, count($out['injected']),
            'At least 5 elite patterns should be injected');

        $liftedLibs = 0;
        foreach ($out['before']['by_library'] as $k => $b) {
            if ($out['after']['by_library'][$k]['score'] > $b['score']) {
                $liftedLibs++;
            }
        }
        $this->assertGreaterThanOrEqual(5, $liftedLibs,
            'At least 5 of the 10 dimensions must improve');
    }

    public function test_amplifier_injects_into_real_bridge_slots(): void
    {
        $out = (new AggressionAmplifier)->amplify($this->weakBridge(), $this->asset());

        $touched = false;
        foreach (['kicker', 'mechanism_tease', 'body_sections', 'cta_blocks', 'objection_flips', 'ps'] as $slot) {
            if (! empty($out['bridge'][$slot]) && $out['bridge'][$slot] !== ($this->weakBridge()[$slot] ?? null)) {
                $touched = true;
                break;
            }
        }
        $this->assertTrue($touched, 'Amplifier must actually mutate at least one bridge slot');
    }

    public function test_amplifier_reports_hollowness_and_rejected(): void
    {
        $out = (new AggressionAmplifier)->amplify($this->weakBridge(), $this->asset());

        // The amplifier MUST now report the Goodhart gate's view of the result:
        $this->assertArrayHasKey('hollowness', $out, 'Amplifier must report hollowness of the final copy');
        $this->assertArrayHasKey('rejected', $out, 'Amplifier must report patterns rejected by the Goodhart gate');
        $this->assertLessThanOrEqual(50, $out['hollowness'],
            'Final amplified copy should not be hollow — the gate must keep us below 50');
    }

    public function test_amplification_never_increases_structural_defects(): void
    {
        $amplifier = new AggressionAmplifier;
        $detector = new \App\Services\Ai\MarketingDomain\Content\DecisionClarityAuditor;
        $leaks = new \App\Services\Ai\MarketingDomain\Content\WatchThroughLeakDetector;

        $bridge = $this->weakBridge();
        $copyOf = function (array $b) use ($detector, $leaks): int {
            // Mirror the amplifier's flatten well enough for the invariant check.
            $text = trim(implode("\n", array_filter([
                (string) ($b['headline'] ?? ''), (string) ($b['kicker'] ?? ''), (string) ($b['subheadline'] ?? ''),
                (string) ($b['lead_paragraph'] ?? ''), (string) ($b['mechanism_tease'] ?? ''), (string) ($b['ps'] ?? ''),
                implode(' ', array_map(fn ($s) => is_array($s) ? (($s['heading'] ?? '').' '.($s['body'] ?? '')) : (string) $s, (array) ($b['body_sections'] ?? []))),
                implode(' ', array_map(fn ($c) => is_array($c) ? (($c['label'] ?? '').' '.($c['sub'] ?? '')) : (string) $c, (array) ($b['cta_blocks'] ?? []))),
            ])));

            return count($detector->audit($text)['flaws']) + count($leaks->detect($text)['flaws']);
        };

        $before = $copyOf($bridge);
        $out = $amplifier->amplify($bridge, $this->asset(), ['until' => 'killer', 'max_iterations' => 3]);

        $this->assertArrayHasKey('structural_defects', $out);
        $this->assertLessThanOrEqual($before, $copyOf($out['bridge']),
            'The structure-safety gate must ensure amplification never ADDS a structural defect (leak / choice overload)');
    }
}
