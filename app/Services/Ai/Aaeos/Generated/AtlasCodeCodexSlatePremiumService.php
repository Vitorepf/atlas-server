<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Code Codex Slate Premium v1 — pure, deterministic CSS/TSX canon guard
 * for the Atlas Code Desktop slate-teal aesthetic.
 *
 * The doc fixes a precise, enforceable visual canon scoped to
 * `.atlas-shell.surface-code` and lists what is explicitly FORBIDDEN. This
 * service turns the prose `forbidden_changes` / `Regras para IA` / `Riscos`
 * sections into a deterministic lint: given a blob of stylesheet or component
 * source it reports every canon violation. It reads source text only — it never
 * touches the filesystem, mutates files, or "promotes completion".
 *
 * Documented rules this code enforces (each load-bearing and tested):
 *   1. Forbidden background base colors. `forbidden_changes`: "Reintroduzir warm
 *      graphite ou preto puro como bg (`#23211c`, `#1c1a16`, `#16140f`, `#000`)."
 *      Any of those four hexes is a `forbidden_bg` violation.
 *   2. No pulse-halo animation on status dots. decisions: "Status dots SEMPRE
 *      estaticos. Animacao pulse halo e cafona — proibida." A `pulse`/halo
 *      keyframe animation or a non-`none` box-shadow on a status dot is a
 *      `pulse_halo` violation.
 *   3. Letter-spacing stays calm. decisions: "Letter-spacing 0 em corpo; max
 *      0.04em em eyebrows/labels." `forbidden_changes`: "letter-spacing
 *      1.0-1.8px em corpo/botoes/dados." Any px letter-spacing >= 1px, or any em
 *      letter-spacing > 0.04em, is a `letter_spacing` violation.
 *   4. No serif italic in operational body. `forbidden_changes`: "Voltar serif
 *      italic em paragrafos operacionais longos." A `font-style: italic` paired
 *      with a serif family (e.g. Cormorant/Georgia/serif) is a `serif_italic`
 *      violation.
 *   5. Slate tokens stay scoped. `forbidden_changes`: "Mover tokens slate teal
 *      para `:root` global (quebra Cartografia)." A `--cc-*` slate token defined
 *      inside a `:root { ... }` block is a `token_scope` violation — they must
 *      live under `.atlas-shell.surface-code`.
 *
 * The canon values themselves (bg `#1d2b34`, accent `#d4a85a`, text-over-gold
 * `#15212a`, etc) are exposed via {@see canonTokens()} as the positive reference
 * the guard checks against, so a single source of truth backs both the allow and
 * the deny lists.
 *
 * @see docs/engineering-knowledge-base/atlas-code-codex-slate-premium-v1.md
 */
final class AtlasCodeCodexSlatePremiumService
{
    /** Stable evidence schema id this guard emits. */
    public const SCHEMA = 'atlas.code.codex_slate_premium.guard.v1';

    /** The one scope the slate theme is allowed to live in. */
    public const CANON_SCOPE = '.atlas-shell.surface-code';

    /** Documented violation finding ids. */
    public const FINDING_FORBIDDEN_BG = 'forbidden_bg';
    public const FINDING_PULSE_HALO = 'pulse_halo';
    public const FINDING_LETTER_SPACING = 'letter_spacing';
    public const FINDING_SERIF_ITALIC = 'serif_italic';
    public const FINDING_TOKEN_SCOPE = 'token_scope';

    /**
     * Warm graphite / pure black backgrounds that are explicitly forbidden as bg
     * base (frontmatter `forbidden_changes`). Lower-cased; matched case-insensitively.
     */
    public const FORBIDDEN_BG_HEXES = [
        '#23211c',
        '#1c1a16',
        '#16140f',
        '#000',
    ];

    /** Max calm letter-spacing in em (decisions: "max 0.04em"). Above this = violation. */
    public const MAX_LETTER_SPACING_EM = 0.04;

    /** Min px letter-spacing that counts as the forbidden 1.0-1.8px brutal range. */
    public const MIN_FORBIDDEN_LETTER_SPACING_PX = 1.0;

