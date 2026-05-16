<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Plan;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\VerificationPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class MiniProgrammingSpecTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../../../../Fixtures/AtlasDev/mini_spec';

    public function test_construction_returns_dto_with_canonical_schema_version(): void
    {
        $spec = $this->makeRepairR2Spec();

        $this->assertSame('atlas.dev.mini_programming_spec.v1', $spec->schemaVersion());
        $this->assertTrue($spec->isProviderSafe());
    }

    public function test_to_canonical_array_sorts_keys_alphabetically(): void
    {
        $spec = $this->makeRepairR2Spec();
        $keys = array_keys($spec->toCanonicalArray());

        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_round_trip_from_canonical_array_preserves_canonical_shape(): void
    {
        $spec = $this->makeRepairR2Spec();
        $canonical = $spec->toCanonicalArray();

        $rebuilt = MiniProgrammingSpec::fromArray($canonical);

        $this->assertSame($canonical, $rebuilt->toCanonicalArray());
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $spec = $this->makeRepairR2Spec();

        $this->assertSame($spec->hash(), $spec->hash());
    }

    public function test_hash_changes_when_goal_changes(): void
    {
        $base = $this->makeRepairR2Spec();
        $variant = $this->makeRepairR2Spec(['goal' => 'objetivo diferente']);

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    public function test_hash_ignores_self_mini_spec_hash_field(): void
    {
        $first = $this->makeRepairR2Spec(['miniSpecHash' => 'a-noise']);
        $second = $this->makeRepairR2Spec(['miniSpecHash' => 'b-noise']);

        $this->assertNotSame($first->miniSpecHash, $second->miniSpecHash);
        $this->assertSame($first->hash(), $second->hash());
    }

    public function test_hash_matches_canonical_hasher_over_payload_without_self_hash(): void
    {
        $spec = $this->makeRepairR2Spec();

        $expected = CanonicalHasher::hashWithout(
            $spec->toCanonicalArray(),
            'mini_spec_hash',
        );

        $this->assertSame($expected, $spec->hash());
    }

    public function test_has_blocking_assumption_returns_false_when_no_blocker(): void
    {
        $spec = $this->makeRepairR2Spec();
        $this->assertFalse($spec->hasBlockingAssumption());
    }

    public function test_has_blocking_assumption_returns_true_when_a_blocker_present(): void
    {
        $spec = $this->makeRepairR2Spec([
            'assumptions' => [
                ['text' => 'algo importante', 'confidence' => 'blocking'],
            ],
        ]);

        $this->assertTrue($spec->hasBlockingAssumption());
    }

    public function test_to_json_round_trips_to_same_canonical_array(): void
    {
        $spec = $this->makeRepairR2Spec();
        $decoded = json_decode($spec->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame($spec->toCanonicalArray(), $decoded);
    }

    public function test_fixture_repair_r2_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_repair_r2.json');
    }

    public function test_fixture_r4_escalate_preview_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_r4_escalate_preview.json');
    }

    private function assertFixtureRoundTrips(string $fixture): void
    {
        $payload = $this->loadFixture($fixture);
        $spec = MiniProgrammingSpec::fromArray($payload);

        $serialized = $spec->toCanonicalArray();

        $this->assertSame($payload['run_id'], $serialized['run_id']);
        $this->assertSame($payload['compact_sdd_hash'], $serialized['compact_sdd_hash']);
        $this->assertSame($payload['goal'], $serialized['goal']);
        $this->assertNotEmpty($spec->hash());
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

    private function makeRepairR2Spec(array $overrides = []): MiniProgrammingSpec
    {
        $defaults = [
            'runId' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'compactSddHash' => 'feedcafe00112233445566778899aabbccddeeff',
            'goal' => 'Corrigir AtlasCliDevWorkflowServiceTest::test_workspace_resolution para passar com git root null',
            'nonGoals' => [
                'nao mudar API publica do WorkflowService',
                'nao mexer em outros testes',
            ],
            'canonicalContext' => [
                ['kind' => 'file', 'ref' => 'app/Services/Ai/Cli/AtlasCliDevWorkflowService.php', 'reason' => 'alvo do bug'],
                ['kind' => 'test', 'ref' => 'tests/Unit/AtlasCliDevWorkflowServiceTest.php', 'reason' => 'teste falhando'],
            ],
            'expectedBehavior' => [
                ['description' => 'test_workspace_resolution passa com git root null', 'observable_by' => 'test'],
            ],
            'assumptions' => [
                ['text' => 'git root nullable e caso valido', 'confidence' => 'inference'],
            ],
            'expectedFiles' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'allowedFiles' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'forbiddenFiles' => ['vendor/*', 'node_modules/*'],
            'acceptanceCriteria' => [
                [
                    'id' => 'ac_1',
                    'description' => 'teste passa',
                    'verification' => 'test',
                    'verification_ref' => 'tests/Unit/AtlasCliDevWorkflowServiceTest.php::test_workspace_resolution',
                ],
            ],
            'verificationPlan' => new VerificationPlan(
                profile: 'php_laravel',
                commands: ['composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution'],
                noTestReason: null,
            ),
            'rollbackOrContainment' => 'git checkout app/Services/Ai/Cli/AtlasCliDevWorkflowService.php',
            'completionCriteria' => ['teste passa', 'nenhum outro teste regrediu (composer test)'],
            'miniSpecHash' => 'babecafe00112233445566778899aabbccddeeff',
        ];

        $values = array_replace($defaults, $overrides);

        return new MiniProgrammingSpec(
            runId: $values['runId'],
            compactSddHash: $values['compactSddHash'],
            goal: $values['goal'],
            nonGoals: $values['nonGoals'],
            canonicalContext: $values['canonicalContext'],
            expectedBehavior: $values['expectedBehavior'],
            assumptions: $values['assumptions'],
            expectedFiles: $values['expectedFiles'],
            allowedFiles: $values['allowedFiles'],
            forbiddenFiles: $values['forbiddenFiles'],
            acceptanceCriteria: $values['acceptanceCriteria'],
            verificationPlan: $values['verificationPlan'],
            rollbackOrContainment: $values['rollbackOrContainment'],
            completionCriteria: $values['completionCriteria'],
            miniSpecHash: $values['miniSpecHash'],
        );
    }
}
