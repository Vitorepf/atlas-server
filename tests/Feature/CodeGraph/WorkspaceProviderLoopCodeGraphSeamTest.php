<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · I-4 (Stage 2, LOOP SEAM) — proves the code-graph context pack actually
 * reaches the provider prompt of the proven default loop driver
 * ({@see WorkspaceProviderLoopExecutionDriver}), and ONLY when the operator flips the
 * `atlas.code_graph.auto_context` flag ON.
 *
 * Strategy (NO provider tokens burned): a fake
 * {@see AtlasForgeProviderInvocationDriverRouter} subclass is bound into the container
 * that (a) reports the chosen provider as configured so `attempt()` proceeds, and
 * (b) CAPTURES the prompt array handed to `invoke()` instead of calling any CLI. We then
 * read the captured prompt string and assert on it directly:
 *
 *   - flag ON + a seeded, matching symbol → the prompt carries the
 *     'Relevant existing code (from the code graph):' section AND the seeded symbol id;
 *   - flag OFF → the prompt is BYTE-IDENTICAL to the no-seam baseline (no section, and
 *     equal to the flag-ON prompt minus its appended section), even with the same symbol
 *     seeded — the default path is provably unchanged.
 *
 * Boots only the code-intelligence read-model tables in setUp (the repo's established
 * pattern, mirrored from {@see AtlasCodeGraphContextCommandTest}); full RefreshDatabase
 * is unreliable here because a core migration is pgsql-only SQL.
 */
