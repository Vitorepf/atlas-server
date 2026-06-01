<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContractSchemaRegistryService;
use Tests\TestCase;

/**
 * Pins the executable governance rules from the Contract Schema Registry doc:
 * the `atlas.<namespace>.<name>.v<int>` id shape, the "unregistered schema is
 * non-canonical" rule, the owner/doc quality gates, the breaking-change policy
 * (receipt + 60-day lane + single version bump), and cross-doc duplicate
 * detection. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-contract-schema-registry.md
 */
class AtlasContractSchemaRegistryTest extends TestCase
{
    private function service(): AtlasContractSchemaRegistryService
    {
        return new AtlasContractSchemaRegistryService;
    }

    public function test_schema_id_shape_is_enforced(): void
    {
        $svc = $this->service();

        // Well formed, including the multi-segment snapshot id.
        $ok = $svc->parseSchemaId('atlas.aaeos.cross_dept.handoff.v1');
        $this->assertTrue($ok['valid']);
        $this->assertSame('aaeos.cross_dept.handoff', $ok['namespace_name']);
        $this->assertSame(1, $ok['version']);

        // No version suffix -> rejected with the precise reason.
        $noVer = $svc->parseSchemaId('atlas.spec_pack');
        $this->assertFalse($noVer['valid']);
        $this->assertContains('missing_version_suffix', $noVer['reasons']);

        // Wrong prefix -> rejected.
        $this->assertFalse($svc->parseSchemaId('foo.spec_pack.v1')['valid']);

        // v0 is not a real version.
        $this->assertContains('invalid_version_v0', $svc->parseSchemaId('atlas.spec_pack.v0')['reasons']);
    }

    public function test_unregistered_schema_is_not_canonical(): void
    {
        // Regras para IA: "Schema sem entry e tratado como nao-existente."
        $registry = [
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => 'o', 'canonical_doc' => 'd.md'],
        ];

        $this->assertTrue($this->service()->isCanonical('atlas.spec_pack.v1', $registry));
        // Well formed but not registered -> still not canonical.
        $this->assertFalse($this->service()->isCanonical('atlas.unknown_thing.v1', $registry));
    }

    public function test_entry_must_have_owner_and_canonical_doc(): void
    {
        $svc = $this->service();

        $missingOwner = $svc->validateEntry([
            'schema_id' => 'atlas.spec_pack.v1', 'owner' => '', 'canonical_doc' => 'd.md',
        ]);
        $this->assertFalse($missingOwner['valid']);
        $this->assertContains('missing_owner', $missingOwner['reasons']);

        $deprecatedNoStamp = $svc->validateEntry([
            'schema_id' => 'atlas.spec_pack.v1', 'owner' => 'o', 'canonical_doc' => 'd.md',
            'deprecated' => true, 'deprecated_at' => null,
        ]);
        $this->assertFalse($deprecatedNoStamp['valid']);
        $this->assertContains('deprecated_without_timestamp', $deprecatedNoStamp['reasons']);

        $good = $svc->validateEntry([
            'schema_id' => 'atlas.spec_pack.v1', 'owner' => 'o', 'canonical_doc' => 'd.md',
        ]);
        $this->assertTrue($good['valid']);
        $this->assertSame([], $good['reasons']);
    }

    public function test_breaking_change_requires_receipt_60d_lane_and_single_bump(): void
    {
        $svc = $this->service();

        // Compliant: receipt + exactly 60 days + v1 -> v2.
        $ok = $svc->evaluateBreakingChange([
            'from_version' => 'v1', 'to_version' => 'v2',
            'receipt_id' => 'rcpt-1', 'deprecation_window_days' => 60,
        ]);
        $this->assertTrue($ok['allowed']);
        $this->assertSame([], $ok['violations']);

        // 59 days is below the documented minimum lane.
        $shortLane = $svc->evaluateBreakingChange([
            'from_version' => 'v1', 'to_version' => 'v2',
            'receipt_id' => 'rcpt-1', 'deprecation_window_days' => 59,
        ]);
        $this->assertFalse($shortLane['allowed']);
        $this->assertContains('deprecation_window_below_min', $shortLane['violations']);

        // Missing receipt is rejected even with a long lane.
        $noReceipt = $svc->evaluateBreakingChange([
            'from_version' => 'v1', 'to_version' => 'v2',
            'receipt_id' => '', 'deprecation_window_days' => 90,
        ]);
        $this->assertFalse($noReceipt['allowed']);
        $this->assertContains('missing_decision_receipt', $noReceipt['violations']);

        // Skipping a version (v1 -> v3) is not a single-step bump.
        $skip = $svc->evaluateBreakingChange([
            'from_version' => 'v1', 'to_version' => 'v3',
            'receipt_id' => 'rcpt-1', 'deprecation_window_days' => 60,
        ]);
        $this->assertFalse($skip['allowed']);
        $this->assertContains('version_bump_must_be_single_step', $skip['violations']);
    }

    public function test_registry_blocks_on_duplicate_and_missing_owner(): void
    {
        $svc = $this->service();

        // Clean snapshot -> active.
        $clean = $svc->validateRegistry([
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => 'a', 'canonical_doc' => 'a.md'],
            ['schema_id' => 'atlas.task_pack.v1', 'owner' => 'b', 'canonical_doc' => 'b.md'],
        ]);
        $this->assertSame('active', $clean['gate']);
        $this->assertSame(2, $clean['valid']);

        // Same namespace.name declared by two different docs = cross-doc duplication.
        $dup = $svc->validateRegistry([
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => 'a', 'canonical_doc' => 'a.md'],
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => 'b', 'canonical_doc' => 'b.md'],
        ]);
        $this->assertSame('block', $dup['gate']);
        $this->assertNotEmpty($dup['duplicates']);

        // Missing owner -> block + orphan.
        $orphan = $svc->validateRegistry([
            ['schema_id' => 'atlas.spec_pack.v1', 'owner' => '', 'canonical_doc' => 'a.md'],
        ]);
        $this->assertSame('block', $orphan['gate']);
        $this->assertContains('atlas.spec_pack.v1', $orphan['orphan_no_owner']);
    }
}
