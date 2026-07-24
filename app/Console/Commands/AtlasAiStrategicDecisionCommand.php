<?php

namespace App\Console\Commands;

use App\Services\Ai\Domain\StrategicDecisionReviewService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiStrategicDecisionCommand extends Command
{
    protected $signature = 'atlas:ai:strategic-decision
        {action=review : review}
        {--title= : Decision title}
        {--decision= : Decision statement}
        {--option=* : Option under consideration}
        {--value=* : Value to preserve}
        {--constraint=* : Constraint or risk}
        {--impact=medium : low, medium, high, or critical}
        {--horizon=90 : Review horizon in days}
        {--audit : Emit dry-run DecisionReceipt and Evidence Ledger events}
        {--json : Print machine-readable JSON}';

    protected $description = 'Generate a plan-only Strategic Decision review packet without executing the decision.';

    public function handle(StrategicDecisionReviewService $reviews): int
    {
        $action = strtolower(trim((string) $this->argument('action')));
        if ($action !== 'review') {
            $this->error("Unsupported action: {$action}. Supported: review.");

            return self::FAILURE;
        }

        $input = [
            'title' => $this->option('title'),
            'decision' => $this->option('decision'),
            'options' => $this->option('option'),
            'values' => $this->option('value'),
            'constraints' => $this->option('constraint'),
            'impact' => $this->option('impact'),
            'horizon_days' => $this->option('horizon'),
            'surface_id' => 'atlas_cli_strategic_decision',
            'operator_id' => 'cli',
        ];
        $result = (bool) $this->option('audit')
            ? $reviews->auditedPacket($input)
            : ['packet' => $reviews->packet($input)];
        $packet = $result['packet'];

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => 'ok',
                'strategic_decision' => $packet,
                'decision_receipt' => $result['receipt'] ?? null,
                'ledger' => $result['ledger'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Strategic Decision</>', (string) $packet['title']);
        $this->components->twoColumnDetail('Mode', (string) $packet['mode']);
        $this->components->twoColumnDetail('Impact', (string) data_get($packet, 'decision_frame.impact'));
        $this->components->twoColumnDetail('Cool-down required', (bool) data_getYesNo::format($packet, 'cooldown.required'));

        $this->table(
            ['gate', 'status', 'reason'],
            collect((array) $packet['gates'])
                ->map(fn (array $gate): array => [$gate['id'], $gate['status'], $gate['reason']])
                ->all(),
        );

        return self::SUCCESS;
    }
}
