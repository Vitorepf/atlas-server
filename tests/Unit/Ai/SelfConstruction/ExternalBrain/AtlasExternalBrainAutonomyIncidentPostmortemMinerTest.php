<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyIncidentPostmortemMiner;
use Tests\TestCase;

final class AtlasExternalBrainAutonomyIncidentPostmortemMinerTest extends TestCase
{
    private function svc(): AtlasExternalBrainAutonomyIncidentPostmortemMiner
    {
        return new AtlasExternalBrainAutonomyIncidentPostmortemMiner;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'malformed_batch']);

        foreach (['schema', 'incident_type', 'root_cause', 'detection_signal', 'wasted_token_risk', 'prevention_guard',
                  'recommended_task_family', 'serving_impact', 'task_quality_impact', 'recurrence_risk', 'next_policy_update'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainAutonomyIncidentPostmortemMiner::SCHEMA, $r['schema']);
    }

    public function test_unknown_incident_type_produces_taxonomy_hardening_not_fake_precision(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'some_brand_new_incident']);

        $this->assertSame('incident_taxonomy_hardening', $r['recommended_task_family']);
        $this->assertSame('unknown', $r['recurrence_risk']);
        $this->assertNotEmpty($r['next_policy_update']);
    }

    public function test_each_known_incident_type_has_non_empty_recurrence_risk_and_policy_update(): void
    {
        foreach ([
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_MALFORMED_BATCH,
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_LEASE_MISMATCH,
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_POISON_RESERVE,
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_WEAK_GREEN_COMMIT,
            AtlasExternalBrainAutonomyIncidentPostmortemMiner::TYPE_QUOTA_FARMING,
        ] as $type) {
            $r = $this->svc()->mine(['incident_type' => $type]);
            $this->assertNotEmpty($r['recurrence_risk'], "{$type} missing recurrence_risk");
            $this->assertNotEmpty($r['next_policy_update'], "{$type} missing next_policy_update");
        }
    }

    // ── AC1: malformed batch → schema validation guard ──────────────────────────

    public function test_malformed_batch_produces_schema_validation_guard(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'malformed_batch']);

        $found = false;
        foreach ($r['prevention_guard'] as $guard) {
            if (str_contains($guard, 'schema')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'malformed_batch must produce a guard tied to packet schema validation');
        $this->assertStringContainsString('schema', $r['root_cause']);
    }

    // ── AC2: quota farming → anti-template and value-proof recommendations ────

    public function test_quota_farming_produces_anti_template_and_value_proof_recommendations(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'quota_farming']);

        $hasAntiTemplate = false;
        $hasValueProof = false;
        foreach ($r['prevention_guard'] as $guard) {
            if (str_contains($guard, 'anti_template')) {
                $hasAntiTemplate = true;
            }
            if (str_contains($guard, 'value_proof')) {
                $hasValueProof = true;
            }
        }
        $this->assertTrue($hasAntiTemplate, 'quota_farming must recommend an anti-template guard');
        $this->assertTrue($hasValueProof, 'quota_farming must recommend a value-proof guard');
    }

    // ── AC3: lease mismatch preserves serving impact separately from task quality impact ──

    public function test_lease_mismatch_serving_impact_differs_from_task_quality_impact(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'lease_mismatch']);

        $this->assertArrayHasKey('serving_impact', $r);
        $this->assertArrayHasKey('task_quality_impact', $r);
        $this->assertNotSame($r['serving_impact'], $r['task_quality_impact']);
        $this->assertStringContainsString('none', $r['task_quality_impact'],
            'a lease mismatch is a serving-layer failure and must not implicate task quality');
    }

    // ── AC4/5: incident_type, root_cause, wasted_token_risk, prevention_guard, recommended_task_family ──

    public function test_poison_reserve_has_all_required_output_fields(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'poison_reserve']);

        $this->assertSame('poison_reserve', $r['incident_type']);
        $this->assertNotEmpty($r['root_cause']);
        $this->assertIsInt($r['wasted_token_risk']);
        $this->assertNotEmpty($r['prevention_guard']);
        $this->assertNotEmpty($r['recommended_task_family']);
    }

    public function test_weak_green_commit_root_cause_names_missing_behavior_assertion(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'weak_green_commit']);

        $this->assertStringContainsString('behavior', $r['root_cause']);
    }

    // ── wasted_token_risk ────────────────────────────────────────────────────────

    public function test_explicit_tokens_spent_overrides_default_estimate(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'malformed_batch', 'tokens_spent' => 12345]);

        $this->assertSame(12345, $r['wasted_token_risk']);
    }

    public function test_missing_tokens_spent_uses_deterministic_default(): void
    {
        $a = $this->svc()->mine(['incident_type' => 'quota_farming']);
        $b = $this->svc()->mine(['incident_type' => 'quota_farming']);

        $this->assertSame($a['wasted_token_risk'], $b['wasted_token_risk']);
        $this->assertGreaterThan(0, $a['wasted_token_risk']);
    }

    public function test_negative_tokens_spent_is_clamped_to_zero(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'malformed_batch', 'tokens_spent' => -500]);

        $this->assertSame(0, $r['wasted_token_risk']);
    }

    // ── unknown incident type ────────────────────────────────────────────────────

    public function test_unknown_incident_type_falls_back_to_unclassified(): void
    {
        $r = $this->svc()->mine(['incident_type' => 'something_never_seen']);

        $this->assertSame('unclassified_incident', $r['root_cause']);
        $this->assertNotEmpty($r['prevention_guard']);
    }

    public function test_missing_incident_type_field_is_treated_as_empty(): void
    {
        $r = $this->svc()->mine([]);

        $this->assertSame('', $r['incident_type']);
        $this->assertSame('unclassified_incident', $r['root_cause']);
    }

    // ── each incident type produces a distinct recommended task family ────────

    public function test_each_known_incident_type_has_a_distinct_recommended_task_family(): void
    {
        $types = ['malformed_batch', 'lease_mismatch', 'poison_reserve', 'weak_green_commit', 'quota_farming'];
        $families = [];
        foreach ($types as $type) {
            $families[] = $this->svc()->mine(['incident_type' => $type])['recommended_task_family'];
        }

        $this->assertSame($families, array_unique($families), 'each incident type must map to a distinct task family');
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_mine_is_deterministic(): void
    {
        $incident = ['incident_type' => 'poison_reserve', 'tokens_spent' => 900];
        $a = $this->svc()->mine($incident);
        $b = $this->svc()->mine($incident);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }
}
