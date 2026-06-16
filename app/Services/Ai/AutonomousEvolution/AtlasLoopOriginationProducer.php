<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use Throwable;

/**
 * ACDE lever O1 — the in-lane ORIGINATION producer (spec-only, propose-only).
 *
 * The loop's hardest frontier: not "fix this target" but "here is a worthwhile change you did not ask for".
 * This producer authors a PROPOSE-ONLY origination proposal — it proposes STRUCTURE (a decomposition hint +
 * criteria count), never the frozen acceptance bar, never a diff, NEVER executeAndProve. A human reviews it.
 *
 * SAFETY (verified by an adversarial design pass before a line was written): an origination row is
 * certified_for_review, so the auto-merge drain WILL re-select it — "propose-only" is NOT self-evidently safe.
 * The load-bearing guarantee is that the row carries an EMPTY diff and NO acceptance_contract, so the drain's
 * reprove fails CLOSED at "no_acceptance_contract" and the row is RETIRED (reviewed_at stamped) on the first
 * pass — never merged, never clogging the oldest-first queue. Plus: every candidate is screened through
 * {@see AtlasLoopHarnessGuard} and a forbidden self-target (the judge/merge/never-merge chain) can NEVER be
 * originated against; and this producer file is itself in FORBIDDEN_SELF_TARGETS so the originator can never be
 * originated. PROVIDER-SAFE: the record carries only paths + structural facts, never raw code or a self-grade.
 * Flag-gated default-OFF => produce() is inert => byte-identical.
 */
final class AtlasLoopOriginationProducer
{
    public const ORIGINATION_SCHEMA = 'atlas.loop.origination.v1';

    public function __construct(private readonly ?AtlasLoopHarnessGuard $guard = null) {}

    /**
     * Author + persist one propose-only origination proposal for $targetPath. Returns the proposal id, or null
     * when OFF / empty target / forbidden self-target / no DB. NEVER returns a row that can auto-merge.
     *
     * @param  array<string,mixed>  $structuralHint  STRUCTURE only (decomposition_hint, criteria_count) — never
     *                                               a frozen acceptance bar (that stays human-frozen)
     */
    public function produce(string $targetPath, string $intent, array $structuralHint = []): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $targetPath = ltrim(trim($targetPath), '/');
        $intent = trim($intent);
        if ($targetPath === '' || $intent === '') {
            return null;
        }
        // Pétreo: an origination can NEVER target a safety file (the judge / merge / never-merge chain).
        if (($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($targetPath)) {
            return null;
        }
        if (! $this->dbAvailable()) {
            return null;
        }

        // ACDE O2 (the 3rd MULTIPLIER) — consult the operator accept/reject prior: if this origination SHAPE
        // has accumulated enough operator decisions AND its accept-rate is below the operator-frozen target,
        // BACK OFF — do not re-propose a shape the human keeps rejecting. The human accept/reject sharpens the
        // NEXT authored origination. Flag-gated default-OFF => no consult => byte-identical; a thin/novel shape
        // never backs off (no false silencing). The feedback stays inside origination — never the shared gate.
        if ((bool) config('atlas.loop.origination_outcome_enabled', false)) {
            $token = (new AtlasLoopOriginationOutcomeRecorder)->shapeToken($targetPath, (int) ($structuralHint['criteria_count'] ?? 0));
            $backsOff = (new AtlasLoopOriginationOutcomeRecorder)->shapeBacksOff(
                $token,
                max(1, (int) config('atlas.loop.origination_backoff_min_samples', 3)),
                (float) config('atlas.loop.origination_backoff_target_rate', 0.5),
            );
            if ($backsOff) {
                return null;
            }
        }

        try {
            $campaign = $this->originationCampaign();
            AtlasLoopProposal::$governedMergeInProgress = false;
            $proposal = AtlasLoopProposal::create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => mb_substr($intent, 0, 250),
                'target_path' => $targetPath,
                'diff_text' => '',                       // spec-only: origination PROPOSES, it never executes a diff
                'proposal_hash' => substr(hash('sha256', 'origination|'.$targetPath.'|'.$intent), 0, 40),
                'metric' => null,
                // NO _acceptance_contract key => the drain reprove fails closed at no_acceptance_contract and
                // RETIRES the row (never merges, never clogs). STRUCTURE only — never the frozen bar.
                'quality' => [
                    '_origination' => array_merge([
                        'schema' => self::ORIGINATION_SCHEMA,
                        'proposal_only' => true,
                        'decomposition_hint' => [],
                        'criteria_count' => 0,
                    ], $this->sanitizeHint($structuralHint)),
                ],
            ]);

            return (string) $proposal->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keep only provider-safe structural keys from a caller-supplied hint (no raw code / model text can ride
     * into the record). Unknown keys are dropped.
     *
     * @param  array<string,mixed>  $hint
     * @return array<string,mixed>
     */
    private function sanitizeHint(array $hint): array
    {
        $safe = [];
        foreach (['decomposition_hint', 'criteria_count', 'readiness_score', 'complexity', 'caller_count'] as $key) {
            if (array_key_exists($key, $hint)) {
                $safe[$key] = $hint[$key];
            }
        }

        return $safe;
    }

    private function originationCampaign(): AtlasLoopCampaign
    {
        $existing = AtlasLoopCampaign::query()
            ->where('goal', 'atlas.loop.origination')
            ->where('status', AtlasLoopCampaign::STATUS_RUNNING)
            ->orderByDesc('updated_at')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'atlas.loop.origination',
            'config' => ['origination' => true],
            'max_seconds' => 60,
        ]);
    }

    /** Is the origination producer ON? Read defensively so a container-less caller never fatals (=> OFF). */
    public function enabled(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('config')) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return (bool) config('atlas.loop.origination_producer_enabled', false);
    }

    private function dbAvailable(): bool
    {
        try {
            $app = function_exists('app') ? app() : null;

            return $app !== null && $app->bound('db');
        } catch (Throwable) {
            return false;
        }
    }
}
