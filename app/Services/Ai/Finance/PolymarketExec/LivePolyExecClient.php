<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use Symfony\Component\Process\Process;

/**
 * The ONE path that can sign real Polymarket orders. By the runtime-language
 * boundary canon (and basic prudence: never hand-roll EIP-712/secp256k1 signing
 * for money in PHP), PHP does not sign — it hands a fully-specified order to the
 * governed Python runtime that wraps the canonical Polymarket CLOB SDK.
 *
 * Secrets (private key, API L2 creds, funder) are passed to the subprocess via
 * its ENVIRONMENT only — never argv, never logged, never persisted. The request
 * goes in on stdin as JSON; a signed-order receipt comes back on stdout. A
 * result is accepted only if it proves a real order (`real_order: true`).
 *
 * Fail-closed: if the runtime is absent, the deps aren't installed, or creds are
 * missing, every method returns a failed {@see FillResult} — which the state
 * machine treats as a leg failure (abort + unwind), so an unready live backend
 * can never silently no-op into a half-open position.
 *
 * STATUS: scaffolded, UNPROVEN. It cannot be exercised end-to-end until the
 * operator installs the runtime deps (py-clob-client) and sets ATLAS_POLY_*
 * keys. Until then it honestly refuses rather than pretending to trade.
 */
final class LivePolyExecClient implements PolyExecClient
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

    public function buyLimit(string $token, float $limitPrice, float $size): FillResult
    {
        return $this->call('buy_limit', [
            'token' => $token,
            'price' => round($limitPrice, 6),
            'size' => round($size, 6),
            'side' => 'BUY',
        ]);
    }

    public function sellLimit(string $token, float $limitPrice, float $size): FillResult
    {
        return $this->call('sell_limit', [
            'token' => $token,
            'price' => round($limitPrice, 6),
            'size' => round($size, 6),
            'side' => 'SELL',
        ]);
    }

    public function sellMarket(string $token, float $size): FillResult
    {
        return $this->call('sell_market', [
            'token' => $token,
            'size' => round($size, 6),
            'side' => 'SELL',
        ]);
    }

    public function positionSize(string $token): ?float
    {
        $res = $this->invoke(['operation' => 'position', 'token' => $token]);
        if ($res === null || ($res['ok'] ?? false) !== true) {
            return null;
        }

        return isset($res['size']) ? (float) $res['size'] : null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function call(string $operation, array $order): FillResult
    {
        if (! $this->cfg->liveEnabled) {
            return FillResult::nothing('live_disabled');
        }
        if (! $this->identity->isFundingResolvable() || ! $this->identity->hasApiCreds()) {
            return FillResult::nothing('creds_incomplete');
        }

        $res = $this->invoke(['operation' => $operation] + $order);
        if ($res === null) {
            return FillResult::nothing('runtime_unavailable');
        }
        // Boundary guard: only a proven real order counts. A stub/echo cannot pass.
        if (($res['ok'] ?? false) !== true || ($res['real_order'] ?? false) !== true) {
            return FillResult::nothing((string) ($res['error'] ?? 'not_a_real_order'));
        }

        return new FillResult(
            ok: true,
            filledSize: (float) ($res['filled_size'] ?? 0),
            avgPrice: (float) ($res['avg_price'] ?? 0),
            cashUsd: (float) ($res['cash_usd'] ?? 0),
            orderId: isset($res['order_id']) ? (string) $res['order_id'] : null,
            error: null,
        );
    }

    /**
     * Run the Python signer with secrets in the env and the request on stdin.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function invoke(array $payload): ?array
    {
        $entry = $this->runtimeRoot.'/entrypoint.py';
        if (! is_file($entry)) {
            return null;
        }

        $live = (array) config('atlas.finance_poly_exec.live', []);
        // Pass-through ONLY the named secret env vars (by value, into the child env).
        $secretEnv = [];
        foreach (['private_key_env', 'funder_address_env', 'api_key_env', 'api_secret_env', 'api_passphrase_env'] as $k) {
            $name = (string) ($live[$k] ?? '');
            if ($name !== '' && is_string(env($name)) && env($name) !== '') {
                $secretEnv[$name] = (string) env($name);
            }
        }

        $request = json_encode($payload + [
            'signature_type' => $this->identity->signatureType(),
            'account_kind' => $this->identity->kind(),
        ]);

        try {
            $process = new Process(
                command: [$this->cfg->pythonBin, $entry],
                cwd: base_path(),
                env: $secretEnv + ['PYTHONUNBUFFERED' => '1'],
                input: $request,
                timeout: 30.0,
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
