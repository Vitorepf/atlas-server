<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingRepairAttemptStore;
use Tests\TestCase;

class ProgrammingRepairAttemptStoreTest extends TestCase
{
    public function test_receipt_returns_canonical_repair_stage_receipt_with_repair_attempt_block(): void
    {
        $failurePacket = [
            'failure_hash' => 'failure-abc',
            'primary_error' => 'Assertion failed in FooTest',
        ];
        $patchManifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'action_id' => 'patch-action-1',
        ];
        $testManifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'action_id' => 'test-action-1',
        ];

        $receipt = app(ProgrammingRepairAttemptStore::class)->receipt(
            planId: 'plan-repair-1',
            parentPlanId: 'plan-parent-1',
            attempt: 2,
            status: 'failed',
            failurePacket: $failurePacket,
            patchManifest: $patchManifest,
            testManifest: $testManifest,
        );

        $this->assertSame('atlas.programming.stage_receipt.v1', $receipt['schema_version']);
        $this->assertSame('plan-repair-1', $receipt['plan_id']);
        $this->assertSame('plan-parent-1', $receipt['parent_plan_id']);
        $this->assertSame('repair', $receipt['stage']);
        $this->assertSame(2, $receipt['attempt']);
        $this->assertSame('failed', $receipt['status']);
        $this->assertTrue($receipt['validation']['valid']);

        $repairAttempt = $receipt['repair_attempt'];
        $this->assertSame('atlas.programming.repair_attempt.receipt.v1', $repairAttempt['repair_attempt_schema']);
        $this->assertSame(2, $repairAttempt['attempt']);
        $this->assertSame('failed', $repairAttempt['status']);
        $this->assertSame('atlas.programming.action_manifest.v1', $repairAttempt['patch_manifest_schema']);
        $this->assertSame('atlas.programming.action_manifest.v1', $repairAttempt['test_manifest_schema']);
        $this->assertSame(
            hash('sha256', json_encode($failurePacket, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            $repairAttempt['failure_packet_hash'],
        );
        $this->assertSame(
            hash('sha256', json_encode($patchManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            $repairAttempt['patch_manifest_hash'],
        );
        $this->assertSame(
            hash('sha256', json_encode($testManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            $repairAttempt['test_manifest_hash'],
        );
    }

    public function test_receipt_collects_evidence_refs_from_failure_patch_and_test_manifests(): void
    {
        $receipt = app(ProgrammingRepairAttemptStore::class)->receipt(
            planId: 'plan-repair-2',
            parentPlanId: null,
            attempt: 1,
            status: 'passed',
            failurePacket: ['failure_hash' => 'hash-1'],
            patchManifest: ['action_id' => 'patch-1'],
            testManifest: ['action_id' => 'test-1'],
        );

        $this->assertSame(
            ['failure:hash-1', 'manifest:patch-1', 'manifest:test-1'],
            $receipt['evidence_refs'],
        );
    }

    public function test_receipt_omits_non_string_evidence_refs(): void
    {
        $receipt = app(ProgrammingRepairAttemptStore::class)->receipt(
            planId: 'plan-repair-3',
            parentPlanId: null,
            attempt: 1,
            status: 'blocked',
            failurePacket: ['failure_hash' => 123],
            patchManifest: [],
            testManifest: ['action_id' => null],
        );

        $this->assertSame([], $receipt['evidence_refs']);
        $this->assertNull($receipt['repair_attempt']['patch_manifest_schema']);
        $this->assertNull($receipt['repair_attempt']['test_manifest_schema']);
    }
}