    /** Serif family tokens that must never carry italic in operational body. */
    public const SERIF_FAMILIES = ['cormorant', 'georgia', 'times', 'serif'];

    /**
     * Canonical slate-teal token values (the positive reference). Verbatim from
     * the ## Contratos section. Callers read these to render/diff the theme; the
     * guard uses them to know the intended palette.
     *
     * @return array{
     *     scope:string,
     *     bg:array{base:string,surface:string,surface_raised:string,surface_sunken:string,terminal:string},
     *     text:array{strong:string,body:string,muted:string,faint:string,disabled:string},
     *     accent:array{accent:string,accent_strong:string,on_gold:string},
     *     status:array<string,string>
     * }
     */
    public static function canonTokens(): array
    {
        return [
            'scope' => self::CANON_SCOPE,
            'bg' => [
                'base' => '#1d2b34',
                'surface' => '#243743',
                'surface_raised' => '#2d4351',
                'surface_sunken' => '#15212a',
                'terminal' => '#11202a',
            ],
            'text' => [
                'strong' => '#f0f4f7',
                'body' => '#d6dde2',
                'muted' => '#95a3ac',
                'faint' => '#677482',
                'disabled' => '#3d4b54',
            ],
            'accent' => [
                'accent' => '#d4a85a',
                'accent_strong' => '#e6b966',
                'on_gold' => '#15212a',
            ],
            'status' => [
                'success' => '#82b577',
                'warning' => '#e0ad5e',
                'danger' => '#d05a52',
                'info' => '#7fa7c4',
                'neutral' => '#95a3ac',
            ],
        ];
    }

    /**
     * Inspect a blob of CSS/TSX source for slate-premium canon violations.
     *
     * Pure: text in, findings out. Line numbers are 1-based against the input.
     *
     * @return array{
     *     schema:string,
     *     scope:string,
     *     source:string,
     *     ok:bool,
     *     violations:array<int,array{finding:string,line:int,detail:string,evidence:string}>,
     *     counts:array{
     *         total:int,
     *         forbidden_bg:int,
     *         pulse_halo:int,
     *         letter_spacing:int,
     *         serif_italic:int,
     *         token_scope:int
     *     }
     * }
     */
    public function inspectSource(string $source, string $label = 'inline'): array
    {
        $lines = preg_split('/\R/u', $source) ?: [];
        $violations = [];

        // Track whether we are currently inside a `:root { ... }` block so a slate
        // token defined there can be flagged as escaping the canon scope.
        $rootDepth = 0; // brace depth at which the current :root block opened (0 = not in one)
        $braceDepth = 0;
        $inRoot = false;

        foreach ($lines as $index => $rawLine) {
            $line = (string) $rawLine;
            $lineNo = $index + 1;
            $lower = strtolower($line);

            // --- :root scope tracking (must run before token check on this line) ---
            $sawRootOpener = preg_match('/(^|[\s,}])::?root\b[^{]*\{/', $lower) === 1;
            if ($sawRootOpener && ! $inRoot) {
                $inRoot = true;
                $rootDepth = $braceDepth; // depth before this line's braces are counted
            }

            // 1. Forbidden background base hexes.
            foreach (self::FORBIDDEN_BG_HEXES as $hex) {
                if ($this->hexAppears($lower, $hex)) {
                    $violations[] = $this->violation(
                        self::FINDING_FORBIDDEN_BG,
                        $lineNo,
                        sprintf(
                            'Forbidden warm-graphite/black background "%s" — bg base must be slate teal (%s). '
                                . 'forbidden_changes prohibits reintroducing it.',
                            $hex,
                            self::canonTokens()['bg']['base'],
                        ),
                        trim($line),
                    );
                }
            }

            // 2. Pulse-halo animation on status dots.
            if ($this->isPulseHalo($lower)) {
                $violations[] = $this->violation(
                    self::FINDING_PULSE_HALO,
                    $lineNo,
                    'Pulse/halo animation on a status dot — status dots are SEMPRE estaticos; '
                        . 'signal state via color + label, never movement.',
                    trim($line),
                );
            }

            // 3. Brutal letter-spacing.
            $ls = $this->offendingLetterSpacing($lower);
            if ($ls !== null) {
                $violations[] = $this->violation(
                    self::FINDING_LETTER_SPACING,
                    $lineNo,
                    sprintf(
                        'Letter-spacing %s exceeds the calm canon (0 in body, max %sem in eyebrows). '
                            . 'The 1.0-1.8px brutal range is explicitly forbidden.',
                        $ls,
                        self::MAX_LETTER_SPACING_EM,
                    ),
                    trim($line),
                );
            }

            // 4. Serif italic in operational body.
            if ($this->isSerifItalic($lower)) {
                $violations[] = $this->violation(
                    self::FINDING_SERIF_ITALIC,
                    $lineNo,
                    'Serif italic in operational body — Cormorant serif is for display titles only; '
                        . 'operational paragraphs use Inter sans, never serif italic.',
                    trim($line),
                );
            }

            // 5. Slate token escaping into :root.
            if ($inRoot && $this->definesSlateToken($lower)) {
                $violations[] = $this->violation(
                    self::FINDING_TOKEN_SCOPE,
                    $lineNo,
                    sprintf(
                        'Slate "--cc-*" token defined in :root global — moving slate tokens to :root '
                            . 'breaks Cartografia. They must live under "%s".',
                        self::CANON_SCOPE,
                    ),
                    trim($line),
                );
            }

            // --- update brace depth for subsequent lines / close :root ---
            $braceDepth += substr_count($line, '{') - substr_count($line, '}');
            if ($braceDepth < 0) {
                $braceDepth = 0;
            }
            if ($inRoot && $braceDepth <= $rootDepth) {
                $inRoot = false;
                $rootDepth = 0;
            }
        }

        $counts = [
            'total' => count($violations),
            self::FINDING_FORBIDDEN_BG => $this->countOf($violations, self::FINDING_FORBIDDEN_BG),
            self::FINDING_PULSE_HALO => $this->countOf($violations, self::FINDING_PULSE_HALO),
            self::FINDING_LETTER_SPACING => $this->countOf($violations, self::FINDING_LETTER_SPACING),
            self::FINDING_SERIF_ITALIC => $this->countOf($violations, self::FINDING_SERIF_ITALIC),
            self::FINDING_TOKEN_SCOPE => $this->countOf($violations, self::FINDING_TOKEN_SCOPE),
        ];

        return [
            'schema' => self::SCHEMA,
            'scope' => self::CANON_SCOPE,
            'source' => $label,
            'ok' => $violations === [],
            'violations' => $violations,
            'counts' => $counts,
        ];
    }

