"""Atlas Polymarket LIVE order signer — the governed Python boundary.

PHP (LivePolyExecClient) hands a fully-specified order in on stdin as JSON and
reads a receipt on stdout. Secrets come from the ENVIRONMENT only:

    ATLAS_POLY_PRIVATE_KEY        signer key (EOA key, or the magic/email session key)
    ATLAS_POLY_FUNDER_ADDRESS     proxy/funder address (required for proxy wallets)
    ATLAS_POLY_CLOB_API_KEY       L2 API creds (derive once via the SDK)
    ATLAS_POLY_CLOB_API_SECRET
    ATLAS_POLY_CLOB_API_PASSPHRASE

This script signs nothing unless py-clob-client is installed AND the creds are
present. Otherwise it returns {"ok": false, "real_order": false, ...} so the
PHP state machine treats it as a leg failure (abort + unwind) — never a silent
no-op into a half-open position.

STATUS: UNPROVEN until `pip install py-clob-client` + funded keys + a minimal
live test order (per the operator runbook). The order semantics below
(marketable limit, immediate fill) MUST be validated with one tiny real order
before any size is trusted.
"""

import json
import os
import sys


def _fail(error: str, **extra):
    out = {"ok": False, "real_order": False, "error": error}
    out.update(extra)
    print(json.dumps(out))
    return 0


def main() -> int:
    try:
        request = json.loads(sys.stdin.read() or "{}")
    except json.JSONDecodeError:
        return _fail("bad_request_json")

    operation = request.get("operation")

    pk = os.environ.get("ATLAS_POLY_PRIVATE_KEY", "").strip()
    funder = os.environ.get("ATLAS_POLY_FUNDER_ADDRESS", "").strip()
    api_key = os.environ.get("ATLAS_POLY_CLOB_API_KEY", "").strip()
    api_secret = os.environ.get("ATLAS_POLY_CLOB_API_SECRET", "").strip()
    api_pass = os.environ.get("ATLAS_POLY_CLOB_API_PASSPHRASE", "").strip()

    if not pk:
        return _fail("missing_private_key")
    if not (api_key and api_secret and api_pass):
        return _fail("missing_api_creds")

    try:
        from py_clob_client.client import ClobClient
        from py_clob_client.clob_types import ApiCreds, OrderArgs, OrderType
        from py_clob_client.order_builder.constants import BUY, SELL
    except Exception as e:  # noqa: BLE001 — any import failure is an honest "not installed"
        return _fail("py_clob_client_not_installed", detail=str(e))

    host = "https://clob.polymarket.com"
    chain_id = 137  # Polygon
    signature_type = int(request.get("signature_type", 0))

    try:
        creds = ApiCreds(api_key=api_key, api_secret=api_secret, api_passphrase=api_pass)
        kwargs = {"key": pk, "chain_id": chain_id, "creds": creds, "signature_type": signature_type}
        if funder:
            kwargs["funder"] = funder
        client = ClobClient(host, **kwargs)
    except Exception as e:  # noqa: BLE001
        return _fail("client_init_failed", detail=str(e))

    try:
        if operation == "position":
            # Best-effort holdings read for reconciliation.
            token = str(request.get("token", ""))
            try:
                # Data API endpoint; SDK surface may vary across versions.
                positions = client.get_positions() if hasattr(client, "get_positions") else []
            except Exception:  # noqa: BLE001
                positions = []
            size = 0.0
            for p in positions or []:
                if str(p.get("asset", p.get("token_id", ""))) == token:
                    size = float(p.get("size", 0))
                    break
            print(json.dumps({"ok": True, "real_order": False, "size": size}))
            return 0

        if operation in ("buy_limit", "sell_limit", "sell_market"):
            token = str(request["token"])
            size = float(request["size"])
            if operation == "buy_limit":
                # Marketable limit buy: cross up to `price`, all-or-nothing (FOK).
                side, price, order_type = BUY, float(request.get("price", 0.0)), OrderType.FOK
            elif operation == "sell_limit":
                # Short leg sell at a protective floor `price`. GTC so a partial fill is
                # allowed — the unsold remainder is simply held as a freeroll.
                side, price, order_type = SELL, float(request.get("price", 0.0)), OrderType.GTC
            else:  # sell_market: best-effort unwind of a long leg.
                side, price, order_type = SELL, 0.01, OrderType.GTC
            # Validate these semantics with ONE tiny order before trusting size.
            args = OrderArgs(price=price, size=size, side=side, token_id=token)
            signed = client.create_order(args)
            resp = client.post_order(signed, order_type)

            order_id = resp.get("orderID") or resp.get("orderId") or resp.get("id")
            if not order_id:
                return _fail("order_not_accepted", detail=json.dumps(resp)[:400])

            # Filled size/price come from the response when matched; otherwise 0.
            filled = float(resp.get("makingAmount", resp.get("filled_size", 0)) or 0)
            avg = float(resp.get("price", price) or price)
            print(json.dumps({
                "ok": True,
                "real_order": True,
                "order_id": str(order_id),
                "filled_size": filled,
                "avg_price": avg,
                "cash_usd": round(filled * avg, 6),
                "raw_status": resp.get("status"),
            }))
            return 0

        return _fail("unknown_operation", operation=str(operation))
    except Exception as e:  # noqa: BLE001
        return _fail("order_exception", detail=str(e))


if __name__ == "__main__":
    sys.exit(main())
