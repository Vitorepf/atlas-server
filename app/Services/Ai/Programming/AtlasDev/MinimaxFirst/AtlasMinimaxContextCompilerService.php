<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst;

/**
 * Compiles ultra-minimal, pre-structured context for MiniMax.
 *
 * Reads ONLY the allowed files (never the whole repo), extracts relevant
 * sections, injects Atlas coding standards, and enforces a strict token
 * budget. When a Codex plan is provided it is embedded first so MiniMax
 * receives a surgical instruction rather than a raw spec dump.
 */
final class AtlasMinimaxContextCompilerService
{
    public const SCHEMA = 'atlas.dev.minimax_first.context_manifest.v1';

    /**
     * Target token ceiling for the MiniMax 200k context window.
     * 140k input + 60k output headroom = 200k total.
     * More context → higher one-shot success probability.
     */
    public const MAX_TOKENS = 140_000;

    /**
     * Compile a MiniMax-ready manifest from a finding + allowed files.
     *
     * @param  array<string,mixed>  $finding
     * @param  list<string>         $allowedFiles  relative paths
     * @param  string               $repoRoot
     * @param  array<string,mixed>  $codexPlan     optional Codex spec (may be empty)
     * @return array<string,mixed>
     */
    public function compile(array $finding, array $allowedFiles, string $repoRoot, array $codexPlan = []): array
    {
        $systemPrompt = $this->buildSystemPrompt($finding);
        $filesContext = $this->buildFilesContext($allowedFiles, $repoRoot);
        $userPrompt   = $this->buildUserPrompt($finding, $codexPlan, $filesContext);

        $estimatedTokens = $this->estimateTokens($systemPrompt . $userPrompt);

        if ($estimatedTokens > self::MAX_TOKENS) {
            $budget     = self::MAX_TOKENS - $this->estimateTokens($systemPrompt) - 500;
            $maxChars   = max(1_000, $budget * 4);
            $userPrompt = mb_substr($userPrompt, 0, $maxChars)
                . "\n\n[Context truncated to fit token budget. Implement what you can from the spec above.]";
            $estimatedTokens = $this->estimateTokens($systemPrompt . $userPrompt);
        }

        // The MiniMax CLI adapter builds the user message from task_contract.task_description +
        // task_contract.context (it ignores `messages`/`system`). Without task_contract the
        // model received an EMPTY request ("I don't see a request attached") and produced no
        // code. Put the full instruction (standards + // FILE: output format + task) into
        // task_description and the file context into context, so the prompt actually reaches
        // the model. `system`/`messages` kept for any consumer that reads them.
        $manifest = [
            'model'      => 'MiniMax-M2.7',
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
            'task_contract' => [
                'task_description' => $systemPrompt."\n\n".$userPrompt,
                'context'          => $filesContext,
            ],
            'max_tokens' => 32_768,
        ];

        return [
            'schema_version'   => self::SCHEMA,
            'system_prompt'    => $systemPrompt,
            'user_prompt'      => $userPrompt,
            'files_included'   => $allowedFiles,
            'estimated_tokens' => $estimatedTokens,
            'manifest'         => $manifest,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────

    private function buildSystemPrompt(array $finding): string
    {
        $title = (string) ($finding['title'] ?? 'Atlas implementation task');

        return <<<PROMPT
You are an expert PHP engineer implementing code for the Atlas system.

TASK: {$title}

ATLAS CODING STANDARDS (mandatory):
- declare(strict_types=1) at top of every PHP file
- final class by default (unless explicitly abstract)
- No inline comments unless the WHY is non-obvious
- PSR-4 namespaces matching directory structure
- No var_dump, no echo, no dd() in production code
- Type-hint every method parameter and return type

SCOPE DISCIPLINE (critical — violating this fails the build):
- You may ONLY create/modify the files explicitly listed as allowed. You cannot create any other file.
- The implementation MUST be self-contained within those allowed files. Do NOT reference, import,
  instantiate, or type-hint any class, interface, enum, model, trait, or facade that does not ALREADY
  exist in the codebase AND is not one of the allowed files.
- Do NOT invent new dependencies (no new Models, Enums, Repositories, Interfaces, Evaluators, Services,
  config keys, or migrations). If the task needs a helper, define it INSIDE an allowed file (e.g. a
  private method, or return a plain array), never as a new external class.
- Prefer plain PHP arrays/scalars over Eloquent models or DB access unless an allowed file already wires them.
- The test file MUST construct the class under test directly (no service container, no DB, no unlisted
  collaborators) so phpunit passes in a clean checkout.

DIFF INTEGRITY CONTRACT (mandatory):
- Existing files are NOT blank canvases. Preserve every existing class, method, data provider, assertion,
  and test unless the task explicitly names that exact member for removal.
- For an existing test file, append or narrowly adjust the focused test. Do NOT replace the file with a
  small new test class, and do NOT delete unrelated tests. A large test deletion fails the quality gate.
- If the task names target_method, method_anchor, surgical_anchor, or mutation_anchor, change only that
  anchored runtime method plus the smallest focused test proof.
- Comment-only, whitespace-only, cosmetic, scaffold-only, or no-op output is invalid even if tests pass.
- Tests must prove behavior by instantiating/calling the target class or method. Do NOT use file_exists(),
  app_path(), class_exists(), or "file loads" smoke assertions as the primary proof for a new class slice.

OUTPUT FORMAT:
- For each file you modify, write the COMPLETE file content
- Precede each file with a marker on its own line: // FILE: relative/path/to/file.php
- Do NOT include any explanation outside code blocks
- Write ONLY valid PHP that compiles without errors
PROMPT;
    }

    private function buildUserPrompt(array $finding, array $codexPlan, string $filesContext): string
    {
        $lines = [];

        if ($codexPlan !== []) {
            $lines[] = 'CODEX PLAN (follow this exactly — do not deviate):';
            $lines[] = json_encode($codexPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '';
        }

        $lines[] = 'FINDING SPEC:';
        $lines[] = 'Title: ' . ($finding['title'] ?? '');
        foreach ($this->findingNarrativeLines($finding) as $line) {
            $lines[] = $line;
        }

        $acceptance = $this->acceptanceLines($finding);
        if ($acceptance !== []) {
            $lines[] = '';
            $lines[] = 'ACCEPTANCE CRITERIA (hard gates):';
            foreach ($acceptance as $line) {
                $lines[] = '- '.$line;
            }
            $lines[] = '- If a return schema_version or return keys are listed above, the product code MUST return that exact schema_version literal and every listed key.';
        }

        $anchors = $this->anchorLines($finding);
        if ($anchors !== []) {
            $lines[] = '';
            $lines[] = 'SURGICAL ANCHORS (must be followed):';
            foreach ($anchors as $line) {
                $lines[] = $line;
            }
        }
        if (! empty($finding['spec_seed']['candidate_id'])) {
            $lines[] = 'Spec ID: ' . $finding['spec_seed']['candidate_id'];
        }

        $lines[] = '';
        $lines[] = 'HARD SCOPE:';
        $lines[] = '- Allowed files: ' . ($this->stringList($finding['allowed_files'] ?? []) !== []
            ? implode(', ', $this->stringList($finding['allowed_files']))
            : 'the files listed in FILES TO MODIFY below');
        $lines[] = '- Preserve existing tests and product methods unless the anchored task explicitly requires changing that member.';
        $lines[] = '- Prefer the smallest semantic diff that proves the target behavior.';

        $lines[] = '';
        $lines[] = $filesContext;
        $lines[] = '';
        $lines[] = 'Now implement the changes. Output only complete PHP file contents with // FILE: markers.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function acceptanceLines(array $finding): array
    {
        $criteria = [];
        foreach ([
            'acceptance_criteria',
            'acceptance',
            'acceptance_gates',
            'executable_slice.acceptance',
            'executable_slice.acceptance_criteria',
            'finding_slice_plan.acceptance',
            'finding_slice_plan.acceptance_criteria',
        ] as $path) {
            $criteria = array_merge($criteria, $this->stringList($this->nestedValue($finding, $path)));
        }

        foreach (['detail', 'description'] as $key) {
            $text = $this->compactScalar($finding[$key] ?? null, 2_000);
            if ($text !== '' && preg_match('/\bAcceptance:\s*(.+)$/i', $text, $match) === 1) {
                $criteria[] = trim((string) $match[1]);
            }
        }

        return array_values(array_unique(array_filter($criteria, static fn (string $line): bool => $line !== '')));
    }

    /**
     * @return list<string>
     */
    private function findingNarrativeLines(array $finding): array
    {
        $fields = [
            'description' => 'Description',
            'detail' => 'Detail',
            'why_it_matters' => 'Why it matters',
            'proposed_next_action' => 'Proposed next action',
        ];

        $lines = [];
        foreach ($fields as $key => $label) {
            $value = $this->compactScalar($finding[$key] ?? null, 1_200);
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        $packetObjective = $this->compactScalar($this->nestedValue($finding, 'self_construction_packet.objective'), 1_200);
        if ($packetObjective !== '' && ! str_contains(implode("\n", $lines), $packetObjective)) {
            $lines[] = 'Packet objective: '.$packetObjective;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function anchorLines(array $finding): array
    {
        $fields = [
            'active_slice_id' => 'active_slice_id',
            'target_method' => 'target_method',
            'target_symbol' => 'target_symbol',
            'method_anchor' => 'method_anchor',
            'surgical_anchor' => 'surgical_anchor',
            'mutation_anchor' => 'mutation_anchor',
        ];

        $lines = [];
        foreach ($fields as $key => $label) {
            $value = $this->compactScalar($finding[$key] ?? $this->nestedValue($finding, 'self_construction_packet.'.$key), 1_200);
            if ($value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return $lines;
    }

    private function compactScalar(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        return mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, $limit);
    }

    private function nestedValue(array $array, string $path): mixed
    {
        $value = $array;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            $strings = array_merge($strings, $this->stringList($item));
        }

        return array_values(array_unique($strings));
    }

    private function buildFilesContext(array $allowedFiles, string $repoRoot): string
    {
        if ($allowedFiles === []) {
            return 'No specific files pre-loaded. Identify the correct file from the spec.';
        }

        $parts = ['FILES TO MODIFY (current content):'];

        foreach ($allowedFiles as $relativePath) {
            $absolutePath = rtrim($repoRoot, '/') . '/' . ltrim($relativePath, '/');
            $parts[]      = "\n--- FILE: {$relativePath} ---";

            if (! is_file($absolutePath)) {
                $parts[] = '[File does not exist yet — create it with the correct namespace.]';
                continue;
            }

            $parts[] = $this->extractRelevantSection($absolutePath);
        }

        return implode("\n", $parts);
    }

    private function extractRelevantSection(string $absolutePath): string
    {
        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '[Could not read file]';
        }

        $total = count($lines);
        if ($total <= 1_000) {
            return implode("\n", $lines);
        }

        // For very large files keep the structural skeleton: declarations at
        // head + implementation at tail — gives MiniMax the full class/method
        // signature context and the recent logic without truncating the middle.
        $head = array_slice($lines, 0, 200);
        $tail = array_slice($lines, -300, 300);

        return implode("\n", $head)
            . "\n// ... [" . ($total - 500) . ' lines omitted for context budget] ...' . "\n"
            . implode("\n", $tail);
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
