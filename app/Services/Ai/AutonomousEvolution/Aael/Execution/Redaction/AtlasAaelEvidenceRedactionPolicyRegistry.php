<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

/**
 * Per-evidence_kind redaction-policy registry. Read-only, deterministic, source-of-truth =
 * config('atlas.aael.redaction.policies'). Pure config → value-object materialization;
 * no I/O, no mutation, no provider calls.
 *
 * FAIL-CLOSED contract: an unknown evidence_kind ALWAYS receives the sealed default policy
 * that redacts the entire payload to a deterministic `[REDACTED:unknown_kind]` sentinel — a
 * new evidence channel cannot silently bypass redaction by being absent from the config map.
 */
final class AtlasAaelEvidenceRedactionPolicyRegistry
{
    public const FROZEN_DEFAULT_KINDS = [
        'stdout',
        'stderr',
        'command_args',
        'env_snapshot',
        'file_path',
        'http_response',
        'agent_prompt',
    ];

    public const UNKNOWN_KIND_SENTINEL = '[REDACTED:unknown_kind]';

    /** @var array<string,AtlasAaelEvidenceRedactionPolicy>|null */
    private ?array $memo = null;

    private ?AtlasAaelEvidenceRedactor $redactor = null;

    /**
     * @param  array<string,list<array<string,mixed>>>|null  $overridePolicies
     */
    public function __construct(private readonly ?array $overridePolicies = null) {}

    public function resolve(string $evidenceKind): AtlasAaelEvidenceRedactionPolicy
    {
        $map = $this->memo ??= $this->buildAll();

        return $map[$evidenceKind] ?? $this->sealedFailClosedDefault($evidenceKind);
    }

    /**
     * Façade — runs the resolved policy through the AtlasAaelEvidenceRedactor on the caller's
     * behalf, so any production site holding the registry can redact in one call without
     * having to wire the redactor itself. This is the production call path for the redactor.
     *
     * @param  string|array<int|string,mixed>  $payload
     */
    public function redact(string $evidenceKind, string|array $payload): RedactedEvidence
    {
        $this->redactor ??= new AtlasAaelEvidenceRedactor($this);

        return $this->redactor->redact($evidenceKind, $payload);
    }

    /**
     * @return array<string,AtlasAaelEvidenceRedactionPolicy>
     */
    public function all(): array
    {
        return $this->memo ??= $this->buildAll();
    }

    private function sealedFailClosedDefault(string $evidenceKind): AtlasAaelEvidenceRedactionPolicy
    {
        return new AtlasAaelEvidenceRedactionPolicy(
            evidenceKind: $evidenceKind,
            rules: [
                new AtlasAaelEvidenceRedactionRule(
                    kind: AtlasAaelEvidenceRedactionRule::KIND_REGEX,
                    pattern: '/(?s).*/',
                    replacement: self::UNKNOWN_KIND_SENTINEL,
                    scope: AtlasAaelEvidenceRedactionRule::SCOPE_FULL,
                ),
            ],
            isFailClosedDefault: true,
        );
    }

    /**
     * @return array<string,AtlasAaelEvidenceRedactionPolicy>
     */
    private function buildAll(): array
    {
        $raw = $this->overridePolicies ?? (array) (function_exists('config') ? config('atlas.aael.redaction.policies', []) : []);

        $out = [];
        foreach (self::FROZEN_DEFAULT_KINDS as $kind) {
            $out[$kind] = $this->materializePolicy($kind, $this->mergeFrozenAndConfigured($kind, $raw));
        }
        // Any config-only kind not in the frozen list is also materialized so the operator can
        // extend coverage without code change — but the frozen set must always be honored.
        foreach ($raw as $kind => $rules) {
            $kind = (string) $kind;
            if ($kind === '' || isset($out[$kind])) {
                continue;
            }
            $out[$kind] = $this->materializePolicy($kind, is_array($rules) ? array_values($rules) : []);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $raw
     * @return list<array<string,mixed>>
     */
    private function mergeFrozenAndConfigured(string $kind, array $raw): array
    {
        $configured = isset($raw[$kind]) && is_array($raw[$kind]) ? array_values($raw[$kind]) : [];

        // Frozen built-ins ALWAYS lead so an operator override can extend but cannot silently
        // remove a core secret-pattern rule.
        return array_values(array_merge($this->frozenRulesFor($kind), $configured));
    }

    /**
     * @param  list<array<string,mixed>>  $rawRules
     */
    private function materializePolicy(string $kind, array $rawRules): AtlasAaelEvidenceRedactionPolicy
    {
        $rules = [];
        foreach ($rawRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $ruleKind = (string) ($rule['kind'] ?? AtlasAaelEvidenceRedactionRule::KIND_REGEX);
            if (! in_array($ruleKind, AtlasAaelEvidenceRedactionRule::ALLOWED_KINDS, true)) {
                continue;
            }
            $rules[] = new AtlasAaelEvidenceRedactionRule(
                kind: $ruleKind,
                pattern: (string) ($rule['pattern'] ?? ''),
                replacement: (string) ($rule['replacement'] ?? '[REDACTED]'),
                scope: (string) ($rule['scope'] ?? AtlasAaelEvidenceRedactionRule::SCOPE_MATCH),
            );
        }

        return new AtlasAaelEvidenceRedactionPolicy($kind, $rules, false);
    }

    /**
     * Frozen built-in rules. These cover the operator-secret families the AAEL evidence log
     * MUST scrub regardless of operator configuration (Anthropic api keys, sk- prefixed keys,
     * OAuth bearer tokens, /Users/<op>/ home prefixes, .env=value lines).
     *
     * @return list<array<string,mixed>>
     */
    private function frozenRulesFor(string $kind): array
    {
        $secretFamilies = [
            [
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_REGEX,
                'pattern' => '/sk-ant-[A-Za-z0-9_\-]{16,}/',
                'replacement' => '[REDACTED:anthropic_api_key]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ],
            [
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_REGEX,
                'pattern' => '/sk-[A-Za-z0-9_\-]{16,}/',
                'replacement' => '[REDACTED:sk_key]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ],
            [
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_REGEX,
                'pattern' => '/Bearer\s+[A-Za-z0-9._\-]{16,}/i',
                'replacement' => '[REDACTED:oauth_bearer]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ],
            [
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_PATH_PREFIX,
                'pattern' => '/Users/',
                'replacement' => '[REDACTED:home]/',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ],
            [
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_REGEX,
                'pattern' => '/^[A-Z][A-Z0-9_]*=.+$/m',
                'replacement' => '[REDACTED:env_line]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ],
        ];

        // env_snapshot additionally redacts every value by key_name; file_path tightens its own
        // path-prefix scope. All other frozen kinds carry the shared secret-family set.
        return match ($kind) {
            'env_snapshot' => array_merge($secretFamilies, [[
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_KEY_NAME,
                'pattern' => '*',
                'replacement' => '[REDACTED:env_value]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ]]),
            default => $secretFamilies,
        };
    }
}
