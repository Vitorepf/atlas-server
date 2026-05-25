<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use Illuminate\Console\Command;

class AtlasFrontendDesignReviewCommand extends Command
{
    protected $signature = 'atlas:frontend:review
        {action=inspect : inspect or template}
        {--report= : Design review report path for inspect action}
        {--output= : Output directory for template action}
        {--json : Emit canonical JSON payload}';

    protected $description = 'Inspect or scaffold Atlas Frontend 5D design review reports.';

    public function handle(AtlasFrontendDesignReviewService $review): int
    {
        $payload = match ((string) $this->argument('action')) {
            'inspect' => $review->inspect((string) ($this->option('report') ?: '')),
            'template' => $review->writeTemplate((string) ($this->option('output') ?: storage_path('app/atlas/frontend-design-review'))),
            default => [
                'schema_version' => AtlasFrontendDesignReviewService::SCHEMA_VERSION,
                'status' => 'failed',
                'error' => 'invalid_action',
            ],
        };

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend 5D Design Review: '.$payload['status']);
        }

        return in_array($payload['status'] ?? null, ['failed', 'blocked'], true) ? self::FAILURE : self::SUCCESS;
    }
}
