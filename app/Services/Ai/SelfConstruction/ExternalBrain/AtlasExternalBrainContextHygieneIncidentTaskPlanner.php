<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner — converts SANITIZED AOBG/context-pack hygiene incidents into a ranked, deduplicated
 * task plan. NEVER reads or echoes raw unsafe text: the only fields this class accepts and emits are
 * issue codes, neutral summaries, source hashes, and bounded evidence counts.
 *
 * INPUT (per incident, already sanitized by {@see \App\Services\Ai\AtlasOpenBrainMemoryProjectionSafetyGate}):
 *   { incident_id, issue_code:string, source_id:string, evidence_count:int, neutral_summary?:string }
 *
 * issue_code values:
 *   raw_hostile_language, raw_prompt_leakage   — leakage class, affects EVERY downstream worker
 *   missing_provider_safe_metadata             — provenance gap
 *   stale_instructions                         — freshness gap
 *   irrelevant_context_overload                — pruning gap
 *
 * DEDUPLICATION: incidents with the same (issue_code, source_id) collapse into ONE task_plan item with
 * a summed evidence_count — one bad memory source never spawns a template-farm of identical tasks.
 *
 * RANKING: leakage-class incidents (raw_hostile_language, raw_prompt_leakage) rank ABOVE every other
 * class — they directly contaminate every downstream worker's provider context, unlike cosmetic docs or
 * wrapper work elsewhere in the queue.
 *
 * OUTPUT (per item): { target_capability, allowed_file_hints, acceptance_strength, recurrence_risk,
 *   leverage_score, issue_code, source_hash, evidence_count }
 *
 * Pure: no I/O, no provider calls, no raw-text fields anywhere in input or output.
 */
final class AtlasExternalBrainContextHygieneIncidentTaskPlanner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.context_hygiene_incident_task_planner.v1';

    public const ISSUE_RAW_HOSTILE_LANGUAGE = 'raw_hostile_language';

    public const ISSUE_RAW_PROMPT_LEAKAGE = 'raw_prompt_leakage';

    public const ISSUE_MISSING_PROVIDER_SAFE_METADATA = 'missing_provider_safe_metadata';

    public const ISSUE_STALE_INSTRUCTIONS = 'stale_instructions';

    public const ISSUE_IRRELEVANT_CONTEXT_OVERLOAD = 'irrelevant_context_overload';

    /** Lower rank = higher severity → appears first. */
    private const SEVERITY_RANK = [
        self::ISSUE_RAW_HOSTILE_LANGUAGE => 1,
        self::ISSUE_RAW_PROMPT_LEAKAGE => 1,
        self::ISSUE_MISSING_PROVIDER_SAFE_METADATA => 2,
        self::ISSUE_STALE_INSTRUCTIONS => 3,
        self::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD => 4,
    ];

    private const TARGET_CAPABILITY = [
        self::ISSUE_RAW_HOSTILE_LANGUAGE => 'memory_safety_gate',
        self::ISSUE_RAW_PROMPT_LEAKAGE => 'memory_safety_gate',
        self::ISSUE_MISSING_PROVIDER_SAFE_METADATA => 'memory_provenance_enrichment',
        self::ISSUE_STALE_INSTRUCTIONS => 'memory_freshness_refresh',
        self::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD => 'context_pack_pruning',
    ];

    private const ALLOWED_FILE_HINTS = [
        self::ISSUE_RAW_HOSTILE_LANGUAGE => ['app/Services/Ai/AtlasOpenBrainMemoryProjectionSafetyGate.php'],
        self::ISSUE_RAW_PROMPT_LEAKAGE => ['app/Services/Ai/AtlasOpenBrainMemoryProjectionSafetyGate.php'],
        self::ISSUE_MISSING_PROVIDER_SAFE_METADATA => ['app/Services/Ai/SelfConstruction/Knowledge/AtlasSelfConstructionKnowledgeDominanceLoopPlanner.php'],
        self::ISSUE_STALE_INSTRUCTIONS => ['app/Services/Ai/SelfConstruction/Cortex/AtlasSelfConstructionCortexFreshnessBridge.php'],
        self::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD => ['app/Services/Ai/AtlasMemoryContextComposer.php'],
    ];

    /** Leakage-class issues affect every downstream worker — always high leverage. */
    private const LEAKAGE_CLASS = [self::ISSUE_RAW_HOSTILE_LANGUAGE, self::ISSUE_RAW_PROMPT_LEAKAGE];

    /**
     * @param  list<array<string,mixed>>  $incidents
     * @return array{schema:string, task_plan:list<array<string,mixed>>}
     */
    public function plan(array $incidents): array
    {
        $grouped = [];

        foreach ($incidents as $incident) {
            if (! is_array($incident)) {
                continue;
            }
            $issueCode = (string) ($incident['issue_code'] ?? '');
            $sourceId = (string) ($incident['source_id'] ?? '');
            if ($issueCode === '' || $sourceId === '') {
                continue;
            }
            $evidenceCount = max(0, (int) ($incident['evidence_count'] ?? 0));
            $key = $issueCode.'|'.$sourceId;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'issue_code' => $issueCode,
                    'source_id' => $sourceId,
                    'evidence_count' => 0,
                ];
            }
            $grouped[$key]['evidence_count'] += $evidenceCount;
        }

        $taskPlan = [];
        foreach ($grouped as $row) {
            $issueCode = $row['issue_code'];
            $evidenceCount = $row['evidence_count'];
            $isLeakage = in_array($issueCode, self::LEAKAGE_CLASS, true);

            $taskPlan[] = [
                'target_capability' => self::TARGET_CAPABILITY[$issueCode] ?? 'unclassified_hygiene_repair',
                'allowed_file_hints' => self::ALLOWED_FILE_HINTS[$issueCode] ?? [],
                'acceptance_strength' => $this->acceptanceStrength($evidenceCount),
                'recurrence_risk' => $this->recurrenceRisk($isLeakage, $evidenceCount),
                'leverage_score' => $this->leverageScore($isLeakage, $evidenceCount),
                'issue_code' => $issueCode,
                'source_hash' => 'sha256:'.hash('sha256', $row['source_id']),
                'evidence_count' => $evidenceCount,
            ];
        }

        usort($taskPlan, static function (array $a, array $b): int {
            $ra = self::SEVERITY_RANK[$a['issue_code']] ?? 99;
            $rb = self::SEVERITY_RANK[$b['issue_code']] ?? 99;

            return $ra <=> $rb
                ?: $b['leverage_score'] <=> $a['leverage_score']
                ?: strcmp($a['source_hash'], $b['source_hash']);
        });

        return [
            'schema' => self::SCHEMA,
            'task_plan' => $taskPlan,
        ];
    }

    private function acceptanceStrength(int $evidenceCount): string
    {
        return match (true) {
            $evidenceCount >= 3 => 'strong',
            $evidenceCount >= 1 => 'medium',
            default => 'weak',
        };
    }

    private function recurrenceRisk(bool $isLeakage, int $evidenceCount): string
    {
        if ($isLeakage && $evidenceCount >= 2) {
            return 'high';
        }
        if ($evidenceCount >= 3) {
            return 'high';
        }
        if ($evidenceCount >= 1) {
            return 'medium';
        }

        return 'low';
    }

    private function leverageScore(bool $isLeakage, int $evidenceCount): int
    {
        $base = $isLeakage ? 80 : 20;

        return $base + min(20, $evidenceCount * 5);
    }
}
