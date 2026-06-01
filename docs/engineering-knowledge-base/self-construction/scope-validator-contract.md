---
id: atlas-ai-self-construction-scope-validator-contract
type: engineering_knowledge
title: Atlas Self-Construction Scope Validator Contract
status: active
category: architecture
priority: 100
summary: Contract for validating that an AI implementation stayed inside its assigned packet scope.
tags:
  - atlas-ai
  - self-construction
  - scope-validator
  - quality-gates
capabilities:
  - self_construction_scope_validator_contract
  - scope_validator
  - scope_validator_governed_implementation
decisions:
  - Scope validation is blocking evidence for every packet.
  - Unknown writes are unsafe until classified.
  - Forbidden files always override allowed files.
maintenance:
  - Update before changing scope classification, packet evidence or blocking violation policies.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-scope-validator-contract

graph_title: Atlas Self-Construction Scope Validator Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Scope Validator Contract
canonical_name: Atlas Self-Construction Scope Validator Contract
technical_name: atlas-ai-self-construction-scope-validator-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/scope-validator-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
evidence_refs:
  - symbol: AtlasScopeValidatorContractService
  - command: atlas:aaeos:scope-validator-contract
  - test: AtlasScopeValidatorContractTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - contract
  - self-construction

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Self-Construction Scope Validator Contract

Scope Validator proves that an AI changed only what its packet allowed.

It must run after implementation and before any completion claim.

## Purpose

The validator classifies every changed path from:

```bash
git status --short
git diff --name-only
```

and compares it with the packet write contract.

For parallel AI work, including Codex, Claude, Gemini, local agents and future
providers, the validator must support packet-scoped validation:

```bash
php artisan atlas:ai:self-construction --scope-validator --packet=AIP-SPLIT-... --json
```

When `--packet` is present, the validator must use that Work Splitter packet's
allowed and forbidden files instead of the broad base implementation packet.

## Non Goals

- Do not decide whether the code is correct. Quality gates do that.
- Do not approve execution.
- Do not hide hot external changes.
- Do not ignore untracked files.
- Do not mutate files or repair scope by itself.

## Input Schema

```json
{
  "packet_id": "AIP-YYYYMMDD-0001",
  "requested_packet_id": "AIP-YYYYMMDD-0001",
  "packet_scope_source": "implementation_packet | work_splitter_packet",
  "allowed_files": [],
  "forbidden_files": [],
  "forbidden_actions": [],
  "hot_scopes": [],
  "changed_files": [],
  "untracked_files": [],
  "required_gates": []
}
```

## Output Schema

```json
{
  "status": "pass | fail | blocked",
  "packet_id": "AIP-YYYYMMDD-0001",
  "summary": {
    "allowed_count": 0,
    "forbidden_count": 0,
    "unknown_count": 0,
    "hot_external_count": 0
  },
  "files": [
    {
      "path": "string",
      "classification": "allowed | forbidden | unknown | hot_external | generated | evidence_only",
      "blocking": true,
      "reason": "string"
    }
  ],
  "blocking_violations": [],
  "required_next_action": "continue | stop | request_review | refresh_packet"
}
```

## Classification Rules

| Classification | Meaning | Blocking |
|---|---|---|
| `allowed` | Path is explicitly allowed by packet | no |
| `forbidden` | Path matches forbidden scope | yes |
| `unknown` | Path is neither allowed nor forbidden | yes |
| `hot_external` | Path belongs to another active front | yes for this packet |
| `generated` | Path is generated by required index/sync command | yes unless packet allows it |
| `evidence_only` | Path contains allowed logs/reports for packet evidence | no |

## Blocking Files And Actions

These block unless explicitly allowed by critical AP and signed receipt:

- migrations;
- route files;
- service providers;
- daemon entrypoints;
- provider/model policy;
- auth, payment, permission or tenant isolation code;
- Voice realtime runtime files;
- Kernel scanner files;
- memory write policy;
- external tool or MCP write policy.

## Validation Protocol

```text
1. Load packet.
2. Load hot scopes.
3. Read git status and diff names.
4. Classify modified and untracked files.
5. Fail on forbidden, unknown or hot_external writes.
6. Fail when generated files are outside packet scope.
7. Emit JSON evidence.
8. Completion may proceed only when status=pass.
```

## Safe Example

```json
{
  "status": "pass",
  "files": [
    {
      "path": "docs/engineering-knowledge-base/self-construction/scope-validator-contract.md",
      "classification": "allowed",
      "blocking": false
    }
  ]
}
```

## Blocked Example

```json
{
  "status": "blocked",
  "files": [
    {
      "path": "runtimes/python/voice_realtime/atlas_voice_agent/main.py",
      "classification": "hot_external",
      "blocking": true,
      "reason": "Voice runtime is outside Self-Construction packet ownership."
    }
  ],
  "required_next_action": "stop"
}
```

## Completion Criteria

Scope Validator is complete when it can classify modified and untracked files
against a packet, separate external hot work from packet-owned work, emit
blocking violations and produce machine-readable evidence for the handoff.

## Resumo

Contract for validating that an AI implementation stayed inside its assigned packet scope.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
