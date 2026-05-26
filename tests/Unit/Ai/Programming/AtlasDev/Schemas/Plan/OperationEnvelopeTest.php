<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Plan;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class OperationEnvelopeTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../../../../../../Fixtures/AtlasDev/envelopes';

    public function test_construction_returns_dto_with_canonical_schema_version(): void
    {
        $envelope = $this->makeValidEnvelope();

        $this->assertSame('atlas.dev.operation_envelope.v1', $envelope->schemaVersion());
        $this->assertTrue($envelope->isProviderSafe());
    }

    public function test_to_canonical_array_sorts_keys_alphabetically(): void
    {
        $envelope = $this->makeValidEnvelope();

        $keys = array_keys($envelope->toCanonicalArray());

        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function test_round_trip_from_canonical_array_preserves_canonical_shape(): void
    {
        $envelope = $this->makeValidEnvelope();
        $canonical = $envelope->toCanonicalArray();

        $rebuilt = OperationEnvelope::fromArray($canonical);

        $this->assertSame($canonical, $rebuilt->toCanonicalArray());
    }

    public function test_hash_is_deterministic_across_calls(): void
    {
        $envelope = $this->makeValidEnvelope();

        $this->assertSame($envelope->hash(), $envelope->hash());
    }

    public function test_hash_changes_when_business_field_changes(): void
    {
        $base = $this->makeValidEnvelope();
        $variant = new OperationEnvelope(
            runId: $base->runId,
            surfaceId: $base->surfaceId,
            surfaceContext: $base->surfaceContext,
            workspace: $base->workspace,
            workspaceHash: $base->workspaceHash,
            gitState: $base->gitState,
            rawIntent: 'CHANGED — adicionar feature em vez do raw original',
            normalizedIntent: $base->normalizedIntent,
            userConstraints: $base->userConstraints,
            intentClarityLevel: $base->intentClarityLevel,
            dirtyWorktreePolicy: $base->dirtyWorktreePolicy,
            preflight: $base->preflight,
            envelopeHash: $base->envelopeHash,
        );

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    public function test_hash_ignores_self_envelope_hash_field(): void
    {
        $first = $this->makeValidEnvelope('first-hash-noise');
        $second = $this->makeValidEnvelope('second-hash-noise');

        $this->assertNotSame($first->envelopeHash, $second->envelopeHash);
        $this->assertSame($first->hash(), $second->hash());
    }

    public function test_hash_matches_canonical_hasher_over_payload_without_self_hash(): void
    {
        $envelope = $this->makeValidEnvelope();

        $expected = CanonicalHasher::hashWithout(
            $envelope->toCanonicalArray(),
            'envelope_hash',
        );

        $this->assertSame($expected, $envelope->hash());
    }

    public function test_to_json_round_trips_to_same_canonical_array(): void
    {
        $envelope = $this->makeValidEnvelope();

        $decoded = json_decode($envelope->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertSame($envelope->toCanonicalArray(), $decoded);
    }

    public function test_fixture_valid_desktop_dev_r2_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_desktop_dev_r2.json');
    }

    public function test_fixture_valid_question_r0_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_question_r0.json');
    }

    public function test_fixture_valid_r4_escalate_preview_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_r4_escalate_preview.json');
    }

    public function test_fixture_valid_app_question_r0_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_app_question_r0.json');
    }

    public function test_fixture_valid_api_interaction_read_only_round_trips(): void
    {
        $this->assertFixtureRoundTrips('valid_api_interaction_read_only.json');
    }

    /**
     * Fatia 0 DoD: envelope must support the four canonical surface_ids
     * declared in contracts doc 4.1 + runbook 6.2 PR 0.2 DoD.
     */
    public function test_four_canonical_surface_ids_are_covered_by_fixtures(): void
    {
        $bySurface = [];
        foreach (glob(self::FIXTURE_DIR.'/*.json') as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded, "fixture not JSON: {$path}");
            $bySurface[$decoded['surface_id']][] = basename($path);
        }

        foreach (['atlas_desktop_ai', 'atlas_cli_dev', 'atlas_app', 'atlas_api_interaction'] as $surfaceId) {
            $this->assertArrayHasKey(
                $surfaceId,
                $bySurface,
                "Fatia 0 DoD: surface_id '{$surfaceId}' has no fixture in envelopes/"
            );
        }
    }

    public function test_envelope_provider_safe_projection_matches_canonical(): void
    {
        $envelope = $this->makeValidEnvelope();

        $this->assertSame(
            $envelope->toCanonicalArray(),
            $envelope->toProviderSafeArray(),
        );
    }

    /**
     * Atlas AI > Router > Atlas Dev: the runtime always identifies itself as
     * the `atlas_dev` flow. The constants and enum below are the source of
     * truth — anything other than these values means intake produced a
     * non-canonical envelope.
     */
    public function test_canonical_flow_id_constant_is_atlas_dev(): void
    {
        $this->assertSame('atlas_dev', OperationEnvelope::FLOW_ID);
    }

    public function test_canonical_flow_origins_are_router_or_direct(): void
    {
        $this->assertSame(
            ['atlas_ai_router', 'direct'],
            OperationEnvelope::FLOW_ORIGINS,
        );
    }

    public function test_default_construction_falls_back_to_atlas_dev_direct_with_null_command_intent(): void
    {
        $envelope = $this->makeValidEnvelope();

        $this->assertSame('atlas_dev', $envelope->flowId);
        $this->assertSame('direct', $envelope->flowOrigin);
        $this->assertNull($envelope->commandIntent);
        $this->assertTrue($envelope->isCanonicalFlowId());
        $this->assertTrue($envelope->isCanonicalFlowOrigin());
    }

    public function test_router_origin_with_command_intent_is_preserved_through_canonical_round_trip(): void
    {
        $envelope = $this->makeValidEnvelope(
            hashSeed: 'router-seed',
            flowOrigin: OperationEnvelope::FLOW_ORIGIN_ATLAS_AI_ROUTER,
            commandIntent: 'dev',
        );

        $canonical = $envelope->toCanonicalArray();
        $this->assertSame('atlas_dev', $canonical['flow_id']);
        $this->assertSame('atlas_ai_router', $canonical['flow_origin']);
        $this->assertSame('dev', $canonical['command_intent']);

        $rebuilt = OperationEnvelope::fromArray($canonical);
        $this->assertSame('atlas_dev', $rebuilt->flowId);
        $this->assertSame('atlas_ai_router', $rebuilt->flowOrigin);
        $this->assertSame('dev', $rebuilt->commandIntent);
    }

    public function test_from_array_with_missing_flow_fields_uses_canonical_defaults(): void
    {
        $envelope = $this->makeValidEnvelope();
        $canonical = $envelope->toCanonicalArray();

        unset($canonical['flow_id'], $canonical['flow_origin'], $canonical['command_intent']);

        $rebuilt = OperationEnvelope::fromArray($canonical);

        $this->assertSame(OperationEnvelope::FLOW_ID, $rebuilt->flowId);
        $this->assertSame(OperationEnvelope::FLOW_ORIGIN_DIRECT, $rebuilt->flowOrigin);
        $this->assertNull($rebuilt->commandIntent);
    }

    public function test_hash_changes_when_flow_origin_changes(): void
    {
        $base = $this->makeValidEnvelope(flowOrigin: OperationEnvelope::FLOW_ORIGIN_DIRECT);
        $variant = $this->makeValidEnvelope(flowOrigin: OperationEnvelope::FLOW_ORIGIN_ATLAS_AI_ROUTER);

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    public function test_hash_changes_when_command_intent_changes(): void
    {
        $base = $this->makeValidEnvelope(commandIntent: null);
        $variant = $this->makeValidEnvelope(commandIntent: 'review');

        $this->assertNotSame($base->hash(), $variant->hash());
    }

    /**
     * Invariant: command_intent is hint-only — it cannot alter raw_intent or
     * normalized_intent. This guards against any future regression that lets
     * a downstream service overwrite operator text with the router's command.
     */
    public function test_command_intent_does_not_alter_raw_or_normalized_intent(): void
    {
        $envelope = $this->makeValidEnvelope(commandIntent: 'debug');

        $this->assertSame('corrija o teste falhando em AtlasCliDevWorkflowServiceTest', $envelope->rawIntent);
        $this->assertSame('corrigir teste falhando em AtlasCliDevWorkflowServiceTest', $envelope->normalizedIntent);
    }

    public function test_non_canonical_flow_id_is_detected_by_invariant_helper(): void
    {
        $envelope = $this->makeValidEnvelope(flowId: 'atlas_chat');

        $this->assertFalse(
            $envelope->isCanonicalFlowId(),
            'Atlas Dev runtime must reject any flow_id other than atlas_dev',
        );
    }

    public function test_non_canonical_flow_origin_is_detected_by_invariant_helper(): void
    {
        $envelope = $this->makeValidEnvelope(flowOrigin: 'forged_router');

        $this->assertFalse(
            $envelope->isCanonicalFlowOrigin(),
            'Atlas Dev runtime must reject any flow_origin not in FLOW_ORIGINS',
        );
    }

    private function assertFixtureRoundTrips(string $fixture): void
    {
        $payload = $this->loadFixture($fixture);
        $envelope = OperationEnvelope::fromArray($payload);

        $serialized = $envelope->toCanonicalArray();

        foreach (['run_id', 'surface_id', 'workspace', 'workspace_hash', 'raw_intent'] as $field) {
            $this->assertSame($payload[$field], $serialized[$field], "field {$field} drifted");
        }

        // hash() is independent of the stored envelope_hash field
        $this->assertNotEmpty($envelope->hash());
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

    private function makeValidEnvelope(
        string $hashSeed = 'deadbeef',
        string $flowId = OperationEnvelope::FLOW_ID,
        string $flowOrigin = OperationEnvelope::FLOW_ORIGIN_DIRECT,
        ?string $commandIntent = null,
    ): OperationEnvelope {
        return new OperationEnvelope(
            runId: '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            surfaceId: 'atlas_desktop_ai',
            surfaceContext: new SurfaceContext(
                productSurface: 'atlas_ai_desktop_mac',
                threadId: 'thread_01J7XYZ456',
                conversationId: 'conversation_01J7XYZ123',
                composerMode: 'programming',
                composerTask: 'dev',
                providerChoice: 'auto',
            ),
            workspace: '/Users/op/code/atlas-server',
            workspaceHash: 'a1b2c3d4e5f60718192a2b3c4d5e6f70819293a4',
            gitState: new GitState(
                headSha: 'f4e5d6c7b8a9101112131415161718191a1b1c1d',
                dirty: false,
                untrackedCount: 0,
                pendingChangesCount: 0,
            ),
            rawIntent: 'corrija o teste falhando em AtlasCliDevWorkflowServiceTest',
            normalizedIntent: 'corrigir teste falhando em AtlasCliDevWorkflowServiceTest',
            userConstraints: [],
            intentClarityLevel: 'high',
            dirtyWorktreePolicy: 'preserve_user_changes',
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: 'write',
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: $hashSeed,
            flowId: $flowId,
            flowOrigin: $flowOrigin,
            commandIntent: $commandIntent,
        );
    }
}
