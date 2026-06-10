<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * AOBG N3.F1 — the COST-FREE, deterministic decomposer (the default + test path).
 *
 * Splits an intent into ordered steps on natural clause boundaries (newline
 * bullets `- `/`* `/`1.`, `;`, and the same connective vocabulary the
 * {@see \App\Services\Ai\Mission\MissionFactoryService} classifier already uses:
 * "depois", "por fim", "e tambem", "alem disso", " then ", " and "). It chains each
 * step on its predecessor, so the produced plan is a valid LINEAR DAG (a real,
 * acyclic, topologically-orderable chain) — never a cycle, never a dangling dep.
 *
 * It is the HONEST-DEGRADE path: when no decompose provider is configured (or the
 * provider one is unavailable), the obra is still planned deterministically with
 * ZERO provider spend — and it is the decomposer every test injects, so the whole
 * spine is provable cost-free. A single-clause intent yields a single-node plan
 * (an obra of one step is still a valid obra).
 *
 * PROVIDER-SAFE by construction: it only ever re-slices the operator's own intent
 * text into step labels — it adds no fabricated content and reaches no external
 * surface.
 */
final class DeterministicObraDecomposer implements ObraDecomposer
{
    public function label(): string
    {
        return 'deterministic';
    }

    /**
     * @param  array<string,mixed>  $opts
     * @return list<ObraNodeDraft>
     */
    public function decompose(string $intent, array $opts = []): array
    {
        $intent = trim($intent);
        if ($intent === '') {
            return [];
        }

        $clauses = $this->splitClauses($intent);

        $drafts = [];
        $prevKey = null;
        foreach (array_values($clauses) as $i => $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            $key = 'step-'.($i + 1);
            $drafts[] = new ObraNodeDraft(
                key: $key,
                title: mb_substr($clause, 0, 80),
                request: $clause,
                targetArea: $this->guessTargetArea($clause),
                // Chain on the prior step → a valid linear DAG (acyclic by build).
                dependsOn: $prevKey !== null ? [$prevKey] : [],
            );
            $prevKey = $key;
        }

        return $drafts;
    }

    /**
     * Split the intent into ordered clauses. Prefers explicit bullet/numbered list
     * structure; falls back to connective + `;` boundaries; else the whole intent is
     * one step.
     *
     * @return list<string>
     */
    private function splitClauses(string $intent): array
    {
        // 1) Bullet / numbered list lines → one step per line item.
        $lines = preg_split('/\R/u', $intent) ?: [];
        $bullets = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:[-*•]\s+|\d+[.)]\s+)(.+)$/u', $line, $m) === 1) {
                $bullets[] = trim($m[1]);
            }
        }
        if (count($bullets) >= 2) {
            return $bullets;
        }

        // 2) Connective / semicolon boundaries on the flattened text.
        $flat = trim(preg_replace('/\s+/u', ' ', $intent) ?? $intent);
        $parts = preg_split(
            '/\s*(?:;|,?\s*(?:depois|por fim|em seguida|alem disso|e tambem)\b|\bthen\b|\band then\b)\s*/iu',
            $flat,
        ) ?: [$flat];

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            return $parts;
        }

        // 3) A single, indivisible step — an obra of one is still a valid obra.
        return [$flat];
    }

    /**
     * Best-effort file/dir/module hint pulled from an explicit path-looking token in
     * the clause (e.g. "app/Services/X.php" or "config/atlas.php"). Null when none —
     * the brain anchoring then biases purely on the request text. Deterministic.
     */
    private function guessTargetArea(string $clause): ?string
    {
        if (preg_match('#\b([A-Za-z0-9_./-]+\.(?:php|md|json|ya?ml|js|ts|py))\b#', $clause, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#\b((?:app|config|database|tests|routes|resources|domains)/[A-Za-z0-9_./-]+)#', $clause, $m) === 1) {
            return rtrim($m[1], '/.');
        }

        return null;
    }
}
