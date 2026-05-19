<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox;

/**
 * Deterministic, rules-first PT-BR polisher for Atlas Vox V1
 * (mode = prompt_polish).
 *
 * Hard contract:
 *   - No network. No LLM. No provider call. No randomness.
 *   - Output preserves the operator's intent: nothing is invented, nothing
 *     destructive is added. If the input is already clean, we polish
 *     minimally (casing, punctuation, terminal period).
 *   - Negations and constraints from the source ("não mexer", "sem editar",
 *     "antes de implementar") are preserved verbatim and surfaced as a
 *     constraints[] array so the Kernel/Desktop can show them explicitly.
 *
 * Why a rules-first polisher: V1 is push-to-talk polish on Vitor's
 * machine. Calling a provider would violate Lei 0.75 (Vox NEVER calls
 * a provider directly) and Lei 0 (no API paga em V0-V5). Aspirational
 * "rewrite into a strong prompt" output belongs to V2 Intent Compiler.
 *
 * The exact rules applied (in order):
 *   1. Whitespace + repeated-vowel normalisation.
 *   2. Filler removal (tipo, assim, aí, né, meio que, ...).
 *   3. Atlas-term casing normalisation (codex → Codex, etc.).
 *   4. Sentence segmentation + initial capital + terminal period.
 *   5. Provider-hint inference from term mentions.
 *   6. Constraint extraction from "não/sem/antes de ..." clauses.
 *   7. Goal extraction (first short imperative-ish sentence, else empty).
 *
 * The class is intentionally pure: no constructor deps, no I/O, no static
 * state. Trivial to unit test.
 */
final class VoxPromptPolisher
{
    public const TEMPLATE_ID = 'builtin.prompt_polish.pt-br@0.1.0';

    /**
     * Atlas-domain terms whose casing we want to enforce when the operator
     * speaks them. Stored as canonical => list of lowercase aliases.
     *
     * Order matters: multi-word entries must come BEFORE shorter overlaps
     * that would otherwise eat their tokens (e.g. "Atlas Vox" before
     * "Atlas", "LiveKit" before "kit").
     *
     * @var array<string, list<string>>
     */
    private const TERM_MAP = [
        'Decision Receipt' => ['decision receipt', 'decisao receipt', 'decision receipts'],
        'Evidence Ledger' => ['evidence ledger', 'evidência ledger', 'evidencia ledger'],
        'Prompt Compiler' => ['prompt compiler'],
        'Atlas Vox' => ['atlas vox'],
        'LiveKit' => ['livekit', 'live kit', 'live-kit'],
        'MacBook' => ['macbook', 'mac book'],
        'Codex' => ['codex'],
        'Claude' => ['claude'],
        'Tauri' => ['tauri'],
        'Vox' => ['vox'],
        'Kernel' => ['kernel'],
        'Forge' => ['forge'],
        'Rivals' => ['rivals'],
        'Atlas' => ['atlas'],
        'Desktop' => ['desktop'],
        'Terminal' => ['terminal'],
        'Laravel' => ['laravel'],
        'Whisper' => ['whisper'],
        'Cartografia' => ['cartografia'],
        'Inbox' => ['inbox'],
        'Workbench' => ['workbench'],
    ];

    /**
     * Fillers we strip when they sit between word boundaries. Order
     * matters: longer multi-word fillers are stripped before their shorter
     * substrings to avoid leaving dangling fragments.
     *
     * @var list<string>
     */
    private const FILLERS = [
        'meio que',
        'tipo assim',
        'tipo',
        'assim',
        'né',
        'aí',
        'então',
    ];

    /**
     * @return array{
     *   compiled_prompt: string,
     *   compiled_prompt_template: string,
     *   goal: string,
     *   constraints: list<string>,
     *   provider_hint: string,
     *   transformations_applied: list<string>
     * }
     */
    public function polish(string $rawText): array
    {
        $applied = [];

        $text = $this->collapseWhitespace($rawText);
        if ($text !== trim($rawText)) {
            $applied[] = 'whitespace_normalised';
        }

        $stripped = $this->stripFillers($text);
        if ($stripped !== $text) {
            $applied[] = 'fillers_removed';
        }
        $text = $stripped;

        $casing = $this->normaliseAtlasTerms($text);
        if ($casing !== $text) {
            $applied[] = 'atlas_terms_recased';
        }
        $text = $casing;

        $providerHint = $this->detectProviderHint($text);
        $constraints = $this->extractConstraints($text);

        $punctuated = $this->punctuate($text);
        if ($punctuated !== $text) {
            $applied[] = 'punctuation_inserted';
        }
        $text = $punctuated;

        $goal = $this->extractGoal($text);

        return [
            'compiled_prompt' => $text,
            'compiled_prompt_template' => self::TEMPLATE_ID,
            'goal' => $goal,
            'constraints' => $constraints,
            'provider_hint' => $providerHint,
            'transformations_applied' => $applied,
        ];
    }

