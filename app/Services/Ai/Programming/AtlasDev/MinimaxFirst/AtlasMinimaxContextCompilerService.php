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
        if (! empty($finding['description'])) {
            $lines[] = 'Description: ' . mb_substr((string) $finding['description'], 0, 500);
        }
        if (! empty($finding['spec_seed']['candidate_id'])) {
            $lines[] = 'Spec ID: ' . $finding['spec_seed']['candidate_id'];
        }

        $lines[] = '';
        $lines[] = $filesContext;
        $lines[] = '';
        $lines[] = 'Now implement the changes. Output only complete PHP file contents with // FILE: markers.';

        return implode("\n", $lines);
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
