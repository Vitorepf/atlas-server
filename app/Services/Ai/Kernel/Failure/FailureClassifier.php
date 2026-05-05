<?php

namespace App\Services\Ai\Kernel\Failure;

use Throwable;

final class FailureClassifier
{
    /**
     * @var array<int,string>
     */
    private const EXPLICIT_DOMAIN_PATHS = [
        'failure_domain',
        'failureDomain',
        'domain',
        'error.failure_domain',
        'error.failureDomain',
        'error.domain',
        'failure.failure_domain',
        'failure.failureDomain',
        'failure.domain',
        'classification.failure_domain',
        'classification.failureDomain',
        'classification.domain',
    ];

    /**
     * @var array<int,string>
     */
    private const STATUS_CODE_PATHS = [
        'status_code',
        'statusCode',
        'http_status',
        'httpStatus',
        'code',
        'response.status_code',
        'response.statusCode',
        'response.http_status',
        'response.httpStatus',
        'response.status',
        'error.status_code',
        'error.statusCode',
        'error.http_status',
        'error.httpStatus',
        'error.code',
    ];

    /**
     * @var array<int,FailureDomain>
     */
    private const STATUS_CODE_DOMAIN_MAP = [
        408 => FailureDomain::ProviderTimeout,
        504 => FailureDomain::ProviderTimeout,
        429 => FailureDomain::ProviderUnavailable,
        500 => FailureDomain::ProviderUnavailable,
        502 => FailureDomain::ProviderUnavailable,
        503 => FailureDomain::ProviderUnavailable,
        401 => FailureDomain::PolicyDenied,
        403 => FailureDomain::PolicyDenied,
    ];

