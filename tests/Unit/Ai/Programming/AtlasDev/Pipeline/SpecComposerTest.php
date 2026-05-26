<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use PHPUnit\Framework\TestCase;

final class SpecComposerTest extends TestCase
{
    public function test_compact_sdd_for_question_picks_read_only_mode_and_low_budget(): void
    {
        $composer = new SpecComposer;
        $compact = $composer->composeCompactSdd(
            envelope: $this->envelope('explique fluxo'),
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_QUESTION,
                intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
                matchedRules: ['question:explique '],
                writeImplied: false,
            ),
            riskLevel: RiskLevelScorer::R0,
        );

        $this->assertSame('read_only', $compact->mode);
        $this->assertSame(6000, $compact->contextBudget->maxChars);
        $this->assertSame(0, $compact->contextBudget->maxRepairAttempts);
        $this->assertNotSame('', $compact->compactSddHash);
    }

    public function test_compact_sdd_for_repair_r2_uses_php_laravel_profile(): void
    {
        $composer = new SpecComposer;
        $compact = $composer->composeCompactSdd(
            envelope: $this->envelope('corrija o teste falhando em tests/Unit/FooTest.php'),
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_REPAIR,
                intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
                matchedRules: ['repair:corrija'],
                writeImplied: true,
            ),
            riskLevel: RiskLevelScorer::R2,
        );

        $this->assertSame('repair', $compact->mode);
        $this->assertSame(SpecComposer::PROFILE_PHP_LARAVEL, $compact->verificationProfile);
        $this->assertContains('sdd', $compact->docTiersRequired);
    }

    public function test_desktop_surface_uses_workspace_composer_json_before_surface_fallback(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-profile-'.bin2hex(random_bytes(4));
        mkdir($workspace, 0o755, true);
        file_put_contents($workspace.'/composer.json', '{"scripts":{"test":"phpunit"}}');

        $composer = new SpecComposer;
        $compact = $composer->composeCompactSdd(
            envelope: $this->envelope(
                'corrija o teste falhando em tests/Unit/FooTest.php',
                surfaceId: 'atlas_desktop_ai',
                workspace: $workspace,
            ),
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_REPAIR,
                intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
                matchedRules: ['repair:corrija'],
                writeImplied: true,
            ),
            riskLevel: RiskLevelScorer::R2,
        );

        $this->assertSame(SpecComposer::PROFILE_PHP_LARAVEL, $compact->verificationProfile);
    }

    public function test_compact_sdd_for_r4_forces_escalate_preview(): void
    {
        $composer = new SpecComposer;
        $compact = $composer->composeCompactSdd(
            envelope: $this->envelope('mexer no fluxo de billing em production'),
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_RISKY,
                intentClarityLevel: IntakeNormalizer::CLARITY_LOW,
                matchedRules: ['risky:billing'],
                writeImplied: false,
            ),
            riskLevel: RiskLevelScorer::R4,
        );

        $this->assertSame('escalate_preview', $compact->mode);
        $this->assertSame(0, $compact->contextBudget->maxProviderCalls);
        $this->assertContains('forge', $compact->docTiersRequired);
        $this->assertContains('risk_level_at_or_above_r4', $compact->escalationTriggers);
    }

    public function test_mini_spec_for_repair_populates_acceptance_and_validation_commands(): void
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope('corrija o teste falhando em tests/Unit/FooTest.php');
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
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $projection);

        $this->assertNotEmpty($miniSpec->acceptanceCriteria);
        $this->assertNotEmpty($miniSpec->verificationPlan->commands);
        $this->assertNotEmpty($miniSpec->allowedFiles);
        $this->assertNotEmpty($miniSpec->forbiddenFiles);
        $this->assertNotEmpty($miniSpec->nonGoals);
        $this->assertStringContainsString('composer test -- --filter=FooTest', $miniSpec->verificationPlan->commands[0]);
        $this->assertNotSame('', $miniSpec->miniSpecHash);
    }

    public function test_mini_spec_preserves_explicit_validation_command_constraint(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-spec-validation-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/src', 0o755, true);
        file_put_contents($workspace.'/package.json', '{"scripts":{"test":"node tests/button-style.test.js"}}');
        file_put_contents($workspace.'/src/Button.css', '.primary-button { color: #fff; }');

        try {
            $composer = new SpecComposer;
            $envelope = $this->envelope(
                intent: 'update src/Button.css so .primary-button uses background #2563eb',
                surfaceId: 'atlas_desktop_ai',
                workspace: $workspace,
                userConstraints: [
                    'allowed_files=src/Button.css',
                    'validation_command=npm test',
                ],
            );
            $classification = new TaskClassification(
                taskKind: TaskClassification::KIND_FRONTEND,
                intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
                matchedRules: ['frontend:css'],
                writeImplied: true,
            );
            $compact = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R3);
            $discovery = new CodeDiscoveryManifest(
                runId: $envelope->runId,
                likelyFiles: [
                    new CodeCandidate(path: $workspace.'/src/Button.css', reason: 'path mentioned in intent', confidence: 0.95, symbols: []),
                ],
                relatedSymbols: [],
                relatedTests: [],
                relatedCommands: [],
                confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
                missingRefs: [],
                forbiddenFiles: [],
                providerSafe: true,
                manifestHash: 'h',
            );

            $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $this->emptyProjection($envelope->runId));
        } finally {
            @unlink($workspace.'/src/Button.css');
            @unlink($workspace.'/package.json');
            @rmdir($workspace.'/src');
            @rmdir($workspace);
        }

        $this->assertSame(['src/Button.css'], $miniSpec->allowedFiles);
        $this->assertSame(['npm test'], $miniSpec->verificationPlan->commands);
    }

    public function test_task_contract_for_r2_repair_locks_provider_and_caps_max_files(): void
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope('corrija o teste falhando em tests/Unit/FooTest.php');
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
            relatedTests: [],
            relatedCommands: [],
            confidence: CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: 'h',
        );
        $miniSpec = $composer->composeMiniSpec($envelope, $compact, $discovery, $this->emptyProjection($envelope->runId));
        $contract = $composer->composeTaskContract($envelope, $compact, $miniSpec);

        $this->assertSame('claude_cli', $contract->providerLock->provider);
        $this->assertSame('sonnet', $contract->providerLock->modelFamily);
        $this->assertFalse($contract->providerLock->fallbackAllowed);
        $this->assertSame(2, $contract->maxFilesChanged);
        $this->assertContains('run_test', $contract->allowedTools);
        $this->assertNotEmpty($contract->validationCommands);
        $this->assertNotEmpty($contract->evidenceRequired);
        $this->assertSame($miniSpec->miniSpecHash, $contract->specHash);
        $this->assertNotSame('', $contract->taskContractHash);
    }

    public function test_compose_compact_sdd_is_idempotent(): void
    {
        $composer = new SpecComposer;
        $envelope = $this->envelope('corrija o teste falhando em tests/Unit/FooTest.php');
        $classification = new TaskClassification(
            taskKind: TaskClassification::KIND_REPAIR,
            intentClarityLevel: IntakeNormalizer::CLARITY_HIGH,
            matchedRules: ['repair:corrija'],
            writeImplied: true,
        );

        $a = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R2);
        $b = $composer->composeCompactSdd($envelope, $classification, RiskLevelScorer::R2);

        $this->assertSame($a->toJson(), $b->toJson());
    }

    private function envelope(
        string $intent,
        string $surfaceId = 'atlas_cli_dev',
        string $workspace = '/ws',
        array $userConstraints = [],
    ): OperationEnvelope {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: $surfaceId,
            surfaceContext: new SurfaceContext(productSurface: $surfaceId),
            workspace: $workspace,
            workspaceHash: hash('sha256', $workspace),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: array_values($userConstraints),
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
