<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Decision Receipt execution-boundary guard.
 *
 * Pure, deterministic implementation of the kernel `decision-receipt` step.
 * The doc states the receipt is "a linha que separa intencao de acao": before
 * any governed change runs, a receipt must exist, be complete, be unexpired,
 * and the target file must sit inside the receipt's allowed scope and outside
 * its forbidden scope. This service answers exactly that question and returns
 * the single controlled verdict — `proceed` or `stop` — with a machine reason.
 *
 * Contract (from the doc "Contratos" / "Regras para IA" / "Riscos"):
 *   Campos minimos: decision_id, trace_id, obra, domain, flow, provider/model,
 *     fallback, confidence, budget, autonomy, allowed_scope, forbidden_scope,
 *     rollback, required_gates, signature (quando aplicavel).
 *   Fluxo: Decide emite -> Receipt cristaliza -> Executor so executa dentro do
 *     contrato.
 *   Regra para IA: "Se o arquivo alvo nao esta permitido ou esta proibido, deve
 *     parar." Forbidden vence allowed (defense in depth).
 *   Riscos enforcados:
 *     - Receipt incompleto vira teatro de governanca  -> stop:receipt_incomplete.
 *     - Assinatura existir sem validar payload real    -> stop:signature_invalid.
 *     - Runtime ignorar escopo permitido/proibido      -> stop:target_in_forbidden_scope /
 *                                                          stop:target_outside_allowed_scope.
 *
 * Scope semantics mirror the canonical Atlas Work Packet matcher
 * (AtlasCodeScopeGuardMatcher): `.gitignore`-style globs, `**` crosses `/`,
 * a trailing `/` means "directory and everything under it", and an empty
 * allowed list permits anything not explicitly forbidden.
 *
 * The service NEVER applies a diff, calls a provider, mutates a codebase or
 * touches the database. It returns the gate verdict plus an audit receipt;
 * callers either execute inside the contract or stop.
 *
 * @see docs/engineering-knowledge-base/system-graph/decision-receipt.md
 */
