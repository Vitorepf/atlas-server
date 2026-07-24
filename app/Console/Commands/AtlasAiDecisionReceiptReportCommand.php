<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiDecisionReceiptReportCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:decision-receipt-report
        {--envelope= : Envelope id to replay}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay and verify DecisionReceipt hash/chain integrity for an Atlas AI envelope.';

    public function handle(AtlasLedgerReplayService $replay): int
    {
        $envelopeId = $this->envelopeId();
        if ($envelopeId === null) {
            $payload = [
                'status' => 'invalid_input',
                'error' => 'envelope_required',
                'decision_receipt_replay' => null,
            ];

            return $this->render($payload);
        }

        $report = $replay->decisionReceiptReportForEnvelope($envelopeId);
        $payload = [
            'status' => 'ok',
            'decision_receipt_replay' => $report,
        ];

        return $this->render($payload);
    }

    private function envelopeId(): ?string
    {
        $value = $this->option('envelope');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['decision_receipt_replay'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas DecisionReceipt Replay</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Envelope', (string) ($report['envelope_id'] ?? '-'));
        $this->components->twoColumnDetail('Decision events', (string) ($report['decision_event_count'] ?? 0));
        $this->components->twoColumnDetail('Invalid events', (string) ($report['invalid_count'] ?? 0));
        $this->components->twoColumnDetail('Latest receipt', (string) ($report['latest_receipt_id'] ?? '-'));
        $this->components->twoColumnDetail('Latest chain hash', (string) ($report['latest_chain_hash'] ?? '-'));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        $rows = collect((array) ($report['events'] ?? []))
            ->map(fn (array $event): array => [
                $event['receipt_id'] ?? '-',
                $event['parent_receipt_id'] ?? '-',
                $event['receipt_integrity_status'] ?? '-',
                $event['chain_integrity_status'] ?? '-',
                $event['provider'] ?? '-',
                $event['model'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->table(['receipt', 'parent', 'receipt hash', 'chain hash', 'provider', 'model'], $rows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
