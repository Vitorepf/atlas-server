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

    public function test_detector_projects_findings_into_repair_gates_and_competitive_dimensions(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-detector-projection-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/bad.html', '<section class="card rounded shadow card rounded shadow card rounded shadow"><img src="placeholder.png"><div class="absolute top-0 left-0"></div></section>');

        $report = app(AtlasFrontendAntiSlopDetectorService::class)->inspectPath($dir, strict: true);

        $this->assertSame('atlas.frontend.detector_findings.v1', $report['findings_schema_version']);
        $this->assertSame('atlas.frontend.anti_slop_rule_registry.v1', data_get($report, 'rule_registry.schema_version'));
        $this->assertSame('atlas.frontend.anti_slop_repair_projection.v1', data_get($report, 'repair_projection.schema_version'));
        $this->assertSame('blocked', data_get($report, 'repair_projection.status'));
        $this->assertContains('anti_ai_slop_detector', data_get($report, 'repair_projection.failed_gates'));
        $this->assertContains('visual_quality_gate', data_get($report, 'repair_projection.rerun_gates'));
        $this->assertContains('anti_slop_report', data_get($report, 'repair_projection.evidence_required'));
        $this->assertSame('php artisan atlas:frontend:repair-plan --failed-gate=anti_ai_slop_detector --json', data_get($report, 'repair_projection.recommended_repair_plan_command'));
        $this->assertFalse((bool) data_get($report, 'claim_policy.visual_completion_claim_allowed'));

        $finding = collect($report['findings'])->firstWhere('rule_id', 'nested_card_surface');
        $this->assertSame('anti_ai_slop_detector', data_get($finding, 'gate_signal'));
        $this->assertSame('visual_hierarchy_and_information_architecture', data_get($finding, 'competitive_rubric_dimension'));
        $this->assertSame('information_architecture', data_get($finding, 'repair_target'));
        $this->assertContains('design_5d_review', data_get($finding, 'rerun_gates'));
        $this->assertNotEmpty(data_get($finding, 'false_positive_policy'));
    }

    public function test_clean_detector_report_allows_visual_claim_projection_only_when_no_findings(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-detector-clean-projection-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/clean.html', '<main><h1>Invoice approvals</h1><button aria-label="Save invoice">Save</button></main>');

        $report = app(AtlasFrontendAntiSlopDetectorService::class)->inspectPath($dir, strict: true);

        $this->assertSame('passed', $report['status']);
        $this->assertSame('clean', data_get($report, 'repair_projection.status'));
        $this->assertSame([], data_get($report, 'repair_projection.failed_gates'));
        $this->assertTrue((bool) data_get($report, 'claim_policy.visual_completion_claim_allowed'));
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