    private function collapseWhitespace(string $text): string
    {
        $text = trim($text);
        // Collapse all whitespace runs (spaces, tabs, newlines) to one space.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return $text;
    }

    /**
     * Remove conversational fillers when they sit between word boundaries.
     * We intentionally do NOT remove fillers at the start of the input,
     * because doing so can change meaning ("Aí que está o problema..." ≠
     * "que está o problema..."). The cost of being a little too
     * conservative here is much smaller than the cost of mangling intent.
     */
    private function stripFillers(string $text): string
    {
        foreach (self::FILLERS as $filler) {
            $escaped = preg_quote($filler, '/');
            // \b doesn't play well with accented chars in PCRE without /u
            // and the unicode-aware boundary; use lookarounds instead.
            $pattern = '/(?<=\s)'.$escaped.'(?=[\s,;.!?])/iu';
            $replaced = preg_replace($pattern, '', $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }
        // Collapse double spaces produced by the strip.
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);
        // Tidy " ," and " ." artifacts.
        $text = (string) preg_replace('/\s+([,;.!?])/u', '$1', $text);

        return trim($text);
    }

    /**
     * Replace Atlas-domain terms with their canonical casing. Uses
     * word-boundary regex with unicode flag.
     */
    private function normaliseAtlasTerms(string $text): string
    {
        foreach (self::TERM_MAP as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $escaped = preg_quote($alias, '/');
                $pattern = '/(?<![\p{L}\p{N}_])'.$escaped.'(?![\p{L}\p{N}_])/iu';
                $replaced = preg_replace($pattern, $canonical, $text);
                if (is_string($replaced)) {
                    $text = $replaced;
                }
            }
        }

