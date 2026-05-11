from __future__ import annotations

import unittest

from atlas_voice_agent.token_issuer_contract import (
    PLAN_SCHEMA_VERSION,
    READINESS_SCHEMA_VERSION,
    SMOKE_SCHEMA_VERSION,
    TokenIssuerContractViolation,
    validate_token_issuer_plan,
    validate_token_issuer_smoke,
)


def valid_plan() -> dict[str, object]:
    return {
        "schema_version": PLAN_SCHEMA_VERSION,
        "status": "blocked",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "mobile_first": True,
        "kernel_only": True,
        "readiness": {
            "schema_version": READINESS_SCHEMA_VERSION,
            "status": "blocked",
            "secrets_exposed": False,
        },
        "required_env": [
            {"name": "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED", "configured": False, "secret": False},
            {"name": "LIVEKIT_URL", "configured": False, "secret": False},
            {"name": "LIVEKIT_API_KEY", "configured": False, "secret": True},
            {"name": "LIVEKIT_API_SECRET", "configured": False, "secret": True},
            {"name": "ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS", "configured": True, "secret": False},
        ],
        "redacted_env_template": [
            "ATLAS_VOICE_LIVEKIT_TOKEN_ISSUER_ENABLED=true",
            "LIVEKIT_URL=https://<your-livekit-host>",
            "LIVEKIT_API_KEY=<set-in-local-env-only>",
            "LIVEKIT_API_SECRET=<set-in-local-env-only>",
            "ATLAS_VOICE_LIVEKIT_TOKEN_TTL_SECONDS=900",
        ],
        "redacted_env_template_hash": "a" * 64,
        "security_contract": {
            "secrets_exposed": False,
            "writes_env_file": False,
            "starts_daemon": False,
            "issues_token_during_plan": False,
            "raw_audio_persistence_allowed": False,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
        },
    }


def valid_smoke() -> dict[str, object]:
    return {
        "schema_version": SMOKE_SCHEMA_VERSION,
        "status": "blocked",
        "surface_id": "voice_realtime",
        "runtime_id": "livekit_agents_sdk",
        "mobile_first": True,
        "kernel_only": True,
        "ephemeral_test_config": False,
        "production_readiness": "current_environment_checked",
        "readiness": {
            "schema_version": READINESS_SCHEMA_VERSION,
            "status": "blocked",
            "secrets_exposed": False,
        },
        "token_issued": False,
        "token_hash": None,
        "token_segments_count": 0,
        "access_token_exposed": False,
        "issued_token": {
            "issued": False,
            "status": "not_issued_missing_config",
            "issuer": "atlas_voice_livekit_token_issuer",
        },
        "smoke_lease": {
            "room_name": "atlas-voice-smoke",
            "participant_identity": "mobile:smoke",
        },
        "security_contract": {
            "secrets_exposed": False,
            "access_token_exposed": False,
            "writes_env_file": False,
            "starts_daemon": False,
            "raw_audio_persistence_allowed": False,
            "direct_provider_call_allowed": False,
            "direct_tool_execution_allowed": False,
            "ephemeral_config_persists_after_command": False,
        },
    }


class TokenIssuerContractTest(unittest.TestCase):
    def test_accepts_safe_token_issuer_plan(self) -> None:
        payload = validate_token_issuer_plan(valid_plan())

        self.assertEqual(PLAN_SCHEMA_VERSION, payload["schema_version"])
        self.assertFalse(payload["security_contract"]["writes_env_file"])
        self.assertFalse(payload["security_contract"]["issues_token_during_plan"])

    def test_rejects_plan_with_unredacted_secret_template(self) -> None:
        payload = valid_plan()
        payload["redacted_env_template"] = ["LIVEKIT_API_SECRET=super-secret-value"]

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_plan(payload)

    def test_rejects_plan_that_writes_env_or_starts_daemon(self) -> None:
        payload = valid_plan()
        payload["security_contract"]["writes_env_file"] = True

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_plan(payload)

        payload = valid_plan()
        payload["security_contract"]["starts_daemon"] = True

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_plan(payload)

    def test_accepts_safe_blocked_token_issuer_smoke(self) -> None:
        payload = validate_token_issuer_smoke(valid_smoke())

        self.assertEqual(SMOKE_SCHEMA_VERSION, payload["schema_version"])
        self.assertFalse(payload["access_token_exposed"])
        self.assertFalse(payload["security_contract"]["starts_daemon"])

    def test_rejects_smoke_with_token_or_secret_leak(self) -> None:
        payload = valid_smoke()
        payload["access_token"] = "header.payload.signature"

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_smoke(payload)

        payload = valid_smoke()
        payload["metadata"] = {"api_secret": "secret"}

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_smoke(payload)

    def test_rejects_smoke_outside_atlas_voice_or_mobile_namespaces(self) -> None:
        payload = valid_smoke()
        payload["smoke_lease"]["room_name"] = "rogue-room"

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_smoke(payload)

        payload = valid_smoke()
        payload["smoke_lease"]["participant_identity"] = "desktop:smoke"

        with self.assertRaises(TokenIssuerContractViolation):
            validate_token_issuer_smoke(payload)


if __name__ == "__main__":
    unittest.main()
