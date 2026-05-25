<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendAntiSlopDetectorService;
use Tests\TestCase;

class AtlasFrontendAntiSlopDetectorServiceTest extends TestCase
{
    public function test_detector_flags_common_ai_slop_patterns(): void
    {
        $source = <<<'HTML'
        <section class="rounded-3xl shadow-xl bg-gradient-to-r from-purple-500 to-pink-500 text-center items-center justify-center">
            <div class="card rounded-3xl shadow-lg"><div class="card rounded-2xl shadow-md">
                <h1 class="bg-gradient-to-r from-purple-500 to-pink-500 bg-clip-text text-transparent">Unlock seamless powerful workflows</h1>
                <img src="https://placehold.co/1200x600" />
                <button><svg></svg></button><button><svg></svg></button><button><svg></svg></button>
            </div></div>
            <div class="absolute top-0 left-0 blur-3xl"></div>
        </section>
        HTML;

        $findings = app(AtlasFrontendAntiSlopDetectorService::class)->inspectSource($source, 'demo.html');
        $ruleIds = collect($findings)->pluck('rule_id')->all();

        $this->assertContains('gradient_text', $ruleIds);
        $this->assertContains('one_note_purple_palette', $ruleIds);
        $this->assertContains('nested_card_surface', $ruleIds);
        $this->assertContains('placeholder_asset', $ruleIds);
        $this->assertContains('generic_hero_copy', $ruleIds);
        $this->assertContains('absolute_overlap_risk', $ruleIds);
        $this->assertContains('icon_buttons_without_accessible_name', $ruleIds);
    }

    public function test_detector_report_is_provider_safe_and_strict_fails_on_high_findings(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-detector-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/bad.html', '<img src="placeholder.png"><div class="absolute top-0 left-0"></div>');

        $report = app(AtlasFrontendAntiSlopDetectorService::class)->inspectPath($dir, strict: true);

        $this->assertSame('atlas.frontend.anti_slop_detector.v1', $report['schema_version']);
        $this->assertSame('failed', $report['status']);
        $this->assertFalse((bool) data_get($report, 'source_policy.raw_source_returned'));
        $this->assertFalse((bool) data_get($report, 'source_policy.absolute_path_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $report['detector_hash']);
        $this->assertSame('bad.html', data_get($report, 'findings.0.path'));
    }
}
