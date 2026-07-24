<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskLandingDeepReviewService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * GAP-COCKPIT-04 surface · "rode um review sobre ESTA landing e mostre findings".
 * Generator (produces findings from the landed commit), complementing the recorder
 * `atlas:review:deep` (which stores findings you already have). Read-only.
 */
class AtlasTaskLandingDeepReviewCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:review:deep
        {ref : Commit sha da landing OU task_packet_id (resolvido via receipts)}
        {--semantic : Também pedir review semântico do diff ao provider governado (opt-in; fail-open)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate deterministic review findings for a landed atlas:task commit (scope/lint/pétreo/tests; --semantic adiciona IA governada).';

    public function handle(AtlasTaskLandingDeepReviewService $service): int
    {
        $packet = $service->review(trim((string) $this->argument('ref')), (bool) $this->option('semantic'));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($packet));

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
        if (isset($packet['semantic'])) {
            $sem = (array) $packet['semantic'];
            $this->components->twoColumnDetail('Semântico', sprintf(
                '%s · provider=%s%s · findings=%d',
                (string) ($sem['status'] ?? ''),
                (string) ($sem['provider'] ?? '-'),
                ($sem['model'] ?? null) !== null ? '/'.(string) $sem['model'] : '',
                (int) ($sem['findings_count'] ?? 0),
            ));
        }

        foreach ((array) $packet['findings'] as $finding) {
            $sev = (string) ($finding['severity'] ?? 'p3');
            $style = in_array($sev, ['p0', 'p1'], true) ? 'error' : 'comment';
            $tag = ($finding['source'] ?? '') === 'semantic'
                ? ' (IA'.(isset($finding['confidence']) && $finding['confidence'] !== null ? ' '.number_format((float) $finding['confidence'], 2) : '').')'
                : '';
            $this->line(sprintf(
                '<%s>[%s] %s</>%s %s',
                $style,
                strtoupper($sev),
                (string) ($finding['category'] ?? ''),
                $tag,
                (string) ($finding['title'] ?? ''),
            ));
        }

        return $packet['risk_level'] === 'blocking' ? self::FAILURE : self::SUCCESS;
    }
}
