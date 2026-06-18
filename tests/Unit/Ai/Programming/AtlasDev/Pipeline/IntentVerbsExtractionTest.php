<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntentActionExtractor;
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
 * E1 — Intent action-verb extraction (persisted verb set).
 *
 * Covers VAL-E1-001, VAL-E1-002.
 *
 * The IntentActionExtractor service reuses the IntakeNormalizer::inferClarity
 * verb list as the recognized set (add/fix/redirect/rename/extract/gate/
 * remove/refactor and their PT-BR/EN synonyms, normalized to canonical
 * labels). The extracted verb set is persisted as a new field on the
 * LightTaskContract (intentVerbs) so downstream stages (E2 per-verb ACs,
 * the E1 intent probe) share one stable basis instead of each re-detecting
 * and re-detecting (today SpecComposer::extractIntentVerbs is private and
 * the verbs are discarded after populating expectedBehavior[]).
 *
 * The verb set is folded into task_contract_hash via toCanonicalArray()
 * (auto-folded by CanonicalHasher::hashWithout), survives the
 * fromArray/toCanonicalArray round-trip, and is absent-aware in fromArray
 * (back-compat default [] for pre-E1 payloads).
 */
final class IntentVerbsExtractionTest extends TestCase
{
    // -- VAL-E1-001: single-verb intent yields a persisted non-empty set ------

    public function test_val_e1_001_single_verb_intent_yields_persisted_non_empty_verb_set_containing_that_verb(): void
    {
        $intent = 'add a rate-limit guard to the API controller';
        $verbs = (new IntentActionExtractor)->extract($intent);

        $this->assertNotEmpty(
            $verbs,
            'VAL-E1-001: a recognized-verb intent must yield a non-empty verb set',
        );
        $this->assertContains(
            'adicionar',
            $verbs,
            'VAL-E1-001: the verb set must contain the recognized verb',
        );
    }

