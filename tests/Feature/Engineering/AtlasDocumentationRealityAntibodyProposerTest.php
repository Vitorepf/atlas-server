<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealityAntibodyProposerService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L1-P3 (first increment) — the Self-Immunizing Antibody PROPOSER. From an escaped
 * failure it must synthesise an antibody whose reproducing_test_outline is ALWAYS
 * present (the absolute doc rule), with a detector spec, and that never writes,
 * creates a gate, or installs enforcement. No RefreshDatabase — the proposer reads
 * the failure-capsule table only when it exists, and degrades otherwise.
 */
final class AtlasDocumentationRealityAntibodyProposerTest extends TestCase
{
    /**
     * One of THIS session's real escaped holes: the predictive simulator (P1)
     * returned clean on a degraded/empty index instead of flagging it.
     *
     * @return array<string,string>
     */
    private function sampleFailure(): array
    {
        return [
            'kind' => 'missing_context',
            'summary' => 'predictive simulator returned clean on a degraded index',
            'location' => 'app/Services/Engineering/AtlasDocumentationRealityPredictiveSimulatorService.php',
            'detail' => 'the code-intelligence index was present but empty, yet the simulator reported no risk',
        ];
    }

    public function test_propose_from_capsule_yields_antibody_with_reproducing_test_and_detector(): void
    {
        $payload = app(AtlasDocumentationRealityAntibodyProposerService::class)
            ->proposeFromCapsule($this->sampleFailure());

        $this->assertSame('missing_context', $payload['failure_kind']);
        $this->assertSame(
            AtlasDocumentationRealityAntibodyProposerService::STATUS,
            $payload['status'],
        );
        $this->assertSame('proposed_requires_human_review', $payload['status']);

        // Reproducing test outline is present and NON-EMPTY (the absolute rule).
        $outline = $payload['reproducing_test_outline'];
        $this->assertIsArray($outline);
        $this->assertNotEmpty($outline);
        $this->assertNotEmpty($outline['description']);
        $this->assertNotEmpty($outline['steps']);
        $this->assertArrayHasKey('given_when_then', $outline);
        $this->assertArrayHasKey('arrange_act_assert', $outline);
        $this->assertArrayHasKey('target_test_path_suggestion', $outline);
        $this->assertTrue($outline['must_fail_before_fix']);

        // Detector spec present: what to add, what kind, where it plugs in.
        $detector = $payload['proposed_detector'];
        $this->assertNotEmpty($detector['description']);
        $this->assertContains(
            $detector['kind'],
            ['gate_check', 'frontmatter_rule', 'drift_rule', 'static_scan'],
        );
        $this->assertNotEmpty($detector['where']);
        $this->assertNotEmpty($detector['example_assertion']);

        $this->assertNotEmpty($payload['plug_in_point']);
        $this->assertNotEmpty($payload['failure_ref']);
    }

    public function test_no_field_or_option_ever_creates_writes_or_installs(): void
    {
        $envelope = app(AtlasDocumentationRealityAntibodyProposerService::class)
            ->proposeRecent(20);

        // Envelope-level claim policy: strictly read-only proposer.
        $this->assertFalse($envelope['writes']);
        $this->assertTrue($envelope['claim_policy']['read_only']);
        $this->assertFalse($envelope['claim_policy']['auto_creates_gate']);
        $this->assertFalse($envelope['claim_policy']['writes']);
        $this->assertFalse($envelope['claim_policy']['executes']);
        $this->assertFalse($envelope['claim_policy']['installs_enforcement']);
        $this->assertFalse($envelope['claim_policy']['generates_code']);
        $this->assertTrue($envelope['claim_policy']['requires_reproducing_test']);
        $this->assertTrue($envelope['claim_policy']['goes_through_gates']);
        $this->assertIsString($envelope['antibody_hash']);

        // A single proposal carries no field that writes/creates/installs/executes.
        $antibody = app(AtlasDocumentationRealityAntibodyProposerService::class)
            ->proposeFromCapsule($this->sampleFailure());

        $forbiddenKeys = [
            'apply', 'install', 'write', 'create_gate', 'create_file', 'created_file',
            'execute', 'exec', 'command_to_run', 'auto_install', 'generated_file', 'file_written',
        ];
        $serialized = json_encode($antibody, JSON_THROW_ON_ERROR);
        foreach ($forbiddenKeys as $key) {
            $this->assertStringNotContainsString(
                "\"{$key}\":",
                (string) $serialized,
                "antibody must never carry a '{$key}' field that mutates/installs anything.",
            );
        }

        // The detector is a SPEC (description/where/assertion), never an installed gate.
        $this->assertArrayNotHasKey('installed', $antibody['proposed_detector']);
        $this->assertArrayNotHasKey('created', $antibody['proposed_detector']);
    }

    public function test_every_antibody_always_has_a_reproducing_test_outline(): void
    {
        // Across a spread of failure kinds, a proposal missing a reproducing test is
        // never produced — the outline is always present and non-empty.
        $kinds = [
            ['kind' => 'missing_context', 'summary' => 'context pack omitted owner doc', 'location' => 'svc.php', 'detail' => 'd'],
            ['kind' => 'type_error', 'summary' => 'phpstan slipped a mixed', 'location' => 'svc.php', 'detail' => 'd'],
            ['kind' => 'scope_violation', 'summary' => 'patch touched forbidden path', 'location' => 'svc.php', 'detail' => 'd'],
            ['kind' => 'architecture_risk', 'summary' => 'boundary crossed', 'location' => 'svc.php', 'detail' => 'd'],
            ['kind' => 'doc_drift', 'summary' => 'frontmatter claimed verified falsely', 'location' => 'doc.md', 'detail' => 'd'],
            ['kind' => 'unknown', 'summary' => 'unclassified escape', 'location' => '', 'detail' => ''],
        ];

        $service = app(AtlasDocumentationRealityAntibodyProposerService::class);

        foreach ($kinds as $failure) {
            $antibody = $service->proposeFromCapsule($failure);

            $this->assertArrayHasKey('reproducing_test_outline', $antibody);
            $outline = $antibody['reproducing_test_outline'];
            $this->assertIsArray($outline);
            $this->assertNotEmpty($outline, "kind {$failure['kind']} produced an empty reproducing_test_outline");
            $this->assertNotEmpty($outline['steps'], "kind {$failure['kind']} produced no reproducing steps");
            $this->assertNotEmpty($outline['description']);

            // And a detector whose kind is one of the four contract kinds.
            $this->assertContains(
                $antibody['proposed_detector']['kind'],
                ['gate_check', 'frontmatter_rule', 'drift_rule', 'static_scan'],
            );
        }
    }

    public function test_propose_recent_degrades_when_no_failure_capsule_table(): void
    {
        // In the sqlite test DB the failure-capsule table is not migrated, so this is
        // the natural path: degrade, never fabricate failures.
        $this->assertFalse(Schema::hasTable('atlas_dev_failure_capsules'));

        $payload = app(AtlasDocumentationRealityAntibodyProposerService::class)
            ->proposeRecent(20);

        $this->assertTrue($payload['degraded']);
        $this->assertSame('no_failure_capsules', $payload['reason']);
        $this->assertSame([], $payload['antibodies']);
        $this->assertSame(0, data_get($payload, 'summary.antibody_count'));
        $this->assertSame(0, data_get($payload, 'summary.failure_count'));
        // Still a fully-formed, read-only envelope.
        $this->assertSame(AtlasDocumentationRealityAntibodyProposerService::SCHEMA, $payload['schema_version']);
        $this->assertFalse($payload['claim_policy']['auto_creates_gate']);
    }
}
