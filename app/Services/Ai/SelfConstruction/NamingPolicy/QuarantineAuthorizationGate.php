<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NamingPolicy;

/**
 * Atlas Self-Construction OS — quarantine authorization gate.
 *
 * Enforces the canonical three-proof rule declared in
 * `docs/engineering-knowledge-base/atlas-self-construction-catalog.md`
 * (Contract 3 / Fluxo 3):
 *
 *   1. ACRUI `reachability=dead` for the individual file.
 *   2. `last_used_at` proven by the evidence ledger to be older than 90 days.
 *   3. Decision Receipt v2 issued by the operator citing the file.
 *
 * The gate is read-only and never moves files. It returns an authorization
 * decision. Callers (a future quarantine CLI / surface) MUST consult this
 * gate before issuing any `mv` and MUST persist the resulting authorization
 * envelope into the evidence ledger.
 *
 * Anti-pattern explicitly forbidden by CLAUDE.md:
 *   "Deletar código sem ACRUI marcar dead E last_used >90d"
 *
 * The 90-day window is canonical and not configurable through this class.
 * If the operator legitimately needs a shorter window for a specific case,
 * the path is a separate Decision Receipt v2 + the override recorded in
 * the catalog doc — not a knob in code.
 */
final class QuarantineAuthorizationGate
{
    public const SCHEMA_VERSION = 'atlas.self_construction.quarantine_authorization.v1';

    public const MIN_DAYS_SINCE_LAST_USE = 90;

    public const DECISION_AUTHORIZED = 'authorized';

    public const DECISION_BLOCKED = 'blocked';

    public const REASON_REACHABILITY_NOT_DEAD = 'reachability_not_dead';

    public const REASON_RECENT_LAST_USE = 'last_use_within_90_days';

    public const REASON_MISSING_RECEIPT = 'missing_operator_decision_receipt';

    public const REASON_OUT_OF_SCOPE = 'path_out_of_self_construction_scope';

    /**
     * @param  array{reachability?: string, last_used_at?: ?string}  $acruiSnapshot  Output from `atlas:code-reality reachability --target=<file>`.
     * @param  array{schema_version?: string, actor?: string, file?: string}|null  $operatorReceipt  Decision Receipt v2 envelope.
     * @return array{
     *   schema_version: string,
     *   decision: 'authorized'|'blocked',
     *   target_path: string,
     *   checks: array{
     *     in_scope: array{passed: bool, reason: ?string},
     *     reachability_dead: array{passed: bool, observed: ?string},
     *     last_used_over_90_days: array{passed: bool, days_since_last_use: ?int},
     *     operator_receipt_valid: array{passed: bool, reason: ?string}
     *   },
     *   blocking_reasons: list<string>,
     *   policy: array{min_days_since_last_use: int, required_receipt_schema: string}
     * }
     */
    public function evaluate(
        string $targetPath,
        array $acruiSnapshot,
        ?array $operatorReceipt,
        ?\DateTimeInterface $now = null,
    ): array {
        $now ??= new \DateTimeImmutable;

        $inScopeCheck = $this->checkInScope($targetPath);
        $reachabilityCheck = $this->checkReachability($acruiSnapshot);
        $lastUseCheck = $this->checkLastUse($acruiSnapshot, $now);
        $receiptCheck = $this->checkReceipt($operatorReceipt, $targetPath);

        $blocking = [];
        if (! $inScopeCheck['passed']) {
            $blocking[] = self::REASON_OUT_OF_SCOPE;
        }
        if (! $reachabilityCheck['passed']) {
            $blocking[] = self::REASON_REACHABILITY_NOT_DEAD;
        }
        if (! $lastUseCheck['passed']) {
            $blocking[] = self::REASON_RECENT_LAST_USE;
        }
        if (! $receiptCheck['passed']) {
            $blocking[] = self::REASON_MISSING_RECEIPT;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $blocking === [] ? self::DECISION_AUTHORIZED : self::DECISION_BLOCKED,
            'target_path' => $targetPath,
            'checks' => [
                'in_scope' => $inScopeCheck,
                'reachability_dead' => $reachabilityCheck,
                'last_used_over_90_days' => $lastUseCheck,
                'operator_receipt_valid' => $receiptCheck,
            ],
            'blocking_reasons' => $blocking,
            'policy' => [
                'min_days_since_last_use' => self::MIN_DAYS_SINCE_LAST_USE,
                'required_receipt_schema' => 'atlas.decision_receipt.v2',
            ],
        ];
    }

    /**
     * @return array{passed: bool, reason: ?string}
     */
    private function checkInScope(string $targetPath): array
    {
        $normalized = ltrim($targetPath, './');
        $inScope = str_starts_with($normalized, 'app/Services/Ai/SelfConstruction/')
            && str_ends_with($normalized, '.php');

        return [
            'passed' => $inScope,
            'reason' => $inScope ? null : 'gate scope is app/Services/Ai/SelfConstruction/**/*.php only',
        ];
    }

    /**
     * @param  array{reachability?: string, last_used_at?: ?string}  $snapshot
     * @return array{passed: bool, observed: ?string}
     */
    private function checkReachability(array $snapshot): array
    {
        $reachability = isset($snapshot['reachability']) ? (string) $snapshot['reachability'] : null;

        return [
            'passed' => $reachability === 'dead',
            'observed' => $reachability,
        ];
    }

    /**
     * @param  array{reachability?: string, last_used_at?: ?string}  $snapshot
     * @return array{passed: bool, days_since_last_use: ?int}
     */
    private function checkLastUse(array $snapshot, \DateTimeInterface $now): array
    {
        $raw = $snapshot['last_used_at'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [
                'passed' => false,
                'days_since_last_use' => null,
            ];
        }

        try {
            $lastUsed = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return [
                'passed' => false,
                'days_since_last_use' => null,
            ];
        }

        $diff = $now->getTimestamp() - $lastUsed->getTimestamp();
        $days = (int) floor($diff / 86_400);

        return [
            'passed' => $days >= self::MIN_DAYS_SINCE_LAST_USE,
            'days_since_last_use' => $days,
        ];
    }

    /**
     * @param  array{schema_version?: string, actor?: string, file?: string}|null  $receipt
     * @return array{passed: bool, reason: ?string}
     */
    private function checkReceipt(?array $receipt, string $targetPath): array
    {
        if ($receipt === null) {
            return ['passed' => false, 'reason' => 'no receipt provided'];
        }
        $schemaVersion = $receipt['schema_version'] ?? null;
        if ($schemaVersion !== 'atlas.decision_receipt.v2') {
            return [
                'passed' => false,
                'reason' => sprintf('receipt schema_version must be atlas.decision_receipt.v2, got %s', is_string($schemaVersion) ? $schemaVersion : 'null'),
            ];
        }
        $actor = $receipt['actor'] ?? null;
        if (! is_string($actor) || ! str_starts_with($actor, 'operator:')) {
            return ['passed' => false, 'reason' => 'receipt actor must start with operator:'];
        }
        $citedFile = $receipt['file'] ?? null;
        if ($citedFile !== $targetPath) {
            return [
                'passed' => false,
                'reason' => sprintf('receipt must cite the exact target file (expected %s, got %s)', $targetPath, is_string($citedFile) ? $citedFile : 'null'),
            ];
        }

        return ['passed' => true, 'reason' => null];
    }
}