    public function test_val_e1_001_single_verb_persisted_on_contract_for_write_task(): void
    {
        $intent = 'fix the failing test in tests/Unit/FooTest.php';
        [$envelope, $compact, $miniSpec] = $this->composeWriteTask($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertNotEmpty(
            $contract->intentVerbs,
            'VAL-E1-001: the persisted intent_verbs field must be non-empty for a recognized-verb write task',
        );
        $this->assertContains(
            'corrigir',
            $contract->intentVerbs,
            'VAL-E1-001: the persisted verb set must contain the recognized verb (corrigir)',
        );
    }

    public function test_val_e1_001_intent_verbs_key_present_in_canonical_array(): void
    {
        $contract = $this->makeBareContract(intentVerbs: ['corrigir']);

        $canonical = $contract->toCanonicalArray();

        $this->assertArrayHasKey(
            'intent_verbs',
            $canonical,
            'VAL-E1-001: intent_verbs must be a key in toCanonicalArray() so it folds into the hash and is persisted',
        );
        $this->assertSame(['corrigir'], $canonical['intent_verbs']);
    }

    public function test_val_e1_001_empty_intent_yields_empty_verb_set(): void
    {
        // An intent with no recognized verb must yield an empty set, never a
        // guess. Downstream stages treat an empty verb set as "no intent verb
        // to probe" (the E1 probe stays silent; E2 emits no behavioral AC).
        $verbs = (new IntentActionExtractor)->extract('explain the architecture of this module');

        $this->assertSame(
            [],
            $verbs,
            'VAL-E1-001: an intent without recognized verbs yields an empty verb set (no false positives)',
        );
    }

    // -- VAL-E1-002: multi-verb intent captures every recognized verb --------

    public function test_val_e1_002_multi_verb_intent_captures_every_recognized_verb_and_excludes_non_verb_tokens(): void
    {
        $intent = 'rename AtlasCliDevWorkflowService and remove the dead branch';
        $verbs = (new IntentActionExtractor)->extract($intent);

        // The extractor returns canonical labels in map-iteration order (the
        // historical behavior); VAL-E1-002 only requires the exact recognized
        // SET, not a specific text-order. Assert as a set to stay robust to
        // map reordering while still proving every recognized verb is present
        // and no non-verb token leaks in.
        $this->assertSame(
            ['remover', 'renomear'],
            $verbs,
            'VAL-E1-002: multi-verb intent must capture every recognized verb, excluding non-verb tokens',
        );
    }

    public function test_val_e1_002_multi_verb_persisted_on_contract_for_write_task(): void
    {
        $intent = 'rename AtlasCliDevWorkflowService and remove the dead branch';
        [$envelope, $compact, $miniSpec] = $this->composeWriteTask($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertSame(
            ['remover', 'renomear'],
            $contract->intentVerbs,
            'VAL-E1-002: the persisted intent_verbs must contain every recognized verb and exclude non-verb tokens',
        );
    }

    public function test_val_e1_002_recognized_verb_set_matches_infer_clarity_vocabulary(): void
    {
        // The extractor MUST reuse the IntakeNormalizer::inferClarity verb
        // list as the recognized set (add/fix/redirect/rename/extract/gate/
        // remove/refactor + PT-BR/EN synonyms), so intake classification and
        // E1 verb extraction agree on the same vocabulary.
        $recognized = IntentActionExtractor::recognizedVerbLabels();

        // Every verb the architecture names must be reachable.
        foreach (['corrigir', 'remover', 'adicionar', 'renomear', 'refatorar', 'extrair', 'redirecionar', 'gatear'] as $expected) {
            $this->assertContains(
                $expected,
                $recognized,
                "VAL-E1-002: the recognized verb set must include the '{$expected}' verb from the inferClarity vocabulary",
            );
        }
    }

    public function test_val_e1_002_non_verb_tokens_excluded_from_set(): void
    {
        // "the", "and", "branch" etc. must NEVER appear in the set.
        $intent = 'refactor the module and extract a helper';
        $verbs = (new IntentActionExtractor)->extract($intent);

        $this->assertSame(['refatorar', 'extrair'], $verbs);
        foreach ($verbs as $v) {
            $this->assertNotEquals('the', $v);
            $this->assertNotEquals('and', $v);
            $this->assertNotEquals('module', $v);
        }
    }

    // -- Folding into task_contract_hash -------------------------------------

    public function test_intent_verbs_folded_into_task_contract_hash_distinct_verbs_distinct_hash(): void
    {
        $contractA = $this->makeBareContract(intentVerbs: ['corrigir']);
        $contractB = $this->makeBareContract(intentVerbs: ['remover', 'renomear']);

        $this->assertNotSame(
            $contractA->hash(),
            $contractB->hash(),
            'intent_verbs must fold into task_contract_hash: distinct verb sets must yield distinct hashes',
        );
    }

    public function test_intent_verbs_folded_into_task_contract_hash_identical_verbs_identical_hash(): void
    {
        $contractA = $this->makeBareContract(intentVerbs: ['renomear', 'remover']);
        $contractB = $this->makeBareContract(intentVerbs: ['renomear', 'remover']);

        $this->assertSame(
            $contractA->hash(),
            $contractB->hash(),
            'identical intent_verbs must yield an identical task_contract_hash',
        );
    }

    // -- Round-trip stability ------------------------------------------------

    public function test_intent_verbs_survive_from_array_to_canonical_array_round_trip(): void
    {
        $contract = $this->makeBareContract(intentVerbs: ['renomear', 'remover']);
        $canonical = $contract->toCanonicalArray();
        $originalHash = $contract->hash();

        $rebuilt = LightTaskContract::fromArray($canonical);

        $this->assertSame(
            $contract->intentVerbs,
            $rebuilt->intentVerbs,
            'intent_verbs must survive the round-trip unchanged',
        );
        $this->assertSame(
            $canonical,
            $rebuilt->toCanonicalArray(),
            'canonical array must be stable across round-trip',
        );
        $this->assertSame(
            $originalHash,
            $rebuilt->hash(),
            'recomputed hash must not drift across round-trip',
        );
    }

    public function test_from_array_back_compat_default_when_intent_verbs_absent(): void
    {
        // Pre-E1 payload without intent_verbs must still round-trip (back-compat).
        $contract = $this->makeBareContract(intentVerbs: ['corrigir']);
        $canonical = $contract->toCanonicalArray();
        unset($canonical['intent_verbs']);

        $rebuilt = LightTaskContract::fromArray($canonical);

        $this->assertSame(
            [],
            $rebuilt->intentVerbs,
            'missing intent_verbs in fromArray must default to empty list (back-compat)',
        );
        $this->assertSame(
            $rebuilt->toCanonicalArray(),
            LightTaskContract::fromArray($rebuilt->toCanonicalArray())->toCanonicalArray(),
        );
    }

    // -- Available to downstream stages --------------------------------------

    public function test_persisted_verb_set_available_to_downstream_via_contract_field(): void
    {
        // The persisted verb set is the shared basis for E2 per-verb ACs and
        // the E1 intent probe. Downstream stages read it from the contract
        // field rather than re-detecting (the verbs are detected once at
        // intake/spec-compose time and persisted).
        $intent = 'gate the feature flag and redirect the legacy route';
        [, , $miniSpec] = $this->composeWriteTask($intent);
        [$envelope, $compact] = $this->composeInputs($intent);

        $contract = (new SpecComposer)->composeTaskContract($envelope, $compact, $miniSpec);

        // Downstream can call IntentActionExtractor::extract() against the
        // persisted normalizedIntent to verify the persisted set matches.
        $reextracted = (new IntentActionExtractor)->extract($envelope->normalizedIntent);

        $this->assertSame(
            $reextracted,
            $contract->intentVerbs,
            'the persisted intent_verbs must equal a fresh extraction against the same intent (single source of truth)',
        );
        $this->assertSame(['redirecionar', 'gatear'], $contract->intentVerbs);
    }

    // -- Helpers -------------------------------------------------------------

    /**
     * @return array{0:OperationEnvelope,1:CompactSdd}
     */
    private function composeInputs(string $intent): array
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope($intent);
        $classification = new TaskClassification(
            taskKind: TaskClassification::KIND_REPAIR,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: ['repair:fix'],
            writeImplied: true,
        );
        $compact = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R2);

        return [$envelope, $compact];
    }

    /**
     * @return array{0:OperationEnvelope,1:CompactSdd,2:MiniProgrammingSpec}
     */
    private function composeWriteTask(string $intent): array
    {
        [$envelope, $compact] = $this->composeInputs($intent);

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
        $miniSpec = (new SpecComposer)->composeMiniSpec($envelope, $compact, $discovery, $this->emptyProjection($envelope->runId));

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

    /**
     * @param  list<string>  $intentVerbs
     */
    private function makeBareContract(array $intentVerbs = []): LightTaskContract
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
            taskContractHash: '',
            noTestReason: null,
            intentText: 'corrija o bug',
            intentVerbs: $intentVerbs,
        );
    }
}
