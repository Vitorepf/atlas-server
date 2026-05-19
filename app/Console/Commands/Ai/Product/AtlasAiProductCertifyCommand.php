<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasAiProductCertificationService;
use Illuminate\Console\Command;

/**
 * Atlas AI · Product Certification CLI.
 *
 * Produces a canonical JSON envelope describing whether Atlas AI is wired
 * end-to-end across surfaces (Mobile, Desktop, Server, Forge).
 *
 * Read-only: this command never invokes a provider, never runs rivals,
 * never benchmarks. It inspects source artifacts (file presence + key
 * tokens) and emits a hashed envelope so reviewers can replay the audit.
 *
 * Doc: docs/engineering-knowledge-base/atlas-ai-product-certification.md
 */
class AtlasAiProductCertifyCommand extends Command
{
    protected $signature = 'atlas:ai:product-certify
        {--json : Print the canonical JSON envelope}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Certifies Atlas AI as an internal product (Mobile + Desktop + Server + Forge) without invoking providers or rivals.';

    public function handle(AtlasAiProductCertificationService $service): int
    {
        $report = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->renderHuman($report);
        }

        return $this->resolveExit($report, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderHuman(array $report): void
    {
        $this->components->twoColumnDetail(
            '<fg=bright-blue;options=bold>Atlas AI · Product Certification</>',
            (string) $report['schema_version'],
        );
        $this->components->twoColumnDetail('status', (string) $report['status']);
        $this->components->twoColumnDetail('checks', sprintf(
            'passed=%d · critical_failed=%d · warn_failed=%d',
            (int) data_get($report, 'summary.passed', 0),
            (int) data_get($report, 'summary.critical_failed', 0),
            (int) data_get($report, 'summary.warn_failed', 0),
        ));
        $this->components->twoColumnDetail('certification_hash', (string) $report['certification_hash']);

        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        foreach ($checks as $check) {
            $id = (string) ($check['id'] ?? 'unknown');
            $status = (string) ($check['status'] ?? 'unknown');
            $severity = (string) ($check['severity'] ?? 'critical');
            $colour = $status === 'passed'
                ? 'fg=green'
                : ($severity === 'warn' ? 'fg=yellow' : 'fg=red');
            $this->components->twoColumnDetail(
                "· {$id}",
                "<{$colour}>{$status}</> ({$severity})",
            );
        }

        $blockers = is_array($report['remaining_blockers'] ?? null) ? $report['remaining_blockers'] : [];
        if ($blockers !== []) {
            $this->newLine();
            $this->components->bulletList($blockers);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function resolveExit(array $report, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        return ($report['status'] ?? null) === 'ready'
            ? self::SUCCESS
            : self::FAILURE;
    }
}
