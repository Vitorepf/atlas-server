<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FrozenContracts;

use Throwable;

/**
 * Fail-closed gate that REFUSES to retire (delete or weaken) a frozen contract on any loop-critical class
 * unless an explicit operator-receipt is presented (signed token, recorded under
 * storage/atlas/loop/frozen-contracts/retirement-receipts.json with timestamp + class + reason + token-hash).
 *
 * INVARIANTS:
 *   - DEFAULT-OFF via config('atlas.loop.frozen_contracts.retirement_gate_enabled', false) ⇒ the gate is a
 *     byte-identical pass-through; the rollout is invisible until the operator arms it.
 *   - When ARMED ⇒ fail-closed: missing/unreadable receipt store, missing matching receipt, or absent token
 *     all REFUSE retirement. The gate NEVER silently allows.
 *
 * Call sites: pre-commit / merge actuator hooks against the Constitution chain.
 */
final class AtlasLoopFrozenContractRetirementGate
{
    public const CONFIG_FLAG_KEY = 'atlas.loop.frozen_contracts.retirement_gate_enabled';

    public const REASON_GATE_DISABLED = 'gate_disabled';

    public const REASON_MISSING_OPERATOR_RECEIPT = 'missing_operator_receipt';

    public const REASON_RECEIPT_STORE_UNREADABLE = 'receipt_store_unreadable';

    public const REASON_ALLOWED = 'allowed_by_matching_operator_receipt';

    private ?string $receiptStorePath = null;

    /** @var null|callable():bool */
    private $flagOverride;

    public function __construct(?string $receiptStorePath = null, ?callable $flagOverride = null)
    {
        $this->receiptStorePath = $receiptStorePath;
        $this->flagOverride = $flagOverride;
    }

    public function setReceiptStorePathForTesting(?string $path): void
    {
        $this->receiptStorePath = $path === null ? null : rtrim($path, '/');
    }

    /**
     * Decide whether to allow the retirement of $class. Returns a verdict; downstream code MUST refuse to
     * act when `allowed === false`.
     *
     * @return array{allowed:bool, reason:string, class:string, token_hash:?string}
     */
    public function decide(string $class, ?string $operatorReceiptToken = null): array
    {
        $class = trim($class, '\\');
        if (! $this->flagOn()) {
            return ['allowed' => true, 'reason' => self::REASON_GATE_DISABLED, 'class' => $class, 'token_hash' => null];
        }

        if ($operatorReceiptToken === null || trim($operatorReceiptToken) === '') {
            return ['allowed' => false, 'reason' => self::REASON_MISSING_OPERATOR_RECEIPT, 'class' => $class, 'token_hash' => null];
        }

        $store = $this->loadReceiptStore();
        if ($store === null) {
            return ['allowed' => false, 'reason' => self::REASON_RECEIPT_STORE_UNREADABLE, 'class' => $class, 'token_hash' => null];
        }

        $tokenHash = hash('sha256', $operatorReceiptToken);
        foreach ($store as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            $matchClass = (string) ($receipt['class'] ?? '') === $class;
            $matchToken = (string) ($receipt['token_hash'] ?? '') === $tokenHash;
            if ($matchClass && $matchToken) {
                return ['allowed' => true, 'reason' => self::REASON_ALLOWED, 'class' => $class, 'token_hash' => $tokenHash];
            }
        }

        return ['allowed' => false, 'reason' => self::REASON_MISSING_OPERATOR_RECEIPT, 'class' => $class, 'token_hash' => $tokenHash];
    }

    public function receiptStorePath(): string
    {
        if ($this->receiptStorePath !== null && $this->receiptStorePath !== '') {
            return $this->receiptStorePath.'/retirement-receipts.json';
        }
        if (function_exists('storage_path')) {
            try {
                return (string) storage_path('atlas/loop/frozen-contracts/retirement-receipts.json');
            } catch (Throwable) {
            }
        }

        return sys_get_temp_dir().'/atlas-loop-frozen-contracts-retirement-receipts.json';
    }

    /**
     * @return list<array{class:string, token_hash:string, reason:string, recorded_at:int}>|null
     */
    private function loadReceiptStore(): ?array
    {
        $path = $this->receiptStorePath();
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : null;
    }

    private function flagOn(): bool
    {
        $override = $this->flagOverride;
        if (is_callable($override)) {
            return (bool) $override();
        }
        if (! function_exists('config')) {
            return false;
        }
        try {
            return (bool) config(self::CONFIG_FLAG_KEY, false);
        } catch (Throwable) {
            return false;
        }
    }
}
