<?php

declare(strict_types=1);

namespace App\Services\Ai\CrossDomain;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * Atlas Temporary Domain Composition — Patamar 4 · 4.6.
 *
 * Compõe K domains (K=2..5) numa cápsula com TTL. NÃO redefine domains,
 * privacy classes, nem regras de bridge — delega para ACDM::evaluate()
 * cada par (source,target) do K-clique. Atravessa Constitutional Kernel
 * + Autonomy Admission antes de emitir a cápsula.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-temporary-domain-composition.md
 *
 * Invariantes:
 *   - 2 ≤ K ≤ 5;
 *   - 60 ≤ ttl_seconds ≤ 86400;
 *   - se qualquer par retornar deny por ACDM, cápsula inteira é negada;
 *   - append-only JSONL local;
 *   - serviço não muta status — ticket de revoke é append-only.
 */
final class AtlasTemporaryDomainCompositionService
{
    public const CAPSULE_SCHEMA = 'atlas.temporary_domain_composition.capsule.v1';

    public const EVALUATION_SCHEMA = 'atlas.temporary_domain_composition.evaluation.v1';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_DENIED = 'denied';

    public const MIN_DOMAINS = 2;

    public const MAX_DOMAINS = 5;

    public const MIN_TTL_SECONDS = 60;

    public const MAX_TTL_SECONDS = 86400;

    private ?string $capsulesLogOverride = null;

