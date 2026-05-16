<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Plan;

use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use PHPUnit\Framework\TestCase;

final class CompactSddTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../../../../Fixtures/AtlasDev/compact_sdd';

    public function test_construction_returns_dto_with_canonical_schema_version(): void
    {
        $sdd = $this->makeRepairR2();

        $this->assertSame('atlas.dev.compact_sdd.v1', $sdd->schemaVersion());
        $this->assertTrue($sdd->isProviderSafe());
    }

    public function test_to_canonical_array_sorts_keys_alphabetically(): void
    {
        $sdd = $this->makeRepairR2();
        $keys = array_keys($sdd->toCanonicalArray());

        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_round_trip_from_canonical_array_preserves_canonical_shape(): void
    {
        $sdd = $this->makeRepairR2();
        $canonical = $sdd->toCanonicalArray();

        $rebuilt = CompactSdd::fromArray($canonical);

        $this->assertSame($canonical, $rebuilt->toCanonicalArray());
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $sdd = $this->makeRepairR2();

        $this->assertSame($sdd->hash(), $sdd->hash());
    }

    public function test_hash_changes_when_classification_field_changes(): void
    {
        $base = $this->makeRepairR2();
        $variant = $this->makeRepairR2(['riskLevel' => 'R3']);

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    public function test_hash_ignores_self_compact_sdd_hash_field(): void
    {
        $first = $this->makeRepairR2(['compactSddHash' => 'a-noise']);
        $second = $this->makeRepairR2(['compactSddHash' => 'b-noise']);

        $this->assertNotSame($first->compactSddHash, $second->compactSddHash);
        $this->assertSame($first->hash(), $second->hash());
    }

    public function test_hash_ignores_mini_spec_hash_and_task_contract_hash_per_invariant_7(): void
    {
        $base = $this->makeRepairR2();
        $withDownstream = $base->withMiniSpecHash('mini-1')->withTaskContractHash('task-1');

        $this->assertSame($base->hash(), $withDownstream->hash());
    }

    public function test_with_mini_spec_hash_returns_new_instance_with_updated_field(): void
    {
        $base = $this->makeRepairR2();
        $updated = $base->withMiniSpecHash('mini-spec-hash-1');

        $this->assertNull($base->miniSpecHash);
        $this->assertSame('mini-spec-hash-1', $updated->miniSpecHash);
        $this->assertNotSame($base, $updated);
    }

    public function test_with_task_contract_hash_returns_new_instance_with_updated_field(): void
    {
        $base = $this->makeRepairR2();
        $updated = $base->withTaskContractHash('task-contract-hash-1');

        $this->assertNull($base->taskContractHash);
        $this->assertSame('task-contract-hash-1', $updated->taskContractHash);
    }

    public function test_to_json_round_trips_to_same_canonical_array(): void
    {
        $sdd = $this->makeRepairR2();
        $decoded = json_decode($sdd->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame($sdd->toCanonicalArray(), $decoded);
    }

    public function test_fixture_repair_r2_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_repair_r2.json', 'R2', 'repair');
    }

    public function test_fixture_question_r0_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_question_r0.json', 'R0', 'read_only');
    }

    public function test_fixture_r4_escalate_preview_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_r4_escalate_preview.json', 'R4', 'escalate_preview');
    }

    private function assertFixtureRoundTrips(string $fixture, string $expectedRisk, string $expectedMode): void
    {
        $payload = $this->loadFixture($fixture);
        $sdd = CompactSdd::fromArray($payload);

        $this->assertSame($expectedRisk, $sdd->riskLevel);
        $this->assertSame($expectedMode, $sdd->mode);

        $serialized = $sdd->toCanonicalArray();
        $this->assertSame($payload['run_id'], $serialized['run_id']);
        $this->assertSame($payload['envelope_hash'], $serialized['envelope_hash']);
        $this->assertNotEmpty($sdd->hash());
    }

    private function loadFixture(string $file): array
    {
        $path = self::FIXTURE_DIR.'/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "fixture missing: {$path}");

        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function makeRepairR2(array $overrides = []): CompactSdd
    {
        $defaults = [
            'runId' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'envelopeHash' => 'deadbeef00112233445566778899aabbccddeeff',
            'intentRaw' => 'corrija o teste falhando em AtlasCliDevWorkflowServiceTest',
            'intentNormalized' => 'corrigir teste falhando em AtlasCliDevWorkflowServiceTest',
            'taskKind' => 'repair',
            'riskLevel' => 'R2',
            'scopeMode' => 'compact',
            'mode' => 'repair',
            'contextBudget' => new ContextBudget(
                maxChars: 12000,
                maxDocs: 4,
                maxCandidateFiles: 6,
                maxPlanSteps: 4,
                maxProviderCalls: 1,
                maxRepairAttempts: 1,
            ),
            'docTiersRequired' => ['core', 'code_intelligence', 'sdd'],
            'verificationProfile' => 'php_laravel',
            'contextDigest' => null,
            'escalationTriggers' => [],
            'miniSpecHash' => null,
            'taskContractHash' => null,
            'compactSddHash' => 'feedcafe00112233445566778899aabbccddeeff',
        ];

        $values = array_replace($defaults, $overrides);

        return new CompactSdd(
            runId: $values['runId'],
            envelopeHash: $values['envelopeHash'],
            intentRaw: $values['intentRaw'],
            intentNormalized: $values['intentNormalized'],
            taskKind: $values['taskKind'],
            riskLevel: $values['riskLevel'],
            scopeMode: $values['scopeMode'],
            mode: $values['mode'],
            contextBudget: $values['contextBudget'],
            docTiersRequired: $values['docTiersRequired'],
            verificationProfile: $values['verificationProfile'],
            contextDigest: $values['contextDigest'],
            escalationTriggers: $values['escalationTriggers'],
            miniSpecHash: $values['miniSpecHash'],
            taskContractHash: $values['taskContractHash'],
            compactSddHash: $values['compactSddHash'],
        );
    }
}
