<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Trust Budget Service — Patamar 4 canon.
 *
 * Operator-defined tiers × operator_class budget for mutative actions.
 * Cada consumo (consume) registra receipt JSONL com action_id único;
 * cada rollback emite receipt reverso. Reset é por dia (UTC YYYY-MM-DD).
 *
 * Tiers canon:
 *   low_risk     — read-only ops, scaffold staging, doc reads
 *   medium_risk  — ASCB.propose, ADML route flip, elastic flip
 *   high_risk    — write to source, vault sign, kernel hash rotation
 *   critical     — claim_policy mutation, external_rivals unblock attempt
 *
 * Operator classes canon:
 *   operator             — Vitor manualmente via CLI/UI
 *   autonomous_agent     — Reconciliation, ADML sweep, ASCB autonomous
 *   external             — provider call ou subagent não-confiável
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-trust-budget.md
 *
 * Schemas:
 *   - atlas.trust_budget.consume_receipt.v1
 *   - atlas.trust_budget.rollback_receipt.v1
 *   - atlas.trust_budget.daily_state.v1
 *
 * Invariants:
 *   - tier 'critical' default budget 0 para todos operator_class != operator;
 *   - reset por dia UTC, sem carry-over;
 *   - append-only JSONL;
 *   - rollback NUNCA pode aumentar consumption acima do limit.
 */
final class AtlasTrustBudgetService
{
    public const CONSUME_SCHEMA = 'atlas.trust_budget.consume_receipt.v1';

    public const ROLLBACK_SCHEMA = 'atlas.trust_budget.rollback_receipt.v1';

    public const STATE_SCHEMA = 'atlas.trust_budget.daily_state.v1';

    public const TIER_LOW = 'low_risk';

    public const TIER_MEDIUM = 'medium_risk';

    public const TIER_HIGH = 'high_risk';

    public const TIER_CRITICAL = 'critical';

    public const VALID_TIERS = [
        self::TIER_LOW, self::TIER_MEDIUM, self::TIER_HIGH, self::TIER_CRITICAL,
    ];

    public const CLASS_OPERATOR = 'operator';

    public const CLASS_AUTONOMOUS_AGENT = 'autonomous_agent';

    public const CLASS_EXTERNAL = 'external';

    public const VALID_CLASSES = [
        self::CLASS_OPERATOR, self::CLASS_AUTONOMOUS_AGENT, self::CLASS_EXTERNAL,
    ];

    public const VERDICT_ALLOW = 'allow';

    public const VERDICT_DENY_BUDGET_EXCEEDED = 'deny_budget_exceeded';

    public const VERDICT_DENY_UNKNOWN_TIER = 'deny_unknown_tier';

    public const VERDICT_DENY_UNKNOWN_CLASS = 'deny_unknown_class';

    /**
     * Canonical daily budget per (tier × operator_class). Operator-defined,
     * mutation requires PR + redeploy. Critical=0 for everyone except operator.
     */
    private const CANONICAL_BUDGET = [
        self::TIER_LOW => [
            self::CLASS_OPERATOR => 1000,
            self::CLASS_AUTONOMOUS_AGENT => 500,
            self::CLASS_EXTERNAL => 100,
        ],
        self::TIER_MEDIUM => [
            self::CLASS_OPERATOR => 200,
            self::CLASS_AUTONOMOUS_AGENT => 50,
            self::CLASS_EXTERNAL => 10,
        ],
        self::TIER_HIGH => [
            self::CLASS_OPERATOR => 50,
            self::CLASS_AUTONOMOUS_AGENT => 5,
            self::CLASS_EXTERNAL => 0,
        ],
        self::TIER_CRITICAL => [
            self::CLASS_OPERATOR => 10,
            self::CLASS_AUTONOMOUS_AGENT => 0,
            self::CLASS_EXTERNAL => 0,
        ],
    ];

    private ?string $logPathOverride = null;

