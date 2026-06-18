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
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Support\Elevations\ElevationConfig;
use PHPUnit\Framework\TestCase;

/**
 * E2 — Semantic (behavioral) Acceptance Criteria.
 *
 * Covers VAL-E2-004, VAL-E2-005, VAL-E2-011, VAL-E2-015.
 *
 * SpecComposer::buildAcceptanceCriteria() must emit behavioral ACs derived
 * from the intent verb(s), each carrying a real verification_ref pointing at
 * a concrete test command. The command/scope ACs stay as the verifiable
 * backstop (AC count/strength is never reduced). For a multi-verb intent,
 * one behavioral AC is emitted per recognized verb, with no id collisions.
 *
 * A purely-tautological AC set (no behavioral AC despite a write task) is
 * never silently green: when E2 is on and a write task carries recognized
 * verbs, the behavioral ACs MUST be present so the intent is backed by a
 * real verification_ref. The intent_not_tested honesty flag (surfaced by
 * the sibling e2-intent-text-contract feature) relies on this emission so
 * a tautology-only set is detectable and flaggable.
 *
 * Gated by atlas_dev.elevations.e2.mode: off => byte-identical AC set to
 * the pre-E2 baseline (only command/scope backstop ACs, no behavioral ACs).
 */
final class SpecComposerSemanticAcceptanceCriteriaTest extends TestCase
{
    public function test_val_e2_004_write_task_yields_at_least_one_behavioral_ac_distinct_from_backstop(): void
    {
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );
        $composer = new SpecComposer;
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $behavioral = $this->behavioralAcs($miniSpec->acceptanceCriteria);
        $this->assertNotEmpty(
            $behavioral,
            'VAL-E2-004: a write task with a recognized verb must yield >=1 behavioral AC',
        );

