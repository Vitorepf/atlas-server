---
id: atlas-ai-research-self-improvement-schemas-and-packets
type: engineering_knowledge
title: Atlas AI Research Self-Improvement Schemas And Packets
status: active
category: contracts
priority: 99
summary: Executable packet contracts for research, source judgment, documentation promotion, implementation plan and self-improvement proposal.
tags:
  - atlas-ai
  - research
  - schemas
  - packets
capabilities:
  - research_packet_schema
  - source_judgment_schema
  - improvement_proposal_schema
decisions:
  - Runtime implementation must use typed packets before background automation.
  - Packets are evidence carriers, not permission to mutate Atlas.
maintenance:
  - Update before adding migrations, DTOs, API resources, commands or queue jobs for this runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/ap/AP-689-research-self-improvement-runtime-contract.md
---

# Atlas AI Research Self-Improvement Schemas And Packets

## Research Packet

```json
{
  "schema_version": "atlas.research_packet.v1",
  "packet_id": "uuid",
  "objective": "string",
  "question": "string",
  "source_ids": [],
  "claim_ids": [],
  "conflict_ids": [],
  "uncertainty_ids": [],
  "atlas_impact": [],
  "recommended_action": "promote_to_doc|create_ap|benchmark|archive|research_more",
  "forbidden_actions": [],
  "created_at": "datetime"
}
```

## Source Judgment

```json
{
  "schema_version": "atlas.source_judgment.v1",
  "source_id": "uuid",
  "url_or_repo_path": "string",
  "title": "string",
  "publisher_or_owner": "string",
  "retrieved_at": "datetime",
  "tier": 0,
  "freshness": "fresh|aging|stale|unknown",
  "primary_source": true,
  "supports_claims": [],
  "known_limits": [],
  "trust_score": 0.0,
  "promotion_allowed": false
}
```

## Claim

```json
{
  "schema_version": "atlas.research_claim.v1",
  "claim_id": "uuid",
  "content": "string",
  "source_ids": [],
  "confidence": 0.0,
  "scope": "atlas_internal|external_provider|architecture|benchmark|security",
  "status": "supported|conflicted|uncertain|retracted",
  "must_preserve_exact_text": false
}
```

## Documentation Promotion Packet

```json
{
  "schema_version": "atlas.docs_promotion_packet.v1",
  "packet_id": "uuid",
  "research_packet_id": "uuid",
  "target_doc": "path",
  "change_type": "new_doc|update_doc|ap|archive",
  "authority_reason": "string",
  "source_trace": [],
  "validation_commands": [],
  "promotion_allowed": false
}
```

## Implementation Plan Packet

```json
{
  "schema_version": "atlas.implementation_plan_packet.v1",
  "packet_id": "uuid",
  "docs_promotion_packet_id": "uuid",
  "owner_files": [],
  "hot_files": [],
  "allowed_files": [],
  "forbidden_changes": [],
  "test_commands": [],
  "rollback_plan": [],
  "risk": "low|medium|high",
  "implementation_allowed": false
}
```

## Self-Improvement Proposal Packet

```json
{
  "schema_version": "atlas.self_improvement_proposal.v1",
  "proposal_id": "uuid",
  "evidence_refs": [],
  "affected_docs": [],
  "affected_code": [],
  "expected_gain": "string",
  "risk": "low|medium|high",
  "autonomy_level": "read_only|proposal_only|approved_apply",
  "review_required": true,
  "promotion_gate": [],
  "rollback_plan": []
}
```

## Fail-Closed Rules

- Missing source judgment blocks promotion.
- Tier 4 or Tier 5 blocks memory truth and policy.
- `promotion_allowed=false` blocks docs law.
- `implementation_allowed=false` blocks code.
- `review_required=true` blocks auto-apply.

