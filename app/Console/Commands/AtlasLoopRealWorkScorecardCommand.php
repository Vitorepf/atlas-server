<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRealWorkScorecardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * REAL-WORK CAMPAIGN SCORECARD · C0 — the executable ruler.
 *
 * Answers, with campaign/task data: "did this campaign produce REAL work, or just proxy/cosmetic?"
 * Read-only by construction — it NEVER invokes a provider, starts a campaign, or merges. The only write
 * it can perform is an explicit evidence receipt (`--write-receipt`), gated behind that flag.
 */
final class AtlasLoopRealWorkScorecardCommand extends Command
{
    protected $signature = 'atlas:loop:real-work-scorecard
        {--campaign= : Scope to a campaign_id (default: all tasks)}
        {--json : Print the canonical JSON scorecard on stdout}
        {--write-receipt : Persist the scorecard JSON as an evidence receipt}
        {--receipt= : Explicit receipt output path (implies --write-receipt)}';

    protected $description = 'Real-work campaign scorecard (C0): classify campaign tasks as real / proxy / cosmetic / unknown and emit an honest claim policy. Read-only.';

    public function handle(AtlasLoopRealWorkScorecardService $service): int
    {
        $campaignId = trim((string) $this->option('campaign')) ?: null;
        $scorecard = $service->scorecard($campaignId);

        $receiptPath = $this->maybeWriteReceipt($scorecard, $campaignId);

        if ((bool) $this->option('json')) {
            // stdout stays pure JSON (so it round-trips through json_decode); the receipt path, if any,
            // is carried INSIDE the payload, never as an extra stdout line.
            if ($receiptPath !== null) {
                $scorecard['receipt_path'] = $receiptPath;
            }
            $this->line((string) json_encode($scorecard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $claim = (bool) data_get($scorecard, 'claim_policy.loop_real_work_claim_allowed', false);
        $this->components->info('Real-Work Campaign Scorecard (C0)');
        $this->components->twoColumnDetail('Campaign', $campaignId ?? '(all)');
        $this->components->twoColumnDetail('Status', (string) ($scorecard['status'] ?? 'ok'));
        $this->components->twoColumnDetail('Tasks total', (string) $scorecard['tasks_total']);
        $this->components->twoColumnDetail('Real work', (string) $scorecard['real_work_tasks']
            .' (bug_fix '.$scorecard['bug_fix_tasks'].' / feature '.$scorecard['feature_tasks'].' / verification '.$scorecard['verification_tasks'].')');
        $this->components->twoColumnDetail('Proxy refactor', (string) $scorecard['proxy_refactor_tasks']);
        $this->components->twoColumnDetail('Cosmetic', (string) $scorecard['cosmetic_tasks']);
        $this->components->twoColumnDetail('Unknown', (string) $scorecard['unknown_tasks']);
        $this->components->twoColumnDetail('Real-work ratio', (string) $scorecard['real_work_ratio']);
        $this->components->twoColumnDetail(
            '<options=bold>Real-work claim allowed</>',
            $claim ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>NO</>'
        );

        $blockers = (array) data_get($scorecard, 'claim_policy.blockers', []);
        if ($blockers !== []) {
            $this->components->twoColumnDetail('Blockers', implode(', ', $blockers));
        }
        if ($receiptPath !== null) {
            $this->components->twoColumnDetail('Receipt', $receiptPath);
        }

        $this->line('');
        $this->components->warn((string) $scorecard['verdict']);

        return self::SUCCESS;
    }

    /**
     * Persist the scorecard JSON as an evidence receipt when requested. Returns the absolute path, or null
     * when no receipt was requested. The receipt is the ONLY side effect this command can have.
     *
     * @param  array<string,mixed>  $scorecard
     */
    private function maybeWriteReceipt(array $scorecard, ?string $campaignId): ?string
    {
        $explicit = trim((string) $this->option('receipt')) ?: null;
        if (! (bool) $this->option('write-receipt') && $explicit === null) {
            return null;
        }

        if ($explicit !== null) {
            $path = $explicit;
        } else {
            $dir = storage_path('app/atlas/evidence/loop-real-work-scorecard');
            $slug = $campaignId !== null ? preg_replace('/[^A-Za-z0-9_-]/', '_', $campaignId) : 'all';
            $path = $dir.DIRECTORY_SEPARATOR.'scorecard-'.$slug.'-'.now()->format('Ymd-His').'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.json';
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($scorecard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
