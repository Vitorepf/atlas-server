<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusInboxService;
use Tests\TestCase;

/**
 * AP-718 · Area Focus Inbox contract tests.
 *
 * Findings are injected via `$input['findings']` (the service is decoupled from
 * the finding engine), so every projection is deterministic and side-effect free.
 * The inbox reuses SelfDirectedEvolutionCurationInboxService for operator-review
 * semantics — these tests pin the area-focus envelope and the AP-718 guarantees.
 */
class AreaFocusInboxServiceTest extends TestCase
{
    private function service(): AreaFocusInboxService
    {
        return app(AreaFocusInboxService::class);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function finding(array $over = []): array
    {
        $seed = (string) ($over['_seed'] ?? 'foo');
        unset($over['_seed']);
        $raw = hash('sha256', 'agentic_engineering_os|docs_stale|'.$seed);

        return array_merge([
            'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
            'area_id' => 'agentic_engineering_os',
            'finding_type' => 'docs_stale',
            'title' => 'Stale references in '.$seed.'.md',
            'detail' => 'Doc references missing paths.',
            'severity' => 'medium',
            'risk_level' => 'medium',
            'confidence' => 'high',
            'confidence_score' => 0.9,
            'route_hint' => 'self_directed_evolution',
            'evidence_refs' => ['missing_ref:bar'],
            'affected_paths' => ['docs/'.$seed.'.md'],
            'recommended_action' => 'Operator review required.',
            'source' => 'docs_stale',
            'safe_to_autofix' => false,
            'requires_operator_review' => true,
            'finding_id' => 'aef_'.substr($raw, 0, 16),
            'finding_hash' => 'sha256:'.$raw,
            'priority_score' => 209,
        ], $over);
    }

    public function test_projects_inbox_schema_and_item_contract(): void
    {
        $inbox = $this->service()->project(['findings' => [$this->finding()]]);

        $this->assertSame(AreaFocusInboxService::INBOX_SCHEMA, $inbox['schema_version']);
        $this->assertSame(AreaFocusInboxService::STATUS_READY, $inbox['status']);
        $this->assertSame('AP-718', $inbox['ap_contract']);
        $this->assertSame(1, $inbox['item_count']);
        foreach (['items', 'counts', 'blockers', 'reused_owners', 'claim_policy', 'inbox_hash', 'generated_at', 'stewardship_stack'] as $key) {
            $this->assertArrayHasKey($key, $inbox, "missing inbox key {$key}");
        }

        $item = $inbox['items'][0];
        $this->assertSame(AreaFocusInboxService::ITEM_SCHEMA, $item['schema_version']);
        // Every mandated item field is present (AP-718).
        foreach (['finding_hash', 'area_id', 'route_hint', 'spec_draftable', 'risk', 'operator_decision_required'] as $key) {
            $this->assertArrayHasKey($key, $item, "missing item key {$key}");
        }
        $this->assertTrue($item['operator_decision_required']);
        $this->assertStringStartsWith('sha256:', (string) $item['finding_hash']);
        $this->assertSame('agentic_engineering_os', $item['area_id']);
        $this->assertSame('self_directed_evolution', $item['route_hint']);
    }

    public function test_inbox_hash_is_deterministic_for_same_findings(): void
    {
        $findings = ['findings' => [$this->finding(['_seed' => 'a']), $this->finding(['_seed' => 'b'])]];

        $first = $this->service()->project($findings);
        $second = $this->service()->project($findings);

        $this->assertSame($first['inbox_hash'], $second['inbox_hash']);
        $this->assertStringStartsWith('sha256:', $first['inbox_hash']);
    }

    public function test_dedupes_by_finding_hash(): void
    {
        // Same finding_hash twice -> a single inbox item.
        $finding = $this->finding();
        $inbox = $this->service()->project(['findings' => [$finding, $finding]]);

        $this->assertSame(1, $inbox['item_count']);
    }

    public function test_every_item_is_operator_gated_with_no_autoapproval(): void
    {
        $inbox = $this->service()->project(['findings' => [
            $this->finding(['_seed' => 'x']),
            $this->finding(['_seed' => 'y', 'severity' => 'high', 'risk_level' => 'high']),
        ]]);

        $this->assertFalse($inbox['claim_policy']['autoapproval_allowed']);
        $this->assertFalse($inbox['claim_policy']['autoimplementation_allowed']);
        $this->assertFalse($inbox['claim_policy']['parallel_proposal_registry_created']);
        $this->assertTrue($inbox['claim_policy']['operator_decision_required']);

        foreach ($inbox['items'] as $item) {
            $this->assertTrue($item['operator_decision_required']);
            $this->assertFalse($item['autoapproval_allowed']);
            $this->assertFalse($item['autoimplementation_allowed']);
            $this->assertSame('pending_operator_review', $item['status']);
        }
    }

    public function test_findings_required_blocks_when_absent(): void
    {
        $inbox = $this->service()->project([]);

        $this->assertSame(AreaFocusInboxService::STATUS_BLOCKED, $inbox['status']);
        $this->assertSame('findings_required', $inbox['reason']);
        $this->assertSame(0, $inbox['item_count']);
    }

    public function test_invalid_finding_becomes_blocker_partial_status(): void
    {
        $inbox = $this->service()->project(['findings' => [
            $this->finding(),
            ['title' => 'no hash here'], // invalid: missing finding_hash
        ]]);

        $this->assertSame(AreaFocusInboxService::STATUS_PARTIAL, $inbox['status']);
        $this->assertSame(1, $inbox['item_count']);
        $this->assertNotEmpty($inbox['blockers']);
        $this->assertSame('invalid_finding', $inbox['blockers'][0]['reason']);
    }

    public function test_all_invalid_findings_block(): void
    {
        $inbox = $this->service()->project(['findings' => [
            ['title' => 'no hash'],
            'not-an-object',
        ]]);

        $this->assertSame(AreaFocusInboxService::STATUS_BLOCKED, $inbox['status']);
        $this->assertSame(0, $inbox['item_count']);
        $this->assertNotEmpty($inbox['blockers']);
    }

    public function test_spec_draftable_flag_follows_route_hint(): void
    {
        $inbox = $this->service()->project(['findings' => [
            $this->finding(['_seed' => 'dev', 'finding_type' => 'missing_test', 'route_hint' => 'atlas_dev']),
            $this->finding(['_seed' => 'sde', 'route_hint' => 'self_directed_evolution']),
        ]]);

        $byRoute = [];
        foreach ($inbox['items'] as $item) {
            $byRoute[$item['route_hint']] = $item['spec_draftable'];
        }

        $this->assertFalse($byRoute['atlas_dev'], 'atlas_dev code work is not spec-draftable');
        $this->assertTrue($byRoute['self_directed_evolution'], 'sde gap is spec-draftable');
    }
}
