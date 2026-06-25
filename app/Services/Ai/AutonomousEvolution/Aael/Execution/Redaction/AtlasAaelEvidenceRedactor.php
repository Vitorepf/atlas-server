<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

/**
 * Pure (no I/O) redactor. Resolves an evidence_kind to its policy via
 * AtlasAaelEvidenceRedactionPolicyRegistry, walks the payload (string or nested array),
 * applies every rule of the policy, and returns a RedactedEvidence value object.
 *
 * Determinism: same (kind, payload) → byte-identical RedactedEvidence on every call. The
 * content_hash is sha256 of (fixed salt || canonical serialization of the ORIGINAL payload)
 * so it never reveals the raw value yet is replayable across processes.
 *
 * Non-UTF8 binary payloads are replaced wholesale with the sentinel [REDACTED:binary] so the
 * downstream log never receives uninterpretable bytes.
 */
final class AtlasAaelEvidenceRedactor
{
    public const BINARY_SENTINEL = '[REDACTED:binary]';

    public function __construct(private readonly AtlasAaelEvidenceRedactionPolicyRegistry $registry) {}

    /**
     * @param  string|array<int|string,mixed>  $payload
     */
    public function redact(string $evidenceKind, string|array $payload): RedactedEvidence
    {
        $contentHash = $this->contentHash($payload);

        if (is_string($payload) && ! $this->isUtf8($payload)) {
            return new RedactedEvidence(
                payload: self::BINARY_SENTINEL,
                contentHash: $contentHash,
                ruleHits: ['__binary__' => 1],
                evidenceKind: $evidenceKind,
            );
        }

        $policy = $this->registry->resolve($evidenceKind);
        $ruleHits = [];
        foreach ($policy->rules as $idx => $rule) {
            $ruleHits[$this->ruleKey($idx, $rule)] = 0;
        }

        $redacted = $this->walk($payload, $policy, $ruleHits);

        return new RedactedEvidence(
            payload: $redacted,
            contentHash: $contentHash,
            ruleHits: $ruleHits,
            evidenceKind: $evidenceKind,
        );
    }

    /**
     * @param  string|array<int|string,mixed>  $value
     * @param  array<string,int>  $ruleHits
     * @return string|array<int|string,mixed>
     */
    private function walk(string|array $value, AtlasAaelEvidenceRedactionPolicy $policy, array &$ruleHits): string|array
    {
        if (is_string($value)) {
            return $this->applyRulesToString($value, $policy, $ruleHits);
        }
        $out = [];
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $out[$key] = $this->walk($child, $policy, $ruleHits);

                continue;
            }
            if (is_string($child)) {
                // Apply KIND_KEY_NAME rules first (key-targeted) then string-pattern rules.
                $candidate = $child;
                foreach ($policy->rules as $idx => $rule) {
                    if ($rule->kind !== AtlasAaelEvidenceRedactionRule::KIND_KEY_NAME) {
                        continue;
                    }
                    if ($this->keyNameMatches((string) $key, $rule->pattern)) {
                        $candidate = $rule->replacement;
                        $ruleHits[$this->ruleKey($idx, $rule)]++;
                    }
                }
                $out[$key] = $this->applyRulesToString($candidate, $policy, $ruleHits);

                continue;
            }
            $out[$key] = $child;
        }

        return $out;
    }

    /**
     * @param  array<string,int>  $ruleHits
     */
    private function applyRulesToString(string $value, AtlasAaelEvidenceRedactionPolicy $policy, array &$ruleHits): string
    {
        foreach ($policy->rules as $idx => $rule) {
            $key = $this->ruleKey($idx, $rule);
            switch ($rule->kind) {
                case AtlasAaelEvidenceRedactionRule::KIND_REGEX:
                    if (@preg_match($rule->pattern, '') === false) {
                        continue 2;
                    }
                    if ($rule->scope === AtlasAaelEvidenceRedactionRule::SCOPE_FULL) {
                        if (preg_match($rule->pattern, $value) === 1) {
                            $value = $rule->replacement;
                            $ruleHits[$key]++;
                        }
                    } else {
                        $count = 0;
                        $replaced = preg_replace($rule->pattern, $rule->replacement, $value, -1, $count);
                        if (is_string($replaced) && $count > 0) {
                            $value = $replaced;
                            $ruleHits[$key] += $count;
                        }
                    }
                    break;
                case AtlasAaelEvidenceRedactionRule::KIND_PATH_PREFIX:
                    if ($rule->pattern !== '' && str_contains($value, $rule->pattern)) {
                        $count = substr_count($value, $rule->pattern);
                        $value = str_replace($rule->pattern, $rule->replacement, $value);
                        $ruleHits[$key] += $count;
                    }
                    break;
                case AtlasAaelEvidenceRedactionRule::KIND_JSONPATH:
                case AtlasAaelEvidenceRedactionRule::KIND_KEY_NAME:
                    // Handled in array walk; on plain strings these are no-ops.
                    break;
            }
        }

        return $value;
    }

    private function keyNameMatches(string $key, string $pattern): bool
    {
        if ($pattern === '' || $pattern === '*') {
            return true;
        }

        return $key === $pattern;
    }

    private function ruleKey(int $idx, AtlasAaelEvidenceRedactionRule $rule): string
    {
        return $idx.':'.$rule->kind.':'.substr(hash('sha256', $rule->pattern), 0, 8);
    }

    /**
     * @param  string|array<int|string,mixed>  $payload
     */
    private function contentHash(string|array $payload): string
    {
        $canonical = is_string($payload)
            ? $payload
            : (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', RedactedEvidence::CONTENT_HASH_SALT.'|'.$canonical);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->sortRecursive($v);
        }

        return $out;
    }

    private function isUtf8(string $s): bool
    {
        return $s === '' || mb_check_encoding($s, 'UTF-8');
    }
}
