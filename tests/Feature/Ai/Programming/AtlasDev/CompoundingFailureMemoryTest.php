<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RiskLevelScorer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecisionEngine;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RunIdGenerator;
use App\Services\Ai\Programming\AtlasDev\Pipeline\SpecComposer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\TaskClassifier;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptRenderer;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptSectionsMapper;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsulePromptInjector;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\PromptProjection\PromptProjectionFixtures;

/**
 * M5 — Compounding failure memory.
 *
 * VAL-M5-001 through VAL-M5-006 assertions.
 *
 * The READ path exists (DevRuntimeIntelligenceService wires failure_capsule via
 * DevFailureCapsuleRuntimeService::build/persist). These tests prove the
 * INJECTION of persisted AtlasDevFailureCapsule rows forward as known failure
 * modes into the prompt projection assembled by ProviderPromptBuilder /
 * PromptSectionsMapper / PromptRenderer (the OpenBrainProjectionAdapter /
 * ACDE B1/B3/B4a seam).
 *
 * Area identity = overlap between a run's target files (allowed_files) and the
 * capsule's changed_files. Injection must be area-scoped, provider-safe
 * (secrets redacted), deterministic, deduped on failure_hash; a foreign-area
 * capsule must NEVER inject; no capsule must NEVER fabricate content.
 */
final class CompoundingFailureMemoryTest extends TestCase
{
    use PromptProjectionFixtures;