    /**
     * @var array<int,array{domain:FailureDomain,signal:string,needles:array<int,string>}>
     */
    private const RULES = [
        ['domain' => FailureDomain::InputMalformed, 'signal' => 'input_malformed', 'needles' => ['input malformed', 'malformed input', 'invalid input', 'invalid request payload', 'schema validation failed']],
        ['domain' => FailureDomain::InputAttachmentUnavailable, 'signal' => 'input_attachment_unavailable', 'needles' => ['attachment unavailable', 'input attachment unavailable', 'file unavailable', 'attachment missing', 'unable to read attachment']],
        ['domain' => FailureDomain::IntentAmbiguous, 'signal' => 'intent_ambiguous', 'needles' => ['intent ambiguous', 'ambiguous intent', 'cannot infer intent', 'unclear intent']],
        ['domain' => FailureDomain::DomainUnsupported, 'signal' => 'domain_unsupported', 'needles' => ['domain unsupported', 'unsupported domain', 'domain not supported']],
        ['domain' => FailureDomain::ProfileMissing, 'signal' => 'profile_missing', 'needles' => ['profile missing', 'missing profile', 'domain profile missing', 'flow profile missing']],
        ['domain' => FailureDomain::PrivacyViolation, 'signal' => 'privacy_violation', 'needles' => ['privacy violation', 'privacy leak', 'redaction leak', 'redaction failed', 'provider safe leak', 'pii leak', 'secret leaked']],
        ['domain' => FailureDomain::SecurityFinding, 'signal' => 'security_finding', 'needles' => ['security finding', 'critical vulnerability', 'high vulnerability', 'secret detected', 'malware', 'injection finding']],
        ['domain' => FailureDomain::ComplianceViolation, 'signal' => 'compliance_violation', 'needles' => ['compliance violation', 'regulatory violation', 'policy compliance failed', 'license violation', 'terms violation']],
        ['domain' => FailureDomain::EvidenceMissing, 'signal' => 'evidence_missing', 'needles' => ['missing evidence', 'evidence missing', 'no evidence', 'evidence required', 'source required', 'unsubstantiated']],
        ['domain' => FailureDomain::GateFailed, 'signal' => 'gate_failed', 'needles' => ['gate failed', 'gate failure', 'gate blocked', 'quality gate failed', 'failed gate']],
        ['domain' => FailureDomain::ToolPolicyDenied, 'signal' => 'tool_policy_denied', 'needles' => ['tool policy denied', 'tool permission denied', 'tool denied', 'tool not permitted', 'workspace write denied']],
        ['domain' => FailureDomain::PolicyDenied, 'signal' => 'policy_denied', 'needles' => ['policy denied', 'permission denied', 'access denied', 'auth denied', 'authentication failed', 'authorization failed', 'unauthorized', 'forbidden']],
        ['domain' => FailureDomain::BudgetExceeded, 'signal' => 'budget_exceeded', 'needles' => ['budget exceeded', 'token budget exceeded', 'cost budget exceeded', 'quota budget exceeded']],
        ['domain' => FailureDomain::DecisionExpired, 'signal' => 'decision_expired', 'needles' => ['decision expired', 'decision receipt expired', 'expired decision', 'decision ttl exceeded']],
        ['domain' => FailureDomain::DecisionInvalid, 'signal' => 'decision_invalid', 'needles' => ['decision invalid', 'decision receipt invalid', 'decision receipt dry run', 'decision receipt provider mismatch', 'decision receipt model mismatch', 'invalid decision', 'decision signature invalid']],
        ['domain' => FailureDomain::ProviderTimeout, 'signal' => 'provider_timeout', 'needles' => ['timeout', 'timed out', 'deadline exceeded', 'request deadline', 'connection timed out']],
        ['domain' => FailureDomain::ProviderUnavailable, 'signal' => 'provider_unavailable', 'needles' => ['rate limit', 'rate limited', 'too many requests', '429', 'provider unavailable', 'service unavailable', 'temporarily unavailable', 'overloaded']],
        ['domain' => FailureDomain::ProviderRefused, 'signal' => 'provider_refused', 'needles' => ['provider refused', 'model refused', 'content refused', 'safety refusal', 'provider refusal']],
        ['domain' => FailureDomain::ContextPackFailed, 'signal' => 'context_pack_failed', 'needles' => ['context pack failed', 'context packing failed', 'context build failed', 'context bundle failed']],
        ['domain' => FailureDomain::MemoryUnavailable, 'signal' => 'memory_unavailable', 'needles' => ['memory unavailable', 'memory lookup failed', 'memory registry unavailable', 'open brain unavailable']],
        ['domain' => FailureDomain::ToolUnavailable, 'signal' => 'tool_unavailable', 'needles' => ['tool unavailable', 'tool missing', 'tool not found', 'tool disabled']],
        ['domain' => FailureDomain::HarnessFailed, 'signal' => 'harness_failed', 'needles' => ['harness failed', 'engineering harness failed', 'harness run failed', 'harness error']],
        ['domain' => FailureDomain::RuntimeUnsupported, 'signal' => 'runtime_unsupported', 'needles' => ['runtime unsupported', 'unsupported runtime', 'runtime not supported']],
        ['domain' => FailureDomain::RuntimeFailed, 'signal' => 'runtime_failed', 'needles' => ['runtime failed', 'runtime failure', 'runtime error', 'execution runtime failed']],
        ['domain' => FailureDomain::ToolExecutionFailed, 'signal' => 'tool_execution_failed', 'needles' => ['tool failed', 'tool execution failed', 'tool call failed', 'command failed', 'process failed']],
        ['domain' => FailureDomain::RepairExhausted, 'signal' => 'repair_exhausted', 'needles' => ['repair exhausted', 'max repair attempts', 'repair attempts exhausted', 'repair budget exhausted']],
        ['domain' => FailureDomain::OutputInvalid, 'signal' => 'output_invalid', 'needles' => ['output invalid', 'invalid output', 'output schema invalid', 'response validation failed']],
        ['domain' => FailureDomain::LedgerUnavailable, 'signal' => 'ledger_unavailable', 'needles' => ['ledger unavailable', 'evidence ledger unavailable', 'ledger write failed', 'ledger failed']],
        ['domain' => FailureDomain::ReplayMismatch, 'signal' => 'replay_mismatch', 'needles' => ['replay mismatch', 'replay diverged', 'deterministic replay mismatch']],
        ['domain' => FailureDomain::SurfaceContractViolation, 'signal' => 'surface_contract_violation', 'needles' => ['surface contract violation', 'surface contract failed', 'surface adapter contract violation']],
    ];

