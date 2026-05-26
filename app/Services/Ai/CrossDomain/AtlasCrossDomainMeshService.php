<?php

declare(strict_types=1);

namespace App\Services\Ai\CrossDomain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Cross-Domain Mesh + ARPTL Gates.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-cross-domain-mesh-arptl.md
 *
 * Schemas:
 *   - atlas.cross_domain.bridge_request.v1
 *   - atlas.cross_domain.veto_decision.v1
 *   - atlas.cross_domain.mesh_topology.v1
 *
 * Invariantes:
 *   - ARPTL veto is absolute (no override path here);
 *   - cyber/secret/sensitive never cross to consumer audiences;
 *   - same-domain bridge is a no-op (approved, bridged=false);
 *   - bridge envelopes carry refs, never raw content;
 *   - deterministic decision hash per (from,to,privacy_class).
 */
final class AtlasCrossDomainMeshService
{
    public const REQUEST_SCHEMA = 'atlas.cross_domain.bridge_request.v1';

    public const DECISION_SCHEMA = 'atlas.cross_domain.veto_decision.v1';

    public const TOPOLOGY_SCHEMA = 'atlas.cross_domain.mesh_topology.v1';

    public const DOMAINS = [
        'engineering', 'marketing', 'finance', 'trading', 'cyber',
        'legal', 'ops', 'sales', 'design', 'research',
        'health', 'personal', 'learning', 'governance', 'infra',
    ];

    public const PRIVACY_PUBLIC = 'public';

    public const PRIVACY_NORMAL = 'normal';

    public const PRIVACY_SENSITIVE = 'sensitive';

    public const PRIVACY_SECRET = 'secret';

    public const PRIVACY_CYBER = 'cyber';

    public const PRIVACY_CLASSES = [
        self::PRIVACY_PUBLIC, self::PRIVACY_NORMAL,
        self::PRIVACY_SENSITIVE, self::PRIVACY_SECRET, self::PRIVACY_CYBER,
    ];

    /**
     * Domains that NEVER receive sensitive/secret/cyber payloads.
     * These are consumer/audience-facing domains where leakage risk
     * is highest.
     */
    public const SENSITIVE_AUDIENCE_TARGETS = [
        'marketing', 'sales', 'design', 'personal', 'learning',
    ];

    private ?string $requestsLogOverride = null;

    private ?string $decisionsLogOverride = null;

    public function setRequestsLogPathForTesting(?string $path): void
    {
        $this->requestsLogOverride = $path;
    }

    public function setDecisionsLogPathForTesting(?string $path): void
    {
        $this->decisionsLogOverride = $path;
    }