    /**
     * Get the canonical budget table. Returns a copy — never mutate the canon.
     *
     * @return array<string, array<string, int>>
     */
    public function canonicalBudget(): array
    {
        return self::CANONICAL_BUDGET;
    }

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'trust_budget.jsonl';
    }

    /**
     * Get the budget cap for a (tier, operator_class). Returns 0 if unknown.
     */
    public function budgetFor(string $tier, string $operatorClass): int
    {
        return self::CANONICAL_BUDGET[$tier][$operatorClass] ?? 0;
    }

    /**
     * Compute current day's used + remaining for a (tier, operator_class).
     * Net of rollbacks. Day is UTC YYYY-MM-DD.
     *
     * @return array<string,mixed>
     */
    public function state(string $tier, string $operatorClass, ?string $day = null): array
    {
        $day ??= $this->today();
        $cap = $this->budgetFor($tier, $operatorClass);
        $consumed = 0;
        $rolledBack = 0;
        $actions = [];
        foreach ($this->readJsonl($this->logPath()) as $entry) {
            $schema = (string) ($entry['schema_version'] ?? '');
            if (($entry['tier'] ?? null) !== $tier
                || ($entry['operator_class'] ?? null) !== $operatorClass
                || ($entry['day'] ?? null) !== $day) {
                continue;
            }
            if ($schema === self::CONSUME_SCHEMA) {
                $consumed++;
                $actions[(string) ($entry['action_id'] ?? '')] = $entry;
            } elseif ($schema === self::ROLLBACK_SCHEMA) {
                $aid = (string) ($entry['action_id'] ?? '');
                if (isset($actions[$aid])) {
                    $rolledBack++;
                    unset($actions[$aid]);
                }
            }
        }
        $net = max(0, $consumed - $rolledBack);
        $remaining = max(0, $cap - $net);

        return [
            'schema_version' => self::STATE_SCHEMA,
            'day' => $day,
            'tier' => $tier,
            'operator_class' => $operatorClass,
            'cap' => $cap,
            'consumed_gross' => $consumed,
            'rolled_back' => $rolledBack,
            'consumed_net' => $net,
            'remaining' => $remaining,
            'open_actions' => array_keys($actions),
        ];
    }

    /**
     * Check if a consume call would be allowed without recording it.
     *
     * @return array<string,mixed>
     */
    public function check(string $tier, string $operatorClass, ?string $day = null): array
    {
        if (! in_array($tier, self::VALID_TIERS, true)) {
            return ['verdict' => self::VERDICT_DENY_UNKNOWN_TIER, 'tier' => $tier];
        }
        if (! in_array($operatorClass, self::VALID_CLASSES, true)) {
            return ['verdict' => self::VERDICT_DENY_UNKNOWN_CLASS, 'operator_class' => $operatorClass];
        }
        $state = $this->state($tier, $operatorClass, $day);
        $verdict = $state['remaining'] > 0
            ? self::VERDICT_ALLOW
            : self::VERDICT_DENY_BUDGET_EXCEEDED;

        return ['verdict' => $verdict] + $state;
    }

    /**
     * Consume one action — records receipt and returns envelope.
     *
     * @param  array{tier:string, operator_class:string, action_kind:string, actor:string, reason:string, scope?:array<string,mixed>}  $input
     * @return array<string,mixed>
     */
    public function consume(array $input): array
    {
        $tier = (string) ($input['tier'] ?? '');
        $operatorClass = (string) ($input['operator_class'] ?? '');
        $actionKind = (string) ($input['action_kind'] ?? '');
        $actor = (string) ($input['actor'] ?? '');
        $reason = (string) ($input['reason'] ?? '');
        $scope = (array) ($input['scope'] ?? []);

        if (! in_array($tier, self::VALID_TIERS, true)) {
            throw new InvalidArgumentException("Unknown tier '{$tier}'.");
        }
        if (! in_array($operatorClass, self::VALID_CLASSES, true)) {
            throw new InvalidArgumentException("Unknown operator_class '{$operatorClass}'.");
        }
        if ($actionKind === '' || $actor === '' || $reason === '') {
            throw new InvalidArgumentException('action_kind, actor and reason are required.');
        }

        $day = $this->today();
        $check = $this->check($tier, $operatorClass, $day);

        $recordedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $actionId = 'tba_'.substr(hash('sha256', $recordedAt.'|'.$tier.'|'.$operatorClass.'|'.$actionKind.'|'.$actor), 0, 12);

        $receipt = [
            'schema_version' => self::CONSUME_SCHEMA,
            'recorded_at' => $recordedAt,
            'day' => $day,
            'action_id' => $actionId,
            'tier' => $tier,
            'operator_class' => $operatorClass,
            'action_kind' => $actionKind,
            'actor' => $actor,
            'reason' => $reason,
            'scope' => $scope,
            'verdict' => $check['verdict'],
            'cap' => $this->budgetFor($tier, $operatorClass),
            'remaining_after' => $check['verdict'] === self::VERDICT_ALLOW
                ? max(0, ($check['remaining'] ?? 0) - 1)
                : ($check['remaining'] ?? 0),
        ];
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'recorded_at' => $recordedAt,
            'action_id' => $actionId,
            'tier' => $tier,
            'operator_class' => $operatorClass,
            'action_kind' => $actionKind,
            'actor' => $actor,
            'verdict' => $receipt['verdict'],
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $receipt);

        return $receipt;
    }

    /**
     * Rollback one previously consumed action by action_id.
     *
     * @return array<string,mixed>
     */
    public function rollback(string $actionId, string $actor, string $reason): array
    {
        if ($actionId === '' || $actor === '' || $reason === '') {
            throw new InvalidArgumentException('action_id, actor and reason are required.');
        }
        $consumeReceipt = null;
        foreach ($this->readJsonl($this->logPath()) as $entry) {
            if (($entry['schema_version'] ?? '') === self::CONSUME_SCHEMA
                && ($entry['action_id'] ?? '') === $actionId) {
                $consumeReceipt = $entry;
                break;
            }
        }
        if ($consumeReceipt === null) {
            throw new InvalidArgumentException("action_id '{$actionId}' not found.");
        }

        $recordedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $receipt = [
            'schema_version' => self::ROLLBACK_SCHEMA,
            'recorded_at' => $recordedAt,
            'day' => $consumeReceipt['day'],
            'action_id' => $actionId,
            'tier' => $consumeReceipt['tier'],
            'operator_class' => $consumeReceipt['operator_class'],
            'original_action_kind' => $consumeReceipt['action_kind'] ?? '',
            'rollback_actor' => $actor,
            'rollback_reason' => $reason,
        ];
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'recorded_at' => $recordedAt,
            'action_id' => $actionId,
            'tier' => $receipt['tier'],
            'rollback_actor' => $actor,
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $receipt);

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReceipts(): array
    {
        return $this->readJsonl($this->logPath());
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    // ---------- internals ----------

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
