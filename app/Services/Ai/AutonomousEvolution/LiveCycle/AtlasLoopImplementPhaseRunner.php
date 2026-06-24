<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle;

use Throwable;

/**
 * IMPLEMENT PHASE RUNNER — phase 5 of the canonical 8-phase live-cycle (orientar → compreender →
 * decidir-alavancagem → arquitetar → decompor → IMPLEMENTAR → certificar → fechar-na-main → aprender).
 *
 * PURE DELEGATE: this runner never writes files itself. It hands the decomposed task packet to an injected
 * Maestro delegate (in production: AtlasLoopAutonomousConductor / AtlasUnifiedLoopOrchestrator surface) and
 * returns a structured receipt of WHAT the delegate did — files_touched + optional commit_sha + status.
 *
 * ANTI-GOODHART: the receipt carries ONLY verifiable artifacts (files_touched list, commit_sha). No line
 * count, no churn metric, no "score". Status is one of {implemented, skipped, failed}; skipped is the honest
 * outcome when the delegate produced nothing (empty file list ⇒ skipped, never "force a write").
 */
final class AtlasLoopImplementPhaseRunner
{
    public const STATUS_IMPLEMENTED = 'implemented';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /** @var callable(array<string,mixed>):array<string,mixed> */
    private $maestroDelegate;

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $maestroDelegate
     *         The Maestro entrypoint. Takes the decomposed task packet, returns
     *         {files_touched:list<string>, commit_sha:?string, reason?:string} — anything else is ignored.
     */
    public function __construct(callable $maestroDelegate)
    {
        $this->maestroDelegate = $maestroDelegate;
    }

    /**
     * @param  array<string,mixed>  $taskPacket  the decomposed packet from the prior phase
     * @return array{
     *     phase:string,
     *     delegate:string,
     *     task_packet_id:string,
     *     files_touched:list<string>,
     *     commit_sha:?string,
     *     status:string,
     *     reason:?string
     * }
     */
    public function run(array $taskPacket): array
    {
        $packetId = (string) ($taskPacket['task_packet_id'] ?? ($taskPacket['id'] ?? ''));

        try {
            $delegateResult = ($this->maestroDelegate)($taskPacket);
        } catch (Throwable $e) {
            return $this->receipt($packetId, [], null, self::STATUS_FAILED, 'maestro_threw:'.$e->getMessage());
        }

        if (! is_array($delegateResult)) {
            return $this->receipt($packetId, [], null, self::STATUS_FAILED, 'maestro_returned_non_array');
        }

        $filesTouched = $this->normaliseFiles($delegateResult['files_touched'] ?? []);
        $commitSha = isset($delegateResult['commit_sha']) && is_string($delegateResult['commit_sha']) && $delegateResult['commit_sha'] !== ''
            ? (string) $delegateResult['commit_sha']
            : null;
        $reason = isset($delegateResult['reason']) && is_string($delegateResult['reason']) && $delegateResult['reason'] !== ''
            ? (string) $delegateResult['reason']
            : null;

        if ($filesTouched === []) {
            // Empty file list ⇒ Maestro produced nothing implementable for this packet. Honest outcome:
            // skipped. The runner does NOT compensate by writing files itself — anti-Goodhart.
            return $this->receipt($packetId, [], $commitSha, self::STATUS_SKIPPED, $reason ?? 'no_files_touched_by_delegate');
        }

        return $this->receipt($packetId, $filesTouched, $commitSha, self::STATUS_IMPLEMENTED, $reason);
    }

    /**
     * @param  mixed  $files
     * @return list<string>
     */
    private function normaliseFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            $f = trim((string) $f);
            if ($f !== '' && ! in_array($f, $out, true)) {
                $out[] = $f;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param  list<string>  $files
     * @return array{phase:string,delegate:string,task_packet_id:string,files_touched:list<string>,commit_sha:?string,status:string,reason:?string}
     */
    private function receipt(string $packetId, array $files, ?string $commitSha, string $status, ?string $reason): array
    {
        return [
            'phase' => 'implement',
            'delegate' => 'maestro',
            'task_packet_id' => $packetId,
            'files_touched' => $files,
            'commit_sha' => $commitSha,
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
