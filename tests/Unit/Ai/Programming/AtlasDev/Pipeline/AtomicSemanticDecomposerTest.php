<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicSemanticDecomposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtomicStep;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use PHPUnit\Framework\TestCase;

final class AtomicSemanticDecomposerTest extends TestCase
{
    public function test_large_multi_service_slice_decomposes_into_atomic_r3_or_lower_steps(): void
    {
        // A large slice: 12 implementation files across service/db/api/ui +
        // tests. Scored as a single unit this is R4+ (>=3 real layers, >=6
        // files). It must decompose into ordered atomic single-layer steps.
        $candidateFiles = [
            'app/Services/Ai/Foundry/HarvesterService.php',
            'app/Services/Ai/Foundry/VerifierService.php',
            'app/Services/Ai/Foundry/GeneratorService.php',
            'app/Services/Ai/Foundry/RarityGateService.php',
            'app/Services/Ai/Foundry/PromotionService.php',
            'app/Services/Ai/Foundry/ConsolidateService.php',
            'app/Models/FoundryProposal.php',
            'database/migrations/2026_05_30_000000_create_foundry_proposals.php',
            'app/Http/Controllers/Foundry/FoundryController.php',
            'routes/foundry.php',
            'atlas-desktop/apps/desktop/src/foundry/FoundryPanel.tsx',
            'resources/js/foundry.js',
        ];
        $testFiles = [
            'tests/Unit/Ai/Foundry/HarvesterServiceTest.php',
            'tests/Unit/Ai/Foundry/GeneratorServiceTest.php',
        ];

        $steps = (new AtomicSemanticDecomposer)->decompose(
            'Build the Foundry proposal harvester end to end across service, model, controller and panel.',
            $candidateFiles,
            $testFiles,
        );

        // The slice fans out into multiple ordered steps.
        $this->assertGreaterThanOrEqual(5, count($steps));

        // Ordering is contiguous from 0 and the first step is a contract.
        foreach ($steps as $i => $step) {
            $this->assertSame($i, $step->order);
            $this->assertContains($step->kind, AtomicStep::KINDS);
            $this->assertNotSame([], $step->allowedFiles);
            $this->assertNotSame([], $step->validation);
            $this->assertNotSame([], $step->acceptance);
        }
        $this->assertSame(AtomicStep::KIND_CONTRACT, $steps[0]->kind);

        // No file is dropped or duplicated across steps.
        $emitted = [];
        foreach ($steps as $step) {
            foreach ($step->allowedFiles as $file) {
                $emitted[] = $file;
            }
        }
        sort($emitted);
        $expected = array_merge($candidateFiles, $testFiles);
        sort($expected);
        $this->assertSame($expected, $emitted);

        // The load-bearing invariant: re-scoring EVERY emitted step through
        // RiskLevelScorer keeps it <= R3.
        $scorer = new RiskLevelScorer;
        foreach ($steps as $step) {
            $this->assertLessThanOrEqual(
                self::MAX_FILES_PER_STEP_FROM_DECOMPOSER(),
                count($step->allowedFiles),
                "step {$step->order} exceeds the per-step file cap",
            );

            $envelope = $this->envelope($step->intent, $step->userConstraints());
            $discovery = $this->discoveryWith($this->absolutize($step->allowedFiles));
            $level = $scorer->score($envelope, (new TaskClassifier)->classify($envelope), $discovery);

            $this->assertTrue(
                $this->rank($level) <= $this->rank(RiskLevelScorer::R3),
                "step {$step->order} ({$step->kind}) scored {$level}, expected <= R3",
            );
        }
    }

    private static function MAX_FILES_PER_STEP_FROM_DECOMPOSER(): int
    {
        return AtomicSemanticDecomposer::MAX_FILES_PER_STEP;
    }

    private function rank(string $level): int
    {
        return (int) array_search($level, RiskLevelScorer::LEVELS, true);
    }

    /**
     * @param  list<string>  $relative
     * @return list<string>
     */
    private function absolutize(array $relative): array
    {
        return array_map(static fn (string $p): string => '/ws/'.$p, $relative);
    }

    /**
     * @param  list<string>  $userConstraints
     */
    private function envelope(string $intent, array $userConstraints = []): OperationEnvelope
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
            userConstraints: $userConstraints,
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
