<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec\OnChain;

use App\Services\Ai\Finance\Kernel\FinanceDomainCanon;
use App\Services\Ai\Finance\PolymarketExec\PolyAccountIdentity;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use Symfony\Component\Process\Process;

/**
 * Dormant on-chain seam for CTF split/merge on Polygon. Like the CLOB signer,
 * PHP never builds or signs the transaction — if the Finance policy ever opens,
 * it hands a fully-specified request to the governed Python runtime (web3 + the
 * canonical contract ABIs) and reads a receipt. Secrets reach the subprocess via
 * its ENVIRONMENT only.
 *
 * Fail-closed on every uncertainty: runtime absent, deps missing, creds missing,
 * RPC unset, or — critically — an account shape that cannot mint on-chain. A
 * proxy/magic wallet holds funds in a proxy contract, so a bare EOA split would
 * be unfunded; until a relay path is wired the live client REFUSES rather than
 * risk a half-state. The state machine treats a refusal as "no mint happened".
 *
 * STATUS: scaffolded, UNPROVEN. The exact NegRisk vs ConditionalTokens call must
 * be validated with ONE minimal real mint before any size is trusted — this is
 * the spec's "verificar se merge multi-outcome via NegRisk está disponível com
 * transação mínima antes de assumir".
 */
final class LivePolyOnChainClient implements PolyOnChainClient
{
    public function __construct(
        private readonly PolyExecConfig $cfg,
        private readonly PolyAccountIdentity $identity,
        private readonly string $runtimeRoot,
    ) {}

    public function mode(): string
    {
        return 'live';
    }

    public function splitFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        return $this->call('split', $conditionId, $tokenIds, $sets, $negRisk);
    }

    public function mergeFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        return $this->call('merge', $conditionId, $tokenIds, $sets, $negRisk);
    }

    /**
     * @param  list<string>  $tokenIds
     */
    private function call(string $operation, string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        if (FinanceDomainCanon::liveTradingBlocked()) {
            return TxResult::nothing('finance_policy_live_blocked');
        }
        if (! $this->cfg->liveEnabled) {
            return TxResult::nothing('live_disabled');
        }
        if (! $this->identity->hasSigningKey()) {
            return TxResult::nothing('creds_incomplete');
        }
        // On-chain minting from a proxy/magic wallet is NOT a plain EOA tx (funds
        // live in the proxy contract). Until a relay path is proven, only an EOA may
        // mint/merge on-chain — refuse rather than sign an unfunded transaction.
        if ($this->identity->kind() !== PolyAccountIdentity::KIND_EOA) {
            return TxResult::nothing('onchain_requires_eoa');
        }
        if ($conditionId === '' || $tokenIds === [] || $sets <= 0.0) {
            return TxResult::nothing('bad_onchain_request');
        }

        $res = $this->invoke([
            'operation' => $operation,
            'condition_id' => $conditionId,
            'token_ids' => array_values($tokenIds),
            'sets' => round($sets, 6),
            'neg_risk' => $negRisk,
        ]);
        if ($res === null) {
            return TxResult::nothing('runtime_unavailable');
        }
        // Boundary guard: only a proven on-chain tx counts. A stub/echo cannot pass.
        if (($res['ok'] ?? false) !== true || ($res['real_tx'] ?? false) !== true) {
            return TxResult::nothing((string) ($res['error'] ?? 'not_a_real_tx'));
        }

        return new TxResult(
            ok: true,
            realTx: true,
            sets: (float) ($res['sets'] ?? $sets),
            collateralUsd: (float) ($res['collateral_usd'] ?? $sets),
            gasUsd: (float) ($res['gas_usd'] ?? 0.0),
            txHash: isset($res['tx_hash']) ? (string) $res['tx_hash'] : null,
            error: null,
        );
    }

    /**
     * Run the Python on-chain runtime with secrets in the env and the request on
     * stdin. Mirrors LivePolyExecClient: named secret env only, never argv/log.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function invoke(array $payload): ?array
    {
        $entry = $this->runtimeRoot.'/onchain.py';
        if (! is_file($entry)) {
            return null;
        }

        $live = (array) config('atlas.finance_poly_exec.live', []);
        $secretEnv = [];
        foreach (['private_key_env', 'funder_address_env', 'polygon_rpc_url_env'] as $k) {
            $name = (string) ($live[$k] ?? '');
            if ($name !== '' && is_string(env($name)) && env($name) !== '') {
                $secretEnv[$name] = (string) env($name);
            }
        }

        $request = json_encode($payload + [
            'signature_type' => $this->identity->signatureType(),
            'account_kind' => $this->identity->kind(),
            'neg_risk_adapter' => (string) ($live['neg_risk_adapter'] ?? ''),
            'conditional_tokens' => (string) ($live['conditional_tokens'] ?? ''),
            'usdc' => (string) ($live['usdc'] ?? ''),
        ]);

        try {
            $process = new Process(
                command: [$this->cfg->pythonBin, $entry],
                cwd: base_path(),
                env: $secretEnv + ['PYTHONUNBUFFERED' => '1'],
                input: $request,
                timeout: 120.0, // on-chain confirmation is slower than a CLOB order
            );
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : null;
    }
}
