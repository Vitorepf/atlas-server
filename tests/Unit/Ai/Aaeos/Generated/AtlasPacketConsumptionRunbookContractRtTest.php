<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPacketConsumptionRunbookContractRtService as Svc;
use Tests\TestCase;

/**
 * Pins the documented Packet Consumption Runbook Contract rules: read-only
 * runbook shape, mandatory ordered steps, conditional gate rules, the seven
 * stop conditions and the evidence contract.
 *
 * @see docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
 */
class AtlasPacketConsumptionRunbookContractRtTest extends TestCase
{
    private function svc(): Svc
    {
        return new Svc();
    }

    /** @param array<string,mixed> $override */
    private function cleanInput(array $override = []): array
    {
        return array_merge([
            'assignment_id' => 'ASSIGN-20260601-0001',
            'selected_packet_id' => 'AIP-SPLIT-20260601-0001',
            'runbook_id' => 'RUNBOOK-20260601-0001',
            'files_in_scope' => [
                'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
            ],
            'forbidden_files' => ['app/Services/Ai/Voice/Realtime.php'],
            'hot_external_files' => [],
            'implementation_requested' => false,
            'live' => [
                'packet_hash_at_selection' => 'sha256:abc',
                'packet_hash_now' => 'sha256:abc',
                'changed_files' => [],
                'failed_gates' => [],
                'user_request_conflicts' => false,
            ],
        ], $override);
    }

    /**
     * Non Goals: the runbook is ALWAYS read-only — execution_allowed=false and
     * claim_persisted=false — and carries the exact documented schema version.
     */
    public function test_runbook_is_read_only_and_never_persists_a_claim(): void
    {
        $rb = $this->svc()->build($this->cleanInput());

        $this->assertSame('atlas.self_construction_packet_consumption_runbook.v1', $rb['schema_version']);
        $this->assertFalse($rb['execution_allowed']);
        $this->assertFalse($rb['claim_persisted']);
        // A clean assignment with matching hashes and no stray changes => ready.
        $this->assertSame(Svc::VERDICT_READY, $rb['verdict']);
        $this->assertFalse($rb['must_stop']);
        $this->assertSame([], $rb['triggered_stops']);
    }

    /**
     * Mandatory Steps must appear in the canonical order, all ten of them,
     * including the two conditional-gate steps.
     */
    public function test_mandatory_steps_are_present_and_ordered(): void
    {
        $rb = $this->svc()->build($this->cleanInput());

        $expected = [
            'inspect_worktree',
            'read_canonical_docs_and_packet',
            'confirm_allowed_and_forbidden_files',
            'confirm_execution_policy',
            'implement_only_if_asked',
            'run_focused_tests',
            'run_docs_health_if_docs_changed',
            'run_architecture_validate_if_architecture_changed',
            'run_scope_validator',
            'report_evidence_and_residual_risk',
        ];
        $this->assertSame($expected, $rb['steps']);
        $this->assertTrue($this->svc()->stepsValid($rb['steps']));
        $this->assertFalse($this->svc()->stepsValid(array_slice($expected, 0, 9)));
    }

    /**
     * Conditional gates: a pure-code packet requires only the always-gates; a
     * docs packet adds docs_health; an AP/governance doc adds architecture_validate.
     */
    public function test_conditional_gates_depend_on_touched_files(): void
    {
        $svc = $this->svc();

        $codeOnly = $svc->requiredGates(['app/Services/Ai/Foo/BarService.php']);
        $this->assertSame(['focused_tests', 'scope_validator'], $codeOnly);

        $docs = $svc->requiredGates(['docs/engineering-knowledge-base/self-construction/scope-validator-contract.md']);
        $this->assertContains('docs_health', $docs);
        $this->assertNotContains('architecture_validate', $docs);

        $ap = $svc->requiredGates(['docs/ap/AP-691-atlas-self-construction-os-contract.md']);
        $this->assertContains('docs_health', $ap);
        $this->assertContains('architecture_validate', $ap);
    }

    /**
     * Stop Conditions: a missing selected packet, a changed packet hash, a
     * forbidden-file edit, an unknown-file edit, a hot external file in scope, a
     * failed gate and a conflicting user request each force a stop.
     */
    public function test_each_stop_condition_fires(): void
    {
        $svc = $this->svc();

        $codes = static fn (array $input): array => array_map(
            static fn (array $s): string => $s['code'],
            $svc->evaluateStopConditions($input),
        );

        // selected packet missing
        $this->assertContains('selected_packet_missing', $codes($this->cleanInput(['selected_packet_id' => null])));

        // packet hash changed
        $this->assertContains('packet_hash_changed', $codes($this->cleanInput([
            'live' => [
                'packet_hash_at_selection' => 'sha256:abc',
                'packet_hash_now' => 'sha256:DIFFERENT',
                'changed_files' => [],
                'failed_gates' => [],
                'user_request_conflicts' => false,
            ],
        ])));

        // forbidden file changed (it is also out of scope, so unknown_file fires too)
        $forbiddenStop = $codes($this->cleanInput([
            'live' => ['changed_files' => ['app/Services/Ai/Voice/Realtime.php']],
        ]));
        $this->assertContains('forbidden_file_changed', $forbiddenStop);

        // unknown file changed (outside the assigned write set)
        $this->assertContains('unknown_file_changed', $codes($this->cleanInput([
            'live' => ['changed_files' => ['app/Random/Unrelated.php']],
        ])));

        // hot external file appears in assigned scope
        $this->assertContains('hot_external_file_in_scope', $codes($this->cleanInput([
            'files_in_scope' => ['app/Services/Ai/Shared/Hot.php'],
            'hot_external_files' => ['app/Services/Ai/Shared/Hot.php'],
        ])));

        // required gate failed
        $this->assertContains('required_gate_failed', $codes($this->cleanInput([
            'live' => ['failed_gates' => ['docs_health']],
        ])));

        // user request conflicts with packet scope
        $this->assertContains('user_request_conflicts_with_packet_scope', $codes($this->cleanInput([
            'live' => ['user_request_conflicts' => true],
        ])));
    }

    /**
     * A stop condition flips the whole runbook verdict to STOP / must_stop.
     */
    public function test_stop_condition_flips_runbook_verdict(): void
    {
        $rb = $this->svc()->build($this->cleanInput(['selected_packet_id' => '']));

        $this->assertSame(Svc::VERDICT_STOP, $rb['verdict']);
        $this->assertTrue($rb['must_stop']);
        $this->assertNotSame([], $rb['triggered_stops']);
    }

    /**
     * Evidence Contract: all seven fields are required. A response missing one
     * (here scope_validator_status) is rejected; the full set is accepted.
     */
    public function test_evidence_contract_requires_all_seven_fields(): void
    {
        $svc = $this->svc();

        $full = [
            'selected_packet_id' => 'AIP-SPLIT-20260601-0001',
            'files_changed' => ['docs/x.md'],
            'gates_run' => ['docs_health'],
            'pass_fail_status' => 'pass',
            'scope_validator_status' => 'pass',
            'known_external_blockers' => [],
            'what_remains_blocked' => [],
        ];
        $accepted = $svc->validateEvidenceContract($full);
        $this->assertTrue($accepted['accepted']);
        $this->assertSame([], $accepted['missing_fields']);

        $missing = $full;
        unset($missing['scope_validator_status']);
        $rejected = $svc->validateEvidenceContract($missing);
        $this->assertFalse($rejected['accepted']);
        $this->assertContains('scope_validator_status', $rejected['missing_fields']);
    }
}
