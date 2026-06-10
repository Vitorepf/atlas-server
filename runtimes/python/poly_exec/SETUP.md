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

The order semantics in `entrypoint.py` (marketable FOK limit buy, immediate
fill, response field names) are **unverified against the live SDK**. Place one
minimal order, confirm the fill receipt and on-chain position reconcile, and
only then raise the caps. This is the spec's "verificar com transação mínima
antes de assumir".

## Kill instantly

```bash
touch storage/app/atlas-poly-exec.kill   # halts everything, unwinds in-flight
rm    storage/app/atlas-poly-exec.kill   # resume
```
