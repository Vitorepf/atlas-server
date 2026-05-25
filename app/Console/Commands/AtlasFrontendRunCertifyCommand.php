<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use Illuminate\Console\Command;

class AtlasFrontendRunCertifyCommand extends Command
{
    protected $signature = 'atlas:frontend:run-certify
        {--visual-report= : Visual quality report JSON}
        {--design-review-report= : 5D design review report JSON}
        {--quality-budget-report= : Objective frontend quality budget report JSON}
        {--evidence-manifest= : Evidence pack manifest JSON}
        {--evidence-root= : Evidence pack root directory}
        {--bundle= : Optional product proof bundle directory}
        {--publication-receipt= : Optional public publication receipt JSON}
        {--outcome-store= : Optional frontend outcome memory JSONL store}
        {--json : Emit canonical JSON payload}
        {--strict : Exit non-zero unless certified or warning}';

    protected $description = 'Certify one Atlas Frontend delivery run from real evidence artifacts.';

    public function handle(AtlasFrontendRunCertificationService $certification): int
    {
        $payload = $certification->certify([
            'visual_report' => (string) ($this->option('visual-report') ?: ''),
            'design_review_report' => (string) ($this->option('design-review-report') ?: ''),
            'quality_budget_report' => (string) ($this->option('quality-budget-report') ?: ''),
            'evidence_manifest' => (string) ($this->option('evidence-manifest') ?: ''),
            'evidence_root' => (string) ($this->option('evidence-root') ?: ''),
            'bundle' => (string) ($this->option('bundle') ?: ''),
            'publication_receipt' => (string) ($this->option('publication-receipt') ?: ''),
            'outcome_store' => (string) ($this->option('outcome-store') ?: ''),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Atlas Frontend Run Certification: '.$payload['status']);
        }

        return (bool) $this->option('strict') && ! in_array($payload['status'] ?? null, ['certified', 'warning'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
