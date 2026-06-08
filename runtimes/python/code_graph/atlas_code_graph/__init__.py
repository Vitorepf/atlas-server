"""Atlas code-graph python_ai_data runtime (AP-812).

Heavy graph analytics over resolved code-graph edges, kept out of the Laravel
Kernel per atlas-ai-runtime-language-boundaries.md. Invoked via a signed Kernel
payload; results flow back through the runtime invoke/result contract. NOT
promoted to production until human review (runtime_promotion_policy.v1).
"""

from __future__ import annotations

from .centrality import betweenness_centrality
from .communities import detect_communities
from .insights import suggested_questions, surprising_connections

__all__ = [
    "betweenness_centrality",
    "detect_communities",
    "suggested_questions",
    "surprising_connections",
]
