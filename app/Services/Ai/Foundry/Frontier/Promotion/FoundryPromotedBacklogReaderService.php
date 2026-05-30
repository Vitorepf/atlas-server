<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Promotion;

/**
 * AFEF AP-D Promotion · Promoted Backlog Reader — closes the structurally
 * severed recursive bridge (Finding 25).
 *
 * The AP-D compiler (FoundryOperatorPromotionBacklogCompilerService) appends
 * operator-gated promoted findings to factory_max_promoted_backlog.jsonl, but
 * that ledger was WRITE-ONLY: no consumer ever read it, so a promoted AFEF
 * finding never flowed back into the Pilar 1 planner/loop. This thin read-only
 * seam scans the backlog for an area and returns the UN-CONSUMED
 * promoted_parent_finding records — those whose promoted finding has no terminal
 * evolution_outcome yet — in the exact shape the EXISTING
 * FindingSlicePlannerService (which BuildPlanDecomposerService delegates to)
 * already consumes as its `finding` input.
 *
 * "Terminal" is read ONLY from the AP-E evolution_outcomes.jsonl ledger written
 * by FoundryEvolutionOutcomeMaterializerService — never asserted here. The join
 * is: an outcome record's finding_id maps (parent::packet::N -> parent) to the
 * promoted_parent_finding.finding_id, and its action is a terminal AP-E action
 * (consolidate / reverted). Loop completion is therefore read from the tracker,
 * never synthesized by the reader (plan-delivery cert preserved).
 *
 * Invariants preserved:
 *   - I4 no-self-canonization: this is PURELY read-only. It performs ZERO
 *     writes and synthesizes ZERO backlog lines; it only echoes records the
 *     operator-gated compiler already wrote.
 *   - real-or-blocked: an absent OR empty backlog ledger returns []. No
 *     fabrication, no placeholder finding.
 */
final class FoundryPromotedBacklogReaderService
{
    public const BACKLOG_FILE = 'factory_max_promoted_backlog.jsonl';

    public const OUTCOMES_FILE = 'evolution_outcomes.jsonl';

    /**
     * AP-E terminal actions. A promoted finding whose hash joins to an outcome
     * carrying one of these is considered consumed (no longer un-consumed).
     */
    public const TERMINAL_ACTIONS = ['consolidate', 'reverted'];

    private ?string $storageDirOverride = null;

    /**
     * Scoped JSONL seam mirroring the compiler's
     * setBacklogStorageDirForTesting / the materializer's
     * setOutcomesStorageDirForTesting: redirects BOTH ledger reads to a test
     * directory. The reader joins the two ledgers under the same scope root.
     */
    public function setStorageDirForTesting(?string $dir): void
    {
        $this->storageDirOverride = $dir;
    }

    /**
     * Return the un-consumed promoted_parent_finding records for an area, ready
     * to feed FindingSlicePlannerService::plan() (the `finding` input). A record
     * is un-consumed when its promoted finding has NO terminal evolution_outcome
     * yet. Read-only: absent/empty backlog returns [].
     *
     * @return list<array<string,mixed>>
     */
    public function unconsumedPromotedFindings(string $areaId): array
    {
        $records = $this->readJsonl($this->filePath($areaId, self::BACKLOG_FILE));
        if ($records === []) {
            return [];
        }

        $terminalParentIds = $this->terminalParentFindingIds($areaId);

        $out = [];
        $seenHashes = [];
        foreach ($records as $record) {
            $promotedHash = (string) ($record['promoted_finding_hash'] ?? '');
            $parent = $record['promoted_parent_finding'] ?? null;
            if ($promotedHash === '' || ! is_array($parent)) {
                continue;
            }

            // Idempotent dedup: the backlog is append-only and the compiler is
            // idempotent, but guard against duplicate hashes defensively.
            if (isset($seenHashes[$promotedHash])) {
                continue;
            }

            $parentFindingId = (string) ($parent['finding_id'] ?? '');
            $parentFindingHash = (string) ($parent['finding_hash'] ?? '');

            // Consumed if a terminal outcome joins via the PARENT finding id OR
            // the promoted finding hash. Either join => exclude.
            if (
                ($parentFindingId !== '' && isset($terminalParentIds['id:'.$parentFindingId]))
                || isset($terminalParentIds['hash:'.$promotedHash])
                || ($parentFindingHash !== '' && isset($terminalParentIds['hash:'.$parentFindingHash]))
            ) {
                continue;
            }

            $seenHashes[$promotedHash] = true;
            $out[] = $parent;
        }

        return $out;
    }

    /**
     * Build the set of terminal join keys from the AP-E evolution_outcomes
     * ledger. Keys are namespaced ('id:'/'hash:') so a packet finding_id and a
     * finding_hash never collide. Read-only over the materializer's ledger.
     *
     * @return array<string,true>
     */
    private function terminalParentFindingIds(string $areaId): array
    {
        $outcomes = $this->readJsonl($this->filePath($areaId, self::OUTCOMES_FILE));

        $terminal = [];
        foreach ($outcomes as $outcome) {
            $action = (string) ($outcome['action'] ?? '');
            if (! in_array($action, self::TERMINAL_ACTIONS, true)) {
                continue;
            }

            $packetFindingId = (string) ($outcome['finding_id'] ?? '');
            if ($packetFindingId !== '') {
                $terminal['id:'.$this->parentFindingId($packetFindingId)] = true;
            }

            $mergeHash = (string) ($outcome['merge_hash'] ?? '');
            if ($mergeHash !== '') {
                $terminal['hash:'.$mergeHash] = true;
            }
        }

        return $terminal;
    }

    /**
     * Map a packet finding_id (parent::packet::N) to its PARENT finding id,
     * mirroring FoundryEvolutionOutcomeMaterializerService::parentFindingId.
     */
    private function parentFindingId(string $packetFindingId): string
    {
        $pos = strpos($packetFindingId, '::packet::');
        if ($pos === false) {
            return $packetFindingId;
        }

        return substr($packetFindingId, 0, $pos);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function filePath(string $areaId, string $file): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->storageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$file;
    }
}
