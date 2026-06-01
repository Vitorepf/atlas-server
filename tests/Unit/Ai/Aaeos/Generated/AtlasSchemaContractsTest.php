<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSchemaContractsService;
use Tests\TestCase;

/**
 * Pins the executable invariants from the Engineering Blueprint Schema Contracts
 * doc: the schema-family taxonomy, the record invariants (explicit
 * schema_version, deterministic + self-matching content_hash, frozen
 * immutability, supersede-to-newer), staleness surfacing, gate/evidence
 * exposure and the additive-compatibility evolution policy. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
 */
class AtlasSchemaContractsTest extends TestCase
{
    private function service(): AtlasSchemaContractsService
    {
        return new AtlasSchemaContractsService;
    }

    public function test_family_taxonomy_is_enforced(): void
    {
        $svc = $this->service();

        // The three canonical top-level families are accepted.
        $top = $svc->classifyFamily('atlas.engineering.task_contract.v1');
        $this->assertTrue($top['valid']);
        $this->assertSame('top_level_family', $top['kind']);
        $this->assertSame('task_contract', $top['name']);
        $this->assertSame(1, $top['version']);

        // A documented payload kind is accepted as such.
        $kind = $svc->classifyFamily('review_finding');
        $this->assertTrue($kind['valid']);
        $this->assertSame('payload_kind', $kind['kind']);

        // A well-formed but non-canonical engineering family is rejected.
        $unknown = $svc->classifyFamily('atlas.engineering.made_up.v1');
        $this->assertFalse($unknown['valid']);
        $this->assertContains('unknown_engineering_family', $unknown['reasons']);

        // Something outside the namespace entirely is rejected.
        $this->assertFalse($svc->classifyFamily('atlas.product.catalog.v3')['valid']);
    }

    public function test_content_hash_is_deterministic_regardless_of_key_order(): void
    {
        $svc = $this->service();

        $a = ['goal' => 'g', 'scope' => ['x', 'y'], 'acceptance' => ['ok']];
        $b = ['acceptance' => ['ok'], 'scope' => ['x', 'y'], 'goal' => 'g']; // reordered keys

        // Invariant: content_hash is deterministic -> key order must not matter.
        $this->assertSame($svc->contentHash($a), $svc->contentHash($b));
        // But list order is meaningful -> reordering a list changes the hash.
        $c = ['goal' => 'g', 'scope' => ['y', 'x'], 'acceptance' => ['ok']];
        $this->assertNotSame($svc->contentHash($a), $svc->contentHash($c));
    }