    private object $migration;

    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php');
        $this->migration->down();
        $this->migration->up();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-m5-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-m5-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Scheduling', 0o755, true);
        mkdir($this->tmpWorkspace.'/tests/Unit', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            '0123456789abcdef0123456789abcdef01234567',
        );
        file_put_contents(
            $this->tmpWorkspace.'/app/Services/Scheduling/ScheduleParser.php',
            "<?php\nclass ScheduleParser {}\n",
        );
        file_put_contents(
            $this->tmpWorkspace.'/tests/Unit/ScheduleParserTest.php',
            "<?php\nclass ScheduleParserTest {}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->migration->down();
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path.DIRECTORY_SEPARATOR.$entry);
        }
        @rmdir($path);
    }

    private function makeBuilder(): ProviderPromptBuilder
    {
        return new ProviderPromptBuilder(
            new PromptSectionsMapper,
            new PromptRenderer,
            new PromptQualityChecker,
        );
    }

    private function persistCapsule(
        array $changedFiles,
        string $failureClass = 'test_failure',
        string $error = 'PHPUnit failed assertion in ScheduleParser area',
        string $suggestedRepair = 'restore the weeks plural null symmetry',
    ): AtlasDevFailureCapsule {
        $packet = (new DevTaskPacketRuntimeService)->persist([
            'run_id' => 'm5-'.bin2hex(random_bytes(2)),
            'task_id' => 'm5-task-'.bin2hex(random_bytes(2)),
            'objective' => 'M5 capsule fixture',
            'risk_band' => 'medium',
            'task_class' => 'feature',
            'suggested_tests' => ['php artisan test tests/Unit/ScheduleParserTest.php'],
            'expected_files' => $changedFiles,
        ]);

        return (new DevFailureCapsuleRuntimeService)->persist([
            'run_id' => $packet->run_id,
            'task_id' => $packet->task_id,
            'failing_gate' => 'verification_gate',
            'failure_class' => $failureClass,
            'error' => $error,
            'changed_files' => $changedFiles,
            'suggested_repair' => $suggestedRepair,
        ], $packet)->fresh();
    }

    /**
     * Helper that builds a projection for the given target area (allowed_files),
     * wiring the injector the same way the orchestrator seam does.
     *
     * @param  list<string>  $targetFiles  the run's allowed_files (its area)
     * @param  list<string>  $forbiddenFiles
     * @return ProviderPromptProjection
     */
    private function buildProjectionForArea(array $targetFiles, array $forbiddenFiles = []): ProviderPromptProjection
    {
        $injector = new DevFailureCapsulePromptInjector;
        $knownFailureModes = $injector->injectFor($targetFiles);

        return $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract([
                'allowed_files' => $targetFiles,
                'forbidden_files' => $forbiddenFiles,
            ]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            knownFailureModes: $knownFailureModes,
        );
    }

    /**
     * VAL-M5-001: Persisted capsule is read and injected into the next run in the same area.
     *
     * Given a AtlasDevFailureCapsule persisted with changed_files = [F], a
     * subsequent run whose area overlaps F surfaces that capsule as a known
     * failure mode in its prompt projection.
     */
    public function test_persisted_capsule_injected_into_next_run_in_same_area(): void
    {
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $capsule = $this->persistCapsule(
            changedFiles: [$target],
            failureClass: 'test_failure',
            suggestedRepair: 'restore the plural-without-every null symmetry for weeks',
            error: 'PHPUnit failed: parseInterval(2 weeks) returned once instead of throwing',
        );

        $projection = $this->buildProjectionForArea([$target]);

        $this->assertStringContainsString(
            $capsule->failure_class,
            $projection->renderedPromptText,
            'VAL-M5-001: capsule failure_class must appear in rendered prompt text',
        );
        $this->assertStringContainsString(
            $capsule->suggested_repair,
            $projection->renderedPromptText,
            'VAL-M5-001: capsule suggested_repair must appear in rendered prompt text',
        );
        $this->assertStringContainsString(
            'Known Failure Modes',
            $projection->renderedPromptText,
            'VAL-M5-001: the Known Failure Modes section heading must be rendered',
        );
    }

    /**
     * VAL-M5-002: Injection is area-scoped to overlapping changed_files (positive scope).
     *
     * A capsule for area F is injected ONLY when the next run's target set
     * overlaps F. The assertion keys on path overlap, not global presence.
     */
    public function test_injection_is_area_scoped_to_overlapping_changed_files(): void
    {
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $siblingInSameArea = 'tests/Unit/ScheduleParserTest.php';

        $capsule = $this->persistCapsule(
            changedFiles: [$target],
            failureClass: 'test_failure',
            suggestedRepair: 'restore the plural-without-every null symmetry for weeks',
        );

        // Overlap: the run targets a file the capsule touched.
        $overlapping = $this->buildProjectionForArea([$target]);
        $this->assertStringContainsString($capsule->failure_class, $overlapping->renderedPromptText);

        // Overlap on the SIBLING file in the capsule's area is also positive
        // scope when the run targets it (the capsule's changed_files contains
        // the production file, but a run touching a sibling test still
        // overlaps area identity only when paths intersect; here the run
        // targets the same production file via the second assertion).
        $alsoOverlapping = $this->buildProjectionForArea([$target, $siblingInSameArea]);
        $this->assertStringContainsString($capsule->failure_class, $alsoOverlapping->renderedPromptText);
    }

    /**
     * VAL-M5-003: ANTI-GAMING — a capsule from a DIFFERENT area is never injected.
     *
     * Given a capsule persisted with changed_files = [A], a run targeting only
     * area [B] (no overlap) MUST NOT receive that capsule.
     */
    public function test_anti_gaming_foreign_area_capsule_is_never_injected(): void
    {
        $foreignArea = 'app/Services/Ai/AutonomousEvolution/AtlasLoopGrinder.php';
        $foreignCapsule = $this->persistCapsule(
            changedFiles: [$foreignArea],
            failureClass: 'architecture_risk',
            suggestedRepair: 'promote to Forge senior review',
            error: 'Forbidden-area error excerpt UNIQUE_TO_FOREIGN_CAPULE_8842',
        );

        // The run targets a completely disjoint area (ScheduleParser).
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $projection = $this->buildProjectionForArea([$target]);

        $this->assertStringNotContainsString(
            $foreignCapsule->failure_class,
            $projection->renderedPromptText,
            'VAL-M5-003: foreign-area failure_class must not leak into an unrelated run',
        );
        $this->assertStringNotContainsString(
            $foreignCapsule->suggested_repair,
            $projection->renderedPromptText,
            'VAL-M5-003: foreign-area suggested_repair must not leak',
        );
        $this->assertStringNotContainsString(
            'UNIQUE_TO_FOREIGN_CAPULE_8842',
            $projection->renderedPromptText,
            'VAL-M5-003: foreign-area error_excerpt must not leak',
        );
    }

    /**
     * VAL-M5-004: ANTI-GAMING — no capsule means no fabricated failure-mode content (honest empty).
     *
     * A run in an area with zero persisted capsules produces a prompt with NO
     * known-failure-mode injection; the baseline prompt is byte-identical to
     * the pre-M5 projection for that input.
     */
    public function test_anti_gaming_no_capsule_produces_no_fabricated_failure_mode_content(): void
    {
        // Confirm the area is empty.
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $this->assertSame(
            0,
            AtlasDevFailureCapsule::query()->count(),
            'No capsules should be persisted for this scenario.',
        );

        // Build the projection with an empty knownFailureModes list (the
        // injector's output when no capsule matches the area).
        $emptyInjector = new DevFailureCapsulePromptInjector;
        $this->assertSame([], $emptyInjector->injectFor([$target]));

        $projectionWithEmpty = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(['allowed_files' => [$target]]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
            knownFailureModes: [],
        );

        // Build the projection WITHOUT passing knownFailureModes at all — the
        // default must be empty so the pre-M5 caller's output is unchanged.
        $projectionPreM5 = $this->makeBuilder()->build(
            envelope: $this->envelope(),
            compactSdd: $this->compactSdd(),
            miniSpec: $this->miniSpec(),
            taskContract: $this->taskContract(['allowed_files' => [$target]]),
            discovery: $this->codeDiscovery(),
            projection: $this->openBrainProjection(),
        );

        $this->assertStringNotContainsString(
            'Known Failure Modes',
            $projectionWithEmpty->renderedPromptText,
            'VAL-M5-004: empty capsule set must not render a Known Failure Modes section',
        );
        $this->assertSame(
            $projectionPreM5->renderedPromptText,
            $projectionWithEmpty->renderedPromptText,
            'VAL-M5-004: empty capsule set must leave the baseline prompt byte-identical (no fabricated content)',
        );
        $this->assertSame(
            $projectionPreM5->renderedPromptHash,
            $projectionWithEmpty->renderedPromptHash,
            'VAL-M5-004: baseline rendered_prompt_hash must be unchanged when no capsule matches',
        );
    }

    /**
     * VAL-M5-005: Injected failure mode carries actionable, provider-safe content.
     *
     * The injected entry exposes behavioral signal (failure_class,
     * suggested_repair, truncated error_excerpt) and remains provider-safe:
     * secret-shaped tokens are redacted as ProviderPromptBuilder enforces.
     */
    public function test_injected_failure_mode_is_actionable_and_provider_safe(): void
    {
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $capsule = $this->persistCapsule(
            changedFiles: [$target],
            failureClass: 'scope_violation',
            suggestedRepair: 'reduce patch to allowed files or escalate to Forge',
            error: 'Forbidden file touched. Bearer credentials leaked: sk-ant-LIVEKEY8842abc in output',
        );

        $projection = $this->buildProjectionForArea([$target]);

        $this->assertStringContainsString(
            'scope_violation',
            $projection->renderedPromptText,
            'VAL-M5-005: injected content must include the actionable failure_class',
        );
        $this->assertStringContainsString(
            'reduce patch to allowed files or escalate to Forge',
            $projection->renderedPromptText,
            'VAL-M5-005: injected content must include the actionable suggested_repair',
        );
        $this->assertStringNotContainsString(
            'sk-ant-LIVEKEY8842abc',
            $projection->renderedPromptText,
            'VAL-M5-005: secret-shaped tokens in error_excerpt must be redacted in the rendered prompt',
        );
    }

    /**
     * VAL-M5-006: Injection is deterministic for the same capsule set (idempotent ordering, deduped).
     *
     * Two builds of the projection for the same area with the same capsule set
     * produce byte-identical injected failure-mode content. Dedup on
     * failure_hash: when a capsule's changed_files spans multiple paths that
     * ALL overlap the run's target set, the capsule still appears EXACTLY ONCE
     * (the injector dedups by failure_hash, never emits one entry per matching
     * path). Determinism: the same capsule set always emits the same order.
     */
    public function test_injection_is_deterministic_and_deduped_on_failure_hash(): void
    {
        $target = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $sibling = 'tests/Unit/ScheduleParserTest.php';

        // A capsule whose changed_files spans multiple paths in the area.
        $first = $this->persistCapsule(
            changedFiles: [$target, $sibling],
            failureClass: 'test_failure',
            suggestedRepair: 'restore plural null symmetry',
        );
        // A second distinct capsule in the same area to verify ordering.
        $second = $this->persistCapsule(
            changedFiles: [$target],
            failureClass: 'type_error',
            suggestedRepair: 'fix phpstan nullability on parseInterval',
        );
        $this->assertNotSame($first->failure_hash, $second->failure_hash);

        $injector = new DevFailureCapsulePromptInjector;

        // Run whose target set overlaps BOTH of the first capsule's paths AND
        // the second capsule's path. The first capsule must still appear only
        // once (deduped on failure_hash, not once per matching path).
        $targetSet = [$target, $sibling];
        $firstSet = $injector->injectFor($targetSet);
        $secondSet = $injector->injectFor($targetSet);

        $this->assertSame(
            $firstSet,
            $secondSet,
            'VAL-M5-006: injector output must be deterministic for the same capsule set',
        );
        $this->assertCount(
            2,
            $firstSet,
            'VAL-M5-006: two distinct failure_hash capsules must produce two entries, and a capsule whose changed_files spans multiple matching paths must appear exactly once (deduped on failure_hash)',
        );

        // Each entry must be unique (no duplicate strings).
        $this->assertSame(
            array_unique($firstSet, SORT_STRING),
            $firstSet,
            'VAL-M5-006: injected entries must be dedup-unique',
        );

        $rebasedProjection = function () use ($targetSet): ProviderPromptProjection {
            $injector = new DevFailureCapsulePromptInjector;

            return $this->makeBuilder()->build(
                envelope: $this->envelope(),
                compactSdd: $this->compactSdd(),
                miniSpec: $this->miniSpec(),
                taskContract: $this->taskContract(['allowed_files' => $targetSet]),
                discovery: $this->codeDiscovery(),
                projection: $this->openBrainProjection(),
                knownFailureModes: $injector->injectFor($targetSet),
            );
        };

        $a = $rebasedProjection();
        $b = $rebasedProjection();

        $this->assertSame(
            $a->renderedPromptText,
            $b->renderedPromptText,
            'VAL-M5-006: two builds of the projection for the same capsule set must be byte-identical',
        );
        $this->assertSame(
            $a->renderedPromptHash,
            $b->renderedPromptHash,
        );
    }

    /**
     * Direct unit-level assertion on the injector: a clean DB in a foreign area
     * returns an empty list (no fabrication).
     */
    public function test_injector_returns_empty_list_when_no_capsule_matches_area(): void
    {
        $this->persistCapsule(
            changedFiles: ['app/Services/AutonomousEvolution/Loop.php'],
            failureClass: 'architecture_risk',
        );

        $injector = new DevFailureCapsulePromptInjector;
        $this->assertSame(
            [],
            $injector->injectFor(['app/Services/Ai/Scheduling/ScheduleParser.php']),
            'Injector must return an empty list when no capsule overlaps the target area.',
        );
    }

    /**
     * VAL-CROSS-001 / manual verification: the DEFAULT AtlasDevFastPathOrchestrator
     * planOnly() path (the seam that produces ProviderPromptProjection for the
     * default hermes_cli run) injects an area-scoped capsule into the rendered
     * prompt with no feature flag and no test-only opt-in. A persisted capsule
     * in the run's area surfaces in the projection's rendered_prompt_text; a
     * foreign-area capsule does not.
     */
    public function test_default_orchestrator_path_injects_area_scoped_capsule_into_rendered_prompt(): void
    {
        $target = 'app/Services/Scheduling/ScheduleParser.php';
        $foreignArea = 'app/Services/AutonomousEvolution/Loop.php';

        $areaCapsule = $this->persistCapsule(
            changedFiles: [$target],
            failureClass: 'test_failure',
            suggestedRepair: 'restore plural null symmetry for weeks',
            error: 'PHPUnit assertion failed: parseInterval(2 weeks) returned once',
        );
        $foreignCapsule = $this->persistCapsule(
            changedFiles: [$foreignArea],
            failureClass: 'architecture_risk',
            suggestedRepair: 'promote to Forge senior review',
            error: 'FOREIGN_AREA_UNIQUE_TOKEN_9931 must never leak',
        );

        $orchestrator = new AtlasDevFastPathOrchestrator(
            intake: new IntakeNormalizer(new RunIdGenerator),
            classifier: new TaskClassifier,
            riskScorer: new RiskLevelScorer,
            specComposer: new SpecComposer,
            tierSelector: new DocContextTierSelector,
            codeDiscovery: new CodeDiscoveryEngine,
            openBrainAdapter: new OpenBrainProjectionAdapter(
                new \Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService,
            ),
            promptBuilder: new ProviderPromptBuilder(
                sectionsMapper: new PromptSectionsMapper,
                renderer: new PromptRenderer,
                qualityChecker: new PromptQualityChecker,
            ),
            routingEngine: new RoutingDecisionEngine,
            receiptStorage: new ReceiptStorage($this->tmpStorage),
            failureCapsuleInjector: new DevFailureCapsulePromptInjector,
        );

        $result = $orchestrator->planOnly(
            surfaceId: 'atlas_cli_dev',
            workspace: $this->tmpWorkspace,
            rawIntent: 'corrija o teste falhando em tests/Unit/ScheduleParserTest.php tocando app/Services/Scheduling/ScheduleParser.php',
        );

        $rendered = $result->promptProjection->renderedPromptText;

        // VAL-M5-001 (positive): area capsule failure_class + suggested_repair appear.
        $this->assertStringContainsString('test_failure', $rendered);
        $this->assertStringContainsString('restore plural null symmetry for weeks', $rendered);
        $this->assertStringContainsString('Known Failure Modes', $rendered);

        // VAL-M5-003 (anti-gaming): foreign-area capsule never leaks.
        $this->assertStringNotContainsString('architecture_risk', $rendered);
        $this->assertStringNotContainsString('promote to Forge senior review', $rendered);
        $this->assertStringNotContainsString('FOREIGN_AREA_UNIQUE_TOKEN_9931', $rendered);
    }
}
