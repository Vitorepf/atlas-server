<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use PHPUnit\Framework\TestCase;

/**
 * E2 — intent_text on LightTaskContract.
 *
 * Covers VAL-E2-006, VAL-E2-007, VAL-E2-008, VAL-E2-012.
 *
 * intent_text is a new LightTaskContract ctor field sourced from the
 * envelope->normalizedIntent. It is folded into task_contract_hash via
 * toCanonicalArray() (auto-folded by CanonicalHasher::hashWithout), survives
 * the fromArray/toCanonicalArray round-trip, and is never empty for write
 * tasks (even sparse single-verb intents).
 *
 * Different intent => different task_contract_hash; identical intent =>
 * identical hash. The field is absent-aware in fromArray (back-compat
 * default for pre-E2 payloads).
 */
final class IntentTextContractTest extends TestCase
{
    // -- VAL-E2-006: intent_text non-empty for write tasks -------------------

    public function test_val_e2_006_intent_text_non_empty_for_write_task(): void
    {
        $intent = 'corrija o teste falhando em tests/Unit/FooTest.php';
        [$envelope, $compact, $miniSpec] = $this->composeWriteTask($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertNotEmpty(
            trim($contract->intentText),
            'VAL-E2-006: intent_text must be non-empty for a write task',
        );
        $this->assertSame(
            trim($envelope->normalizedIntent),
            $contract->intentText,
            'VAL-E2-006: intent_text is sourced from the normalized intent',
        );
    }

    // -- VAL-E2-012: intent_text non-empty even for sparse intents -----------

    public function test_val_e2_012_intent_text_non_empty_for_sparse_single_verb_intent(): void
    {
        // Minimal intent: a single recognized verb with almost no context.
        $intent = 'corrija';
        [$envelope, $compact, $miniSpec] = $this->composeWriteTask($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertNotEmpty(
            trim($contract->intentText),
            'VAL-E2-012: intent_text must be non-empty even for a sparse single-verb intent',
        );
    }

    public function test_val_e2_012_intent_text_non_empty_when_raw_intent_is_minimal(): void
    {
        // Even an empty normalized intent must yield a non-empty intent_text
        // for a write task (never falls back to empty).
        $intent = 'fix';
        [$envelope, $compact, $miniSpec] = $this->composeWriteTask($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertNotEmpty(trim($contract->intentText));
    }

    // -- VAL-E2-007: intent_text folded into task_contract_hash --------------

    public function test_val_e2_007_different_intent_yields_different_task_contract_hash(): void
    {
        // Use bare contracts (same fields except intent_text) so the ONLY
        // delta is intent_text and the hash difference is attributable to it.
        $contractA = $this->makeBareContract(intentText: 'corrija o bug em FooService');
        $contractB = $this->makeBareContract(intentText: 'renomeie AtlasCliDevWorkflowService para AtlasWorkflowService');

        $this->assertNotSame(
            $contractA->hash(),
            $contractB->hash(),
            'VAL-E2-007: different intent_text must yield a different task_contract_hash',
        );
    }

    public function test_val_e2_007_identical_intent_yields_identical_task_contract_hash(): void
    {
        $intent = 'corrija o teste falhando em tests/Unit/FooTest.php';

        $contractA = $this->makeBareContract(intentText: $intent);
        $contractB = $this->makeBareContract(intentText: $intent);

        $this->assertSame(
            $contractA->hash(),
            $contractB->hash(),
            'VAL-E2-007: identical intent_text must yield an identical task_contract_hash',
        );
    }

    public function test_val_e2_007_intent_text_key_present_in_canonical_array(): void
    {
        $contract = $this->makeBareContract(intentText: 'corrija o bug');

        $canonical = $contract->toCanonicalArray();

        $this->assertArrayHasKey(
            'intent_text',
            $canonical,
            'VAL-E2-007: intent_text must be a key in toCanonicalArray() so it folds into the hash',
        );
        $this->assertSame('corrija o bug', $canonical['intent_text']);
    }

    public function test_val_e2_007_hash_ignores_task_contract_hash_but_includes_intent_text(): void
    {
        $base = $this->makeBareContract(intentText: 'intent one');
        $variant = $this->makeBareContract(intentText: 'intent two');

        // The self hash field is excluded but intent_text is included.
        $this->assertNotSame($base->hash(), $variant->hash());

        // Same intent_text => same hash regardless of the stored taskContractHash noise.
        $same = $this->makeBareContract(intentText: 'intent one', taskContractHash: 'noise');
        $this->assertSame($base->hash(), $same->hash());
    }

    // -- VAL-E2-008: round-trip stability ------------------------------------

    public function test_val_e2_008_intent_text_survives_from_array_to_canonical_array_round_trip(): void
    {
        $contract = $this->makeBareContract(intentText: 'corrija o teste falhando em tests/Unit/FooTest.php');
        $canonical = $contract->toCanonicalArray();
        $originalHash = $contract->hash();

        $rebuilt = LightTaskContract::fromArray($canonical);

        $this->assertSame(
            $contract->intentText,
            $rebuilt->intentText,
            'VAL-E2-008: intent_text must survive the round-trip unchanged',
        );
        $this->assertSame(
            $canonical,
            $rebuilt->toCanonicalArray(),
            'VAL-E2-008: canonical array must be stable across round-trip',
        );
        $this->assertSame(
            $originalHash,
            $rebuilt->hash(),
            'VAL-E2-008: recomputed hash must not drift across round-trip',
        );
    }

    public function test_val_e2_008_from_array_back_compat_default_when_intent_text_absent(): void
    {
        // Pre-E2 payload without intent_text must still round-trip (back-compat).
        $contract = $this->makeBareContract(intentText: 'corrija o bug');
        $canonical = $contract->toCanonicalArray();
        unset($canonical['intent_text']);

        $rebuilt = LightTaskContract::fromArray($canonical);

        $this->assertSame(
            '',
            $rebuilt->intentText,
            'VAL-E2-008: missing intent_text in fromArray must default to empty string (back-compat)',
        );
        // Canonical round-trip is stable for the rebuilt instance.
        $this->assertSame(
            $rebuilt->toCanonicalArray(),
            LightTaskContract::fromArray($rebuilt->toCanonicalArray())->toCanonicalArray(),
        );
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * Build the inputs for a R2 repair task (write-implied) so the composed
     * contract carries a non-empty intent_text.
     *
     * @return array{0:OperationEnvelope,1:CompactSdd,2:MiniProgrammingSpec}
     */
    private function composeWriteTask(string $intent): array
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope($intent);
        $classification = new TaskClassification(
            taskKind: TaskClassification::KIND_REPAIR,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: ['repair:corrija'],
            writeImplied: true,
        );
        $compact = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R2);

        $discovery = new CodeDiscoveryManifest(
            runId: $envelope->runId,
            likelyFiles: [
                new CodeCandidate(path: '/ws/app/Services/Foo.php', reason: 'symbol', confidence: 0.9, symbols: ['FooService']),
            ],
            relatedSymbols: [],
            relatedTests: [
                new ContextRef(kind: ContextRef::KIND_TEST, ref: 'file:///ws/tests/Unit/FooTest.php', reason: 'conv test'),
            ],
            relatedCommands: [],
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: 'deadbeef',
        );
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $this->emptyProjection($envelope->runId));

        return [$envelope, $compact, $miniSpec];
    }

    private function envelope(
        string $intent,
        string $surfaceId = 'atlas_cli_dev',
        string $workspace = '/ws',
    ): OperationEnvelope {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: $surfaceId,
            surfaceContext: new SurfaceContext(productSurface: $surfaceId, providerChoice: null),
            workspace: $workspace,
            workspaceHash: hash('sha256', $workspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: [],
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            dirtyWorktreePolicy: IntakeNormalizer::DIRTY_POLICY_PRESERVE,
            preflight: new Preflight(
                workspaceResolved: true,
                permissionMode: IntakeNormalizer::PERMISSION_WRITE_ALLOWED,
                writeAllowed: true,
                operatorExplicit: false,
            ),
            envelopeHash: 'deadbeef',
        );
    }

    private function emptyProjection(string $runId): OpenBrainProgrammingProjection
    {
        return new OpenBrainProgrammingProjection(
            runId: $runId,
            mode: OpenBrainProgrammingProjection::MODE,
            objectiveHash: hash('sha256', 'objective'),
            memoryRefs: [],
            knowledgeRefs: [],
            codeRefs: [],
            missingSources: [],
            truncation: ['truncated' => false, 'reasons' => []],
            providerSafe: true,
            projectionHash: 'projection-h',
        );
    }

    private function makeBareContract(string $intentText = '', string $taskContractHash = ''): LightTaskContract
    {
        return new LightTaskContract(
            runId: '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            taskId: '0192b5d2-3001-7c4f-9d2a-1a2b3c4d5e6f',
            specHash: 'babecafe00112233445566778899aabbccddeeff',
            allowedTools: ['read', 'write', 'grep', 'run_test'],
            blockedActions: [
                'production_write',
                'migration_apply',
                'secret_access',
                'broad_refactor',
                'council_invoke',
                'forge_invoke_direct',
            ],
            allowedFiles: ['app/Services/Ai/Cli/AtlasCliDevWorkflowService.php'],
            watchedFiles: [],
            forbiddenFiles: ['vendor/*', 'node_modules/*'],
            maxFilesChanged: 1,
            validationCommands: [
                'composer test -- --filter=AtlasCliDevWorkflowServiceTest::test_workspace_resolution',
            ],
            evidenceRequired: [
                'diff_hash',
                'changed_files',
                'test_output_hash',
                'scope_guard_receipt',
                'verification_receipt',
            ],
            repairPolicy: new RepairPolicy(
                maxAttempts: 1,
                sameProvider: true,
                requiresFailedGateOutput: true,
                abortOnSameSignatureTwice: true,
            ),
            escalationOn: [
                'same_signature_failure_twice',
                'diff_grew_without_progress',
                'new_scope_appeared',
            ],
            providerLock: new ProviderLock(
                provider: 'claude_cli',
                modelFamily: 'sonnet',
                fallbackAllowed: false,
            ),
            taskContractHash: $taskContractHash,
            noTestReason: null,
            intentText: $intentText,
        );
    }
}