        return $text;
    }

    /**
     * Inspect mentions of Codex/Claude/Atlas (after term normalisation)
     * and return a provider hint. Conservative defaults to `local` when
     * nothing is mentioned. Never invents a provider.
     *
     * V6-FPG-B · detecta `Atlas Dev|Atlas AI|Atlas Code|Atlas interno`
     * explícitos como destinatário separado de Codex/Claude. "Atlas"
     * solto (sem qualificador) NÃO força — pode ser o nome do projeto.
     */
    private function detectProviderHint(string $text): string
    {
        $hasCodex = (bool) preg_match('/(?<![\p{L}\p{N}_])Codex(?![\p{L}\p{N}_])/u', $text);
        $hasClaude = (bool) preg_match('/(?<![\p{L}\p{N}_])Claude(?![\p{L}\p{N}_])/u', $text);
        $hasAtlasDest = (bool) preg_match(
            '/(?<![\p{L}\p{N}_])(?:Atlas\s+(?:Dev|AI|Code|interno|kernel)|fala\s+(?:com|pro)\s+Atlas|pergunta\s+(?:pro|para\s+o)\s+Atlas)/u',
            $text,
        );

        // Atlas explícito ganha de Codex/Claude porque é o destinatário
        // mais específico que o operador pode pedir.
        if ($hasAtlasDest && ! $hasCodex && ! $hasClaude) {
            return 'atlas';
        }
        if ($hasCodex && $hasClaude) {
            return 'auto';
        }
        if ($hasCodex) {
            return 'codex_cli';
        }
        if ($hasClaude) {
            return 'claude_cli';
        }
        if ($hasAtlasDest) {
            return 'atlas';
        }

        return 'local';
    }

    /**
     * Pull explicit constraints out of the text. Returns them as a
     * de-duplicated list of short trimmed clauses, preserving the
     * operator's words verbatim. Patterns covered:
     *   - "não <verb> ..."   → "não <verb> ..."
     *   - "sem <verb/noun> ..." → "sem <verb/noun> ..."
     *   - "antes de <clause>"  → "antes de <clause>"
     *
     * Clauses are bounded by punctuation, conjunctions or end-of-string.
     *
     * @return list<string>
     */
    private function extractConstraints(string $text): array
    {
        $constraints = [];

        // Boundary: stop at sentence punctuation OR strong connectives.
        // We deliberately allow up to ~80 chars of clause body so we don't
        // truncate "não tocar nos arquivos do módulo Voice" mid-thought.
        // \s+n[ãa]o\b stops the lazy match when the operator repeats the
        // negation colloquially ("não mexer não.") — without it the
        // constraint would absorb the trailing repetition.
        $boundary = "[\\.!?,;]|\\s+e\\s+|\\s+mas\\s+|\\s+só\\s+|\\s+depois\\s+|\\s+pra\\s+que|\\s+n[ãa]o\\b|$";

        // V6-FPG-B · expansão das negações canônicas:
        //   * `não X`        → veto direto
        //   * `não X ainda`  → defer ("ainda" preservado no clause)
        //   * `sem X`        → veto direto (já existia)
        //   * `nada de X`    → veto coloquial ("nada de provider pago")
        //   * `antes de X`   → pré-condição
        //   * `só X`         → read-only/escopo único ("só analisa")
        //   * `apenas X`     → idem ("apenas leia")
        //   * `somente X`    → idem
        $patterns = [
            '/(?<![\p{L}\p{N}_])(n[ãa]o\s+[\p{L}\p{N}_\s\-]{1,80}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(sem\s+[\p{L}\p{N}_\s\-]{1,80}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(nada\s+de\s+[\p{L}\p{N}_\s\-]{1,80}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(antes\s+de\s+[\p{L}\p{N}_\s\-]{1,80}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(s[óo]\s+(?:analisa|analise|leia|ler|l[êe]|olha|olhar|investiga|investigue|investigar)\b[\p{L}\p{N}_\s\-]{0,60}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(apenas\s+(?:analisa|analise|leia|ler|l[êe]|olha|olhar)\b[\p{L}\p{N}_\s\-]{0,60}?)(?='.$boundary.')/iu',
            '/(?<![\p{L}\p{N}_])(somente\s+(?:analisa|analise|leia|ler|l[êe]|olha|olhar)\b[\p{L}\p{N}_\s\-]{0,60}?)(?='.$boundary.')/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) !== false) {
                foreach ($matches[1] ?? [] as $hit) {
                    $clause = trim((string) $hit);
                    // Drop trivial captures like "não" / "sem" alone.
                    if ($clause === '' || preg_match('/^(n[ãa]o|sem|antes\s+de|nada\s+de|s[óo]|apenas|somente)$/iu', $clause) === 1) {
                        continue;
                    }
                    // Normalise inner whitespace.
                    $clause = (string) preg_replace('/\s+/u', ' ', $clause);
                    if (! in_array($clause, $constraints, true)) {
                        $constraints[] = $clause;
                    }
                }
            }
        }

        return $constraints;
    }

    /**
     * Light punctuation: ensure the text ends with sentence-final
     * punctuation, and that the first letter is uppercased. Mid-sentence
     * punctuation is left to the operator; we don't guess commas.
     */
    private function punctuate(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return $text;
        }

        // Capitalise the first character (unicode-aware via mb_*).
        $first = mb_substr($text, 0, 1);
        $rest = mb_substr($text, 1);
        if (mb_strtoupper($first) !== $first) {
            $text = mb_strtoupper($first).$rest;
        }

        // Append final period if no terminal punctuation.
        $last = mb_substr($text, -1);
        if (! in_array($last, ['.', '!', '?'], true)) {
            $text .= '.';
        }

        return $text;
    }

    /**
     * Pull the first sentence as a goal if it reads like a short
     * directive. Returns "" otherwise — the contract permits an empty
     * goal in modes where the compiler can't extract one cleanly.
     */
    private function extractGoal(string $text): string
    {
        $segments = preg_split('/(?<=[\.!?])\s+/u', $text);
        if (! is_array($segments) || $segments === []) {
            return '';
        }
        $first = trim((string) $segments[0]);
        if ($first === '') {
            return '';
        }
        // Strip terminal period for the goal, keep it punctuation-clean.
        $first = rtrim($first, '.!?');
        if (mb_strlen($first) > 160) {
            return '';
        }

        return $first;
    }
}
