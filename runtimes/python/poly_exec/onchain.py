"""Atlas Polymarket ON-CHAIN runtime — CTF split (mint a full set) / merge.

This is the governed boundary for the SHORT motor (mint a full set for $1/set,
then sell legs on the CLOB) and the long early-merge (redeem a held set back to
$1). PHP (LivePolyOnChainClient) hands a fully-specified request on stdin and
reads a receipt on stdout. Secrets come from the ENVIRONMENT only:

    ATLAS_POLY_PRIVATE_KEY        EOA signer key (holds the USDC.e collateral)
    ATLAS_POLY_FUNDER_ADDRESS     proxy/funder address (proxy wallets only)
    ATLAS_POLY_POLYGON_RPC_URL    Polygon RPC endpoint (web3 provider)

STATUS: scaffolded, UNPROVEN, and DISARMED by default. It signs NOTHING unless
ALL of these hold:
  - web3 is installed AND a Polygon RPC is set AND a private key is present
  - the account is an EOA (a proxy/magic wallet holds funds in a proxy contract;
    an on-chain split from a bare EOA key would be unfunded — fail-closed)
  - ATLAS_POLY_ONCHAIN_ARMED=true is set explicitly by the operator
  - for a NegRisk multi-outcome event, ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED=true,
    set ONLY after the exact NegRiskAdapter split/merge call has been confirmed
    with ONE minimal real transaction (the spec's "verificar com transação
    mínima antes de assumir"). The single-condition ConditionalTokens path uses
    the stable, well-known CTF ABI; the cross-outcome NegRisk path is the part
    that MUST be operator-verified before any size is trusted.

Anything short of that returns {"ok": false, "real_tx": false, ...} so the PHP
state machine treats it as "no mint happened" — never a silent half-state.
"""

import json
import os
import sys

# Standard ConditionalTokens framework calls (stable ABI). NegRisk multi-outcome
# events route through the NegRiskAdapter at the same call shape but MUST be
# verified before trust — see the module docstring.
CTF_ABI = [
    {
        "name": "splitPosition",
        "type": "function",
        "stateMutability": "nonpayable",
        "inputs": [
            {"name": "collateralToken", "type": "address"},
            {"name": "parentCollectionId", "type": "bytes32"},
            {"name": "conditionId", "type": "bytes32"},
            {"name": "partition", "type": "uint256[]"},
            {"name": "amount", "type": "uint256"},
        ],
        "outputs": [],
    },
    {
        "name": "mergePositions",
        "type": "function",
        "stateMutability": "nonpayable",
        "inputs": [
            {"name": "collateralToken", "type": "address"},
            {"name": "parentCollectionId", "type": "bytes32"},
            {"name": "conditionId", "type": "bytes32"},
            {"name": "partition", "type": "uint256[]"},
            {"name": "amount", "type": "uint256"},
        ],
        "outputs": [],
    },
]

ZERO_BYTES32 = "0x" + "00" * 32
USDC_DECIMALS = 6


def _fail(error: str, **extra):
    out = {"ok": False, "real_tx": False, "error": error}
    out.update(extra)
    print(json.dumps(out))
    return 0


def _truthy(name: str) -> bool:
    return os.environ.get(name, "").strip().lower() in ("1", "true", "yes", "on")