    public function requestsLogPath(): string
    {
        if ($this->requestsLogOverride !== null) {
            return $this->requestsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/cross_domain')
            : sys_get_temp_dir().'/atlas/cross_domain';

        return $base.DIRECTORY_SEPARATOR.'requests.jsonl';
    }

    public function decisionsLogPath(): string
    {
        if ($this->decisionsLogOverride !== null) {
            return $this->decisionsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/cross_domain')
            : sys_get_temp_dir().'/atlas/cross_domain';

        return $base.DIRECTORY_SEPARATOR.'decisions.jsonl';
    }

    /**
     * Request a bridge. Returns the veto/approve decision. Appends both
     * the request and the decision to their JSONL logs.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>  veto_decision envelope
     */
    public function bridge(array $input): array
    {
        $from = (string) ($input['from_domain'] ?? '');
        $to = (string) ($input['to_domain'] ?? '');
        $privacy = (string) ($input['privacy_class'] ?? self::PRIVACY_NORMAL);
        $this->validateDomain($from, 'from_domain');
        $this->validateDomain($to, 'to_domain');
        $this->validatePrivacy($privacy);

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $requestId = 'br_'.substr(hash('sha256', $from.'|'.$to.'|'.$privacy.'|'.$at), 0, 12);

        $request = [
            'schema_version' => self::REQUEST_SCHEMA,
            'request_id' => $requestId,
            'at' => $at,
            'from_domain' => $from,
            'to_domain' => $to,
            'privacy_class' => $privacy,
            'memory_refs' => array_values((array) ($input['memory_refs'] ?? [])),
            'snapshot_hash' => $input['snapshot_hash'] ?? null,
            'rationale' => (string) ($input['rationale'] ?? ''),
            'actor' => (string) ($input['actor'] ?? 'operator'),
        ];
        $this->appendJsonl($this->requestsLogPath(), $request);

        $decision = $this->decide($from, $to, $privacy, $requestId);
        $this->appendJsonl($this->decisionsLogPath(), $decision);

        return $decision;
    }

    /**
     * Dry decide: returns the veto/approve outcome without persisting
     * anything. Idempotent.
     */
    public function evaluate(string $from, string $to, string $privacy): array
    {
        $this->validateDomain($from, 'from_domain');
        $this->validateDomain($to, 'to_domain');
        $this->validatePrivacy($privacy);

        return $this->decide($from, $to, $privacy, 'eval');
    }

    /**
     * Mesh topology — which (from,to,privacy) triples are allowed.
     *
     * @return array<string,mixed>
     */
    public function topology(): array
    {
        $edges = [];
        foreach (self::DOMAINS as $from) {
            foreach (self::DOMAINS as $to) {
                if ($from === $to) {
                    continue;
                }
                $allowedClasses = [];
                foreach (self::PRIVACY_CLASSES as $privacy) {
                    if ($this->decide($from, $to, $privacy, 'topology')['approved']) {
                        $allowedClasses[] = $privacy;
                    }
                }
                if ($allowedClasses !== []) {
                    $edges[] = [
                        'from' => $from,
                        'to' => $to,
                        'privacy_classes_allowed' => $allowedClasses,
                    ];
                }
            }
        }

        $envelope = [
            'schema_version' => self::TOPOLOGY_SCHEMA,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'domains' => self::DOMAINS,
            'edges_allowed' => $edges,
        ];
        $envelope['topology_hash'] = 'sha256:'.hash('sha256', json_encode([
            'domains' => self::DOMAINS,
            'edges' => $edges,
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRequests(int $limit = 100): array
    {
        return $this->tail($this->readJsonl($this->requestsLogPath()), $limit);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listDecisions(int $limit = 100): array
    {
        return $this->tail($this->readJsonl($this->decisionsLogPath()), $limit);
    }

    // ---------- internals ----------

    private function decide(string $from, string $to, string $privacy, string $requestId): array
    {
        $reasons = [];
        $approved = true;
        $bridgeApplied = false;

        if ($from === $to) {
            return $this->envelopeDecision(
                $requestId,
                true,
                ['same_domain_no_op'],
                false
            );
        }

        // Cyber outbound: only public crosses, and only to governance/legal.
        if ($from === 'cyber') {
            if ($privacy !== self::PRIVACY_PUBLIC) {
                $approved = false;
                $reasons[] = 'cyber_outbound_only_public';
            } elseif (! in_array($to, ['governance', 'legal'], true)) {
                $approved = false;
                $reasons[] = 'cyber_public_targets_restricted_to_governance_legal';
            }
        }

        // Sensitive/secret/cyber payloads never reach consumer-facing audiences.
        if (in_array($privacy, [self::PRIVACY_SENSITIVE, self::PRIVACY_SECRET, self::PRIVACY_CYBER], true)
            && in_array($to, self::SENSITIVE_AUDIENCE_TARGETS, true)) {
            $approved = false;
            $reasons[] = 'privacy_class_blocks_consumer_audience_target';
        }

        // Secret payloads cross only inside a tight cluster.
        if ($privacy === self::PRIVACY_SECRET) {
            $allowedClusters = [
                ['finance', 'trading', 'legal', 'governance'],
                ['engineering', 'infra', 'governance', 'ops'],
                ['health', 'personal', 'governance'],
            ];
            $found = false;
            foreach ($allowedClusters as $cluster) {
                if (in_array($from, $cluster, true) && in_array($to, $cluster, true)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $approved = false;
                $reasons[] = 'secret_only_crosses_within_trusted_cluster';
            }
        }

        // Personal/health never broadcast to engineering/marketing/sales/trading.
        if (in_array($from, ['personal', 'health'], true)
            && in_array($to, ['engineering', 'marketing', 'sales', 'trading', 'design'], true)
            && $privacy !== self::PRIVACY_PUBLIC) {
            $approved = false;
            $reasons[] = 'personal_health_outbound_restricted';
        }

        $bridgeApplied = $approved;
        if ($approved && $reasons === []) {
            $reasons[] = 'arptl_approved';
        }

        return $this->envelopeDecision($requestId, $approved, $reasons, $bridgeApplied);
    }

    private function envelopeDecision(string $requestId, bool $approved, array $reasons, bool $bridgeApplied): array
    {
        $decision = [
            'schema_version' => self::DECISION_SCHEMA,
            'request_id' => $requestId,
            'decided_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'approved' => $approved,
            'reason' => array_values(array_unique($reasons)),
            'bridge_applied' => $bridgeApplied,
        ];
        $decision['decision_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::DECISION_SCHEMA,
            'request_id' => $requestId,
            'approved' => $approved,
            'reason' => $decision['reason'],
        ], JSON_THROW_ON_ERROR));

        return $decision;
    }

    private function validateDomain(string $domain, string $label): void
    {
        if (! in_array($domain, self::DOMAINS, true)) {
            throw new InvalidArgumentException("Unknown {$label} '{$domain}'.");
        }
    }

    private function validatePrivacy(string $privacy): void
    {
        if (! in_array($privacy, self::PRIVACY_CLASSES, true)) {
            throw new InvalidArgumentException("Unknown privacy_class '{$privacy}'.");
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function tail(array $rows, int $limit): array
    {
        if ($limit <= 0 || count($rows) <= $limit) {
            return $rows;
        }

        return array_values(array_slice($rows, -$limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
