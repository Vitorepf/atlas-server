<?php

namespace App\Console\Commands;

use App\Models\AiCompoundingMemory;
use App\Models\AiLearningProposal;
use App\Services\Ai\Compounding\AtlasCaptureQualityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Dry-run the capture quality gate over recent learning candidates and report how much
 * is noise (by reason) + the content-dedup ratio. READ-ONLY — mutates nothing. Shows,
 * on real data, what enabling the gate in enforce mode would prune.
 */
class AtlasAiCaptureQualityAuditCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:capture-quality-audit
        {--days=7 : Lookback window in days}
        {--json : Print machine-readable JSON}';

    protected $description = 'Dry-run the capture quality gate over recent learnings: how much is noise (by reason) + the content-dedup ratio (read-only).';

    public function handle(AtlasCaptureQualityGate $gate): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $surfaces = [];

        if (DatabaseTableAvailability::has('ai_learning_proposals')) {
            $surfaces['ai_learning_proposals'] = $this->audit(
                AiLearningProposal::query()->where('created_at', '>=', $since)->get(),
                $gate,
                static fn ($p): array => [
                    'kind' => (string) $p->kind,
                    'claim' => (string) ($p->summary ?? (is_array($p->proposed_state) ? ($p->proposed_state['summary'] ?? $p->proposed_state['claim'] ?? '') : '')),
                    'content' => $p->proposed_state,
                ],
            );
        }
        if (DatabaseTableAvailability::has('ai_compounding_memories')) {
            $surfaces['ai_compounding_memories'] = $this->audit(
                AiCompoundingMemory::query()->where('created_at', '>=', $since)->get(),
                $gate,
                static fn ($m): array => ['kind' => (string) $m->memory_type, 'claim' => (string) $m->claim, 'content' => $m->payload],
            );
        }

        $totAll = array_sum(array_column($surfaces, 'total'));
        $rejAll = array_sum(array_column($surfaces, 'would_reject'));
        $keptAll = array_sum(array_column($surfaces, 'distinct_kept'));
        $wasteAll = array_sum(array_column($surfaces, 'waste'));
        $report = [
            'schema_version' => AtlasCaptureQualityGate::SCHEMA,
            'window_days' => $days,
            'surfaces' => $surfaces,
            'overall' => [
                'total' => $totAll,
                'distinct_kept' => $keptAll,
                'would_reject' => $rejAll,
                'duplicate_admitted' => max(0, $totAll - $rejAll - $keptAll),
                'waste' => $wasteAll,
                'waste_pct' => $totAll > 0 ? (int) round(100 * $wasteAll / $totAll) : 0,
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));

            return self::SUCCESS;
        }

        $this->info('Capture quality audit — last '.$days.' day(s) · DRY-RUN (nothing changed)');
        foreach ($surfaces as $name => $s) {
            $this->newLine();
            $this->line('<comment>'.$name.'</comment>: '.$s['total'].' rows → '
                .'<fg=green>'.$s['distinct_kept'].' distinct kept</> · '
                .'<fg=red>'.$s['would_reject'].' noise + '.$s['duplicate_admitted'].' duplicates</> = '.$s['waste'].' waste');
            $rows = [];
            foreach ($s['by_reason'] as $reason => $n) {
                $rows[] = [$reason, (string) $n];
            }
            if ($rows !== []) {
                $this->table(['reject reason', 'count'], $rows);
            }
        }
        $this->newLine();
        $o = $report['overall'];
        $this->line(sprintf('<info>Overall: %d of %d rows are waste (%d%%) — %d noise + %d duplicates. Only %d DISTINCT learning(s) remain.</info>',
            $o['waste'], $o['total'], $o['waste_pct'], $o['would_reject'], $o['duplicate_admitted'], $o['distinct_kept']));
        $this->line('Enable pruning: ATLAS_CAPTURE_QUALITY_MODE=enforce (default: observe — logs, never drops).');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int,mixed>  $rows
     * @param  callable(mixed):array<string,mixed>  $map
     * @return array<string,mixed>
     */
    private function audit(Collection $rows, AtlasCaptureQualityGate $gate, callable $map): array
    {
        $byReason = [];
        $admittedHashes = [];
        $admit = 0;
        foreach ($rows as $r) {
            $v = $gate->assess($map($r));
            if ($v['admit'] === true) {
                $admit++;
                $admittedHashes[] = $v['content_hash'];
            } else {
                $byReason[$v['reason']] = ($byReason[$v['reason']] ?? 0) + 1;
            }
        }
        $total = $rows->count();
        $distinctKept = count(array_unique($admittedHashes)); // unique GOOD learnings after content-dedup
        arsort($byReason);

        return [
            'total' => $total,
            'would_admit' => $admit,
            'would_reject' => $total - $admit,
            'by_reason' => $byReason,
            'distinct_kept' => $distinctKept,
            'duplicate_admitted' => max(0, $admit - $distinctKept),
            'waste' => max(0, $total - $distinctKept), // rejected noise + duplicate rows
        ];
    }
}
