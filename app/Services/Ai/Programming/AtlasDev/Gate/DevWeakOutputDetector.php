<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Catches weak model output BEFORE it touches code. Feeds the fact into the existing repair loop
 * ({@see \App\Services\Ai\Aaeos\AtlasRepairLoopGuard}) instead of building a second loop: this
 * detector only produces {weak, signals, repair_hint} — it never iterates, never escalates, never
 * calls a provider. The guard owns iteration/escalation once a caller decides to act on the fact.
 *
 * Weakness signals (independently testable, all checked so every present signal is reported):
 *   empty_or_truncated_diff       — blank output, or unbalanced braces/brackets/parens.
 *   restates_existing_code        — looks like a unified diff but has zero real +/- change lines.
 *   placeholder_marker            — TODO, ellipsis-only body, or a fake always-true assertion.
 *   out_of_scope_file_reference   — a file path in the diff is not in workcell['allowed_files'].
 *   ignores_verification_command  — workcell['verification_command'] is never mentioned in the output.
 *   hallucinated_symbol           — a PascalCase symbol added by the diff is absent from
 *                                    workcell['known_symbols'] (the discovery manifest's confirmed
 *                                    symbols) — only checked when known_symbols is non-empty.
 *
 * Input workcell shape:
 *   { allowed_files?: list<string>, verification_command?: string, known_symbols?: list<string> }
 *
 * Pure: string/array analysis only. No I/O, no provider calls, no loop of its own.
 */
final class DevWeakOutputDetector
{
    public const SIGNAL_EMPTY_OR_TRUNCATED_DIFF = 'empty_or_truncated_diff';

    public const SIGNAL_RESTATES_EXISTING_CODE = 'restates_existing_code';

    public const SIGNAL_PLACEHOLDER_MARKER = 'placeholder_marker';

    public const SIGNAL_OUT_OF_SCOPE_FILE = 'out_of_scope_file_reference';

    public const SIGNAL_IGNORES_VERIFICATION_COMMAND = 'ignores_verification_command';

    public const SIGNAL_HALLUCINATED_SYMBOL = 'hallucinated_symbol';

    /**
     * Honesty flag appended by the post-gate applied-diff probe
     * ({@see self::inspectAppliedDiff()}) via the sanctioned advisory channel:
     * CompletionStateGate downgrades PASSED -> needs_review, never green.
     */
    public const FLAG_WEAK_OUTPUT_DETECTED = 'weak_output_detected';

    /** Context section to re-emphasize per signal, in priority order (first failing signal drives repair_hint). */
    private const CONTEXT_SECTION_BY_SIGNAL = [
        self::SIGNAL_EMPTY_OR_TRUNCATED_DIFF => 'the full diff hunk with matching braces/brackets',
        self::SIGNAL_OUT_OF_SCOPE_FILE => "the workcell's allowed_files list",
        self::SIGNAL_HALLUCINATED_SYMBOL => "the code discovery manifest's confirmed symbols",
        self::SIGNAL_RESTATES_EXISTING_CODE => 'the required code CHANGE (added/removed lines), not just context',
        self::SIGNAL_PLACEHOLDER_MARKER => 'a concrete implementation instead of TODO/ellipsis/fake-assert placeholders',
        self::SIGNAL_IGNORES_VERIFICATION_COMMAND => 'the required verification command',
    ];

    private const PLACEHOLDER_PATTERNS = [
        '/\bTODO\b/i',
        '/\bFIXME\b/i',
        '/^\s*\.\.\.\s*$/m',
        '/assert(?:True|Equal)\(\s*true\s*,?\s*true\s*\)/i',
        '/expect\(true\)->toBeTrue\(\)/i',
    ];

    /**
     * @param  array{allowed_files?:list<string>, verification_command?:string, known_symbols?:list<string>}  $workcell
     * @return array{weak:bool, signals:list<array{id:string,detail:string}>, repair_hint:?string}
     */
    public function inspect(string $modelOutput, array $workcell = []): array
    {
        $signals = [];

        $trimmed = trim($modelOutput);
        if ($trimmed === '' || $this->looksTruncated($modelOutput)) {
            $signals[] = ['id' => self::SIGNAL_EMPTY_OR_TRUNCATED_DIFF, 'detail' => $trimmed === '' ? 'output is empty' : 'unbalanced braces/brackets/parens'];
        }

        $allowedFiles = array_values(array_map('strval', (array) ($workcell['allowed_files'] ?? [])));
        $outOfScope = $this->outOfScopeFiles($modelOutput, $allowedFiles);
        if ($outOfScope !== []) {
            $signals[] = ['id' => self::SIGNAL_OUT_OF_SCOPE_FILE, 'detail' => 'referenced outside allowed_files: '.implode(', ', $outOfScope)];
        }

        $knownSymbols = array_values(array_map('strval', (array) ($workcell['known_symbols'] ?? [])));
        if ($knownSymbols !== []) {
            $hallucinated = $this->hallucinatedSymbols($modelOutput, $knownSymbols);
            if ($hallucinated !== []) {
                $signals[] = ['id' => self::SIGNAL_HALLUCINATED_SYMBOL, 'detail' => 'symbols absent from discovery manifest: '.implode(', ', $hallucinated)];
            }
        }

        if ($this->looksLikeDiff($modelOutput) && ! $this->hasRealChangeLines($modelOutput)) {
            $signals[] = ['id' => self::SIGNAL_RESTATES_EXISTING_CODE, 'detail' => 'diff has no added/removed lines, only context'];
        }

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            if (preg_match($pattern, $modelOutput) === 1) {
                $signals[] = ['id' => self::SIGNAL_PLACEHOLDER_MARKER, 'detail' => 'placeholder pattern matched: '.$pattern];
                break;
            }
        }

