<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Semantic;

/**
 * MAESTRO ORPHAN-CALLER VERIFIER — for orphan-wiring packets (add a call site to a currently-dead sibling
 * service), it checks the PROPOSED insertion site sits inside a method that already calls an ANALOGOUS sibling
 * (role-tokens overlapping the orphan target's by >=1). It FAILS the sibling-by-role-token mismatch class —
 * where the site only calls a sibling that plays a DIFFERENT role (e.g. wiring a *AuditPanel next to a
 * *Committer call) — the exact false-twin trap.
 *
 * Anchors the orphan target with AtlasMaestroSemanticSymbolResolver (duck-typed so it's testable) and skips
 * cleanly for non-orphan-wiring packets, so it never over-reaches into pure new-file packets.
 */
final class AtlasMaestroOrphanCallerVerifier
{
    private const PREFIX_STOPWORDS = ['atlas', 'loop'];

    public function __construct(private readonly ?object $resolver = null)
    {
    }

    /**
     * @param  array{orphan_target?:string, insertion_site?:array{file?:string, line?:int, enclosing_source?:string}}  $orphanPacket
     * @return array<string,mixed>
     */
    public function verify(array $orphanPacket): array
    {
        $site = $orphanPacket['insertion_site'] ?? null;
        $source = is_array($site) ? (string) ($site['enclosing_source'] ?? '') : '';
        if (! is_array($site) || (trim((string) ($site['file'] ?? '')) === '' && trim($source) === '')) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'not_orphan_wiring_shaped'];
        }

        $target = (string) ($orphanPacket['orphan_target'] ?? '');
        $short = $this->shortName($target);
        $orphanTokens = $this->roleTokens($short);

        // FACT-anchor the orphan target (best-effort; the role-token check stands on the name either way).
        $resolved = ($this->resolver ?? new AtlasMaestroSemanticSymbolResolver)->resolve($target);

        if (trim($source) === '') {
            $source = $this->readEnclosing((string) ($site['file'] ?? ''), (int) ($site['line'] ?? 0));
        }

        $siblings = $this->observedSiblings($source, $short);
        if ($siblings === []) {
            return ['ok' => false, 'reason' => 'no_sibling_call_at_site', 'expected_role_tokens' => $orphanTokens, 'target_resolved' => (bool) ($resolved['exists'] ?? false)];
        }

        foreach ($siblings as $sibling) {
            $overlap = array_values(array_intersect($orphanTokens, $this->roleTokens($sibling)));
            if ($overlap !== []) {
                return ['ok' => true, 'sibling_role_match' => true, 'sibling' => $sibling, 'overlap' => $overlap, 'target_resolved' => (bool) ($resolved['exists'] ?? false)];
            }
        }

        return [
            'ok' => false,
            'reason' => 'sibling_role_mismatch',
            'expected_role_tokens' => $orphanTokens,
            'observed_sibling' => $siblings[0],
            'target_resolved' => (bool) ($resolved['exists'] ?? false),
        ];
    }

    private function readEnclosing(string $relFile, int $line): string
    {
        $abs = base_path().'/'.ltrim($relFile, '/');
        if ($relFile === '' || ! is_file($abs) || $line < 1) {
            return '';
        }
        $lines = @file($abs, FILE_IGNORE_NEW_LINES) ?: [];
        // A bounded window around the site approximates the enclosing method body (deterministic, read-only).
        $from = max(0, $line - 40);
        $to = min(count($lines), $line + 40);

        return implode("\n", array_slice($lines, $from, $to - $from));
    }

    /**
     * @return list<string>  PascalCase multi-word class references in the source (the orphan itself excluded)
     */
    private function observedSiblings(string $source, string $orphanShort): array
    {
        preg_match_all('/\b([A-Z][a-z0-9]+(?:[A-Z][a-z0-9]+)+)\b/', $source, $matches);
        $out = [];
        foreach ($matches[1] as $name) {
            if ($name !== $orphanShort) {
                $out[$name] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @return list<string>
     */
    private function roleTokens(string $short): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $this->shortName($short)) ?? $short;
        preg_match_all('/[a-z]+/', strtolower($spaced), $matches);

        $set = [];
        foreach ($matches[0] as $token) {
            if (strlen($token) >= 3 && ! in_array($token, self::PREFIX_STOPWORDS, true)) {
                $set[$token] = true;
            }
        }

        return array_keys($set);
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));

        return (string) end($parts);
    }
}
