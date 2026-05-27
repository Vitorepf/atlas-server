<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use Tests\TestCase;

/**
 * Read-only contract tests for the Area Focus Loop Core Read-Only Runtime
 * (AP-716).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS.
 *
 * Owner-doc presence is injected via the `owner_doc_exists` input override so
 * the projection is deterministic and side-effect free regardless of the test
 * environment's filesystem.
 */
class AtlasAreaFocusLoopReadModelServiceTest extends TestCase
{
    private function service(): AtlasAreaFocusLoopReadModelService
    {
        return app(AtlasAreaFocusLoopReadModelService::class);
    }

    /**
     * Build an `owner_doc_exists` map marking every default owner doc present.
     *
     * @return array<string,bool>
     */
    private function allOwnerDocsPresent(): array
    {
        $docs = [
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
            'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md',
            'docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md',
            'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
            'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
        ];

        return array_fill_keys($docs, true);
    }

    public function test_emits_report_schema_and_full_envelope(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);

        $this->assertSame(AtlasAreaFocusLoopReadModelService::REPORT_SCHEMA, $report['schema_version']);
        foreach ([
            'status', 'generated_at', 'stewardship_stack', 'area_contract', 'area_map',
            'owner_docs_resolved', 'readiness', 'governed_mode', 'finding_seeds',
            'finding_seed_count', 'health_summary', 'evidence_refs', 'owner_reuse_matrix',
            'next_actions', 'claim_policy', 'report_hash',
        ] as $key) {
            $this->assertArrayHasKey($key, $report, "missing report key {$key}");
        }
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
        $this->assertSame('atlas.software_company_stewardship.area_focus_loop.v1', $report['schema_version']);
    }

    public function test_agentic_engineering_os_defaults(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);
        $contract = $report['area_contract'];

        $this->assertSame('agentic_engineering_os', $contract['area_id']);
        $this->assertSame('Agentic Engineering OS', $contract['area_name']);
        $this->assertSame(0, $contract['autonomy_tier']);
        $this->assertSame(3, $contract['wip_limit']);
        $this->assertSame('max_governed', $contract['dev_budget']['mode']);
        $this->assertSame('max_governed', $contract['forge_budget']['mode']);
        $this->assertSame('morning_inbox', $contract['inbox_destination']);
        $this->assertContains('atlas-server', $contract['repo_scope']['repos']);
        $this->assertNotEmpty($contract['stop_conditions']);
        $this->assertContains('operator_kill_switch', $contract['stop_conditions']);
    }

    public function test_owner_docs_list_is_resolved(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);

        $resolved = $report['owner_docs_resolved'];
        $this->assertCount(5, $resolved);
        foreach ($resolved as $doc) {
            $this->assertArrayHasKey('path', $doc);
            $this->assertArrayHasKey('exists', $doc);
            $this->assertTrue($doc['exists']);
        }
        $this->assertSame('ready', $report['readiness']['status']);
        $this->assertSame(5, $report['readiness']['checks']['owner_docs_present']);
        $this->assertSame(5, $report['readiness']['checks']['owner_docs_total']);
    }

    public function test_missing_owner_doc_degrades_to_partial_and_seeds_a_finding(): void
    {
        $map = $this->allOwnerDocsPresent();
        $map['docs/engineering-knowledge-base/atlas-forge-operating-system.md'] = false;

        $report = $this->service()->project(['owner_doc_exists' => $map]);

        $this->assertSame('partial', $report['readiness']['status']);
        $this->assertSame('partial', $report['status']);
        $this->assertSame(4, $report['readiness']['checks']['owner_docs_present']);

        $missingSeeds = array_values(array_filter(
            $report['finding_seeds'],
            static fn (array $s): bool => $s['kind'] === 'missing_owner_doc',
        ));
        $this->assertCount(1, $missingSeeds);
        $this->assertSame('high', $missingSeeds[0]['severity']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-forge-operating-system.md', $missingSeeds[0]['evidence_refs']);
        $this->assertFalse($missingSeeds[0]['deep_scan_performed']);
        $this->assertTrue($missingSeeds[0]['requires_operator_review']);
    }

    public function test_seeds_point_at_canonical_owners_without_deep_scan(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);

        $kinds = array_map(static fn (array $s): string => $s['kind'], $report['finding_seeds']);
        $this->assertContains('deep_finding_scan_available', $kinds);
        $this->assertContains('gap_spec_proposal_available', $kinds);

        foreach ($report['finding_seeds'] as $seed) {
            $this->assertSame(AtlasAreaFocusLoopReadModelService::SEED_SCHEMA, $seed['schema_version']);
            $this->assertTrue($seed['is_seed']);
            $this->assertFalse($seed['deep_scan_performed']);
            $this->assertStringStartsWith('afs_', $seed['seed_id']);
            $this->assertStringStartsWith('sha256:', $seed['seed_hash']);
        }
    }

    public function test_deterministic_hash_for_same_input(): void
    {
        $input = ['owner_doc_exists' => $this->allOwnerDocsPresent()];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['report_hash'], $b['report_hash']);
        $this->assertSame($a['finding_seeds'], $b['finding_seeds']);
        $this->assertSame($a['area_contract'], $b['area_contract']);
    }

    public function test_read_only_claim_policy_invariants(): void
    {
        $policy = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()])['claim_policy'];

        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['writes_state']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertFalse($policy['parallel_runtime_created']);
        $this->assertFalse($policy['parallel_finding_detector_created']);
        $this->assertFalse($policy['new_os_created']);
        $this->assertFalse($policy['deep_scan_performed']);
        $this->assertFalse($policy['drafts_spec']);
        $this->assertFalse($policy['creates_branch']);
        $this->assertFalse($policy['routes_for_execution']);
        $this->assertTrue($policy['requires_operator_review']);
    }

    public function test_no_merge_deploy_secrets_destructive_in_governed_mode(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);
        $invariants = $report['governed_mode']['invariants'];
        $policy = $report['claim_policy'];

        $this->assertFalse($invariants['merge_without_operator']);
        $this->assertFalse($invariants['deploy_without_operator']);
        $this->assertFalse($invariants['secret_access']);
        $this->assertFalse($invariants['destructive_change']);
        $this->assertTrue($invariants['branch_isolation_required']);
        $this->assertTrue($invariants['kill_switch_required']);
        $this->assertTrue($invariants['morning_inbox_required']);

        $this->assertFalse($policy['merge_without_operator']);
        $this->assertFalse($policy['deploy_without_operator']);
        $this->assertFalse($policy['secret_access']);
        $this->assertFalse($policy['destructive_change']);

        $this->assertSame('max_governed', $report['governed_mode']['mode']);
        $this->assertSame(0, $report['governed_mode']['autonomy_tier_active']);
    }

    public function test_invalid_area_is_blocked(): void
    {
        $report = $this->service()->project(['area_id' => 'not_a_real_area']);

        $this->assertSame('blocked', $report['status']);
        $this->assertNull($report['area_contract']);
        $this->assertSame(0, $report['finding_seed_count']);
        $reasons = array_map(static fn (array $b): string => $b['reason'], $report['blockers']);
        $this->assertContains('area_not_registered', $reasons);
        // claim policy stays read-only even when blocked
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
    }

    public function test_registered_area_ids_lists_agentic_engineering_os(): void
    {
        $this->assertContains('agentic_engineering_os', $this->service()->registeredAreaIds());
    }

    public function test_stewardship_stack_declares_not_a_new_os(): void
    {
        $report = $this->service()->project(['owner_doc_exists' => $this->allOwnerDocsPresent()]);
        $stack = $report['stewardship_stack'];

        $this->assertSame('Atlas Software Company Stewardship Stack', $stack['stack']);
        $this->assertSame('AP-716', $stack['ap']);
        $this->assertStringContainsString('not a new OS', $stack['note']);
    }
}
