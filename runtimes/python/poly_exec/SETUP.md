# Polymarket live signer — activation (operator only)

This is the **only** code path that signs real Polymarket orders. It stays inert
until every step below is done. Sim mode needs none of this.

## 1. Install the signer deps (local venv)

```bash
python3 -m venv runtimes/python/poly_exec/.venv
runtimes/python/poly_exec/.venv/bin/pip install py-clob-client
```

> The PHP `LivePolyExecClient` currently calls `python3` on PATH. If you use the
> venv above, point it at the venv python (one-line change in
> `LivePolyExecClient::invoke`) or `pip install --user py-clob-client`.

## 2. Funding + account shape

- Confirm the Polymarket account holds USDC.e on Polygon.
- **Proxy wallet (email/magic login — the default):** signature type 1. You need
  the magic/session private key **and** the proxy (funder) address.
- **EOA (own wallet):** signature type 0. Just the private key.

`atlas:finance:poly-exec preflight` prints the detected kind and what's missing —
never any secret values.

## 3. Derive L2 API creds (once)

Use the SDK's `create_or_derive_api_key()` with your signer key to mint the L2
`api_key / api_secret / api_passphrase`.

## 4. Set LOCAL env (never commit, never log)

```
ATLAS_POLY_EXEC_LIVE_ENABLED=true
ATLAS_POLY_ACCOUNT_KIND=proxy        # or eoa
ATLAS_POLY_PRIVATE_KEY=...
ATLAS_POLY_FUNDER_ADDRESS=0x...      # proxy only
ATLAS_POLY_CLOB_API_KEY=...
ATLAS_POLY_CLOB_API_SECRET=...
ATLAS_POLY_CLOB_API_PASSPHRASE=...
```

## 5. Validate with ONE tiny real order BEFORE trusting size

The order semantics in `entrypoint.py` (marketable FOK limit buy, GTC limit
sell, immediate fill, response field names) are **unverified against the live
SDK**. Place one minimal order, confirm the fill receipt and on-chain position
reconcile, and only then raise the caps. This is the spec's "verificar com
transação mínima antes de assumir".

## 6. SHORT side — on-chain minting (`onchain.py`)

The short motor (mint a full set for $1/set, sell legs > $1) and the long
early-merge need **on-chain** CTF transactions, which the CLOB SDK cannot do.
`onchain.py` is the governed boundary. It is **fail-closed and DISARMED by
default** — it signs nothing until every guard below passes.

### Wallet requirement (read this first)

On-chain minting is **only wired for an EOA** that directly holds USDC.e on
Polygon. A proxy/magic (email-login) wallet keeps funds in a proxy contract, so
a bare-EOA split would be unfunded — `onchain.py` refuses (`onchain_requires_eoa`)
and the short side stays sim-only for that wallet. To run the short engine live,
use an EOA funded with USDC.e (`ATLAS_POLY_ACCOUNT_KIND=eoa`).

### Deps + env

```bash
runtimes/python/poly_exec/.venv/bin/pip install web3
```

```
ATLAS_POLY_POLYGON_RPC_URL=https://polygon-rpc.com   # or your own RPC
ATLAS_POLY_ONCHAIN_ARMED=true                        # explicit opt-in
# Only after the NegRisk split/merge is confirmed with one minimal real tx:
ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED=true
```

### Verify the NegRisk path with ONE minimal mint

Polymarket multi-outcome (NegRisk) events split/merge through the
**NegRiskAdapter**, not the vanilla ConditionalTokens framework. The exact
adapter call/partition is the part that **must be confirmed with one minimal
real mint** before any size is trusted — until `ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED`
is set, `onchain.py` returns `negrisk_unverified` and signs nothing. The
single-condition CTF path uses the stable, well-known ABI.

`atlas:finance:poly-exec preflight --mode=live` prints `short.live_onchain_ready`
and the wallet note — never any secret values.

## Kill instantly

```bash
touch storage/app/atlas-poly-exec.kill   # halts everything, holds/unwinds in-flight
rm    storage/app/atlas-poly-exec.kill   # resume
```

## Run (sim is the default and needs none of section 5/6)

```bash
php artisan atlas:finance:poly-exec preflight --mode=sim --json
php artisan atlas:finance:poly-exec plan      --mode=sim --kind=both --json
php artisan atlas:finance:poly-exec run       --mode=sim --kind=both --json   # shadow-sim, signs/mints nothing
# live (ALL gates must pass): --mode=live --confirm, flag on, EOA ready, armed
```