        $verificationCommand = trim((string) ($workcell['verification_command'] ?? ''));
        if ($verificationCommand !== '' && ! str_contains($modelOutput, $verificationCommand)) {
            $signals[] = ['id' => self::SIGNAL_IGNORES_VERIFICATION_COMMAND, 'detail' => 'required command never mentioned: '.$verificationCommand];
        }

        $weak = $signals !== [];
        $repairHint = null;
        if ($weak) {
            foreach (self::CONTEXT_SECTION_BY_SIGNAL as $signalId => $section) {
                $match = array_values(array_filter($signals, static fn (array $s): bool => $s['id'] === $signalId));
                if ($match !== []) {
                    $repairHint = sprintf('signal=%s: re-emphasize %s', $signalId, $section);
                    break;
                }
            }
        }

        return [
            'weak' => $weak,
            'signals' => $signals,
            'repair_hint' => $repairHint,
        ];
    }

    /**
     * Post-gate variant for the FINAL applied diff: scans only the ADDED
     * lines ('+' prefix stripped) for placeholder markers (TODO/FIXME,
     * ellipsis-only body, fake always-true assertion).
     *
     * The other inspect() signals are deliberately excluded here because on a
     * green gate they are either covered elsewhere or false-positive:
     * scope escapes are ScopeGuard's job, the verification command was
     * actually RUN by the VerificationGate (not merely mentioned), and a
     * no-change diff already fires E1's intent-falsification probe. What can
     * still survive a green gate is a placeholder inside applied code whose
     * tests pass vacuously — exactly what this catches.
     *
     * @return array{weak:bool, signals:list<array{id:string,detail:string}>}
     */
    public function inspectAppliedDiff(string $diff): array
    {
        $added = [];
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added[] = substr($line, 1);
            }
        }
        $text = implode("\n", $added);

        $signals = [];
        if ($text !== '') {
            foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $signals[] = ['id' => self::SIGNAL_PLACEHOLDER_MARKER, 'detail' => 'placeholder in added lines: '.$pattern];
                    break;
                }
            }
        }

        return ['weak' => $signals !== [], 'signals' => $signals];
    }

    private function looksTruncated(string $output): bool
    {
        if (trim($output) === '') {
            return false; // handled separately as "empty"
        }

        return substr_count($output, '{') !== substr_count($output, '}')
            || substr_count($output, '[') !== substr_count($output, ']')
            || substr_count($output, '(') !== substr_count($output, ')');
    }

    private function looksLikeDiff(string $output): bool
    {
        return str_contains($output, '@@') || str_contains($output, '--- ') || str_contains($output, '+++ ');
    }

    private function hasRealChangeLines(string $output): bool
    {
        foreach (explode("\n", $output) as $line) {
            if ((str_starts_with($line, '+') && ! str_starts_with($line, '+++'))
                || (str_starts_with($line, '-') && ! str_starts_with($line, '---'))) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $allowedFiles
     * @return list<string> */
    private function outOfScopeFiles(string $output, array $allowedFiles): array
    {
        if ($allowedFiles === [] || ! preg_match_all('/^(?:\+\+\+|---)\s+(?:[ab]\/)?(\S+)/m', $output, $matches)) {
            return [];
        }

        $referenced = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $p): bool => $p !== '/dev/null',
        )));

        return array_values(array_diff($referenced, $allowedFiles));
    }

    /** @param  list<string>  $knownSymbols
     * @return list<string> */
    private function hallucinatedSymbols(string $output, array $knownSymbols): array
    {
        $added = [];
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                $added[] = $line;
            }
        }
        if ($added === []) {
            return [];
        }

        $found = [];
        foreach ($added as $line) {
            if (preg_match_all('/\b[A-Z][A-Za-z0-9]{3,}\b/', $line, $m)) {
                foreach ($m[0] as $symbol) {
                    $found[$symbol] = true;
                }
            }
        }

        return array_values(array_diff(array_keys($found), $knownSymbols));
    }
}
