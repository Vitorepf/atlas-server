<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignReviewServiceTest extends TestCase
{
    public function test_template_writes_review_report_and_placeholder_blocks_until_filled(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-review-template-'.bin2hex(random_bytes(4));
        $review = app(AtlasFrontendDesignReviewService::class);

        $template = $review->writeTemplate($dir);
        $inspection = $review->inspect($dir.'/design-review-report.json');

        $this->assertSame(AtlasFrontendDesignReviewService::TEMPLATE_SCHEMA_VERSION, $template['schema_version']);
        $this->assertTrue(File::isFile($dir.'/design-review-report.json'));
        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('task_spec_hash_invalid', $inspection['blockers']);
        $this->assertContains('philosophy_consistency', $template['required_dimensions']);
    }

    public function test_valid_review_passes_with_5d_scores_and_evidence(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-review-valid-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/design-review-report.json', json_encode($this->validReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignReviewService::class)->inspect($dir.'/design-review-report.json');

        $this->assertSame(AtlasFrontendDesignReviewService::SCHEMA_VERSION, $inspection['schema_version']);
        $this->assertSame('passed', $inspection['status']);
        $this->assertSame(8.8, $inspection['overall_score']);
        $this->assertTrue((bool) data_get($inspection, 'claim_policy.design_review_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $inspection['review_hash']);
    }

    public function test_score_below_threshold_blocks_visual_claim(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-review-low-score-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['dimensions']['originality']['score'] = 3;
        File::put($dir.'/design-review-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignReviewService::class)->inspect($dir.'/design-review-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('design_review_score_below_threshold', $inspection['blockers']);
    }

    public function test_raw_prompt_or_sensitive_evidence_ref_blocks_review(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-review-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $report = $this->validReport();
        $report['raw_prompt'] = 'make this nicer';
        $report['evidence_refs'][] = 'token://secret';
        File::put($dir.'/design-review-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $inspection = app(AtlasFrontendDesignReviewService::class)->inspect($dir.'/design-review-report.json');

        $this->assertSame('blocked', $inspection['status']);
        $this->assertContains('forbidden_raw_prompt_source_or_customer_field_present', $inspection['blockers']);
        $this->assertContains('evidence_ref_contains_sensitive_term', $inspection['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validReport(): array
    {
        return [
            'schema_version' => AtlasFrontendDesignReviewService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => str_repeat('a', 64),
            'dimensions' => [
                'philosophy_consistency' => ['score' => 9, 'rationale' => 'Direction matches the product job and company context.', 'evidence_refs' => ['receipt://direction']],
                'visual_hierarchy' => ['score' => 9, 'rationale' => 'Primary path and secondary actions are clearly ranked.', 'evidence_refs' => ['receipt://screens']],
                'craft_execution' => ['score' => 8, 'rationale' => 'Spacing, type and states are consistent across viewports.', 'evidence_refs' => ['receipt://visual-quality']],
                'functional_clarity' => ['score' => 9, 'rationale' => 'User can complete the target workflow without visual ambiguity.', 'evidence_refs' => ['receipt://state-flow']],
                'originality' => ['score' => 9, 'rationale' => 'The design avoids generic AI tropes while staying usable.', 'evidence_refs' => ['receipt://anti-slop']],
            ],
            'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
        ];
    }
}