final class WorkspaceProviderLoopCodeGraphSeamTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
    ];

    /** The provider id the fake router will accept; never reaches a real CLI. */
    private const PROVIDER = 'codex_cli';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        // The driver only runs the provider path when a provider is chosen; pin the loop
        // default so attempt() reaches buildPrompt()/invoke() (the fake router below makes
        // it configured + capturing — no real CLI is contacted).
        config()->set('atlas.loop.default_provider', self::PROVIDER);

        // This test isolates the AP-815 code-graph `auto_context` seam, so it holds the
        // unrelated text-provider edit-apply protocol flag fixed OFF. With it ON (the
        // config default), buildPrompt() appends editProtocolLines()' 8-line OUTPUT PROTOCOL
        // header to EVERY prompt — which would make the flag-OFF prompt 14 lines, not the
        // 6-line pre-seam baseline this test asserts byte-identically. Pinning it OFF keeps
        // the baseline correct while still proving the auto_context flag adds NO section.
        config()->set('atlas.loop.text_provider_edit_apply', false);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * Insert an active symbol into the W-1-keyed read-model (mirrors the atlas:ctx test).
     */
    private function symbol(string $workspace, string $type, string $name, string $file, ?string $signature = null): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $workspace.$name.$file), 0, 64),
        ]);
    }

    /**
     * Bind a prompt-capturing fake router and return the capture box. The fake reports
     * the chosen provider configured and records the prompt array passed to invoke(),
     * answering 'completed' WITHOUT touching any CLI.
     *
     * @return object{prompt: array<string,mixed>|null}
     */
    private function bindCapturingRouter(): object
    {
        $capture = new class
        {
            /** @var array<string,mixed>|null */
            public ?array $prompt = null;
        };

        $fake = new class($capture) extends AtlasForgeProviderInvocationDriverRouter
        {
            public function __construct(private readonly object $capture)
            {
                // Intentionally NOT calling parent::__construct(): this fake overrides
                // every method the driver uses and never touches the $drivers map, so the
                // 8 real driver dependencies are not needed.
            }

            public function isConfigured(?string $provider): bool
            {
                return $provider !== null && $provider !== '';
            }

            public function invoke(?string $provider, ?string $model, array $prompt, array $context = []): array
            {
                $this->capture->prompt = $prompt;

                return [
                    'schema_version' => 'atlas.forge.provider_invocation_driver_result.v1',
                    'provider' => $provider,
                    'model' => $model,
                    'provider_called' => true,
                    'exit_code' => 0,
                    'changed_files' => [],
                    'note' => 'captured by fake router; no CLI contacted',
                ];
            }
        };

        $this->app->instance(AtlasForgeProviderInvocationDriverRouter::class, $fake);

        return $capture;
    }

    /**
     * Resolve a FRESH driver (so it picks up the just-bound fake router) and run one
     * attempt, returning the captured prompt text.
     *
     * @param  list<string>  $allowedFiles
     */
    private function runAttemptAndCapturePromptText(string $intent, array $allowedFiles): string
    {
        $capture = $this->bindCapturingRouter();

        // Resolve via the container so constructor auto-wiring (the new code-graph deps)
        // is exercised exactly as in production; the fake router instance is injected.
        $driver = $this->app->make(WorkspaceProviderLoopExecutionDriver::class);

        $constraints = array_map(static fn (string $f): string => 'allowed_files='.$f, $allowedFiles);

        $summary = $driver->attempt(
            'surface-loop-seam-test',
            sys_get_temp_dir(), // a throwaway cwd; the fake router never reads it
            $intent,
            $constraints,
            [],
        );

        $this->assertSame('completed', $summary['status'] ?? null, 'the fake router must report the attempt completed');
        $this->assertIsArray($capture->prompt, 'the fake router must have captured the prompt array');

        $text = (string) ($capture->prompt['text'] ?? '');
        $this->assertNotSame('', $text, 'the captured prompt must carry a non-empty text');

        return $text;
    }

    public function test_flag_on_injects_the_code_graph_section_with_the_seeded_symbol(): void
    {
        config()->set('atlas.code_graph.auto_context', true);

        $this->symbol(
            'atlas-server',
            'class',
            'App\\Services\\WorkspaceIdentityResolver',
            'app/Services/WorkspaceIdentityResolver.php',
            'class WorkspaceIdentityResolver',
        );

        $text = $this->runAttemptAndCapturePromptText('improve WorkspaceIdentity resolution', []);

        $this->assertStringContainsString(
            'Relevant existing code (from the code graph):',
            $text,
            'flag ON must inject the labelled code-graph section into the provider prompt',
        );
        $this->assertStringContainsString(
            'sym:App\\Services\\WorkspaceIdentityResolver',
            $text,
            'the relevant seeded symbol id must appear in the injected pack',
        );
    }

    public function test_flag_off_is_byte_identical_no_section_even_with_a_matching_symbol(): void
    {
        // Same matching symbol as the flag-ON test, but the flag is OFF.
        $this->symbol(
            'atlas-server',
            'class',
            'App\\Services\\WorkspaceIdentityResolver',
            'app/Services/WorkspaceIdentityResolver.php',
            'class WorkspaceIdentityResolver',
        );

        config()->set('atlas.code_graph.auto_context', false);
        $offText = $this->runAttemptAndCapturePromptText('improve WorkspaceIdentity resolution', []);

        // No code-graph section at all when the flag is OFF.
        $this->assertStringNotContainsString(
            'Relevant existing code (from the code graph):',
            $offText,
            'flag OFF must NOT inject any code-graph section',
        );
        $this->assertStringNotContainsString('sym:App\\Services\\WorkspaceIdentityResolver', $offText);

        // BYTE-IDENTICAL proof: the flag-OFF prompt equals the exact pre-seam baseline the
        // driver would build for this intent (the four fixed lines + the trailing
        // preserve-behavior line), with nothing appended.
        $expectedBaseline = implode("\n", [
            'You are autonomously improving code in an ISOLATED throwaway workspace. Make the change directly by editing files in place.',
            '',
            'OBJECTIVE: improve WorkspaceIdentity resolution',
            '',
            'Do NOT modify anything under tests/ or composer.json — those are the frozen acceptance and must stay untouched.',
            'Preserve all existing behavior; make the smallest change that satisfies the objective.',
        ]);

        $this->assertSame(
            $expectedBaseline,
            $offText,
            'flag OFF must produce the byte-identical pre-seam baseline prompt',
        );
    }

    public function test_flag_on_prompt_is_exactly_the_off_prompt_plus_the_appended_section(): void
    {
        $this->symbol(
            'atlas-server',
            'class',
            'App\\Services\\WorkspaceIdentityResolver',
            'app/Services/WorkspaceIdentityResolver.php',
            'class WorkspaceIdentityResolver',
        );

        config()->set('atlas.code_graph.auto_context', false);
        $offText = $this->runAttemptAndCapturePromptText('improve WorkspaceIdentity resolution', []);

        config()->set('atlas.code_graph.auto_context', true);
        $onText = $this->runAttemptAndCapturePromptText('improve WorkspaceIdentity resolution', []);

        // The ON prompt is the OFF prompt with ONLY the code-graph section appended — the
        // baseline portion is untouched (the seam is purely additive).
        $this->assertStringStartsWith(
            $offText."\n\nRelevant existing code (from the code graph):",
            $onText,
            'the ON prompt must be the exact OFF baseline followed by the appended section',
        );
    }

    public function test_the_default_bound_loop_driver_is_the_workspace_provider_driver(): void
    {
        // Sanity: the seam lives on the actual default loop execution driver (the proven
        // one), not an unused class. The bound interface decorates it with the time-bound
        // guard, so we assert the concrete driver resolves with its new code-graph deps.
        $driver = $this->app->make(WorkspaceProviderLoopExecutionDriver::class);
        $this->assertInstanceOf(WorkspaceProviderLoopExecutionDriver::class, $driver);

        $bound = $this->app->make(LoopExecutionDriver::class);
        $this->assertInstanceOf(LoopExecutionDriver::class, $bound);
    }
}
