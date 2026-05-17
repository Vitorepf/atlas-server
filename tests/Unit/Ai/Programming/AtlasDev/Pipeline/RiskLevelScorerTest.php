<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassification;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use PHPUnit\Framework\TestCase;

final class RiskLevelScorerTest extends TestCase
{
    public function test_question_is_r0(): void
    {
        $this->assertSame(RiskLevelScorer::R0, $this->score(
            intent: 'explique como funciona o classifier',
            classification: $this->classify('explique como funciona o classifier'),
        ));
    }

    public function test_read_only_question_about_risky_domain_is_r0(): void
    {
        $intent = 'Responda usando o codigo: quantos dias de grace period BillingPolicy usa?';

        $this->assertSame(RiskLevelScorer::R0, $this->score(
            intent: $intent,
            classification: $this->classify($intent),
        ));
    }

    public function test_typo_in_docs_is_r1(): void
    {
        $this->assertSame(RiskLevelScorer::R1, $this->score(
            intent: 'corrija typo em docs/README.md',
            classification: $this->classify('corrija typo em docs/README.md'),
        ));
    }

    public function test_patch_one_file_is_r2(): void
    {
        $envelope = $this->envelope('corrija bug em app/Foo.php');
        $discovery = $this->discoveryWith(['/ws/app/Foo.php']);
        $this->assertSame(RiskLevelScorer::R2, (new RiskLevelScorer)->score(
            $envelope,
            $this->classify('corrija bug em app/Foo.php'),
            $discovery,
        ));
    }

    public function test_three_files_raise_to_r3(): void
    {
        $envelope = $this->envelope('refactor service em app/Foo.php');
        $discovery = $this->discoveryWith([
            '/ws/app/Services/A.php',
            '/ws/app/Services/B.php',
            '/ws/app/Services/C.php',
        ]);
        $this->assertSame(RiskLevelScorer::R3, (new RiskLevelScorer)->score(
            $envelope,
            $this->classify('refactor service em app/Foo.php'),
            $discovery,
        ));
    }

    public function test_six_files_or_three_layers_raise_to_r4(): void
    {
        $envelope = $this->envelope('ajuste cobertura em app/Foo.php e tests');
        $discovery = $this->discoveryWith([
            '/ws/app/Models/Foo.php',
            '/ws/app/Http/Controllers/FooController.php',
            '/ws/atlas-desktop/apps/desktop/src/foo.tsx',
            '/ws/app/Services/Foo.php',
        ]);
        // 4 buckets touched (db, api, ui, service) → R4
        $this->assertSame(RiskLevelScorer::R4, (new RiskLevelScorer)->score(
            $envelope,
            $this->classify('ajuste cobertura em app/Foo.php e tests'),
            $discovery,
        ));
    }

    public function test_risky_keyword_is_r4(): void
    {
        $this->assertSame(RiskLevelScorer::R4, $this->score(
            intent: 'mexer no fluxo de billing em app/Services/Payment.php',
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_RISKY,
                intentClarityLevel: IntakeNormalizer::CLARITY_LOW,
                matchedRules: ['risky:billing'],
                writeImplied: false,
            ),
        ));
    }

    public function test_risky_plus_multiagent_is_r5(): void
    {
        $this->assertSame(RiskLevelScorer::R5, $this->score(
            intent: 'orchestrar multi-agent replay no fluxo de billing',
            classification: new TaskClassification(
                taskKind: TaskClassification::KIND_RISKY,
                intentClarityLevel: IntakeNormalizer::CLARITY_LOW,
                matchedRules: ['risky:billing'],
                writeImplied: false,
            ),
        ));
    }

    private function score(string $intent, TaskClassification $classification): string
    {
        return (new RiskLevelScorer)->score($this->envelope($intent), $classification);
    }

    private function classify(string $intent): TaskClassification
    {
        return (new TaskClassifier)->classify($this->envelope($intent));
    }

    private function envelope(string $intent): OperationEnvelope
    {
        return new OperationEnvelope(
            runId: 'dev-test',
            surfaceId: 'atlas_cli_dev',
            surfaceContext: new SurfaceContext(productSurface: 'atlas_cli_dev'),
            workspace: '/ws',
            workspaceHash: hash('sha256', '/ws'),
            gitState: new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0),
            rawIntent: $intent,
            normalizedIntent: trim($intent),
            userConstraints: [],
            intentClarityLevel: IntakeNormalizer::CLARITY_MEDIUM,
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

    /**
     * @param  list<string>  $paths
     */
    private function discoveryWith(array $paths): CodeDiscoveryManifest
    {
        $likely = array_map(
            static fn (string $path): CodeCandidate => new CodeCandidate(
                path: $path,
                reason: 'fixture',
                confidence: 0.9,
                symbols: [],
            ),
            $paths,
        );

        return new CodeDiscoveryManifest(
            runId: 'dev-test',
            likelyFiles: $likely,
            relatedSymbols: [],
            relatedTests: [],
            relatedCommands: [],
            confidence: count($paths) >= 2
                ? CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT
                : CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE,
            missingRefs: [],
            forbiddenFiles: [],
            providerSafe: true,
            manifestHash: '',
        );
    }
}
