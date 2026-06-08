<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The governed merge-promotion gate — the professional way to cross (or not) the
 * never-merge sovereignty line.
 *
 * Promoting a loop win to source is NEVER a boolean. This gate requires, in order:
 *   1. config `atlas.ai.loop.merge_to_source_enabled` = true (default OFF);
 *   2. an explicit operator approval (operator id + approved=true) — recorded;
 *   3. the proposal materializes cleanly (reuses AtlasLoopProposalMaterializer);
 *   4. a re-proof of the materialized workspace (the frozen-judge re-run; the gate
 *      refuses when it cannot re-prove — never promote what you cannot verify);
 *   5. the change is committed to a NEW BRANCH inside the isolated workspace —
 *      NEVER the operator's working tree, NEVER main. Merging that branch to the
 *      operator's main stays a human git/PR act.
 *
 * So even when fully enabled+approved, Atlas's self-authored change gets the same
 * rigour as any external contributor's PR (branch + review + the frozen-judge
 * re-proof), and the gate is structurally incapable of writing main itself.
 */
final class AtlasLoopProposalPromotionGate
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_promotion.v1';

    public function __construct(
        private readonly AtlasLoopProposalMaterializer $materializer,
    ) {}

    /**
     * @param  array<string,mixed>  $approval  ['operator_id' => string, 'approved' => bool]
     * @param  Closure(string,AtlasLoopProposal):bool|null  $reprover  override for testing/wiring
     * @return array<string,mixed>
     */
    public function promote(AtlasLoopProposal $proposal, string $baseDir, array $approval, ?Closure $reprover = null): array
    {
        if (! (bool) config('atlas.ai.loop.merge_to_source_enabled', false)) {
            return $this->deny('merge_to_source_disabled');
        }

        $operator = trim((string) ($approval['operator_id'] ?? ''));
        if ($operator === '' || ($approval['approved'] ?? false) !== true) {
            return $this->deny('operator_approval_required');
        }

        $mat = $this->materializer->materialize($proposal, $baseDir);
        if (($mat['materialized'] ?? false) !== true) {
            return $this->deny('materialize_failed:'.(string) ($mat['reason'] ?? 'unknown'));
        }
        $workspace = (string) $mat['isolated_path'];

        $reprover ??= fn (string $ws, AtlasLoopProposal $p): bool => $this->defaultReprove($ws, $p);
        if ($reprover($workspace, $proposal) !== true) {
            return $this->deny('reproof_failed', $workspace);
        }

        // Commit to a NEW BRANCH inside the isolated workspace. Never main, never the
        // operator's working tree.
        $branch = 'atlas-loop-promotion-'.substr((string) ($proposal->proposal_hash ?: bin2hex(random_bytes(6))), 0, 16);
        $this->git($workspace, ['checkout', '-q', '-b', $branch]);
        $this->git($workspace, ['add', '-A']);
        $this->git($workspace, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas', 'commit', '-q', '-m', 'atlas loop promotion: '.(string) $proposal->target_path, '--no-gpg-sign']);

        $receiptHash = hash('sha256', $operator.'|'.$branch.'|'.(string) ($proposal->proposal_hash ?? '').'|'.(string) $proposal->target_path);
        $this->recordReceipt($proposal, $operator, $branch, $receiptHash);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promoted' => true,
            'reason' => null,
            'branch' => $branch,
            'isolated_path' => $workspace,
            'operator_id' => $operator,
            'receipt_hash' => $receiptHash,
            // The two invariants: a branch artifact only; main is never written here.
            'merged_to_main' => false,
            'never_main' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function deny(string $reason, ?string $workspace = null): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'promoted' => false,
            'reason' => $reason,
            'branch' => null,
            'isolated_path' => $workspace,
            'operator_id' => null,
            'receipt_hash' => null,
            'merged_to_main' => false,
            'never_main' => true,
        ];
    }

    /**
     * Conservative default re-proof: re-run the frozen judge against the materialized
     * workspace when the proposal carries a runnable acceptance; refuse otherwise
     * (never promote what cannot be re-proven). Best-effort by design — a caller may
     * inject a stronger reprover.
     */
    private function defaultReprove(string $workspace, AtlasLoopProposal $proposal): bool
    {
        $acceptance = is_array($proposal->metric ?? null) ? $proposal->metric : [];
        if ($acceptance === []) {
            return false;
        }

        try {
            $score = app(AtlasEvolutionFrozenJudge::class)->score($workspace, $acceptance);

            return ($score['passed'] ?? $score['ok'] ?? (($score['gate'] ?? '') === 'pass')) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordReceipt(AtlasLoopProposal $proposal, string $operator, string $branch, string $receiptHash): void
    {
        try {
            app(\App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger::class)->record(
                \App\Services\Ai\Kernel\Evidence\LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'loop_promotion_to_branch',
                    'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
                    'target_path' => (string) $proposal->target_path,
                    'branch' => $branch,
                    'merged_to_main' => false,
                    'receipt_hash' => $receiptHash,
                ],
                [
                    'operator_id' => $operator,
                    'emitter_stage' => 'atlas.ai.loop_promotion',
                    'emitter_version' => 'loop-promotion-gate-v1',
                ],
            );
        } catch (Throwable) {
            // Receipt recording is best-effort; the gate's invariants do not depend on it.
        }
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $p = new Process(array_merge(['git'], $argv), $cwd, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }
}
