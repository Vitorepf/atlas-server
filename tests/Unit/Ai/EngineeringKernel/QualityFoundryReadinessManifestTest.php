<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryReadinessManifest;
use PHPUnit\Framework\TestCase;

final class QualityFoundryReadinessManifestTest extends TestCase
{
    public function test_current_master_plan_does_not_claim_completion_from_checklists_alone(): void
    {
        $manifest = (new QualityFoundryReadinessManifest)->build();

        self::assertSame(QualityFoundryReadinessManifest::SCHEMA, $manifest['schema']);
        self::assertSame('blocked', $manifest['status']);
        self::assertFalse($manifest['completion_allowed']);
        self::assertSame('ready', $manifest['checklist_status']);
        self::assertTrue($manifest['checklist_completion_allowed']);
        self::assertSame('not_attested', $manifest['operational_evidence_status']);
        self::assertContains('operational_evidence_not_attested', $manifest['blockers']);
        self::assertSame(8, $manifest['summary']['packet_count']);
        self::assertSame(0, $manifest['summary']['open_items']);
        self::assertSame(
            $manifest['summary']['open_items'],
            count($manifest['open_items']),
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['manifest_hash']);
        self::assertSame('refs_present', $manifest['verification_refs']['status']);
        self::assertNotEmpty($manifest['verification_refs']['refs']);
        self::assertTrue(collect($manifest['verification_refs']['refs'])->every(fn (array $ref): bool => $ref['exists'] && is_string($ref['sha256'])));
    }

    public function test_manifest_hash_is_stable_and_each_packet_has_a_truthful_breakdown(): void
    {
        $first = (new QualityFoundryReadinessManifest)->build();
        $second = (new QualityFoundryReadinessManifest)->build();

        self::assertSame($first['manifest_hash'], $second['manifest_hash']);
        self::assertCount(8, $first['packets']);
        foreach ($first['packets'] as $packet) {
            self::assertArrayHasKey('plan', $packet);
            self::assertArrayHasKey('total_items', $packet);
            self::assertArrayHasKey('completed_items', $packet);
            self::assertArrayHasKey('open_items', $packet);
            self::assertSame($packet['total_items'], $packet['completed_items'] + $packet['open_items']);
        }
    }
}