    public function test_record_requires_known_family_and_matching_hash(): void
    {
        $svc = $this->service();

        $payload = ['goal' => 'ship', 'scope' => ['service']];
        $goodHash = $svc->contentHash($payload);

        // Valid frozen record: known family + matching deterministic hash.
        $valid = $svc->validateRecord([
            'schema_version' => 'atlas.engineering.blueprint.v1',
            'status' => 'frozen',
            'content_hash' => $goodHash,
            'payload' => $payload,
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertTrue($valid['frozen_immutable']);

        // Unknown schema_version is rejected.
        $badFamily = $svc->validateRecord([
            'schema_version' => 'atlas.engineering.nope.v1',
            'status' => 'draft',
            'content_hash' => $goodHash,
            'payload' => $payload,
        ]);
        $this->assertFalse($badFamily['valid']);
        $this->assertContains('unknown_schema_version', $badFamily['reasons']);
    }

    public function test_frozen_record_is_immutable_and_hash_mismatch_is_caught(): void
    {
        $svc = $this->service();

        $original = ['goal' => 'v1 goal', 'scope' => ['a']];
        $frozenHash = $svc->contentHash($original);

        // The payload was mutated after freeze; the stored frozen hash no longer
        // matches its content -> both content_hash_mismatch AND frozen mutation.
        $mutated = $svc->validateRecord([
            'schema_version' => 'atlas.engineering.project_blueprint.v1',
            'status' => 'frozen',
            'content_hash' => $frozenHash,
            'payload' => ['goal' => 'tampered goal', 'scope' => ['a']],
        ]);

        $this->assertFalse($mutated['valid']);
        $this->assertFalse($mutated['frozen_immutable']);
        $this->assertContains('frozen_record_mutated', $mutated['reasons']);
        $this->assertContains('content_hash_mismatch', $mutated['reasons']);
    }

    public function test_supersede_must_point_to_strictly_newer_version(): void
    {
        $svc = $this->service();

        // Invariant: supersede points to the NEWER version.
        $this->assertTrue($svc->isValidSupersede(1, 2));
        $this->assertFalse($svc->isValidSupersede(2, 2)); // equal is not newer
        $this->assertFalse($svc->isValidSupersede(3, 2)); // older is not newer

        // A superseded record with no successor pointer is invalid.
        $orphan = $svc->validateRecord([
            'schema_version' => 'atlas.engineering.blueprint.v1',
            'status' => 'superseded',
            'content_hash' => $svc->contentHash(['k' => 'v']),
            'payload' => ['k' => 'v'],
            'superseded_by' => '',
        ]);
        $this->assertFalse($orphan['valid']);
        $this->assertContains('superseded_without_successor', $orphan['reasons']);
    }

    public function test_staleness_and_surface_state_expose_problems(): void
    {
        $svc = $this->service();

        // Stale when the upstream hash it was built against has changed.
        $this->assertFalse($svc->isStale('abc123', 'abc123'));
        $this->assertTrue($svc->isStale('abc123', 'def456'));

        // Surface state blocks on a blocking gate OR missing acceptance evidence.
        $blocked = $svc->surfaceState(
            [['id' => 'g1', 'status' => 'block']],
            [['id' => 'a1', 'evidence_ref' => 'receipt://x']],
        );
        $this->assertSame('block', $blocked['state']);
        $this->assertContains('g1', $blocked['blocking_gates']);

        $missingEvidence = $svc->surfaceState(
            [['id' => 'g1', 'status' => 'pass']],
            [['id' => 'a1', 'evidence_ref' => '']],
        );
        $this->assertSame('block', $missingEvidence['state']);
        $this->assertContains('a1', $missingEvidence['missing_evidence']);

        // All green -> ready, but still explicitly exposed.
        $ready = $svc->surfaceState(
            [['id' => 'g1', 'status' => 'pass']],
            [['id' => 'a1', 'evidence_ref' => 'receipt://ok']],
        );
        $this->assertSame('ready', $ready['state']);
        $this->assertTrue($ready['exposed']);
    }

    public function test_evolution_policy_requires_additive_or_migration_adapter_tests(): void
    {
        $svc = $this->service();

        $fullWave = ['app_types', 'api_resources', 'cli_output'];

        // Additive, single-step bump, full wave -> allowed.
        $additive = $svc->evaluateEvolution([
            'from_version' => 1,
            'to_version' => 2,
            'additive' => true,
            'wave' => $fullWave,
        ]);
        $this->assertTrue($additive['allowed']);

        // Non-additive WITHOUT migration/adapter/tests -> rejected with reasons.
        $unsafe = $svc->evaluateEvolution([
            'from_version' => 1,
            'to_version' => 2,
            'additive' => false,
            'wave' => $fullWave,
        ]);
        $this->assertFalse($unsafe['allowed']);
        $this->assertContains('non_additive_requires_migration', $unsafe['reasons']);
        $this->assertContains('non_additive_requires_compat_adapter', $unsafe['reasons']);
        $this->assertContains('non_additive_requires_tests', $unsafe['reasons']);

        // Non-additive WITH migration + adapter + tests + single step + full wave -> allowed.
        $safe = $svc->evaluateEvolution([
            'from_version' => 2,
            'to_version' => 3,
            'additive' => false,
            'has_migration' => true,
            'has_compat_adapter' => true,
            'has_tests' => true,
            'wave' => $fullWave,
        ]);
        $this->assertTrue($safe['allowed']);

        // Skipping a downstream surface in the wave is rejected.
        $partialWave = $svc->evaluateEvolution([
            'from_version' => 1,
            'to_version' => 2,
            'additive' => true,
            'wave' => ['app_types'],
        ]);
        $this->assertFalse($partialWave['allowed']);
        $this->assertContains('missing_wave_surface_api_resources', $partialWave['reasons']);
        $this->assertContains('missing_wave_surface_cli_output', $partialWave['reasons']);

        // A multi-step version jump is rejected even if additive.
        $jump = $svc->evaluateEvolution([
            'from_version' => 1,
            'to_version' => 3,
            'additive' => true,
            'wave' => $fullWave,
        ]);
        $this->assertFalse($jump['allowed']);
        $this->assertContains('version_bump_must_be_single_step', $jump['reasons']);
    }
}