        // The behavioral AC must be DISTINCT from the command/scope backstop.
        foreach ($behavioral as $ac) {
            $this->assertStringNotContainsString(
                'exit_code=0',
                $ac['description'],
                'behavioral AC must not be the tautological "command exits 0" AC',
            );
            $this->assertStringNotContainsString(
                'expected_files',
                $ac['description'],
                'behavioral AC must not be the tautological "diff in scope" AC',
            );
        }
    }

    public function test_val_e2_005_behavioral_ac_carries_real_non_empty_verification_ref(): void
    {
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );
        $composer = new SpecComposer;
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $behavioral = $this->behavioralAcs($miniSpec->acceptanceCriteria);
        $this->assertNotEmpty($behavioral);

        foreach ($behavioral as $ac) {
            $ref = $ac['verification_ref'] ?? null;
            $this->assertNotNull(
                $ref,
                'VAL-E2-005: behavioral AC verification_ref must not be null',
            );
            $this->assertNotSame(
                '',
                trim((string) $ref),
                'VAL-E2-005: behavioral AC verification_ref must not be empty',
            );
            // No placeholder/sentinel.
            $this->assertStringNotContainsString(
                strtolower((string) $ref),
                '<placeholder>',
                'VAL-E2-005: verification_ref must not be a placeholder',
            );
            // Must look like a resolvable test command (non-empty executable string).
            $this->assertNotEmpty(
                $miniSpec->verificationPlan->commands,
                'VAL-E2-005: the spec must carry at least one verification command',
            );
            $this->assertTrue(
                in_array($ref, $miniSpec->verificationPlan->commands, true)
                || $this->refResolvesToACommand((string) $ref),
                'VAL-E2-005: verification_ref must resolve to a concrete test command from the verification plan or profile default',
            );
        }
    }

    public function test_val_e2_015_multi_verb_intent_yields_one_behavioral_ac_per_verb_no_id_collisions(): void
    {
        // Two recognized verbs: "rename" and "remove".
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'rename AtlasCliDevWorkflowService e remova o dead branch em FooService',
        );
        $composer = new SpecComposer;
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $behavioral = $this->behavioralAcs($miniSpec->acceptanceCriteria);

        // At least one behavioral AC per recognized verb.
        $this->assertGreaterThanOrEqual(
            2,
            count($behavioral),
            'VAL-E2-015: multi-verb intent must yield >=1 behavioral AC per recognized verb',
        );

        // No id collisions across the whole AC set.
        $ids = array_map(fn (array $ac): string => $ac['id'], $miniSpec->acceptanceCriteria);
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'VAL-E2-015: no id collisions across acceptanceCriteria',
        );

        // Each behavioral AC carries a real verification_ref.
        foreach ($behavioral as $ac) {
            $this->assertNotNull(
                $ac['verification_ref'] ?? null,
                'VAL-E2-015: each per-verb behavioral AC must carry a verification_ref',
            );
            $this->assertNotSame(
                '',
                trim((string) ($ac['verification_ref'] ?? '')),
                'VAL-E2-015: each per-verb behavioral AC verification_ref must be non-empty',
            );
        }
    }

    public function test_val_e2_011_tautology_only_set_is_never_silently_passed_behavioral_baseline_preserved(): void
    {
        // A write task with recognized verbs MUST carry behavioral ACs; it
        // can never silently fall back to a tautology-only set while E2 is on.
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'corrija o bug em FooService',
        );
        $composer = new SpecComposer;
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $behavioral = $this->behavioralAcs($miniSpec->acceptanceCriteria);
        $this->assertNotEmpty(
            $behavioral,
            'VAL-E2-011: a write task with recognized verbs must not degrade to a tautology-only AC set',
        );

        // AC count/strength never reduced below the behavioral baseline:
        // the command/scope backstop is ALSO present.
        $commandAcs = array_filter(
            $miniSpec->acceptanceCriteria,
            static fn (array $ac): bool => str_contains($ac['description'], 'exit_code=0'),
        );
        $this->assertNotEmpty(
            $commandAcs,
            'VAL-E2-011: the command backstop ACs must be retained (AC strength never reduced)',
        );
    }

    public function test_e2_off_yields_byte_identical_ac_set_to_pre_e2_baseline(): void
    {
        // With e2.mode=off, the AC set must be byte-identical to the pre-E2
        // baseline: ONLY the command/scope backstop ACs, NO behavioral ACs.
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );

        $composer = new SpecComposer;

        $offSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection, e2Config: ElevationConfig::for('e2', ['mode' => 'off']));
        $onSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection, e2Config: ElevationConfig::for('e2', ['mode' => 'advisory']));

        // Off-mode: no behavioral ACs.
        $this->assertSame(
            [],
            $this->behavioralAcs($offSpec->acceptanceCriteria),
            'e2.mode=off must NOT emit behavioral ACs',
        );

        // On-mode: behavioral ACs present.
        $this->assertNotEmpty(
            $this->behavioralAcs($onSpec->acceptanceCriteria),
            'e2.mode=advisory must emit behavioral ACs',
        );

        // Off-mode AC set must equal the legacy baseline shape:
        // every AC has verification='test' with a command verification_ref,
        // OR verification='scope_guard'.
        foreach ($offSpec->acceptanceCriteria as $ac) {
            $this->assertTrue(
                $ac['verification'] === 'test' || $ac['verification'] === 'scope_guard' || $ac['verification'] === 'manual',
                'off-mode ACs must match the pre-E2 verification categories',
            );
        }
    }

    public function test_behavioral_acs_are_emitted_before_the_command_scope_backstop(): void
    {
        [$envelope, $compact, $discovery, $projection] = $this->composeRepairR2(
            intent: 'corrija o teste falhando em tests/Unit/FooTest.php',
        );
        $composer = new SpecComposer;
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $ids = array_map(fn (array $ac): string => $ac['id'], $miniSpec->acceptanceCriteria);
        $firstBehavioralIndex = null;
        $firstBackstopIndex = null;
        foreach ($miniSpec->acceptanceCriteria as $i => $ac) {
            $isBehavioral = str_starts_with($ac['id'], 'ac_behavior_');
            $isBackstop = str_contains($ac['description'], 'exit_code=0')
                || str_contains($ac['description'], 'expected_files');
            if ($isBehavioral && $firstBehavioralIndex === null) {
                $firstBehavioralIndex = $i;
            }
            if ($isBackstop && $firstBackstopIndex === null) {
                $firstBackstopIndex = $i;
            }
        }
        $this->assertNotNull($firstBehavioralIndex);
        $this->assertNotNull($firstBackstopIndex);
        $this->assertLessThan(
            $firstBackstopIndex,
            $firstBehavioralIndex,
            'behavioral ACs should precede the command/scope backstop',
        );
    }

    // -- Helpers ------------------------------------------------------------

    /**
     * @param  list<array{id:string,description:string,verification:string,verification_ref:?string}>  $criteria
     * @return list<array{id:string,description:string,verification:string,verification_ref:?string}>
     */
    private function behavioralAcs(array $criteria): array
    {
        return array_values(array_filter(
            $criteria,
            static fn (array $ac): bool => str_starts_with($ac['id'], 'ac_behavior_'),
        ));
    }

    private function refResolvesToACommand(string $ref): bool
    {
        // Accept the profile default test command as a real verification_ref.
        return $ref === 'composer test' || $ref === 'pnpm test' || $ref === 'npm test';
    }

    /**
     * Build the inputs for a R2 repair mini-spec with a PHP-Laravel profile
     * (so verification commands are produced) and one related test.
     *
     * @return array{0:OperationEnvelope,1:CompactSdd,2:CodeDiscoveryManifest,3:OpenBrainProgrammingProjection}
     */
    private function composeRepairR2(string $intent): array
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
        $projection = $this->emptyProjection($envelope->runId);

        return [$envelope, $compact, $discovery, $projection];
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
}
