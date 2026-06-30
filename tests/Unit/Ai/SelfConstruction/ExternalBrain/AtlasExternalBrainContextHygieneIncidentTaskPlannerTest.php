<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainContextHygieneIncidentTaskPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainContextHygieneIncidentTaskPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainContextHygieneIncidentTaskPlanner
    {
        return new AtlasExternalBrainContextHygieneIncidentTaskPlanner;
    }

    private function incident(string $issueCode, string $sourceId, int $evidenceCount = 1): array
    {
        return [
            'incident_id' => $sourceId.'-'.$issueCode,
            'issue_code' => $issueCode,
            'source_id' => $sourceId,
            'evidence_count' => $evidenceCount,
            'neutral_summary' => 'sanitized neutral summary',
        ];
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_task_plan_item_has_all_required_fields(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-1'),
        ]);

        $item = $r['task_plan'][0];
        foreach (['target_capability', 'allowed_file_hints', 'acceptance_strength', 'recurrence_risk', 'leverage_score'] as $key) {
            $this->assertArrayHasKey($key, $item, "Missing key: {$key}");
        }
    }

    // ── AC: never echoes raw unsafe text — only codes, hashes, bounded counts ──

    public function test_output_never_contains_raw_text_fields(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-1'),
        ]);

        $item = $r['task_plan'][0];
        $this->assertArrayNotHasKey('raw_text', $item);
        $this->assertArrayNotHasKey('excerpt', $item);
        $this->assertArrayNotHasKey('source_id', $item, 'raw source_id must not leak — only its hash');
        $this->assertArrayHasKey('source_hash', $item);
        $this->assertStringStartsWith('sha256:', $item['source_hash']);
    }

    public function test_evidence_count_is_bounded_integer_not_raw_payload(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-1', 7),
        ]);

        $this->assertIsInt($r['task_plan'][0]['evidence_count']);
    }

    // ── AC: leakage incidents rank above non-leakage ──────────────────────────

    public function test_hostile_memory_leakage_ranks_above_stale_instructions(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-stale'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-hostile'),
        ]);

        $this->assertSame(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, $r['task_plan'][0]['issue_code']);
    }

    public function test_prompt_leakage_ranks_above_context_overload(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD, 'src-overload'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_PROMPT_LEAKAGE, 'src-leak'),
        ]);

        $this->assertSame(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_PROMPT_LEAKAGE, $r['task_plan'][0]['issue_code']);
    }

    public function test_leakage_class_has_higher_leverage_score_than_other_classes(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-h'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD, 'src-o'),
        ]);

        $byCode = array_column($r['task_plan'], null, 'issue_code');
        $this->assertGreaterThan(
            $byCode[AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_IRRELEVANT_CONTEXT_OVERLOAD]['leverage_score'],
            $byCode[AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE]['leverage_score'],
        );
    }

    // ── AC: dedup repeated incidents (no template-farm) ───────────────────────

    public function test_repeated_incidents_from_same_source_deduplicate_into_one_item(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-same'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-same'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-same'),
        ]);

        $this->assertCount(1, $r['task_plan']);
        $this->assertSame(3, $r['task_plan'][0]['evidence_count']);
    }

    public function test_different_issue_codes_from_same_source_remain_separate_items(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-mixed'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-mixed'),
        ]);

        $this->assertCount(2, $r['task_plan']);
    }

    public function test_different_sources_with_same_issue_remain_separate_items(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-a'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-b'),
        ]);

        $this->assertCount(2, $r['task_plan']);
    }

    // ── target_capability / allowed_file_hints mapping ────────────────────────

    public function test_hostile_language_maps_to_memory_safety_gate_capability(): void
    {
        $r = $this->planner()->plan([
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-1'),
        ]);

        $this->assertSame('memory_safety_gate', $r['task_plan'][0]['target_capability']);
        $this->assertNotEmpty($r['task_plan'][0]['allowed_file_hints']);
    }

    // ── acceptance_strength / recurrence_risk scale with evidence_count ───────

    public function test_higher_evidence_count_yields_stronger_acceptance_strength(): void
    {
        $weak = $this->planner()->plan([$this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-1', 0)]);
        $strong = $this->planner()->plan([$this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-2', 5)]);

        $this->assertSame('weak', $weak['task_plan'][0]['acceptance_strength']);
        $this->assertSame('strong', $strong['task_plan'][0]['acceptance_strength']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $incidents = [
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_RAW_HOSTILE_LANGUAGE, 'src-1'),
            $this->incident(AtlasExternalBrainContextHygieneIncidentTaskPlanner::ISSUE_STALE_INSTRUCTIONS, 'src-2'),
        ];

        $a = $this->planner()->plan($incidents);
        $b = $this->planner()->plan($incidents);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_incidents_returns_empty_task_plan(): void
    {
        $r = $this->planner()->plan([]);
        $this->assertSame([], $r['task_plan']);
    }
}
