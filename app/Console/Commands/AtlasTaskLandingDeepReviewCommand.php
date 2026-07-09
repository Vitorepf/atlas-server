<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskLandingDeepReviewService;
use Illuminate\Console\Command;

/**
 * GAP-COCKPIT-04 surface · "rode um review sobre ESTA landing e mostre findings".
 * Generator (produces findings from the landed commit), complementing the recorder
 * `atlas:review:deep` (which stores findings you already have). Read-only.
 */
class AtlasTaskLandingDeepReviewCommand extends Command
{
    protected $signature = 'atlas:task:review:deep
        {ref : Commit sha da landing OU task_packet_id (resolvido via receipts)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate deterministic review findings for a landed atlas:task commit (scope/lint/pétreo/tests).';

    public function handle(AtlasTaskLandingDeepReviewService $service): int
    {
        $packet = $service->review(trim((string) $this->argument('ref')));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $packet['risk_level'] === 'blocking' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Landing', (string) ($packet['sha'] ?? $packet['ref']));
        if (($packet['task_packet_id'] ?? null) !== null) {
            $this->components->twoColumnDetail('Task', (string) $packet['task_packet_id'].' · '.(string) ($packet['agent_id'] ?? ''));
        }
        $this->components->twoColumnDetail('Arquivos no commit', (string) count((array) $packet['files_changed']));
        $this->components->twoColumnDetail('Checks', implode(', ', (array) $packet['checks_run']));
        $this->components->twoColumnDetail('Risk', (string) $packet['risk_level']);
        $this->components->twoColumnDetail('Recomendação', (string) $packet['recommendation']);

        foreach ((array) $packet['findings'] as $finding) {
            $sev = (string) ($finding['severity'] ?? 'p3');
            $style = in_array($sev, ['p0', 'p1'], true) ? 'error' : 'comment';
            $this->line(sprintf(
                '<%s>[%s] %s</> %s',
                $style,
                strtoupper($sev),
                (string) ($finding['category'] ?? ''),
                (string) ($finding['title'] ?? ''),
            ));
        }

        return $packet['risk_level'] === 'blocking' ? self::FAILURE : self::SUCCESS;
    }
}
