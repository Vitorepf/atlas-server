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
    private const MAX_WIRINGS = 4;

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
        $obligations = $this->architectObligations($payload);

        $provider = trim((string) config('atlas.loop.default_provider', ''));
        $prompt = $this->buildPrompt($fqcn, $orphanRel, $methods, (string) ($payload['sibling_test'] ?? ''), $obligations);
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
                foreach ($parsed['wirings'] as $wiring) {
                    $this->writeFile($workspace, $wiring['rel'], $wiring['content']);
                }
            },
            'meta' => ['test_rel' => $parsed['test_rel'], 'wiring_rel' => $parsed['wiring_rel']],
        ];
    }

    /**
     * The strict, deterministic response contract: the provider MUST emit exactly these marker-delimited fields.
     * A malformed / partial response => null (the route degrades to no_winner). Returns the parsed authoring spec.
     *
     * @return array{test_rel:string, test_command:string, test_content:string, wiring_rel:string, wiring_content:string, wirings:list<array{rel:string, content:string}>, allowed_globs:list<string>}|null
     */
    public function parse(string $response): ?array
    {
        $fields = ['TEST_REL', 'TEST_COMMAND', 'TEST_CONTENT'];
        $out = [];
        foreach ($fields as $i => $field) {
            $open = '<<<'.$field.'>>>';
            $start = strpos($response, $open);
            if ($start === false) {
                return null;
            }
            $start += strlen($open);
            $closeMarker = '<<<'.($fields[$i + 1] ?? 'WIRING_REL').'>>>';
            if ($field === 'TEST_CONTENT') {
                $closeMarker = strpos($response, '<<<WIRING_REL_1>>>') !== false ? '<<<WIRING_REL_1>>>' : '<<<WIRING_REL>>>';
            }
            $end = strpos($response, $closeMarker, $start);
            if ($end === false) {
                return null;
            }
            $out[$field] = trim(substr($response, $start, $end - $start));
        }

        $testRel = ltrim($out['TEST_REL'], '/');
        if ($testRel === '' || $out['TEST_CONTENT'] === '') {
            return null;
        }
        $wirings = $this->parseWirings($response);
        if ($wirings === null) {
            return null;
        }
        if (! str_starts_with($testRel, 'tests/')) {
            return null;
        }

        return [
            'test_rel' => $testRel,
            'test_command' => $out['TEST_COMMAND'] !== '' ? $out['TEST_COMMAND'] : 'php '.$testRel,
            'test_content' => $out['TEST_CONTENT'],
            'wiring_rel' => $wirings[0]['rel'],
            'wiring_content' => $wirings[0]['content'],
            'wirings' => $wirings,
            'allowed_globs' => ['app/**'],
        ];
    }

    /**
     * @return list<array{rel:string, content:string}>|null
     */
    private function parseWirings(string $response): ?array
    {
        if (strpos($response, '<<<WIRING_REL_1>>>') === false) {
            return $this->parseLegacyWiring($response);
        }

        $wirings = [];
        for ($i = 1; $i <= self::MAX_WIRINGS; $i++) {
            $relMarker = '<<<WIRING_REL_'.$i.'>>>';
            $contentMarker = '<<<WIRING_CONTENT_'.$i.'>>>';
            if (strpos($response, $relMarker) === false) {
                if ($i === 1) {
                    return null;
                }
                break;
            }
            $rel = $this->between($response, $relMarker, $contentMarker);
            $nextRelMarker = '<<<WIRING_REL_'.($i + 1).'>>>';
            $contentClose = strpos($response, $nextRelMarker) !== false ? $nextRelMarker : '<<<END>>>';
            $content = $this->between($response, $contentMarker, $contentClose);
            if ($rel === null || $content === null) {
                return null;
            }
            $rel = ltrim($rel, '/');
            if ($rel === '' || $content === '' || ! str_starts_with($rel, 'app/')) {
                return null;
            }
            $wirings[] = ['rel' => $rel, 'content' => $content];
        }

        if ($wirings === []) {
            return null;
        }
        if (strpos($response, '<<<WIRING_REL_'.(self::MAX_WIRINGS + 1).'>>>') !== false) {
            return null;
        }

        return $wirings;
    }

    /**
     * @return list<array{rel:string, content:string}>|null
     */
    private function parseLegacyWiring(string $response): ?array
    {
        $rel = $this->between($response, '<<<WIRING_REL>>>', '<<<WIRING_CONTENT>>>');
        $content = $this->between($response, '<<<WIRING_CONTENT>>>', '<<<END>>>');
        if ($rel === null || $content === null) {
            return null;
        }
        $rel = ltrim($rel, '/');
        if ($rel === '' || $content === '' || ! str_starts_with($rel, 'app/')) {
            return null;
        }

        return [['rel' => $rel, 'content' => $content]];
    }

    private function between(string $response, string $open, string $close): ?string
    {
        $start = strpos($response, $open);
        if ($start === false) {
            return null;
        }
        $start += strlen($open);
        $end = strpos($response, $close, $start);
        if ($end === false) {
            return null;
        }

        return trim(substr($response, $start, $end - $start));
    }

    /**
     * @param  list<string>  $methods
     * @param  list<string>  $obligations
     */
    private function buildPrompt(string $fqcn, string $orphanRel, array $methods, string $siblingTest, array $obligations = []): string
    {
        $methodList = $methods === [] ? '(none public)' : implode(', ', $methods);
        $architectBlock = '';
        if ($obligations !== []) {
            $architectBlock = "ARCHITECT OBLIGATIONS (MUST be satisfied by your wiring):\n"
                .implode("\n", $obligations)
                ."\n\n";
        }

        return <<<PROMPT
            You are wiring a built-but-unused capability into the codebase. The class {$fqcn} (file {$orphanRel},
            public methods: {$methodList}) is fully implemented and unit-tested ({$siblingTest}) but has ZERO
            production callers. Wire it into a REAL production call path so its behavior is actually used.

            {$architectBlock}Output EXACTLY this marker format and nothing else:
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

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function architectObligations(array $payload): array
    {
        $raw = $payload['_architect_obligations'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function writeFile(string $workspace, string $rel, string $content): void
    {
        $abs = rtrim($workspace, '/').'/'.ltrim($rel, '/');
        AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource($abs);
        $dir = dirname($abs);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($abs, $content);
    }

    /**
     * The live provider call — the ONLY empirical piece. Wraps the loop's canonical provider router with its
     * prompt-array contract (the SAME shape {@see WorkspaceProviderLoopExecutionDriver::buildPrompt} produces).
     * FAIL-CLOSED at every step: an empty/unconfigured provider, a non-call, or empty output ⇒ null ⇒ the route
     * degrades to no_winner. It is NOT a cert — Guard 4e still gates the authored wiring, so a wrong/garbage
     * completion can never certify; the worst case is a wasted attempt, never a fabricated pass. The exact
     * prompt that makes a given provider reliably emit the marker structure is tuned empirically over live runs
     * (parse() rejects anything malformed); tests exercise parse()/apply() via the injected completion double.
     */
    private function liveCompletion(string $provider, string $prompt): ?string
    {
        if ($provider === '') {
            return null;
        }

        $router = app(\App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter::class);
        if (! $router->isConfigured($provider)) {
            return null; // honest no-op when the provider isn't configured on this host
        }

        $result = $router->invoke($provider, null, [
            'text' => $prompt,
            'instruction' => $prompt,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], [
            'timeout_seconds' => max(120, (int) config('atlas.loop.campaign.attempt_hard_seconds', 900)),
            'max_output_chars' => 24000,
        ]);

        if (($result['provider_called'] ?? false) !== true) {
            return null;
        }
        $stdout = (string) ($result['stdout'] ?? $result['output_excerpt'] ?? '');

        return $stdout !== '' ? $stdout : null;
    }
}
