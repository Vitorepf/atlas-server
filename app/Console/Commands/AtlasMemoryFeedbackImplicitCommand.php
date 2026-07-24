<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntryUsage;
use App\Services\Ai\Memory\AtlasMemoryUsageService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use App\Console\Concerns\EmitsCanonicalJson;

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
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:feedback-implicit
        {--diff= : path to a unified diff / changed content (default: `git diff HEAD`)}
        {--since=24 : window (hours) of recall usages to consider}
        {--limit=500 : max usages scanned}
        {--min-ignored-sessions=3 : minimum delivered sessions/usages without identity mention before ignored_implicit}
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
        $usefulUsageIds = [];
        foreach ($usages as $usage) {
            if ($usage->getAttribute('feedback_action') !== null) {
                continue; // already has feedback — never overwrite
            }
            $entry = $usage->memoryEntry;
            if ($entry === null || ! $this->diffMentions($diff, (string) $entry->getAttribute('title'))) {
                continue;
            }
            if ($apply) {
                $usageService->recordFeedback($usage, [
                    'feedback_action' => 'useful_implicit',
                    'feedback_source' => 'implicit_diff',
                    'feedback_comment' => 'recalled ∧ present in the session diff',
                ]);
            }
            $usefulUsageIds[(string) $usage->getAttribute('id')] = true;
            $marked++;
        }

        $minIgnoredSessions = max(1, (int) $this->option('min-ignored-sessions'));
        $ignoredCandidatesByEntry = [];
        foreach ($usages as $usage) {
            $usageId = (string) $usage->getAttribute('id');
            if (isset($usefulUsageIds[$usageId]) || $usage->getAttribute('feedback_action') !== null) {
                continue;
            }
            $entry = $usage->memoryEntry;
            if ($entry === null || $this->diffMentionsMemoryIdentity($diff, $usage, $entry)) {
                continue;
            }
            $entryId = (string) $usage->getAttribute('memory_entry_id');
            $ignoredCandidatesByEntry[$entryId] ??= [];
            $ignoredCandidatesByEntry[$entryId][] = $usage;
        }

        $markedIgnored = 0;
        foreach ($ignoredCandidatesByEntry as $entryUsages) {
            $sessionKeys = [];
            foreach ($entryUsages as $usage) {
                $session = trim((string) $usage->getAttribute('session_id'));
                $sessionKeys[] = $session !== '' ? $session : (string) $usage->getAttribute('id');
            }
            if (count(array_unique($sessionKeys)) < $minIgnoredSessions) {
                continue;
            }

            foreach ($entryUsages as $usage) {
                if ($apply) {
                    $usageService->recordFeedback($usage, [
                        'feedback_action' => 'ignored_implicit',
                        'feedback_source' => 'implicit_identity_absence',
                        'feedback_comment' => 'delivered memory identity was not referenced by the session artifact',
                    ]);
                }
                $markedIgnored++;
            }
        }

        return $this->emit([
            'ok' => true,
            'applied' => $apply,
            'window_hours' => $sinceHours,
            'usages_scanned' => $usages->count(),
            'marked_useful_implicit' => $marked,
            'marked_ignored_implicit' => $markedIgnored,
            'min_ignored_sessions' => $minIgnoredSessions,
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

    private function diffMentionsMemoryIdentity(string $diffLower, AtlasMemoryEntryUsage $usage, mixed $entry): bool
    {
        if (trim($diffLower) === '') {
            return false;
        }

        foreach ($this->identityNeedles($usage, $entry) as $needle) {
            $needle = mb_strtolower($needle);
            if ($needle !== '' && str_contains($diffLower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identity-only markers. Never derive implicit negatives from title tokens; those
     * are too broad for a negative signal.
     *
     * @return array<int,string>
     */
    private function identityNeedles(AtlasMemoryEntryUsage $usage, mixed $entry): array
    {
        $needles = [
            (string) $usage->getAttribute('memory_entry_id'),
            (string) $entry->getAttribute('id'),
            (string) $entry->getAttribute('content_hash'),
            (string) $entry->getAttribute('source_hash'),
            (string) data_get($entry->getAttribute('metadata') ?? [], 'slug'),
        ];

        foreach ([$usage->getAttribute('source_ref_json') ?? [], $usage->getAttribute('context_payload_json') ?? []] as $payload) {
            foreach ($this->identityScalars((array) $payload) as $value) {
                $needles[] = $value;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $value): string => trim($value), $needles),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function identityScalars(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                array_push($out, ...$this->identityScalars($value));
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $key = mb_strtolower((string) $key);
            if (in_array($key, ['id', 'slug', 'hash', 'content_hash', 'source_hash', 'memory_ref'], true)) {
                $out[] = (string) $value;
            }
        }

        return $out;
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
            $this->line($this->encode($payload));
        } else {
            $this->line(sprintf(
                '%s · scanned=%s marked_useful=%s dominant=%s%s',
                ($payload['ok'] ?? false) ? '<info>feedback-implicit</info>' : '<error>skipped</error>',
                $payload['usages_scanned'] ?? 0,
                ((int) ($payload['marked_useful_implicit'] ?? 0)) + ((int) ($payload['marked_ignored_implicit'] ?? 0)),
                count((array) ($payload['dominant_recall_entries'] ?? [])),
                ($payload['applied'] ?? false) ? '' : ' (dry-run — use --apply)',
            ));
        }

        return $code;
    }
}
