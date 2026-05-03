# MCP Tools Expansion Rollout Report

Date: 2026-05-03
Phase complete: 1 (Tier 1) + 2 (Tier 2) + 3 (Tier 3) + 4 (Memory Curation) + 5 (Validation)
Server version: 1.1.0 (was 1.0.0)
Plan: docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md

## Inventory delivered

10 tools total (3 existing + 7 new):

### Existing
- atlas_memory_recall
- atlas_open_brain_context_pack
- atlas_memory_maintenance_status

### New (this rollout)
- atlas_memory_record (write — closes inbound→outbound loop)
- atlas_code_find_relevant (8.7k symbols exposed)
- atlas_docs_lookup (KB queryable)
- atlas_capabilities (capability negotiation)
- atlas_workspace_info (fast workspace classification)
- atlas_recent_changes (git log + index drift detection)
- atlas_decision_query (memory_type=decision filtered recall)

## Tests

- Feature tests in `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php`: 12 tests passing (38 assertions)
- Benchmark tests in `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php`: 2 tests passing
- Test concerns added: `CreatesAtlasMemoryEntryTable`, `CreatesAtlasEngineeringCodeTables`, `CreatesAtlasEngineeringKnowledgeTables`

## Performance

- Cold start (raw PHP boot + handshake + tools/list): 0.376s real
- Warm recall: 0.12s (budget 0.5s) — asserted in benchmark test
- Warm capabilities: 0.01s (budget 0.1s) — asserted in benchmark test

## Integration

- Claude Code (CLI): MCP loads from arbitrary cwd — verified Step 5.1.1
  `atlas-open-brain: /Users/vitorepf/Develop/atlas/atlas-server/bin/atlas open-brain mcp - ✓ Connected`
- Tools/list: 10 tools enumerated — verified Step 5.1.2
- Smoke tests: 7 new tools all returned `isError: false` — verified Step 5.1.3

## Memory curation

Atlas memory was previously empty of project-specific rules (5 generic meta-entries about Atlas itself). Phase 4 added 3 canonical decisions from `/Users/vitorepf/Develop/atlas/CLAUDE.md`:

- `decision[global]` Migrations: nunca INSERT INTO migrations manualmente (priority 90)
- `decision[global]` Database em dev: fresh > reparo (priority 80)
- `technical_context[global]` Invariantes de schema vivem no DB, não no ORM (priority 80)

Provider-safe memory count: 8 (was 5).
Both `atlas-server/CLAUDE.md` and `atlas-server/AGENTS.md` reprojected via `atlas:memory:projection apply --target=all`.

## Manual follow-ups (require human in loop)

- [ ] **Step 5.2:** Open Claude Code in `/tmp/atlas-mcp-test`, ask "como o projeto Atlas lida com migrations que conflitam com schema vivo?". Verify response cites canonical Atlas memory ("nunca INSERT INTO migrations") and not generic best practices. If model doesn't call atlas_decision_query or atlas_memory_recall, the imperative rule needs to be moved higher in `~/.claude/CLAUDE.md`.
- [ ] **Step 5.3:** Same prompt with Codex CLI. Same verification.

## Known limitations / out-of-scope follow-ups

- Cold start of 0.376s is acceptable for on-demand use. If high-frequency orchestration is needed, daemon mode would eliminate this overhead.
- Atlas does not auto-inject the imperative consultation rule when spawning engines into new workspaces. Workaround: global `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` carry the rule. Long-term: orchestrator should inject directly into system prompt.
- Tools `atlas_run_report` and `atlas_audit_event` not implemented. Will be added when harness orchestrator phase begins.
- Policy layer is still binary (`external_ai_allowed`). No contextual filter.
- `atlas_workspace_info` derives Atlas-tracked status by counting memory entries — does not check for explicit project registration. Could improve with a dedicated `AtlasProject` lookup.
- `atlas_code_find_relevant` is lexical (substring match on name/path/signature). Semantic vector search not exposed.

## Commits

```
d25f3a8 feat(memory): seed 3 canonical decisions from master CLAUDE.md, reproject
c65677b feat(mcp): add atlas_decision_query for type=decision filtered recall
d2b200e feat(mcp): add atlas_recent_changes for git+index drift awareness
4c2040b feat(mcp): add atlas_workspace_info for fast workspace discovery
3b848a5 feat(mcp): add atlas_capabilities for capability negotiation, bump to 1.1.0
73979c6 feat(mcp): add atlas_docs_lookup tool exposing knowledge base
1217329 fix(mcp): remove layer param from atlas_code_find_relevant — backend has no layer filter on symbols
5e529e7 feat(mcp): add atlas_code_find_relevant tool exposing 8.7k symbol index
3c8e49a fix(mcp): update instructions for write tool; align test trait softDeletesTz with prod schema
ec5c1b4 feat(mcp): add atlas_memory_record tool to close inbound→outbound loop
2a0d11c docs(mcp): add tools contract v1.1 with 10-tool inventory and error taxonomy
```
