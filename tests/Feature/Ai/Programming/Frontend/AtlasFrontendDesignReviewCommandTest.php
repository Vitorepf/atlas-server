<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendDesignReviewCommandTest extends TestCase
{
    public function test_review_template_command_writes_report(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-design-review-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:review', [
            'action' => 'template',
            '--output' => $dir,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendDesignReviewService::TEMPLATE_SCHEMA_VERSION, $output);
        $this->assertStringContainsString('design_review_report', $output);
    }

    public function test_review_inspect_command_blocks_missing_report(): void
    {
        $exitCode = Artisan::call('atlas:frontend:review', [
            'action' => 'inspect',
            '--report' => '/missing/design-review-report.json',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('design_review_report_missing', $output);
    }
}
