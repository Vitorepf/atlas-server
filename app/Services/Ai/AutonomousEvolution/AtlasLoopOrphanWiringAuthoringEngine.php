<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §5.6 · ORPHAN-WIRING execution — the §9 AUTHORING engine (the model-bound boundary, made explicit + testable).
 *
 * Authoring the wired-behavior test + the wiring for a specific orphan is the frontier engine's job (writer ≠
 * judge): it needs to understand the orphan's contract and pick a real production call-site. So the PROVIDER
 * call lives behind an injected completion seam — a fixture passes a double (ZERO spend, fully deterministic
 * parse/apply test), and prod wraps the loop's provider router. The cert (earned-RED + Guard 4e neutralization)
 * is AUTHOR-BLIND, so a weak authored wiring is simply rejected, never certified — the engine can be empirical
 * while the gate stays honest.
 *
 * This class owns the DETERMINISTIC half end-to-end: the prompt, the strict response parse, and the two apply
 * callables the executor runs (test first on the pre-wiring tree, then the wiring). The provider's authoring
 * QUALITY is the only empirical part, and it is fenced behind {@see liveCompletion} (an honest null no-op until
 * the router wiring lands — the route then degrades to no_winner, never a fabricated cert).
 */
class AtlasLoopOrphanWiringAuthoringEngine
{
    /** @var (callable(string,string): ?string)|null  (provider, prompt) -> raw response text */
    private $complete;

    /** @param (callable(string,string): ?string)|null $complete */
    public function __construct(?callable $complete = null)
    {
        $this->complete = $complete;
    }

    /**
     * @param  array<string,mixed>  $payload  the orphan-wiring directive (orphan_path, orphan_fqcn, public_methods, sibling_test)
     * @return array{author_test: callable, author_wiring: callable, meta: array<string,mixed>}|null
     */
    public function author(array $payload, string $workspace): ?array
    {
        $fqcn = trim((string) ($payload['orphan_fqcn'] ?? ''));
        $orphanRel = trim((string) ($payload['orphan_path'] ?? ''));
        $methods = array_values(array_filter((array) ($payload['public_methods'] ?? []), 'is_string'));
        if ($fqcn === '' || $orphanRel === '') {
            return null;
        }

        $provider = trim((string) config('atlas.loop.default_provider', ''));
        $prompt = $this->buildPrompt($fqcn, $orphanRel, $methods, (string) ($payload['sibling_test'] ?? ''));
        $complete = $this->complete ?? fn (string $p, string $pr): ?string => $this->liveCompletion($p, $pr);

        $response = $complete($provider, $prompt);
        if (! is_string($response) || trim($response) === '') {
            return null; // no authoring available => honest null (route -> no_winner, never a fabricated cert)
        }

        $parsed = $this->parse($response);
        if ($parsed === null) {
            return null;
        }

        return [
            'author_test' => function () use ($workspace, $parsed): array {
                $this->writeFile($workspace, $parsed['test_rel'], $parsed['test_content']);

                return ['test_rel' => $parsed['test_rel'], 'test_command' => $parsed['test_command'], 'allowed_globs' => $parsed['allowed_globs']];
            },
            'author_wiring' => function () use ($workspace, $parsed): void {
                $this->writeFile($workspace, $parsed['wiring_rel'], $parsed['wiring_content']);
            },
            'meta' => ['test_rel' => $parsed['test_rel'], 'wiring_rel' => $parsed['wiring_rel']],
        ];
    }

    /**
     * The strict, deterministic response contract: the provider MUST emit exactly these marker-delimited fields.
     * A malformed / partial response => null (the route degrades to no_winner). Returns the parsed authoring spec.
     *
     * @return array{test_rel:string, test_command:string, test_content:string, wiring_rel:string, wiring_content:string, allowed_globs:list<string>}|null
     */
    public function parse(string $response): ?array
    {
        $fields = ['TEST_REL', 'TEST_COMMAND', 'TEST_CONTENT', 'WIRING_REL', 'WIRING_CONTENT'];
        $out = [];
        foreach ($fields as $i => $field) {
            $open = '<<<'.$field.'>>>';
            $start = strpos($response, $open);
            if ($start === false) {
                return null;
            }
            $start += strlen($open);
            $closeMarker = '<<<'.($fields[$i + 1] ?? 'END').'>>>';
            $end = strpos($response, $closeMarker, $start);
            if ($end === false) {
                return null;
            }
            $out[$field] = trim(substr($response, $start, $end - $start));
        }

        $testRel = ltrim($out['TEST_REL'], '/');
        $wiringRel = ltrim($out['WIRING_REL'], '/');
        if ($testRel === '' || $out['TEST_CONTENT'] === '' || $wiringRel === '' || $out['WIRING_CONTENT'] === '') {
            return null;
        }
        // FENCE: the engine may only author a test under tests/ and wire a production file under app/ — never
        // touch the judge/cert organs or escape the scope. A violating response is rejected (null).
        if (! str_starts_with($testRel, 'tests/') || ! str_starts_with($wiringRel, 'app/')) {
            return null;
        }

        return [
            'test_rel' => $testRel,
            'test_command' => $out['TEST_COMMAND'] !== '' ? $out['TEST_COMMAND'] : 'php '.$testRel,
            'test_content' => $out['TEST_CONTENT'],
            'wiring_rel' => $wiringRel,
            'wiring_content' => $out['WIRING_CONTENT'],
            'allowed_globs' => ['app/**'],
        ];
    }

    /**
     * @param  list<string>  $methods
     */
    private function buildPrompt(string $fqcn, string $orphanRel, array $methods, string $siblingTest): string
    {
        $methodList = $methods === [] ? '(none public)' : implode(', ', $methods);

        return <<<PROMPT
            You are wiring a built-but-unused capability into the codebase. The class {$fqcn} (file {$orphanRel},
            public methods: {$methodList}) is fully implemented and unit-tested ({$siblingTest}) but has ZERO
            production callers. Wire it into a REAL production call path so its behavior is actually used.

            Output EXACTLY this marker format and nothing else:
            <<<TEST_REL>>>
            tests/Feature/Loop/Wiring/<Name>Test.php
            <<<TEST_COMMAND>>>
            ./vendor/bin/phpunit <that path>
            <<<TEST_CONTENT>>>
            <?php  // a test that FAILS on the current tree and PASSES once the wiring exists; it must exercise
                   // {$fqcn}'s behavior through the production call-site (not by instantiating it directly).
            <<<WIRING_REL>>>
            app/<the production file you edit to call {$fqcn}>
            <<<WIRING_CONTENT>>>
            <?php  // the FULL new contents of that production file, now invoking {$fqcn}.
            <<<END>>>
            PROMPT;
    }

    private function writeFile(string $workspace, string $rel, string $content): void
    {
        $abs = rtrim($workspace, '/').'/'.ltrim($rel, '/');
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($abs, $content);
    }

    /**
     * The live provider call — the ONLY empirical piece. Wrapping the loop's provider router with the exact
     * prompt-array contract is the final wiring step; until it lands this returns null (honest no-op: the route
     * degrades to no_winner, never a fabricated cert). A configured provider double is injected for tests.
     */
    private function liveCompletion(string $provider, string $prompt): ?string
    {
        return null;
    }
}
