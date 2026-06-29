# Provider safety

Every byte that can cross to an external AI is redacted by construction. Atlas lets external tools (Claude Code, Codex, Cursor) plug into its brain via MCP, and it sends context to frontier models through its AI Gateway. Both paths cross a trust boundary: the external AI is untrusted, and the content it receives must not include raw sensitive data, secret bodies, or unredacted memory. Provider-safety is the structural enforcement of this.

The term "provider-bound" means content has been cleared to cross the boundary. Content that has not been cleared never leaves the server. This is enforced in code, not by policy or convention.

## The three enforcement layers

### AtlasMemoryPrivacyService

`app/Services/Ai/AtlasMemoryPrivacyService.php` classifies memory entries by privacy level and provides the `providerAllowed` filter. This is the floor for all memory recall: when the hybrid retrieval service (`AtlasHybridMemoryRetrievalService`) recalls memories for a context pack, every entry passes through this filter. Entries marked sensitive or secret are excluded before the result ever reaches the provider-bound assembly.

### AtlasOpenBrainGuardService

`app/Services/Ai/AtlasOpenBrainGuardService.php` (41KB) is the provider-safety guard for outbound brain content. It enforces redaction on every byte that exits through the Open Brain. The context pack, the per-file brain-delta, and the MCP tool responses all pass through this guard. It strips sensitive patterns, redacts memory to projections, and replaces raw content with ids and hashes where needed.

### The AURG provider floor

The reality graph (AURG, the fused Unified Reality Graph) is queried with `provider_bound=true` when serving external AI. This flag excludes sensitive nodes structurally. The exclusion is not a post-filter that could miss something. It is a query-time constraint that prevents sensitive nodes from entering the result set at all.

## What is excluded

| Content type | Treatment |
|-------------|-----------|
| Raw sensitive/secret bodies | Excluded entirely |
| Memory entries | Redacted to provider-safe projections |
| Evidence | Exposed as ids and hashes only, never raw content |
| Access logs | Hash-only query redaction, no raw objective or workspace path |
| Capture content | Blocked by cognitive quarantine until human ratification |

The access log (`atlas_open_brain_access_logs`) records that a context pack was exported, with a sha256 hash of the query, but never the raw objective text or workspace path. An auditor can prove what was accessed without seeing what was asked.

## Write-back is governed

Provider-safety is bidirectional. Content leaving the server is redacted, and content entering the server from an external AI is treated as untrusted. The write-back service (`app/Services/Ai/AtlasOpenBrainWriteBackService.php`) accepts external output as proposals only:

1. Size is capped first (`config('atlas.aobg.write_back.*')`: max request chars, max files, max memory refs). Oversized input is rejected honestly, not silently truncated.
2. The input passes a capture quality gate and provider-safety check.
3. It writes to a branch, never to main.
4. It never auto-merges or auto-promotes. It becomes a human-review proposal.

## Cognitive quarantine

Raw captures (text, audio, photo) are cognitively quarantined. A raw capture is not memory, context, decision, or learning signal. It is blocked from embedding, provider-export, and the Open Brain until a human ratifies a curation proposal. This prevents unprocessed personal data from ever crossing the provider boundary.

See [cognitive quarantine and privacy](../systems/capture-ingestion/cognitive-quarantine-and-privacy.md) for the full quarantine lifecycle.

## Related pages

- [Open Brain](../systems/open-brain/index.md) — the MCP server and context pack that enforce provider-safety
- [Cognitive quarantine and privacy](../systems/capture-ingestion/cognitive-quarantine-and-privacy.md) — the capture-side quarantine
- [Knowledge governance](knowledge-governance.md) — provider projections are a low-authority layer
- [Security](../security.md) — trust boundaries, auth, and the full security model
- [Glossary](../overview/glossary.md) — provider-safe, provider-bound, AURG, write-back
