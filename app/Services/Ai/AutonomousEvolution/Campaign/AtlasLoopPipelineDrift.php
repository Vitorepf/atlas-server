<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Campaign;

use Throwable;

/**
 * Single source of truth for "is a long-lived loop supervisor running STALE engine code?".
 *
 * A supervisor holds the whole loop PIPELINE (discovery/grinder/certifier/gates/auto-merge)
 * in memory at boot. When a fix lands on those files AFTER boot, the live process keeps
 * evolving Atlas with the OLD engine until it is recycled — proven live (2026-06-15: a
 * materializer fix sat un-loaded for 3h). Two consumers share this ONE definition so the
 * in-process check and the out-of-process keepalive can never diverge:
 *
 *  - AtlasLoopCampaignSupervisor: in-process, top-of-loop — exits cleanly on pipeline drift.
 *  - AtlasLoopKeepaliveCommand: out-of-process, every cadence — recycles a supervisor whose
 *    process start predates the latest pipeline commit (immune to a grind-starved inner loop
 *    or a null boot HEAD, the two ways the in-process check silently goes dark).
 */
final class AtlasLoopPipelineDrift
{
    /**
     * Path prefixes that make up the loop ENGINE. A change touching any of them means a live
     * supervisor is running old code of what matters. Files OUTSIDE these prefixes (the common
     * TARGETS the loop improves every cycle) must NOT trigger a restart — the supervisor does
     * not hold them in memory, so recycling on a target merge is pure churn (~5min downtime).
     *
     * @var list<string>
     */
    public const PREFIXES = [
        'app/Services/Ai/AutonomousEvolution/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
        'app/Models/AtlasLoop',
        'config/atlas.php',
        'database/migrations/2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
    ];

    /**
     * The ENGINE files among a changed-file set. Empty ⇒ only target files changed ⇒ no restart.
     *
     * @param  iterable<mixed>  $changed
     * @return list<string>
     */
    public static function pipelineFiles(iterable $changed): array
    {
        $pipeline = [];
        foreach ($changed as $file) {
            $file = trim((string) $file);
            if ($file === '') {
                continue;
            }
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    $pipeline[] = $file;
                    break;
                }
            }
        }

        return array_values(array_unique($pipeline));
    }

    /**
     * Pure recycle decision for the OUT-OF-PROCESS keepalive, anchored on TIME (not a captured
     * boot HEAD hash) so it does not share the in-process check's failure modes: it works even
     * when the supervisor never recorded a boot HEAD (null git read) or is starved inside a long
     * grind. Recycle iff the newest engine commit is strictly newer than the process start AND
     * the process has been alive past a grace window (so a just-respawned supervisor — already on
     * fresh code, its start now AFTER the commit — is never killed; this also makes the decision
     * self-clearing: one recycle moves the start past the commit and the condition goes false).
     */
    public static function shouldRecycle(
        ?int $bootEpoch,
        ?int $latestPipelineCommitEpoch,
        int $aliveSeconds,
        int $graceSeconds,
    ): bool {
        if ($bootEpoch === null || $latestPipelineCommitEpoch === null) {
            return false; // missing evidence ⇒ never recycle (fail-safe, the real-death respawn still covers)
        }
        if ($aliveSeconds < max(0, $graceSeconds)) {
            return false; // too young — could be mid-respawn; let it settle
        }

        return $latestPipelineCommitEpoch > $bootEpoch;
    }

    /**
     * Committer epoch of the most recent commit touching ANY engine path, or null on any git
     * error (best-effort: a missing signal degrades to "no drift", never throws into the watchdog).
     * An optional resolver is the test seam (no real git in unit tests).
     *
     * @param  null|callable(string, list<string>):?int  $resolver
     */
    public static function latestPipelineCommitEpoch(string $workspace, ?callable $resolver = null): ?int
    {
        if ($resolver !== null) {
            try {
                $v = $resolver($workspace, self::PREFIXES);

                return $v === null ? null : max(0, (int) $v);
            } catch (Throwable) {
                return null;
            }
        }
        if ($workspace === '' || ! is_dir($workspace)) {
            return null;
        }

        $args = '';
        foreach (self::PREFIXES as $p) {
            $args .= ' '.escapeshellarg($p);
        }
        $lines = [];
        $exit = 1;
        @exec(
            'git -C '.escapeshellarg($workspace).' log -1 --format=%ct --'.$args.' 2>/dev/null',
            $lines,
            $exit,
        );
        if ($exit !== 0) {
            return null;
        }
        $raw = trim(implode('', $lines));

        return preg_match('/\A\d+\z/', $raw) === 1 ? (int) $raw : null;
    }
}