    /**
     * @param  Throwable|array<string,mixed>|string  $failure
     */
    public function classify(Throwable|array|string $failure, ?string $message = null): FailureClassification
    {
        if ($failure instanceof Throwable) {
            return $this->classifyThrowable($failure);
        }

        if (is_array($failure)) {
            return $this->classifyPayload($failure);
        }

        return $this->classifyStatus($failure, $message);
    }

    public function classifyThrowable(Throwable $throwable): FailureClassification
    {
        $metadata = $this->throwableMetadata($throwable);
        $classification = $this->classifyText(
            text: $this->throwableText($throwable),
            source: 'throwable',
            metadata: $metadata,
        );

        if (! $classification->isUnknown()) {
            return $classification;
        }

        return $this->classifyStatusCode($this->throwableStatusCode($throwable), 'throwable', $metadata) ?? $classification;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function classifyPayload(array $payload): FailureClassification
    {
        $explicit = $this->explicitDomain($payload);
        if ($explicit instanceof FailureDomain) {
            return new FailureClassification(
                domain: $explicit,
                source: 'payload',
                signals: ['explicit_failure_domain'],
                metadata: $this->payloadMetadata($payload),
            );
        }

        $metadata = $this->payloadMetadata($payload);
        $classification = $this->classifyText(
            text: $this->payloadText($payload),
            source: 'payload',
            metadata: $metadata,
        );

        if (! $classification->isUnknown()) {
            return $classification;
        }

        return $this->classifyStatusCode($metadata['status_code'], 'payload', $metadata) ?? $classification;
    }

    public function classifyStatus(string $status, ?string $message = null): FailureClassification
    {
        $metadata = [
            'status' => $status,
            'has_message' => $message !== null && trim($message) !== '',
        ];
        $classification = $this->classifyText(
            text: trim($status.' '.($message ?? '')),
            source: 'status_message',
            metadata: $metadata,
        );

        if (! $classification->isUnknown()) {
            return $classification;
        }

        return $this->classifyStatusCode(ctype_digit($status) ? (int) $status : null, 'status_message', $metadata) ?? $classification;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function classifyText(string $text, string $source, array $metadata = []): FailureClassification
    {
        $normalized = $this->normalize($text);

        foreach (self::RULES as $rule) {
            $domain = $rule['domain'];
            $signals = $rule['signal'];
            $needles = $rule['needles'];

            foreach ($needles as $needle) {
                if (str_contains($normalized, $needle)) {
                    return new FailureClassification(
                        domain: $domain,
                        source: $source,
                        signals: [$signals, $needle],
                        metadata: $metadata,
                    );
                }
            }
        }

        return new FailureClassification(
            domain: FailureDomain::Unknown,
            source: $source,
            signals: ['unclassified'],
            confidence: 0.0,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function explicitDomain(array $payload): ?FailureDomain
    {
        $value = $this->firstStringAtPaths($payload, self::EXPLICIT_DOMAIN_PATHS);

        if ($value === null) {
            return null;
        }

        return FailureDomain::tryFrom($value) ?? $this->domainFromAlias($value);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadText(array $payload): string
    {
        return implode(' ', $this->flatten($payload));
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<int,string>
     */
    private function flatten(array $payload): array
    {
        $values = [];

        foreach ($payload as $key => $value) {
            if ($this->isStatusCodeKey((string) $key)) {
                continue;
            }

            $values[] = (string) $key;

            if (is_array($value)) {
                array_push($values, ...$this->flatten($value));

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    private function isStatusCodeKey(string $key): bool
    {
        return in_array($key, ['status_code', 'statusCode', 'http_status', 'httpStatus', 'code', 'status'], true);
    }

    private function normalize(string $text): string
    {
        return strtolower(str_replace(['_', '-', '.', ':', "\n", "\r", "\t"], ' ', $text));
    }

    private function throwableText(Throwable $throwable): string
    {
        $parts = [];
        $current = $throwable;
        $depth = 0;

        while ($current instanceof Throwable && $depth < 5) {
            $parts[] = $current::class;
            $parts[] = $current->getMessage();
            $parts[] = (string) $current->getCode();
            $current = $current->getPrevious();
            $depth++;
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function throwableMetadata(Throwable $throwable): array
    {
        return [
            'throwable_class' => $throwable::class,
            'code' => $throwable->getCode(),
            'status_code' => $this->throwableStatusCode($throwable),
            'chain' => $this->throwableChain($throwable),
        ];
    }

    /**
     * @return array<int,array{class:string,code:int|string}>
     */
    private function throwableChain(Throwable $throwable): array
    {
        $chain = [];
        $current = $throwable;
        $depth = 0;

        while ($current instanceof Throwable && $depth < 5) {
            $chain[] = [
                'class' => $current::class,
                'code' => $current->getCode(),
            ];
            $current = $current->getPrevious();
            $depth++;
        }

        return $chain;
    }

    /**
     * @return array<int,string>
     */
    public static function ruleDomains(): array
    {
        return array_values(array_unique(array_map(
            fn (array $rule): string => $rule['domain']->value,
            self::RULES,
        )));
    }

    /**
     * @return array<int,array{domain:string,domain_name:string,signal:string,needles:array<int,string>}>
     */
    public static function ruleCatalog(): array
    {
        return array_map(fn (array $rule): array => [
            'domain' => $rule['domain']->value,
            'domain_name' => $rule['domain']->name,
            'signal' => $rule['signal'],
            'needles' => $rule['needles'],
        ], self::RULES);
    }

    /**
     * @return array<int,string>
     */
    public static function statusCodeMap(): array
    {
        $map = [];

        foreach (self::STATUS_CODE_DOMAIN_MAP as $statusCode => $domain) {
            $map[$statusCode] = $domain->value;
        }

        return $map;
    }

    /**
     * @return array{ok:bool,missing_domains:array<int,string>,duplicate_signals:array<int,string>,rule_count:int,status_code_count:int}
     */
    public function complianceReport(): array
    {
        $expected = array_values(array_filter(
            FailureDomain::cases(),
            fn (FailureDomain $domain): bool => $domain !== FailureDomain::Unknown,
        ));
        $covered = self::ruleDomains();
        $missing = array_values(array_filter(
            array_map(fn (FailureDomain $domain): string => $domain->value, $expected),
            fn (string $domain): bool => ! in_array($domain, $covered, true),
        ));
        $signals = array_map(fn (array $rule): string => $rule['signal'], self::RULES);
        $counts = array_count_values($signals);
        $duplicates = array_values(array_keys(array_filter($counts, fn (int $count): bool => $count > 1)));

        return [
            'ok' => $missing === [] && $duplicates === [],
            'missing_domains' => $missing,
            'duplicate_signals' => $duplicates,
            'rule_count' => count(self::RULES),
            'status_code_count' => count(self::STATUS_CODE_DOMAIN_MAP),
        ];
    }

    private function domainFromAlias(string $value): ?FailureDomain
    {
        $normalized = $this->normalize($value);

        foreach (FailureDomain::cases() as $domain) {
            if ($normalized === $this->normalize($domain->name) || $normalized === $this->normalize($domain->value)) {
                return $domain;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function statusCode(array $payload): ?int
    {
        foreach (self::STATUS_CODE_PATHS as $path) {
            $value = $this->valueAtPath($payload, $path);

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function throwableStatusCode(Throwable $throwable): ?int
    {
        $code = $throwable->getCode();

        if (is_int($code) && $code >= 100 && $code <= 599) {
            return $code;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{keys:array<int,string>,status_code:?int,explicit_domain_path:?string}
     */
    private function payloadMetadata(array $payload): array
    {
        return [
            'keys' => array_keys($payload),
            'status_code' => $this->statusCode($payload),
            'explicit_domain_path' => $this->firstExistingPath($payload, self::EXPLICIT_DOMAIN_PATHS),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $paths
     */
    private function firstStringAtPaths(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->valueAtPath($payload, $path);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $paths
     */
    private function firstExistingPath(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($this->valueAtPath($payload, $path) !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function valueAtPath(array $payload, string $path): mixed
    {
        $current = $payload;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function classifyStatusCode(?int $statusCode, string $source, array $metadata): ?FailureClassification
    {
        $domain = $statusCode !== null ? (self::STATUS_CODE_DOMAIN_MAP[$statusCode] ?? null) : null;

        if (! $domain instanceof FailureDomain) {
            return null;
        }

        return new FailureClassification(
            domain: $domain,
            source: $source,
            signals: ['http_status_code', (string) $statusCode],
            confidence: 0.85,
            metadata: $metadata,
        );
    }
}