    public function __construct(
        private readonly AtlasCrossDomainMeshService $acdm,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setCapsulesLogPathForTesting(?string $path): void
    {
        $this->capsulesLogOverride = $path;
    }

    public function capsulesLogPath(): string
    {
        if ($this->capsulesLogOverride !== null) {
            return $this->capsulesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/cross_domain')
            : sys_get_temp_dir().'/atlas/cross_domain';

        return $base.DIRECTORY_SEPARATOR.'capsules.jsonl';
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $domains = array_values(array_unique(array_map('strval', (array) ($input['domains'] ?? []))));
        if (count($domains) < self::MIN_DOMAINS || count($domains) > self::MAX_DOMAINS) {
            throw new InvalidArgumentException(sprintf(
                'domains must have between %d and %d distinct entries (got %d).',
                self::MIN_DOMAINS, self::MAX_DOMAINS, count($domains)
            ));
        }
        foreach ($domains as $d) {
            if (! in_array($d, AtlasCrossDomainMeshService::DOMAINS, true)) {
                throw new InvalidArgumentException("unknown domain '{$d}'.");
            }
        }

        $privacy = (string) ($input['privacy_class'] ?? AtlasCrossDomainMeshService::PRIVACY_NORMAL);
        if (! in_array($privacy, AtlasCrossDomainMeshService::PRIVACY_CLASSES, true)) {
            throw new InvalidArgumentException("unknown privacy_class '{$privacy}'.");
        }

        // Default TTL: honor the Kernel runtime invariant `tdc_ttl_window` when
        // tuned by the operator; otherwise fall back to canonical 2h.
        $defaultTtl = 7200;
        try {
            $tuned = $this->kernel->currentRuntimeValue('tdc_ttl_window');
            if (is_int($tuned)) {
                $defaultTtl = $tuned;
            }
        } catch (\Throwable $e) {
            // Defensive — keep canonical default if the Kernel API rejects.
        }
        $ttl = (int) ($input['ttl_seconds'] ?? $defaultTtl);
        if ($ttl < self::MIN_TTL_SECONDS || $ttl > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'ttl_seconds must be between %d and %d.',
                self::MIN_TTL_SECONDS, self::MAX_TTL_SECONDS
            ));
        }

        $purpose = (string) ($input['purpose'] ?? 'unspecified');
        $actor = (string) ($input['actor'] ?? 'operator');
        $requestedAutonomy = (string) ($input['requested_autonomy'] ?? 'execute_with_approval');
        $rationale = (string) ($input['rationale'] ?? '');

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $createdAt = $now->format(DateTimeInterface::ATOM);
        $expiresAt = $now->modify("+{$ttl} seconds")->format(DateTimeInterface::ATOM);
        // entropy seed: microtime + random_bytes — ensures unique capsule_id even within the same ISO second.
        $entropy = bin2hex(random_bytes(8));
        $capsuleId = 'tdc_'.substr(hash('sha256', implode('|', $domains).'|'.$privacy.'|'.$createdAt.'|'.$entropy), 0, 12);

        // 1. Constitutional Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'temporary_domain_composition',
            'proposed_effect' => sprintf('compose temporary capsule with domains=%s ttl=%ds purpose=%s', implode(',', $domains), $ttl, $purpose),
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
        ]);

        // 2. Autonomy admission.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'temporary_domain_composition',
            'proposed_effect' => sprintf('compose temporary capsule with domains=%s ttl=%ds purpose=%s', implode(',', $domains), $ttl, $purpose),
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
            'requested_autonomy' => $requestedAutonomy,
        ]);

        // 3. ACDM per-pair evaluation.
        $bridges = [];
        $anyDeny = false;
        foreach ($domains as $source) {
            foreach ($domains as $target) {
                if ($source === $target) {
                    continue;
                }
                $dec = $this->acdm->evaluate($source, $target, $privacy);
                $approved = (bool) ($dec['approved'] ?? false);
                $bridges[] = [
                    'source' => $source,
                    'target' => $target,
                    'approved' => $approved,
                    'decision' => $approved ? 'allow' : 'deny',
                    'reason' => array_values((array) ($dec['reason'] ?? [])),
                ];
                if (! $approved) {
                    $anyDeny = true;
                }
            }
        }

        $kernelBlocked = $kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK;
        $status = ($kernelBlocked || $anyDeny)
            ? self::STATUS_DENIED
            : self::STATUS_ACTIVE;

        $capsule = [
            'schema_version' => self::CAPSULE_SCHEMA,
            'capsule_id' => $capsuleId,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'ttl_seconds' => $ttl,
            'domains' => $domains,
            'privacy_class' => $privacy,
            'purpose' => $purpose,
            'actor' => $actor,
            'rationale' => $rationale,
            'bridges' => $bridges,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'status' => $status,
        ];
        $capsule['capsule_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::CAPSULE_SCHEMA,
            'capsule_id' => $capsuleId,
            'domains' => $domains,
            'privacy' => $privacy,
            'ttl' => $ttl,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'bridges_hash' => hash('sha256', json_encode($bridges)),
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->capsulesLogPath(), $capsule);

        return $capsule;
    }

    /**
     * Project current validity of a capsule. Does not mutate state.
     *
     * @return array<string,mixed>
     */
    public function evaluateCapsule(string $capsuleId): array
    {
        $capsule = $this->findCapsule($capsuleId);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        if ($capsule === null) {
            return [
                'schema_version' => self::EVALUATION_SCHEMA,
                'capsule_id' => $capsuleId,
                'valid' => false,
                'reason' => 'not_found',
            ];
        }
        if ($capsule['status'] === self::STATUS_DENIED) {
            return [
                'schema_version' => self::EVALUATION_SCHEMA,
                'capsule_id' => $capsuleId,
                'valid' => false,
                'reason' => 'denied_at_creation',
            ];
        }
        if ($this->isRevoked($capsuleId)) {
            return [
                'schema_version' => self::EVALUATION_SCHEMA,
                'capsule_id' => $capsuleId,
                'valid' => false,
                'reason' => 'revoked',
            ];
        }
        $expiresTs = strtotime((string) $capsule['expires_at']);
        if ($expiresTs !== false && $expiresTs < $now) {
            return [
                'schema_version' => self::EVALUATION_SCHEMA,
                'capsule_id' => $capsuleId,
                'valid' => false,
                'expired' => true,
                'reason' => 'ttl_expired',
            ];
        }

        // Re-eval bridges to catch policy drift since creation.
        $current = [];
        foreach ($capsule['domains'] as $s) {
            foreach ($capsule['domains'] as $t) {
                if ($s === $t) {
                    continue;
                }
                $current[] = $this->acdm->evaluate($s, $t, $capsule['privacy_class']);
            }
        }
        foreach ($current as $d) {
            if (! (bool) ($d['approved'] ?? false)) {
                return [
                    'schema_version' => self::EVALUATION_SCHEMA,
                    'capsule_id' => $capsuleId,
                    'valid' => false,
                    'reason' => 'bridge_drift_deny',
                ];
            }
        }

        return [
            'schema_version' => self::EVALUATION_SCHEMA,
            'capsule_id' => $capsuleId,
            'valid' => true,
            'expires_at' => $capsule['expires_at'],
        ];
    }

    /**
     * Append-only revoke ticket. Original capsule record remains intact.
     */
    public function expireCapsule(string $capsuleId, string $reason): array
    {
        $capsule = $this->findCapsule($capsuleId);
        if ($capsule === null) {
            throw new InvalidArgumentException("capsule '{$capsuleId}' not found.");
        }
        $ticket = [
            'schema_version' => 'atlas.temporary_domain_composition.revoke_ticket.v1',
            'capsule_id' => $capsuleId,
            'reason' => $reason,
            'revoked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'status' => self::STATUS_REVOKED,
        ];
        AppendOnlyJsonlStore::append($this->capsulesLogPath(), $ticket);

        return $ticket;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAllCapsules(): array
    {
        $rows = AppendOnlyJsonlStore::read($this->capsulesLogPath());
        $out = [];
        foreach ($rows as $r) {
            if (($r['schema_version'] ?? null) === self::CAPSULE_SCHEMA) {
                $out[] = $r;
            }
        }

        return $out;
    }

    /**
     * Returns capsules currently `active`, not revoked, and not past TTL.
     *
     * @return list<array<string,mixed>>
     */
    public function listActiveCapsules(): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $out = [];
        foreach ($this->listAllCapsules() as $c) {
            if ($c['status'] !== self::STATUS_ACTIVE) {
                continue;
            }
            if ($this->isRevoked((string) $c['capsule_id'])) {
                continue;
            }
            $ts = strtotime((string) ($c['expires_at'] ?? ''));
            if ($ts !== false && $ts >= $now) {
                $out[] = $c;
            }
        }

        return $out;
    }

    // ---------- internals ----------

    private function findCapsule(string $capsuleId): ?array
    {
        foreach ($this->listAllCapsules() as $c) {
            if (($c['capsule_id'] ?? null) === $capsuleId) {
                return $c;
            }
        }

        return null;
    }

    private function isRevoked(string $capsuleId): bool
    {
        foreach (AppendOnlyJsonlStore::read($this->capsulesLogPath()) as $r) {
            if (($r['schema_version'] ?? null) === 'atlas.temporary_domain_composition.revoke_ticket.v1'
                && ($r['capsule_id'] ?? null) === $capsuleId) {
                return true;
            }
        }

        return false;
    }
}
