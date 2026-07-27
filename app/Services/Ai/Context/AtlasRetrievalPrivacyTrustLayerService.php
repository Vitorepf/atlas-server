<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Support\AtlasSecurity;
use Illuminate\Support\Carbon;

final class AtlasRetrievalPrivacyTrustLayerService
{
    public const SCHEMA_VERSION = 'atlas.aucri.retrieval_privacy_trust_layer.v1';

    public const PROVIDER_GATE_SCHEMA = 'atlas.aucri.provider_safe_context_gate.v1';

    public const REDACTION_RECEIPT_SCHEMA = 'atlas.aucri.context_redaction_receipt.v1';

    public const RETENTION_POLICY_SCHEMA = 'atlas.aucri.context_retention_policy.v1';

    public const TRUST_RECEIPT_SCHEMA = 'atlas.aucri.trust_receipt.v1';

    private const PII_PATTERNS = [
        'email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        'cpf_like' => '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
        'phone_like' => '/\b(?:\+?55\s*)?\(?\d{2}\)?\s?\d{4,5}-?\d{4}\b/',
    ];

    public function __construct(private readonly AtlasContextObservabilityPlaneService $observability) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? $input['risk'] ?? 'low'));
        $providerTarget = $this->providerTarget((string) ($input['provider_target'] ?? 'external'));
        $rawContext = $this->rawContext($input);
        $detections = $this->detectSensitiveClasses($rawContext);
        $classification = $this->classification($detections, $risk);
        $redactions = $this->redactions($detections);
        $sources = $this->sources($input, $classification);
        $observability = $this->observability->snapshot([
            'domain' => (string) ($input['domain'] ?? 'atlas'),
            'task_type' => (string) ($input['task_type'] ?? 'direct'),
            'risk_level' => $risk,
            'query' => MissionCanonicalHash::sha256($rawContext === '' ? 'arptl' : $rawContext),
        ]);

        $providerAllowed = $this->providerAllowed($classification, $providerTarget, $redactions, $risk);
        $blockedReasons = $this->blockedReasons($classification, $providerTarget, $redactions, $risk);
        $status = $blockedReasons !== [] ? 'blocked' : ($redactions !== [] ? 'redacted' : 'ready');
        $retentionPolicy = $this->retentionPolicy($classification, $sources);
        $redactionReceipt = $this->redactionReceipt($classification, $redactions, $rawContext);
        $providerGate = [
            'schema_version' => self::PROVIDER_GATE_SCHEMA,
            'status' => $blockedReasons === [] ? 'passed' : 'blocked',
            'provider_target' => $providerTarget,
            'provider_allowed' => $providerAllowed,
            'classification' => $classification,
            'allowed_actions' => $this->allowedActions($classification, $providerAllowed),
            'blocked_reasons' => $blockedReasons,
            'requires_local_only' => $classification === 'secret' || ($classification === 'confidential' && ! $providerAllowed),
        ];
        $trustReceipt = $this->trustReceipt($classification, $sources, $providerGate, $retentionPolicy, $observability, $redactionReceipt);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'provider_gate' => $providerGate,
            'redaction_receipt' => $redactionReceipt,
            'retention_policy' => $retentionPolicy,
            'trust_receipt' => $trustReceipt,
            'source_receipts' => $sources,
            'observability_ref' => [
                'schema_version' => AtlasContextObservabilityPlaneService::SCHEMA_VERSION,
                'status' => (string) ($observability['status'] ?? 'unknown'),
                'snapshot_hash' => (string) ($observability['snapshot_hash'] ?? ''),
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['privacy_trust_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function rawContext(array $input): string
    {
        $parts = [];
        foreach (['query', 'objective', 'raw_context', 'content'] as $key) {
            if (isset($input[$key]) && is_scalar($input[$key])) {
                $parts[] = (string) $input[$key];
            }
        }

        foreach ((array) ($input['source_refs'] ?? []) as $ref) {
            if (is_array($ref)) {
                foreach (['title', 'summary', 'snippet', 'content'] as $key) {
                    if (isset($ref[$key]) && is_scalar($ref[$key])) {
                        $parts[] = (string) $ref[$key];
                    }
                }
            } elseif (is_scalar($ref)) {
                $parts[] = (string) $ref;
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<string,int>
     */
    private function detectSensitiveClasses(string $value): array
    {
        $detections = [];
        // AtlasSecurity is the single pattern authority. This gate used to carry
        // its own 3-pattern table while AtlasSecurity redacted 10 kinds — so a
        // GitHub PAT, a JWT, an AWS key or a Slack token walked out through the
        // provider-export gate that the redactor would have caught.
        foreach (AtlasSecurity::secretDetections($value) as $kind => $count) {
            $detections['secret:'.$kind] = $count;
        }

        foreach (self::PII_PATTERNS as $kind => $pattern) {
            preg_match_all($pattern, $value, $matches);
            if (count($matches[0]) > 0) {
                $detections['pii:'.$kind] = count($matches[0]);
            }
        }

        ksort($detections);

        return $detections;
    }

    /**
     * @param  array<string,int>  $detections
     */
    private function classification(array $detections, string $risk): string
    {
        foreach (array_keys($detections) as $kind) {
            if (str_starts_with($kind, 'secret:')) {
                return 'secret';
            }
        }

        if ($detections !== []) {
            return 'confidential';
        }

        return in_array($risk, ['high', 'irreversible'], true) ? 'internal' : 'public';
    }

    /**
     * @param  array<string,int>  $detections
     * @return array<int,array<string,mixed>>
     */
    private function redactions(array $detections): array
    {
        $redactions = [];
        foreach ($detections as $kind => $count) {
            [$category, $subtype] = explode(':', $kind, 2);
            $redactions[] = [
                'category' => $category,
                'subtype' => $subtype,
                'count' => $count,
                'replacement' => '[redacted:'.$category.']',
            ];
        }

        return $redactions;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function sources(array $input, string $fallbackClassification): array
    {
        $refs = (array) ($input['source_refs'] ?? []);
        if ($refs === []) {
            $refs = [[
                'source_type' => 'runtime_input',
                'source_ref' => 'runtime_input',
                'classification' => $fallbackClassification,
                'authority_level' => 'operator_supplied',
            ]];
        }

        $sources = [];
        foreach ($refs as $index => $ref) {
            $sourceType = is_array($ref) ? (string) ($ref['source_type'] ?? $ref['type'] ?? 'source') : 'source';
            $sourceRef = is_array($ref) ? (string) ($ref['source_ref'] ?? $ref['ref'] ?? $sourceType.':'.$index) : (string) $ref;
            $classification = is_array($ref) ? (string) ($ref['classification'] ?? $fallbackClassification) : $fallbackClassification;
            $classification = in_array($classification, ['public', 'internal', 'confidential', 'secret'], true) ? $classification : $fallbackClassification;
            $authority = is_array($ref) ? (string) ($ref['authority_level'] ?? 'unknown') : 'unknown';
            $providerSafe = in_array($classification, ['public', 'internal'], true);

            $sources[] = [
                'schema_version' => self::TRUST_RECEIPT_SCHEMA,
                'source_type' => $sourceType,
                'source_ref_hash' => MissionCanonicalHash::sha256($sourceRef),
                'classification' => $classification,
                'authority_level' => $authority,
                'provider_safe' => $providerSafe,
                'delete_key' => MissionCanonicalHash::sha256('delete:'.$sourceRef),
                'trust_score' => $this->trustScore($classification, $authority),
            ];
        }

        usort($sources, static fn (array $a, array $b): int => strcmp((string) $a['source_ref_hash'], (string) $b['source_ref_hash']));

        return $sources;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function retentionPolicy(string $classification, array $sources): array
    {
        $days = match ($classification) {
            'secret' => 0,
            'confidential' => 30,
            'internal' => 180,
            default => 365,
        };

        return [
            'schema_version' => self::RETENTION_POLICY_SCHEMA,
            'classification' => $classification,
            'retention_days' => $days,
            'delete_cascade_required' => true,
            'delete_refs' => array_values(array_map(
                static fn (array $source): string => (string) $source['delete_key'],
                $sources,
            )),
            'embedding_delete_required' => true,
            'graph_edge_delete_required' => true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $redactions
     * @return array<string,mixed>
     */
    private function redactionReceipt(string $classification, array $redactions, string $rawContext): array
    {
        return [
            'schema_version' => self::REDACTION_RECEIPT_SCHEMA,
            'classification' => $classification,
            'redaction_status' => $redactions === [] ? 'clean' : 'redacted',
            'redaction_count' => array_sum(array_column($redactions, 'count')),
            'redactions' => $redactions,
            'raw_context_hash' => MissionCanonicalHash::sha256($rawContext === '' ? 'empty' : $rawContext),
            'raw_context_exposed' => false,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $providerGate
     * @param  array<string,mixed>  $retentionPolicy
     * @param  array<string,mixed>  $observability
     * @param  array<string,mixed>  $redactionReceipt
     * @return array<string,mixed>
     */
    private function trustReceipt(string $classification, array $sources, array $providerGate, array $retentionPolicy, array $observability, array $redactionReceipt): array
    {
        $receipt = [
            'schema_version' => self::TRUST_RECEIPT_SCHEMA,
            'classification' => $classification,
            'source_count' => count($sources),
            'provider_allowed' => (bool) $providerGate['provider_allowed'],
            'delete_cascade_required' => (bool) $retentionPolicy['delete_cascade_required'],
            'redaction_status' => (string) $redactionReceipt['redaction_status'],
            'observability_snapshot_hash' => (string) ($observability['snapshot_hash'] ?? ''),
            'policy_version' => 'arptl.policy.v1',
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<int,array<string,mixed>>  $redactions
     */
    private function providerAllowed(string $classification, string $providerTarget, array $redactions, string $risk): bool
    {
        if ($providerTarget === 'local') {
            return $classification !== 'secret' || $redactions === [];
        }

        if ($classification === 'secret') {
            return false;
        }

        if ($classification === 'confidential' && $redactions !== []) {
            return false;
        }

        return $risk !== 'irreversible';
    }

    /**
     * @param  array<int,array<string,mixed>>  $redactions
     * @return array<int,string>
     */
    private function blockedReasons(string $classification, string $providerTarget, array $redactions, string $risk): array
    {
        $reasons = [];
        if ($providerTarget !== 'local' && $classification === 'secret') {
            $reasons[] = 'secret_context_requires_local_only';
        }

        if ($providerTarget !== 'local' && $classification === 'confidential' && $redactions !== []) {
            $reasons[] = 'confidential_context_requires_review_or_local_execution';
        }

        if ($risk === 'irreversible' && $providerTarget !== 'local') {
            $reasons[] = 'irreversible_risk_requires_local_or_operator_review';
        }

        return AtlasContextStringListNormalizer::uniqueTrimmedStrings($reasons);
    }

    /**
     * @return array<int,string>
     */
    private function allowedActions(string $classification, bool $providerAllowed): array
    {
        $actions = ['local_retrieval', 'local_context_pack', 'observability_hash_only'];
        if ($providerAllowed) {
            $actions[] = 'provider_context_after_gate';
        }
        if ($classification !== 'secret') {
            $actions[] = 'embedding_with_delete_key';
            $actions[] = 'graph_edge_with_delete_key';
        }

        return $actions;
    }

    private function trustScore(string $classification, string $authority): float
    {
        $base = match ($classification) {
            'public' => 0.88,
            'internal' => 0.78,
            'confidential' => 0.62,
            default => 0.42,
        };

        return round(min(1.0, $base + match ($authority) {
            'canonical_doc', 'operator_supplied' => 0.08,
            'evidence_ledger' => 0.1,
            default => 0.0,
        }), 4);
    }

    private function providerTarget(string $target): string
    {
        return in_array($target, ['local', 'external', 'mixed'], true) ? $target : 'external';
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }
}
