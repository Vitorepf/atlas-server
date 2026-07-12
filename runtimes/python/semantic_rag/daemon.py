"""Resident JSONL daemon for the Atlas semantic/RAG runtime.

The PHP boundary still sends the same manifests and verifies the same receipt.
This process only removes the repeated Python/model startup cost by keeping the
runtime loaded behind a local Unix socket.
"""

from __future__ import annotations

import argparse
import hashlib
import importlib
import json
import os
import socket
import sys
import time
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))  # locate sibling runtime packages


SCHEMA_VERSION = "atlas.semantic_rag.daemon.manifest.v1"
PROTOCOL = "jsonl.unix_socket.v1"


def _stable_json(payload: dict[str, Any]) -> str:
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def _write_manifest(path: str, socket_path: str, idle_timeout: int) -> None:
    if not path:
        return

    manifest = {
        "schema_version": SCHEMA_VERSION,
        "protocol": PROTOCOL,
        "pid": os.getpid(),
        "socket_path_hash": hashlib.sha256(socket_path.encode("utf-8")).hexdigest(),
        "runtime": "semantic_rag",
        "started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "idle_timeout_seconds": int(idle_timeout),
    }
    manifest["manifest_sha256"] = hashlib.sha256(_stable_json(manifest).encode("utf-8")).hexdigest()

    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def _handle_line(line: bytes) -> bytes:
    try:
        manifest = json.loads(line.decode("utf-8"))
        result = importlib.import_module("atlas_semantic_rag").run_manifest(manifest)
        payload = {"ok": True, "result": result}
    except Exception as exc:  # pragma: no cover - exercised through PHP fallback behavior
        payload = {"ok": False, "error": str(exc)}

    return (json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Atlas semantic_rag resident daemon")
    parser.add_argument("--socket", required=True)
    parser.add_argument("--manifest-path", default="")
    parser.add_argument("--idle-timeout", type=int, default=300)
    args = parser.parse_args(argv)

    socket_path = str(args.socket)
    idle_timeout = max(1, int(args.idle_timeout))
    path = Path(socket_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        path.unlink()
    except FileNotFoundError:
        pass

    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(socket_path)
    os.chmod(socket_path, 0o600)
    server.listen(8)
    server.settimeout(idle_timeout)
    _write_manifest(str(args.manifest_path), socket_path, idle_timeout)

    try:
        while True:
            try:
                conn, _ = server.accept()
            except socket.timeout:
                return 0

            with conn:
                handle = conn.makefile("rwb")
                line = handle.readline()
                if not line:
                    continue
                handle.write(_handle_line(line))
                handle.flush()
    finally:
        server.close()
        try:
            path.unlink()
        except FileNotFoundError:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
