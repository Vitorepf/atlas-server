"""ANTI-FAKE PROOF (provider-gated).

With a REAL embedding model, two sentences that mean similar things rank close
even with NON-OVERLAPPING vocabulary — something a hash signature or a TF-IDF
token bag fundamentally cannot do (no shared tokens => ~zero similarity). If no
real provider is configured this is SKIPPED honestly (never replaced by a fake).
"""

from __future__ import annotations

import pytest

from atlas_semantic_rag.embeddings import NoEmbeddingProviderError, resolve_embedder
from atlas_semantic_rag.rag import SemanticRag


def _provider():
    try:
        return resolve_embedder()
    except NoEmbeddingProviderError:
        return None


PROVIDER = _provider()


@pytest.mark.skipif(PROVIDER is None, reason="no real embedding provider configured (honest skip, not a fake)")
def test_real_embeddings_capture_meaning_without_shared_tokens():
    rag = SemanticRag(embedder=PROVIDER)
    rag.index(
        [
            {"id": "feline", "text": "A cat is a small domesticated carnivorous mammal that purrs."},
            {"id": "finance", "text": "Quarterly revenue exceeded the analyst earnings forecast."},
        ]
    )
    # The query shares essentially no content words with either document.
    res = rag.retrieve("a kitten sleeping on the sofa", k=2)
    assert res.matches[0]["id"] == "feline", res.matches
    assert res.matches[0]["score"] > res.matches[1]["score"]
    assert res.provider in {"fastembed_local", "openai_api"}
    assert res.dim > 0


@pytest.mark.skipif(PROVIDER is None, reason="no real embedding provider configured (honest skip, not a fake)")
def test_real_embeddings_are_unit_norm():
    import numpy as np

    vectors = PROVIDER.embed(["alpha", "beta"])
    norms = np.linalg.norm(vectors, axis=1)
    assert np.allclose(norms, 1.0, atol=1e-4)
