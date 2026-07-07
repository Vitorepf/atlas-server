<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\AtlasMemoryUsageService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * D4 (Obra #18) — implicit feedback: 18 320 recall usages carried ZERO feedback
 * (a dead, expensive write). This closes the loop cheaply: a recalled memory whose
 * subject appears in the session's DIFF was USEFUL (cited ∧ in the change), so mark
 * it `useful_implicit` — no operator prompt, no model call. It also FLAGS the
 * dominant-recall pathology (one entry returned by >50% of a window's recalls — the
 * wiper signature) so the operator can dedup it.
 *
 * The recorded feedback feeds D5's feedback-fill dimension directly, so the memory
 * quality score rises only when real usage signal accrues.
 *
 * ponytail: subject match is a crude title-token scan of the diff text (the honest
 * signal available before D3 lands memory↔code edges); dry-run by default, --apply writes.
 */
class AtlasMemoryFeedbackImplicitCommand extends Command
{
    protected $signature = 'atlas:memory:feedback-implicit
        {--diff= : path to a unified diff / changed content (default: `git diff HEAD`)}
        {--since=24 : window (hours) of recall usages to consider}
        {--limit=500 : max usages scanned}
        {--apply : actually record feedback (default: dry-run report)}
        {--json : machine-readable output}';

    protected $description = 'D4 · mark recalled memories cited ∧ present in the diff as useful_implicit + flag dominant recalls (Obra #18).';

    public function handle(AtlasMemoryUsageService $usageService): int
    {
        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            return $this->emit(['ok' => false, 'reason' => 'usages_table_absent'], self::SUCCESS);
        }

        $diff = mb_strtolower($this->diffText());
        $sinceHours = max(1, (int) $this->option('since'));
        $window = now()->subHours($sinceHours);

        $usages = AtlasMemoryEntryUsage::query()
            ->where('created_at', '>=', $window)
            ->with('memoryEntry')
            ->orderByDesc('created_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        // Dominant-recall pathology: an entry returned by >50% of the window's recalls.
        $perEntry = $usages->groupBy('memory_entry_id')->map->count();
        $windowTotal = max(1, $usages->count());
        $dominant = $perEntry->filter(fn (int $n): bool => $n / $windowTotal > 0.5)->keys()->values()->all();

        $apply = (bool) $this->option('apply');
        $marked = 0;
        foreach ($usages as $usage) {
            if ($usage->feedback_action !== null) {
                continue; // already has feedback — never overwrite
            }
            $entry = $usage->memoryEntry;
            if ($entry === null || ! $this->diffMentions($diff, (string) $entry->title)) {
                continue;
            }
            if ($apply) {
                $usageService->recordFeedback($usage, [
                    'feedback_action' => 'useful_implicit',
                    'feedback_source' => 'implicit_diff',
                    'feedback_comment' => 'recalled ∧ present in the session diff',
                ]);
            }
            $marked++;
        }

        return $this->emit([
            'ok' => true,
            'applied' => $apply,
            'window_hours' => $sinceHours,
            'usages_scanned' => $usages->count(),
            'marked_useful_implicit' => $marked,
            'dominant_recall_entries' => $dominant,
        ], self::SUCCESS);
    }

    /**
     * True when a significant (len ≥ 4) token of the memory's title appears in the
     * lower-cased diff text — the crude "cited ∧ in the change" signal.
     */
    private function diffMentions(string $diffLower, string $title): bool
    {
        if (trim($diffLower) === '' || trim($title) === '') {
            return false;
        }
        foreach (preg_split('/[^a-z0-9]+/i', mb_strtolower($title)) ?: [] as $token) {
            if (mb_strlen($token) >= 4 && str_contains($diffLower, $token)) {
                return true;
            }
        }

        return false;
    }

    private function diffText(): string
    {
        $path = (string) $this->option('diff');
        if ($path !== '' && is_file($path)) {
            return (string) file_get_contents($path);
        }
        $p = Process::path(base_path())->run(['git', 'diff', 'HEAD']);

        return $p->successful() ? $p->output() : '';
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf(
                '%s · scanned=%s marked_useful=%s dominant=%s%s',
                ($payload['ok'] ?? false) ? '<info>feedback-implicit</info>' : '<error>skipped</error>',
                $payload['usages_scanned'] ?? 0,
                $payload['marked_useful_implicit'] ?? 0,
                count((array) ($payload['dominant_recall_entries'] ?? [])),
                ($payload['applied'] ?? false) ? '' : ' (dry-run — use --apply)',
            ));
        }

        return $code;
    }
}
