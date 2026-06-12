<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use PHPUnit\Framework\TestCase;

/**
 * S50 / L3-8 — "single-source of execution" anti-refragmentation INVARIANT.
 *
 * Atlas's recurring failure mode is MULTIPLE parallel execution stacks that each
 * resolve and shell out to a provider CLI (codex / cursor / hermes / claude /
 * minimax / gemini) DIRECTLY, instead of routing through the one sanctioned
 * meta-provider spine: {@see \App\Services\Ai\AiProviderManager} → the four
 * `*CliProvider` drivers → their shared process primitive
 * ({@see \App\Services\Ai\Concerns\RunsCliProcesses}) / the governed Forge runner
 * ({@see \App\Services\Ai\Programming\AtlasForgeProviderProcessRunner}). Every new
 * direct resolver+spawner re-fragments execution and silently bypasses Atlas
 * governance (routing, cost sentinel, compression, evidence, cache decoration).
 *
 * This test is the RATCHET. It statically scans `app/Services/Ai` for files that
 * BOTH (a) resolve a provider CLI binary in the canonical shape (`?? 'codex'`,
 * `providers.<x>.binary`, `providerConfig('<x>_cli')[...binary]`, or
 * `resolveBinary()` paired with a real `codex exec`) AND (b) spawn an OS process
 * — the precise fingerprint of a direct provider invocation — and asserts that
 * set is a SUBSET of an explicitly curated allow-list of spine / probe / tool
 * files. A NEW direct provider-CLI caller makes this test go RED.
 *
 * Honesty contract (anti-over-claim): the allow-list documents the codebase as it
 * ACTUALLY is. Files that resolve+spawn for legitimate non-inference reasons
 * (capability probes, `--version` readiness checks, Hermes' own kanban/mesh tool
 * surfaces) are listed and labelled as such — they are not pretended-absent. The
 * one genuine inference fragmenter still on the books carries a `// TODO(S50)`
 * with the reason it has not yet been migrated onto the spine. The invariant's
 * value is "no NEW ones"; migrating one shrinks the list by one.
 *
 * Modeled on the kernel runtime-boundary scanner allow-list pattern and the
 * existing single-source contract {@see
 * \Tests\Unit\Ai\Programming\AtlasDev\WorkspaceMutatingProvidersContractTest}.
 */
final class AtlasExecutionSpineSingleSourceTest extends TestCase
{
    /**
     * Repo-root-relative paths PERMITTED to resolve+spawn a provider CLI. Each
     * entry is curated and commented with its ROLE. Adding a file here is a
     * deliberate governance decision, never an accident.
     *
     * @var array<string,string> path => role label
     */
    private const SPINE_ALLOWLIST = [
        // --- SPINE: governed provider invocation (sanctioned). ---
        // Atlas Dev's claude gateway — spawns `claude --print` behind an explicit
        // binary allow-list guard. The governed claude arm of the spine.
        'app/Services/Ai/Programming/AtlasDev/Provider/SymfonyClaudeCliGateway.php' => 'spine: governed claude gateway (binary-guarded)',
        // Forge governed cursor driver — builds `cursor-agent --print` argv and
        // runs it via the governed AtlasForgeProviderProcessRunner.
        'app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php' => 'spine: Forge governed cursor driver',
        // Forge governed hermes driver — delegates inference to HermesCliProvider
        // ($provider->run); its direct spawn is the fallback availability path.
        'app/Services/Ai/Programming/AtlasForgeHermesCliInvocationDriver.php' => 'spine: Forge governed hermes driver',

        // --- PROBES: availability checks, NOT model inference. ---
        // `hermes chat --help` / `--version` capability manifest.
        'app/Services/Ai/Hermes/HermesCapabilityProbe.php' => 'probe: hermes capability/version',
        // `<binary> --version` readiness gate for Atlas Dev.
        'app/Services/Ai/Programming/AtlasDev/Runtime/AtlasDevReadinessService.php' => 'probe: atlas-dev binary --version',

        // --- HERMES-NATIVE TOOL SURFACES: not Atlas-routed model inference. ---
        // `hermes kanban` board CLI — task-board tooling, not an inference call.
        'app/Services/Ai/Hermes/Kanban/HermesKanbanProcessCli.php' => 'tool: hermes kanban board',
        // `hermes mesh dispatch` — Hermes' OWN internal multi-agent swarm, a
        // distinct product surface (not Atlas routing a model through the manager).
        'app/Services/Ai/Hermes/Mesh/HermesMeshProcessWorkerFactory.php' => 'tool: hermes mesh swarm',

        // --- KNOWN FRAGMENTED INFERENCE CALLER (documented, not hidden). ---
        // TODO(S50): AtlasCodexPlannerService spawns `codex exec --sandbox
        // read-only --ephemeral` DIRECTLY to pre-plan before MiniMax, bypassing
        // AiProviderManager. Self-contained planning helper, no code-graph
        // consumers, bespoke flag set. Should be migrated onto the spine
        // (CodexCliProvider via the manager) but only with behaviour-equivalence
        // proof. Listed so the RATCHET holds (no NEW fragmentation) while this
        // remains visible as debt.
        'app/Services/Ai/Programming/AtlasDev/MinimaxFirst/AtlasCodexPlannerService.php' => 'TODO(S50): direct codex-exec planner — migrate onto spine',
    ];

