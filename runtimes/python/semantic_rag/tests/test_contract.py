"""Boundary contract: manifest validation, forbidden keys, provider probe."""

from __future__ import annotations

import pytest

from atlas_semantic_rag.contract import (
    ManifestError,
    probe_provider,
    validate_manifest,
)


def test_forbidden_keys_are_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "embed", "texts": ["x"], "api_key": "sk-secret"})
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "retrieve", "documents": [], "query": "q", "model": "override"})


def test_invalid_operation_rejected():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "drop_database"})
    with pytest.raises(ManifestError):
        validate_manifest({})


def test_valid_manifests_pass():
    validate_manifest({"operation": "embed", "texts": ["a", "b"]})
    validate_manifest({"operation": "retrieve", "documents": [{"id": "1", "text": "a"}], "query": "q"})
    validate_manifest({"operation": "graph", "documents": [{"id": "1", "text": "a"}]})


def test_embed_requires_texts_list():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "embed", "texts": "not-a-list"})


def test_retrieve_requires_query_string():
    with pytest.raises(ManifestError):
        validate_manifest({"operation": "retrieve", "documents": [{"id": "1", "text": "a"}]})


def test_probe_provider_reports_availability_honestly():
    probe = probe_provider()
    assert isinstance(probe, dict)
    assert "available" in probe
    if probe["available"]:
        assert probe["provider"] in {"fastembed_local", "openai_api"}
        assert isinstance(probe["dim"], int) and probe["dim"] > 0
    else:
        assert "reason" in probe  # honest reason, not a silent fake
