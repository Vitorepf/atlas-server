<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\AiMission;
use App\Models\OperatorPatternDetection;
use App\Services\Ai\Mission\MissionFactoryService;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bridge #3 — Initiative. A detected recurring pattern becomes a PROACTIVE mission that
 * Atlas PREPARES for the operator ("I noticed you keep doing X — here's a draft you can
 * review and enable"). It is propose-only by STRUCTURE, not convention:
 *
 *   • the mission is created at AUTONOMY_SUGGEST + STATUS_DRAFT (MissionFactoryService),
 *     and the mission lifecycle/control-plane never auto-transitions DRAFT → ACTIVE, so
 *     NOTHING executes (no send/publish/deploy/buy/delete) without explicit operator
 *     activation. Even a "you deploy every Friday" pattern yields a draft to review, never
 *     a deploy that fires;
 *   • the proactive_origin marks it as Atlas-initiated (the operator sees exactly which
 *     recurrence + how many occurrences prompted it);
 *   • one mission per pattern (the detection ledger flips to 'proposed'), so it never
 *     re-drafts the same recurrence.
 */
final class OperatorInitiativeBridge
{
    public function __construct(private readonly MissionFactoryService $missions) {}

    public function propose(OperatorPatternDetection $detection): ?AiMission
    {
        if ($detection->proposed_mission_id !== null || $detection->status === OperatorPatternDetection::STATUS_DISMISSED) {
            return null; // already drafted a mission for this recurrence, or operator dismissed it
        }
        if (! in_array($detection->proposal_target, ['mission', 'both'], true)) {
            return null;
        }

        try {
            $prompt = sprintf(
                'Padrão recorrente detectado (%dx em %dd): %s. Preparei esta missão como RASCUNHO para você revisar e ativar quando quiser — nada será executado sem a sua aprovação.',
                (int) $detection->occurrence_count,
                (int) $detection->window_days,
                $detection->privacy_class === 'normal' ? (string) $detection->summary : '[recorrência '.$detection->privacy_class.']',
            );

            $mission = $this->missions->create($prompt, [
                'mission_type' => MissionFactoryService::TYPE_TASK,
                'autonomy_level' => MissionFactoryService::AUTONOMY_SUGGEST, // never auto-execute
                'risk_level' => MissionFactoryService::RISK_LOW,
                'actor_type' => 'operator_pattern_detector',
                'context_summary' => $detection->privacy_class === 'normal' ? Str::limit((string) $detection->summary, 480) : null,
                'title' => 'Proativa: '.Str::limit((string) ($detection->privacy_class === 'normal' ? $detection->summary : $detection->kind), 90),
            ]);

            $mission->forceFill([
                'proactive_origin' => [
                    'schema_version' => OperatorPatternDetector::SCHEMA,
                    'detection_id' => (string) $detection->id,
                    'pattern_id' => (string) $detection->pattern_id,
                    'kind' => (string) $detection->kind,
                    'occurrence_count' => (int) $detection->occurrence_count,
                    'confidence' => (float) $detection->confidence,
                    'evidence_count' => is_array($detection->evidence) ? count($detection->evidence) : 0,
                ],
                'proactive_status' => 'proposed',
            ])->save();

            $detection->forceFill([
                'status' => OperatorPatternDetection::STATUS_PROPOSED,
                'proposed_mission_id' => (string) $mission->id,
            ])->save();

            return $mission;
        } catch (Throwable) {
            return null; // best-effort initiative — a failed proposal never disrupts anything
        }
    }
}
