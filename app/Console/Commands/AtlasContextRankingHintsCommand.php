<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasContextRankingHintsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:ranking-hints
        {--limit=20 : Maximum hint snapshots to list}
        {--json : Emit canonical JSON}';

    protected $description = 'List provider-safe ACRS feedback-hint before/after snapshots from the Evidence Ledger (read-only).';

    public function handle(): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $hints = $this->hintSnapshots($limit);
        $payload = [
            'schema_version' => 'atlas.context.ranking_hints.report.v1',
            'status' => $hints !== [] ? 'ready' : 'empty',
            'generated_at' => now()->toIso8601String(),
            'hints' => $hints,
            'policy' => [
                'read_only' => true,
                'writes' => false,
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'providers_invoked' => false,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Context Ranking Hints', $payload['status']);
        $this->components->twoColumnDetail('Snapshots', (string) count($hints));
        foreach ($hints as $hint) {
            $this->line(sprintf(
                '- %s source=%s rank_changes=%s',
                (string) ($hint['ledger_event_id'] ?? ''),
                (string) data_get($hint, 'hint.source', 'unknown'),
                (string) data_get($hint, 'delta.rank_position_change_count', 0),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function hintSnapshots(int $limit): array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return [];
        }

        return AtlasLedgerEvent::query()
            ->where('event_type', 'CONTEXT_COMPOSED')
            ->latest('occurred_at')
            ->limit($limit * 4)
            ->get()
            ->filter(fn (AtlasLedgerEvent $event): bool => data_get($event->payload, 'event_name') === 'context.ranking_hints.snapshot')
            ->take($limit)
            ->map(function (AtlasLedgerEvent $event): array {
                $payload = (array) $event->payload;

                return [
                    'ledger_event_id' => (string) $event->event_id,
                    'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                    'hint' => (array) ($payload['hint'] ?? []),
                    'snapshot' => (array) ($payload['snapshot'] ?? []),
                    'delta' => (array) ($payload['delta'] ?? []),
                    'hold' => (array) ($payload['hold'] ?? []),
                    'rerank_result_hash' => (string) ($payload['rerank_result_hash'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }
}
