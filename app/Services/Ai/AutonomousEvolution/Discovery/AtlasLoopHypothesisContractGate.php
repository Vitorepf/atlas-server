<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ARBOR-GRAFT HC1 — the 4-line hypothesis contract gate (Mechanism / Hypothesis / Observable / Conflicts).
 *
 * Arbor's TreeAddNode requires a structured hypothesis whose "Conflicts" line states the anti-re-tread
 * orthogonality argument. We graft the STRUCTURE — but enforced OUT-OF-PROCESS by a DETERMINISTIC validator,
 * never by Arbor's honor-gate (Arbor §12 explicitly rejects the "paste a LOAD_RECEIPT" trust gate a small
 * model just fabricates).
 *
 * FLOOR DISCIPLINE (critical): this gates DRAFT ADMISSION (whether a candidate enters the queue), NEVER the
 * certification gate, and NEVER relaxes it. Every rejection is PURELY STRUCTURAL / evidence-resolvable:
 *   (a) each of the 4 labelled lines is present and non-empty,
 *   (b) the Conflicts line (anti-re-tread) is non-empty,
 *   (c) any path-like evidence token cited in the contract RESOLVES to a real file (probe-disconnected
 *       candidates are rejected) — resolution is filesystem-checked, not model-asserted.
 * It NEVER scores the prose quality or plausibility of a line — that would slide into the very honor-gate
 * Arbor rejects. A genuinely good candidate phrased outside rubric language is not rejected on prose grounds.
 */
final class AtlasLoopHypothesisContractGate
{
    private const LABELS = ['mechanism', 'hypothesis', 'observable', 'conflicts'];

    /**
     * Parse the 4 labelled lines. Pure. Missing labels map to null.
     *
     * @return array<string, ?string>
     */
    public static function parse(string $text): array
    {
        $out = array_fill_keys(self::LABELS, null);
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(Mechanism|Hypothesis|Observable|Conflicts)\s*:\s*(.*)$/i', $line, $m) === 1) {
                $out[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $out;
    }

    /**
     * Extract path-like evidence tokens (e.g. app/Foo.php, tests/BarTest.php). Pure.
     *
     * @return list<string>
     */
    public static function evidenceTokens(string $text): array
    {
        if (preg_match_all('#[A-Za-z0-9_./-]+\.[A-Za-z0-9]+#', $text, $m) === false) {
            return [];
        }
        $tokens = [];
        foreach ($m[0] as $tok) {
            // only path-shaped tokens (contain a slash) — avoid matching plain words like "v1.2"
            if (str_contains($tok, '/')) {
                $tokens[] = $tok;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Structurally validate a parsed contract. $pathExists resolves an evidence token to a real file.
     * Pure (given the resolver).
     *
     * @param  array<string, ?string>  $parsed
     * @param  callable(string):bool   $pathExists
     * @return array{ok: bool, reasons: list<string>}
     */
    public static function validate(array $parsed, string $rawText, callable $pathExists): array
    {
        $reasons = [];
        foreach (self::LABELS as $label) {
            if (($parsed[$label] ?? '') === null || trim((string) ($parsed[$label] ?? '')) === '') {
                $reasons[] = "missing_or_empty:{$label}";
            }
        }

        // Any cited path token must resolve — a probe-disconnected candidate (cites a file that does not
        // exist) is rejected. If it cites no path token at all, that is also probe-disconnected.
        $tokens = self::evidenceTokens($rawText);
        if ($tokens === []) {
            $reasons[] = 'no_evidence_token';
        } else {
            $anyResolved = false;
            foreach ($tokens as $tok) {
                if ($pathExists($tok)) {
                    $anyResolved = true;
                    break;
                }
            }
            if (! $anyResolved) {
                $reasons[] = 'evidence_unresolved';
            }
        }

        return ['ok' => $reasons === [], 'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * Admit a structured-objective text against a real repo root (thin FS wrapper). Zero provider calls.
     *
     * @return array{ok: bool, reasons: list<string>}
     */
    public function admit(string $text, string $repoRoot): array
    {
        $root = rtrim($repoRoot, '/');
        $resolver = static fn (string $tok): bool => is_file($root.'/'.ltrim($tok, '/'));

        return self::validate(self::parse($text), $text, $resolver);
    }
}
