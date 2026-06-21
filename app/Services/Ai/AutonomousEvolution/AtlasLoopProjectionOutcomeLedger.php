<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * §5 · LEARNING — the architect phase records each projection OUTCOME so the next cycle is smarter.
 *
 * Every projection the worker runs ends converged / parked(forbidden) / parked(blast-radius|non-converged).
 * This ledger appends those verdicts (campaign-scoped, best-effort, never throws — observability must never
 * break a projection). The ONE feedback it drives is the SAFE one: a target the architect phase already
 * parked as a pétreo CERT ORGAN (forbidden) is permanently off-limits — re-projecting it only rebuilds the
 * ~8s comprehension model to reach the same verdict. So {@see wasForbidden} lets the worker SKIP the build
 * and park immediately on the next encounter in the same campaign. The loop literally gets faster at avoiding
 * what it must never originate.
 *
 * DELIBERATELY NOT fed back into suppression: a blast-radius / non-converged park is NOT recorded as a
 * permanent veto — those targets may be valuable later (a better design, an operator approval), and silencing
 * them autonomously is the #4 Goodhart surface. Only the logically-certain "never touch the judge" learning
 * acts. The rest is audit-only.
 *
 * File-backed (no migration): a JSON array per campaign under the local disk. Append is read-modify-write,
 * bounded, and tolerant of a corrupt/absent file (treated as empty).
 */
final class AtlasLoopProjectionOutcomeLedger
{
    public const STATUS_CONVERGED = 'converged';

    public const STATUS_PARKED = 'parked';

    private function path(string $campaignId): string
    {
        return 'atlas/loop/projection-outcomes/'.preg_replace('/[^A-Za-z0-9_\-]/', '_', $campaignId).'.json';
    }

    /**
     * Append a projection outcome (best-effort; a storage failure never escapes).
     */
    public function record(string $campaignId, string $target, string $status, ?string $reason = null): void
    {
        $campaignId = trim($campaignId);
        $target = $this->norm($target);
        if ($campaignId === '' || $target === '') {
            return;
        }

        try {
            $rows = $this->read($campaignId);
            $rows[] = ['target' => $target, 'status' => $status, 'reason' => $reason];
            Storage::disk('local')->put($this->path($campaignId), (string) json_encode([
                'schema_version' => 'atlas.loop.projection_outcomes.v1',
                'outcomes' => array_slice($rows, -2000), // cap unbounded growth
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // observability is best-effort — never break a projection over a ledger write
        }
    }

    /**
     * Was this target already parked as a pétreo/forbidden cert organ in this campaign? (The only outcome
     * that is a permanent, safe-to-suppress verdict.)
     */
    public function wasForbidden(string $campaignId, string $target): bool
    {
        $target = $this->norm($target);
        if ($target === '') {
            return false;
        }
        foreach ($this->read(trim($campaignId)) as $row) {
            if (($row['target'] ?? '') === $target && ($row['reason'] ?? '') === 'forbidden_target_petreo') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{target:string, status:string, reason:?string}>
     */
    public function read(string $campaignId): array
    {
        $campaignId = trim($campaignId);
        if ($campaignId === '') {
            return [];
        }
        try {
            $path = $this->path($campaignId);
            if (! Storage::disk('local')->exists($path)) {
                return [];
            }
            $decoded = json_decode((string) Storage::disk('local')->get($path), true);
            $rows = is_array($decoded['outcomes'] ?? null) ? $decoded['outcomes'] : [];

            return array_values(array_filter($rows, 'is_array'));
        } catch (Throwable) {
            return [];
        }
    }

    private function norm(string $target): string
    {
        return strtolower(ltrim(trim($target), '/'));
    }
}
