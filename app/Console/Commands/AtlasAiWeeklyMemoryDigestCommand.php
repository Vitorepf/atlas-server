<?php

namespace App\Console\Commands;

use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use Illuminate\Console\Command;

/**
 * The Sunday memory digest — reports EVERYTHING Atlas saved to memory in the window
 * (default 7 days) plus every learning the autonomous loop auto-applied, each with a
 * reverse handle, so the operator reviews after the fact and prunes what they don't
 * want. Read-only: this command never mutates memory. Scheduled weekly on Sundays.
 */
class AtlasAiWeeklyMemoryDigestCommand extends Command
{
    protected $signature = 'atlas:ai:weekly-memory-digest
        {--days=7 : Lookback window in days}
        {--json : Print machine-readable JSON}';

    protected $description = 'Sunday digest of everything saved to Atlas memory + auto-applied learnings, each with a reverse handle (read-only).';

    public function handle(AtlasWeeklyMemoryDigestService $digest): int
    {
        $report = $digest->digest((int) $this->option('days'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $t = $report['totals'];
        $this->info('Atlas weekly memory digest — last '.$report['window_days'].' day(s)');
        $this->line('Generated '.$report['generated_at']);
        $this->newLine();
        $this->table(['what', 'count'], [
            ['Memory entries saved', (string) $t['memory_entries']],
            ['Compounding candidates', (string) $t['compounding_candidates']],
            ['Staged captures (ai_memory_deltas)', (string) $t['staged_captures']],
            ['AEMOR candidates', (string) $t['aemor_candidates']],
            ['Auto-applied learnings', (string) $t['auto_applied_learnings']],
            ['⚠ Pending YOUR review', (string) $t['pending_your_review']],
            ['Total saved', (string) $t['total_saved']],
        ]);

        $proposals = $report['learning_proposals'];
        if (($proposals['count'] ?? 0) > 0) {
            $this->newLine();
            $this->line('<comment>Learning proposals — by status:</comment> '.$this->kv($proposals['by_status'] ?? []));
            $this->line('<comment>By kind:</comment> '.$this->kv($proposals['by_kind'] ?? []));
            $rows = [];
            foreach (array_slice($proposals['items'] ?? [], 0, 25) as $i) {
                $rows[] = [
                    substr((string) $i['id'], 0, 8),
                    (string) $i['kind'],
                    (string) $i['status'],
                    (string) $i['decided_by'],
                    $this->trunc((string) $i['reverse_handle'], 46),
                ];
            }
            $this->table(['id', 'kind', 'status', 'decided_by', 'handle / action'], $rows);
        }

        $entries = $report['memory_entries'];
        if (($entries['count'] ?? 0) > 0) {
            $this->newLine();
            $this->line('<comment>Memory registry — by type:</comment> '.$this->kv($entries['by_type'] ?? []));
            $this->line('<comment>By privacy class:</comment> '.$this->kv($entries['by_privacy_class'] ?? []));
            $rows = [];
            foreach (array_slice($entries['items'] ?? [], 0, 25) as $i) {
                $rows[] = [
                    substr((string) $i['id'], 0, 8),
                    (string) $i['memory_type'],
                    $this->trunc((string) $i['title'], 42),
                    (string) $i['privacy_class'],
                    (string) $i['status'],
                ];
            }
            $this->table(['id', 'type', 'title', 'privacy', 'status'], $rows);
            if (($entries['count'] ?? 0) > 25) {
                $this->line('… +'.(($entries['count']) - 25).' more (use --json for all)');
            }
        }

        $applied = $report['applied_learnings'];
        if (($applied['count'] ?? 0) > 0) {
            $this->newLine();
            $this->line('<comment>Auto-applied learnings this week:</comment>');
            foreach (array_slice($applied['items'] ?? [], 0, 25) as $i) {
                $this->line(sprintf('  • %s/%s → %s (%s)  · reverse: %s',
                    $i['task_category'] ?? '', $i['role'] ?? '', $i['provider'] ?? '', $i['model'] ?? '', $i['reverse_handle'] ?? ''));
            }
        }

        $this->newLine();
        $this->line('<info>'.$report['review_note'].'</info>');

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $map
     */
    private function kv(array $map): string
    {
        if ($map === []) {
            return '(none)';
        }
        $parts = [];
        foreach ($map as $k => $v) {
            $parts[] = ($k === '' ? '(unset)' : $k).'='.$v;
        }

        return implode('  ', $parts);
    }

    private function trunc(string $s, int $n): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);

        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s;
    }
}