    /**
     * Subtrees excluded from the scan: other in-flight work owns them, and the
     * task scope explicitly forbids touching them. Documented for honesty.
     *
     * @var list<string>
     */
    private const SCAN_EXCLUDED_SUBTREES = [
        'app/Services/Ai/AutonomousEvolution/',
        'app/Services/Ai/Cognition/',
        'app/Services/Ai/Cognitive/Harness/',
        'app/Services/Ai/OperatorIntelligence/',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/',
    ];

    public function test_provider_cli_invocation_only_happens_inside_the_sanctioned_spine(): void
    {
        $root = \dirname(__DIR__, 3);
        $scanRoot = $root.'/app/Services/Ai';
        $this->assertDirectoryExists($scanRoot, 'app/Services/Ai must exist to scan the execution spine');

        $offenders = [];

        foreach ($this->phpFiles($scanRoot) as $absPath) {
            $rel = ltrim(str_replace($root, '', $absPath), '/');

            if ($this->isExcludedFromScan($rel)) {
                continue;
            }

            $src = (string) file_get_contents($absPath);

            if (! $this->resolvesAndSpawnsProviderCli($src)) {
                continue;
            }

            if (! array_key_exists($rel, self::SPINE_ALLOWLIST)) {
                $offenders[] = $rel;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "Execution-spine single-source INVARIANT violated. The following file(s) resolve+spawn a ".
            "provider CLI (codex/cursor/hermes/claude/minimax/gemini) OUTSIDE the sanctioned spine:\n  - ".
            implode("\n  - ", $offenders).
            "\n\nRoute provider invocation through App\\Services\\Ai\\AiProviderManager (the meta-provider), ".
            "NOT a fresh new Process/proc_open that resolves a provider binary. If the file is genuinely ".
            "part of the spine / a probe / a non-inference tool surface, add it to ".
            "AtlasExecutionSpineSingleSourceTest::SPINE_ALLOWLIST with a comment explaining why."
        );
    }

    /**
     * The allow-list must stay honest: every entry must still exist AND still
     * resolve+spawn a provider CLI, otherwise it is stale and must be removed so
     * the ratchet keeps biting (e.g. once the TODO caller is migrated).
     */
    public function test_allowlist_has_no_stale_entries(): void
    {
        $root = \dirname(__DIR__, 3);
        $stale = [];

        foreach (array_keys(self::SPINE_ALLOWLIST) as $rel) {
            $abs = $root.'/'.$rel;
            if (! is_file($abs)) {
                $stale[] = $rel.' (file missing)';

                continue;
            }
            if (! $this->resolvesAndSpawnsProviderCli((string) file_get_contents($abs))) {
                $stale[] = $rel.' (no longer resolves+spawns a provider CLI — remove from allow-list)';
            }
        }

        $this->assertSame([], $stale, "Stale execution-spine allow-list entries:\n  - ".implode("\n  - ", $stale));
    }

    /**
     * The canonical meta-provider spine entry point must exist — the thing every
     * sanctioned caller routes through. If this class is gone/renamed the whole
     * invariant is meaningless, so assert it explicitly.
     */
    public function test_meta_provider_spine_entry_point_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Services\Ai\AiProviderManager::class),
            'AiProviderManager is the single sanctioned execution spine entry point'
        );
    }

    /**
     * Detector: a file is a direct provider-CLI caller when it BOTH spawns an OS
     * process AND resolves a provider binary in the canonical shape.
     */
    private function resolvesAndSpawnsProviderCli(string $src): bool
    {
        $spawnsProcess = (bool) preg_match(
            '/\bnew\s+Process\s*\(|\bproc_open\s*\(|Process::fromShellCommandline\s*\(/',
            $src
        );
        if (! $spawnsProcess) {
            return false;
        }

        // Canonical provider-binary resolution shapes.
        $resolvesBinary = (bool) preg_match(
            "/\\?\\?\\s*['\"](codex|hermes|claude|gemini|minimax|cursor-agent)['\"]"
            ."|providers\\.(codex|cursor|hermes|claude|minimax|gemini)(_cli)?\\.binary"
            ."|providerConfig\\(\\s*['\"](codex|cursor|hermes|claude|minimax|gemini)_cli['\"]/",
            $src
        );

        // The bespoke planner shape: resolveBinary() paired with `codex exec`.
        $plannerShape = (bool) preg_match('/resolveBinary\s*\(\s*\)/', $src)
            && (bool) preg_match("/['\"]exec['\"]/", $src);

        return $resolvesBinary || $plannerShape;
    }

    private function isExcludedFromScan(string $rel): bool
    {
        foreach (self::SCAN_EXCLUDED_SUBTREES as $sub) {
            if (str_starts_with($rel, $sub)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string> absolute paths to *.php files under $dir
     */
    private function phpFiles(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