def main() -> int:
    try:
        request = json.loads(sys.stdin.read() or "{}")
    except json.JSONDecodeError:
        return _fail("bad_request_json")

    operation = request.get("operation")
    if operation not in ("split", "merge"):
        return _fail("unknown_operation", operation=str(operation))

    # --- Fail-closed gates (no signing unless every one passes) -----------------
    if not _truthy("ATLAS_POLY_ONCHAIN_ARMED"):
        return _fail("onchain_disarmed", hint="set ATLAS_POLY_ONCHAIN_ARMED=true after verifying with a minimal mint")

    pk = os.environ.get("ATLAS_POLY_PRIVATE_KEY", "").strip()
    rpc = os.environ.get("ATLAS_POLY_POLYGON_RPC_URL", "").strip()
    if not pk:
        return _fail("missing_private_key")
    if not rpc:
        return _fail("missing_polygon_rpc")

    # A proxy/magic wallet holds funds in a proxy contract — a bare EOA split is
    # unfunded. Only the EOA path is wired; proxy on-chain stays fail-closed.
    if str(request.get("account_kind", "")) != "eoa":
        return _fail("onchain_requires_eoa")

    neg_risk = bool(request.get("neg_risk", False))
    if neg_risk and not _truthy("ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED"):
        return _fail(
            "negrisk_unverified",
            hint="confirm the NegRiskAdapter split/merge with one minimal real tx, then set ATLAS_POLY_ONCHAIN_NEGRISK_VERIFIED=true",
        )

    try:
        from web3 import Web3
    except Exception as e:  # noqa: BLE001 — any import failure is an honest "not installed"
        return _fail("web3_not_installed", detail=str(e))

    condition_id = str(request.get("condition_id", "")).strip()
    if not condition_id:
        return _fail("missing_condition_id")
    try:
        sets = float(request.get("sets", 0.0))
    except (TypeError, ValueError):
        return _fail("bad_sets")
    if sets <= 0.0:
        return _fail("nonpositive_sets")

    token_ids = request.get("token_ids") or []
    if not isinstance(token_ids, list) or len(token_ids) < 2:
        return _fail("bad_token_ids")

    usdc = str(request.get("usdc", "")).strip()
    # NegRisk events route through the adapter; single conditions through the CTF.
    target = str(request.get("neg_risk_adapter" if neg_risk else "conditional_tokens", "")).strip()
    if not (Web3.is_address(usdc) and Web3.is_address(target)):
        return _fail("bad_contract_addresses")

    try:
        w3 = Web3(Web3.HTTPProvider(rpc))
        if not w3.is_connected():
            return _fail("rpc_unreachable")
        acct = w3.eth.account.from_key(pk)
        contract = w3.eth.contract(address=Web3.to_checksum_address(target), abi=CTF_ABI)

        # Full set across N outcomes: partition = [1, 2, 4, ...] (one index set each).
        partition = [1 << i for i in range(len(token_ids))]
        amount = int(round(sets * (10 ** USDC_DECIMALS)))
        fn = contract.functions.splitPosition if operation == "split" else contract.functions.mergePositions
        call = fn(Web3.to_checksum_address(usdc), ZERO_BYTES32, condition_id, partition, amount)

        tx = call.build_transaction({
            "from": acct.address,
            "nonce": w3.eth.get_transaction_count(acct.address),
            "chainId": 137,
            "gas": int(request.get("gas_limit", 400000)),
            "maxFeePerGas": w3.eth.gas_price * 2,
            "maxPriorityFeePerGas": w3.to_wei(30, "gwei"),
        })
        signed = acct.sign_transaction(tx)
        tx_hash = w3.eth.send_raw_transaction(signed.raw_transaction)
        receipt = w3.eth.wait_for_transaction_receipt(tx_hash, timeout=100)
    except Exception as e:  # noqa: BLE001
        return _fail("onchain_exception", detail=str(e)[:400])

    if receipt.get("status", 0) != 1:
        return _fail("tx_reverted", tx_hash=receipt.get("transactionHash", b"").hex() if hasattr(receipt.get("transactionHash", b""), "hex") else str(receipt.get("transactionHash")))

    gas_used = int(receipt.get("gasUsed", 0))
    effective = int(receipt.get("effectiveGasPrice", w3.eth.gas_price))
    gas_native_wei = gas_used * effective
    print(json.dumps({
        "ok": True,
        "real_tx": True,
        "operation": operation,
        "sets": sets,
        "collateral_usd": round(sets, 6),         # $1 per set (USDC.e)
        "gas_native_wei": str(gas_native_wei),     # MATIC wei; PHP applies its USD estimate
        "gas_usd": 0.0,                            # measured in native units; PHP uses est_*_gas_usd
        "tx_hash": receipt["transactionHash"].hex() if hasattr(receipt["transactionHash"], "hex") else str(receipt["transactionHash"]),
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