final class AtlasDecisionReceiptGuardService
{
    /** Stable schema id for the gate decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.kernel.decision_receipt_guard.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_PROCEED = 'proceed';
    public const VERDICT_STOP = 'stop';

    /**
     * Minimum receipt fields the doc lists under "Contratos". A receipt missing
     * any of these is "teatro de governanca" and the gate must stop.
     *
     * `signature` is intentionally NOT here: the doc qualifies it as "quando
     * aplicavel" (only when signing is required), so it is validated
     * conditionally — see decide().
     *
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'decision_id',
        'trace_id',
        'obra',
        'domain',
        'flow',
        'provider',
        'fallback',
        'confidence',
        'budget',
        'autonomy',
        'allowed_scope',
        'forbidden_scope',
        'rollback',
        'required_gates',
    ];

    /**
     * Gate one target file against one decision receipt.
     *
     * @param  array<string,mixed>|null  $receipt
     *         null / [] means "no receipt was issued" — the strongest stop.
     *         allowed_scope / forbidden_scope : list<string> of glob patterns.
     *         expires_at      : ISO-8601 string; absent => treated as not expired.
     *         requires_signature : bool (default false). When true the receipt
     *           MUST carry a non-empty `signature` AND a `signature_valid` flag
     *           that is true — "assinatura existir sem validar payload real" is a
     *           documented risk, so an unverified signature stops.
     * @param  string  $targetFile  posix-relative path about to be written/patched.
     * @param  array<string,mixed>  $context  { now : ISO-8601 string for expiry checks }
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function decide(?array $receipt, string $targetFile, array $context = []): array
    {
        $target = $this->normalizePath($targetFile);

        // Rule 0 — no receipt at all: the boundary is absolute.
        // "Nenhuma execucao relevante deve ocorrer sem receipt."
        if ($receipt === null || $receipt === []) {
            return $this->verdict(self::VERDICT_STOP, 'no_receipt', $target, [
                'missing_fields' => self::REQUIRED_FIELDS,
            ]);
        }

        // Rule 1 — completeness: every minimum field must be present and non-empty.
        // An incomplete receipt is governance theater and never authorizes action.
        $missing = $this->missingFields($receipt);
        if ($missing !== []) {
            return $this->verdict(self::VERDICT_STOP, 'receipt_incomplete', $target, [
                'missing_fields' => $missing,
            ]);
        }

        // Rule 2 — expiry: a receipt is a contract before runtime, not forever.
        if ($this->isExpired($receipt, $context)) {
            return $this->verdict(self::VERDICT_STOP, 'receipt_expired', $target, [
                'expires_at' => AtlasAaeosValueNormalizer::stringOrNull($receipt['expires_at'] ?? null),
            ]);
        }

        // Rule 3 — signature when applicable: never trust a signature that does
        // not validate the real payload.
        if ((bool) ($receipt['requires_signature'] ?? false)) {
            $signature = AtlasAaeosValueNormalizer::stringOrNull($receipt['signature'] ?? null);
            $signatureValid = (bool) ($receipt['signature_valid'] ?? false);
            if ($signature === null || ! $signatureValid) {
                return $this->verdict(self::VERDICT_STOP, 'signature_invalid', $target, [
                    'signature_present' => $signature !== null,
                    'signature_valid' => $signatureValid,
                ]);
            }
        }

        // Rule 4 — forbidden wins (defense in depth): a target that matches the
        // forbidden scope stops, even if it also matches the allowed scope.
        $forbidden = $this->patternList($receipt['forbidden_scope'] ?? []);
        if ($this->matchedBy($target, $forbidden)) {
            return $this->verdict(self::VERDICT_STOP, 'target_in_forbidden_scope', $target, [
                'allowed_scope' => $this->patternList($receipt['allowed_scope'] ?? []),
                'forbidden_scope' => $forbidden,
            ]);
        }

        // Rule 5 — allowed scope: when the receipt declares an allowed scope, the
        // target MUST match it. An empty allowed scope permits anything not
        // forbidden (same convention as the canonical Work Packet matcher).
        $allowed = $this->patternList($receipt['allowed_scope'] ?? []);
        if ($allowed !== [] && ! $this->matchedBy($target, $allowed)) {
            return $this->verdict(self::VERDICT_STOP, 'target_outside_allowed_scope', $target, [
                'allowed_scope' => $allowed,
                'forbidden_scope' => $forbidden,
            ]);
        }

        // All boundary conditions satisfied: execution is authorized inside the
        // contract.
        return $this->verdict(self::VERDICT_PROCEED, 'within_contract', $target, [
            'allowed_scope' => $allowed,
            'forbidden_scope' => $forbidden,
            'required_gates' => $this->patternList($receipt['required_gates'] ?? []),
        ]);
    }

    /**
     * Bulk gate: of a changeset, which files violate the receipt boundary?
     * Returns only the stops, in input order, so a caller can block a whole
     * patch the moment any file falls outside the contract.
     *
     * @param  array<string,mixed>|null  $receipt
     * @param  array<int,string>  $targetFiles
     * @param  array<string,mixed>  $context
     *
     * @return array<int,array{file:string,reason:string}>
     */
    public function violations(?array $receipt, array $targetFiles, array $context = []): array
    {
        $out = [];
        foreach ($targetFiles as $raw) {
            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decision = $this->decide($receipt, $raw, $context);
            if ($decision['verdict'] !== self::VERDICT_PROCEED) {
                $out[] = ['file' => $decision['target'], 'reason' => $decision['reason']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $receipt
     *
     * @return list<string>
     */
    private function missingFields(array $receipt): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $receipt) || $this->isEmptyValue($receipt[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context
     */
    private function isExpired(array $receipt, array $context): bool
    {
        $expiresAt = AtlasAaeosValueNormalizer::stringOrNull($receipt['expires_at'] ?? null);
        if ($expiresAt === null) {
            return false;
        }
        $expiry = strtotime($expiresAt);
        if ($expiry === false) {
            return false;
        }
        $nowRaw = AtlasAaeosValueNormalizer::stringOrNull($context['now'] ?? null);
        $now = $nowRaw !== null ? strtotime($nowRaw) : false;
        if ($now === false) {
            $now = time();
        }

        return $now > $expiry;
    }

    /**
     * @param  array<int,string>  $patterns
     */
    private function matchedBy(string $normalizedPath, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }
        $matched = false;
        foreach ($patterns as $raw) {
            $pattern = trim($raw);
            if ($pattern === '') {
                continue;
            }
            $negated = false;
            if (str_starts_with($pattern, '!')) {
                $negated = true;
                $pattern = ltrim(substr($pattern, 1));
            }
            if ($this->matchSinglePattern($normalizedPath, $this->normalizePattern($pattern))) {
                $matched = ! $negated;
            }
        }

        return $matched;
    }

    private function matchSinglePattern(string $path, string $pattern): bool
    {
        return preg_match($this->patternToRegex($pattern), $path) === 1;
    }

    private function patternToRegex(string $pattern): string
    {
        $out = '';
        $i = 0;
        $len = strlen($pattern);
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '*' && $i + 1 < $len && $pattern[$i + 1] === '*') {
                $out .= '.*';
                $i += 2;
                if ($i < $len && $pattern[$i] === '/') {
                    $out .= '/?';
                    $i++;
                }

                continue;
            }
            if ($c === '*') {
                $out .= '[^/]*';
                $i++;

                continue;
            }
            if ($c === '?') {
                $out .= '[^/]';
                $i++;

                continue;
            }
            if ($c === '[') {
                $j = $i + 1;
                while ($j < $len && $pattern[$j] !== ']') {
                    $j++;
                }
                if ($j < $len) {
                    $out .= '['.substr($pattern, $i + 1, $j - $i - 1).']';
                    $i = $j + 1;

                    continue;
                }
            }
            $out .= preg_quote($c, '#');
            $i++;
        }

        return '#^'.$out.'$#u';
    }

    private function normalizePath(string $raw): string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return '';
        }
        $clean = preg_replace('#^\./+#', '', $clean) ?? $clean;
        $clean = ltrim($clean, '/');
        $clean = str_replace('\\', '/', $clean);
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        return $clean;
    }

    private function normalizePattern(string $raw): string
    {
        $pattern = trim($raw);
        $pattern = preg_replace('#^\./+#', '', $pattern) ?? $pattern;
        $pattern = ltrim($pattern, '/');
        $pattern = str_replace('\\', '/', $pattern);
        if (str_ends_with($pattern, '/')) {
            $pattern .= '**';
        }

        return $pattern;
    }

    /**
     * @return array<int,string>
     */
    private function patternList(mixed $values): array
    {
        return AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($values);
    }

    /**
     * @param  array<string,mixed>  $detail
     *
     * @return array<string,mixed>
     */
    private function verdict(string $verdict, string $reason, string $target, array $detail): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'allowed' => $verdict === self::VERDICT_PROCEED,
            'reason' => $reason,
            'target' => $target,
            'detail' => $detail,
        ];
    }
}