    /**
     * Convenience: inspect a known-clean canonical snippet (the doc's own
     * Exemplos block) so the command has a safe default that returns ok=true.
     *
     * @return array{
     *     schema:string,
     *     scope:string,
     *     source:string,
     *     ok:bool,
     *     violations:array<int,array{finding:string,line:int,detail:string,evidence:string}>,
     *     counts:array{total:int,forbidden_bg:int,pulse_halo:int,letter_spacing:int,serif_italic:int,token_scope:int}
     * }
     */
    public function auditCanonExample(): array
    {
        $canon = self::canonTokens();

        // The doc's own canonical example: tokens under .atlas-shell.surface-code,
        // static status dot (box-shadow: none; animation: none), no forbidden hex.
        $snippet = <<<CSS
        {$canon['scope']} {
          --cc-bg: {$canon['bg']['base']};
          --cc-accent: {$canon['accent']['accent']};
          --cc-text-strong: {$canon['text']['strong']};
        }
        .cc-status-dot[data-status='running'] { background: var(--cc-info); }
        .cc-status-dot[data-pulse='true'] { box-shadow: none; animation: none; }
        CSS;

        return $this->inspectSource($snippet, 'canon_example');
    }

    /**
     * True if a CSS color hex appears in the line as a whole token (so `#000`
     * matches `#000` and `#000;` but not the longer `#0001` or `#000aff`).
     */
    private function hexAppears(string $lowerLine, string $hex): bool
    {
        $escaped = preg_quote($hex, '/');

        // Negative lookahead on a trailing hex digit prevents #000 from matching
        // #0001/#000aff while still matching #000, #000;, "#000".
        return preg_match('/' . $escaped . '(?![0-9a-f])/', $lowerLine) === 1;
    }

