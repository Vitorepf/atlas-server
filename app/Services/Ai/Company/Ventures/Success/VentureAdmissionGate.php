<?php

namespace App\Services\Ai\Company\Ventures\Success;

use App\Models\AiVenture;
use App\Models\AiVentureAdmissionDecision;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\VentureFoundryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Records cohort admission decisions for the company success engine. Every
 * venture/idea assessed for the cohort is logged here so the admission_rate
 * exposes (and forbids) gaming the >=70% target by cowardly under-admission.
 *
 * A venture is admissible when it carries a declared success milestone and has
 * no open existential risk. The gate is advisory by default
 * (`admission_gate_enabled` controls auto-enforcement on promotion).
 */
class VentureAdmissionGate
{
    private const VALID_DECISIONS = [
        AiVentureAdmissionDecision::DECISION_ADMITTED,
        AiVentureAdmissionDecision::DECISION_REJECTED,
        AiVentureAdmissionDecision::DECISION_PARKED,
    ];

    private const VALID_ORIGINS = [
        AiVentureAdmissionDecision::ORIGIN_CREATED,
        AiVentureAdmissionDecision::ORIGIN_MANAGED,
    ];

    /**
     * Persist an admission decision.
     *
     * @param  array<string,mixed>  $args
     */
    public function record(array $args): AiVentureAdmissionDecision
    {
        $decision = (string) ($args['decision'] ?? '');
        if (! in_array($decision, self::VALID_DECISIONS, true)) {
            throw VentureFoundryException::invalidValue('admission', 'decision', "invalid decision [{$decision}]");
        }

        $origin = (string) ($args['origin'] ?? AiVentureAdmissionDecision::ORIGIN_CREATED);
        if (! in_array($origin, self::VALID_ORIGINS, true)) {
            throw VentureFoundryException::invalidValue('admission', 'origin', "invalid origin [{$origin}]");
        }

        // Anti-cowardice / anti-theatre: an admission must declare the success
        // milestone the venture is being held to.
        $milestone = $args['success_milestone'] ?? null;
        if ($decision === AiVentureAdmissionDecision::DECISION_ADMITTED && (! is_array($milestone) || $milestone === [])) {
            throw VentureFoundryException::missingField('admission', 'success_milestone');
        }

        $uuid = (string) Str::uuid();
        $decidedAt = null;
        if (isset($args['decided_at'])) {
            try {
                $decidedAt = Carbon::parse((string) $args['decided_at']);
            } catch (\Throwable) {
                $decidedAt = null;
            }
        }
        if ($decidedAt === null) {
            $decidedAt = Carbon::now();
        }

        return AiVentureAdmissionDecision::query()->create([
            'uuid' => $uuid,
            'venture_id' => $args['venture_id'] ?? null,
            'idea_id' => $args['idea_id'] ?? null,
            'decision' => $decision,
            'origin' => $origin,
            'reason' => $args['reason'] ?? null,
            'assessment_run_id' => $args['assessment_run_id'] ?? null,
            'score' => isset($args['score']) ? (float) $args['score'] : null,
            'success_milestone' => is_array($milestone) ? $milestone : null,
            'decided_by' => (string) ($args['decided_by'] ?? 'atlas'),
            'decided_at' => $decidedAt,
            'decision_hash' => StrategyCanonicalHash::sha256([
                'uuid' => $uuid,
                'venture_id' => $args['venture_id'] ?? null,
                'idea_id' => $args['idea_id'] ?? null,
                'decision' => $decision,
                'decided_at' => $decidedAt->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Convenience: admit a venture into the cohort with a declared milestone.
     *
     * @param  array<string,mixed>  $milestone
     */
    public function admit(AiVenture $venture, array $milestone, string $origin = AiVentureAdmissionDecision::ORIGIN_CREATED, ?string $reason = null): AiVentureAdmissionDecision
    {
        return $this->record([
            'venture_id' => $venture->id,
            'decision' => AiVentureAdmissionDecision::DECISION_ADMITTED,
            'origin' => $origin,
            'success_milestone' => $milestone,
            'reason' => $reason,
        ]);
    }
}
