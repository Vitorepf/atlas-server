<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Plan;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class LightTaskContractTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../../../../Fixtures/AtlasDev/task_contract';

    public function test_construction_returns_dto_with_canonical_schema_version(): void
    {
        $contract = $this->makeRepairR2Contract();

        $this->assertSame('atlas.dev.light_task_contract.v1', $contract->schemaVersion());
        $this->assertTrue($contract->isProviderSafe());
    }

    public function test_to_canonical_array_sorts_keys_alphabetically(): void
    {
        $contract = $this->makeRepairR2Contract();
        $keys = array_keys($contract->toCanonicalArray());

        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_round_trip_from_canonical_array_preserves_canonical_shape(): void
    {
        $contract = $this->makeRepairR2Contract();
        $canonical = $contract->toCanonicalArray();

        $rebuilt = LightTaskContract::fromArray($canonical);

        $this->assertSame($canonical, $rebuilt->toCanonicalArray());
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $contract = $this->makeRepairR2Contract();

        $this->assertSame($contract->hash(), $contract->hash());
    }

    public function test_hash_changes_when_allowed_files_change(): void
    {
        $base = $this->makeRepairR2Contract();
        $variant = $this->makeRepairR2Contract(['allowedFiles' => ['app/Services/Different.php']]);

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    public function test_hash_ignores_self_task_contract_hash_field(): void
    {
        $first = $this->makeRepairR2Contract(['taskContractHash' => 'a-noise']);
        $second = $this->makeRepairR2Contract(['taskContractHash' => 'b-noise']);

        $this->assertNotSame($first->taskContractHash, $second->taskContractHash);
        $this->assertSame($first->hash(), $second->hash());
    }

    public function test_hash_matches_canonical_hasher_over_payload_without_self_hash(): void
    {
        $contract = $this->makeRepairR2Contract();

        $expected = CanonicalHasher::hashWithout(
            $contract->toCanonicalArray(),
            'task_contract_hash',
        );

        $this->assertSame($expected, $contract->hash());
    }

    public function test_allows_write_returns_true_when_write_tool_present(): void
    {
        $contract = $this->makeRepairR2Contract();
        $this->assertTrue($contract->allowsWrite());
    }

    public function test_allows_write_returns_false_when_only_read_tools(): void
    {
        $contract = $this->makeRepairR2Contract(['allowedTools' => ['read', 'grep']]);
        $this->assertFalse($contract->allowsWrite());
    }

    public function test_canonical_array_includes_locked_owner(): void
    {
        $contract = $this->makeRepairR2Contract();

        $this->assertSame('atlas_dev_sonnet', $contract->toCanonicalArray()['owner']);
    }

    public function test_to_json_round_trips_to_same_canonical_array(): void
    {
        $contract = $this->makeRepairR2Contract();
        $decoded = json_decode($contract->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame($contract->toCanonicalArray(), $decoded);
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
        $contract = LightTaskContract::fromArray($payload);

        $serialized = $contract->toCanonicalArray();

        foreach (['run_id', 'task_id', 'spec_hash', 'owner', 'max_files_changed'] as $field) {
            $this->assertSame($payload[$field], $serialized[$field], "field {$field} drifted");
        }
        $this->assertNotEmpty($contract->hash());
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

    private function makeRepairR2Contract(array $overrides = []): LightTaskContract
    {
        $defaults = [
            'runId' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'taskId' => '0192b5d2-3001-7c4f-9d2a-1a2b3c4d5e6f',
            'specHash' => 'babecafe00112233445566778899aabbccddeeff',
            'allowedTools' => ['read', 'write', 'grep', 'run_test'],
            'blockedActions' => [
                'production_write',
                'migration_apply',
                'secret_access',
                'broad_refactor',
                'council_invoke',
                'forge_invoke_direct',
            ],
            'allowedFiles' => ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            'watchedFiles' => [],
            'forbiddenFiles' => ['vendor/*', 'node_modules/*'],
            'maxFilesChanged' => 1,
            'validationCommands' => [
                'composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            ],
            'evidenceRequired' => [
                'diff_hash',
                'changed_files',
                'test_output_hash',
                'scope_guard_receipt',
                'verification_receipt',
            ],
            'repairPolicy' => new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            'escalationOn' => [
                'same_signature_failure_twice',
                'diff_grew_without_progress',
                'new_scope_appeared',
            ],
            'providerLock' => new ProviderLock(
                provider: 'claude_cli',
                modelFamily: 'sonnet',
                fallbackAllowed: false,
            ),
            'taskContractHash' => 'ace5beef00112233445566778899aabbccddeeff',
            'noTestReason' => null,
        ];

        $values = array_replace($defaults, $overrides);

        return new LightTaskContract(
            runId: $values['runId'],
            taskId: $values['taskId'],
            specHash: $values['specHash'],
            allowedTools: $values['allowedTools'],
            blockedActions: $values['blockedActions'],
            allowedFiles: $values['allowedFiles'],
            watchedFiles: $values['watchedFiles'],
            forbiddenFiles: $values['forbiddenFiles'],
            maxFilesChanged: $values['maxFilesChanged'],
            validationCommands: $values['validationCommands'],
            evidenceRequired: $values['evidenceRequired'],
            repairPolicy: $values['repairPolicy'],
            escalationOn: $values['escalationOn'],
            providerLock: $values['providerLock'],
            taskContractHash: $values['taskContractHash'],
            noTestReason: $values['noTestReason'],
        );
    }
}