    /** A pulse/halo animation or a glowing box-shadow attached to a status dot. */
    private function isPulseHalo(string $lowerLine): bool
    {
        $mentionsDot = str_contains($lowerLine, 'status-dot') || str_contains($lowerLine, 'statusdot');

        // Any keyframe/animation literally named with "pulse" or "halo" is banned
        // outright (the named decoration the doc calls cafona).
        if (preg_match('/(?:animation[^;]*|@keyframes\s+|animation-name\s*:\s*)[a-z0-9_-]*(?:pulse|halo)/', $lowerLine) === 1) {
            return true;
        }

        // A status dot that carries a non-`none` box-shadow is the halo glow.
        if ($mentionsDot
            && preg_match('/box-shadow\s*:/', $lowerLine) === 1
            && preg_match('/box-shadow\s*:\s*none/', $lowerLine) !== 1
        ) {
            return true;
        }

        return false;
    }

    /**
     * Returns the offending letter-spacing literal (e.g. "1.4px" / "0.08em") if
     * the line sets a letter-spacing outside the calm canon, else null.
     */
    private function offendingLetterSpacing(string $lowerLine): ?string
    {
        // CSS property form: letter-spacing: 1.4px
        if (preg_match('/letter-spacing\s*:\s*([0-9]*\.?[0-9]+)\s*(px|em|rem)/', $lowerLine, $m) === 1) {
            return $this->judgeLetterSpacing((float) $m[1], $m[2], $m[1] . $m[2]);
        }

        // JSX camelCase form: letterSpacing: '1.4px' (quotes optional)
        if (preg_match('/letterspacing\s*:\s*[\'"]?([0-9]*\.?[0-9]+)\s*(px|em|rem)[\'"]?/', $lowerLine, $m) === 1) {
            return $this->judgeLetterSpacing((float) $m[1], $m[2], $m[1] . $m[2]);
        }

        return null;
    }

    /** Decide whether a parsed letter-spacing value violates the canon. */
    private function judgeLetterSpacing(float $value, string $unit, string $literal): ?string
    {
        if ($unit === 'px') {
            // Forbidden brutal range starts at 1px (covers the doc's 1.0-1.8px).
            return $value >= self::MIN_FORBIDDEN_LETTER_SPACING_PX ? $literal : null;
        }

        // em / rem: anything above 0.04em is louder than the eyebrow max.
        return $value > self::MAX_LETTER_SPACING_EM ? $literal : null;
    }

    /** Serif family combined with italic on the same declaration line. */
    private function isSerifItalic(string $lowerLine): bool
    {
        $hasItalic = preg_match('/font-style\s*:\s*italic/', $lowerLine) === 1
            || preg_match('/fontstyle\s*:\s*[\'"]?italic/', $lowerLine) === 1;

        if (! $hasItalic) {
            return false;
        }

        foreach (self::SERIF_FAMILIES as $family) {
            // Word-boundary match so the generic `serif` keyword does NOT trip on
            // `sans-serif` (a sans family). A preceding letter/hyphen disqualifies.
            if (preg_match('/(?<![a-z-])' . preg_quote($family, '/') . '\b/', $lowerLine) === 1) {
                return true;
            }
        }

        return false;
    }

    /** True if the line declares a `--cc-*` slate token (custom property). */
    private function definesSlateToken(string $lowerLine): bool
    {
        return preg_match('/--cc-[a-z0-9-]+\s*:/', $lowerLine) === 1;
    }

    /**
     * @param  array<int,array{finding:string,line:int,detail:string,evidence:string}>  $violations
     */
    private function countOf(array $violations, string $finding): int
    {
        $n = 0;
        foreach ($violations as $v) {
            if ($v['finding'] === $finding) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array{finding:string,line:int,detail:string,evidence:string}
     */
    private function violation(string $finding, int $line, string $detail, string $evidence): array
    {
        return [
            'finding' => $finding,
            'line' => $line,
            'detail' => $detail,
            'evidence' => $evidence,
        ];
    }
}
