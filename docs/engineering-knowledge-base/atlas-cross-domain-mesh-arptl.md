---
id: atlas-cross-domain-mesh-arptl
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Cross-Domain Mesh and ARPTL Gates
status: active
implementation_state: runtime_scorecard_ready
category: cross_domain_governance
priority: 93
summary: Mesh governado entre dominios do Atlas em que ARPTL decide se memoria/contexto pode atravessar fronteiras de dominio sem vazar dado sensivel.
tags: [atlas-ai, acos, cross-domain, arptl, privacy, trust]
capabilities: [domain_bridge_request, arptl_veto, mesh_topology, append_only_bridge_audit]
decisions:
  - ARPTL veto e absoluto para bridges entre dominios.
  - Bridge carrega referencias e hashes, nao conteudo bruto.
  - Dominios sensiveis exigem regras deterministicas e auditaveis antes de compartilhar contexto.
maintenance:
  - Atualizar antes de mudar matriz de privacy, dominio canonico ou comandos de bridge.
  - Manter scorecard ACOS sincronizado com service, comandos e testes reais.
related_paths:
  - docs/engineering-knowledge-base/atlas-retrieval-privacy-trust-layer.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - app/Services/Ai/CrossDomain/AtlasCrossDomainMeshService.php
  - app/Console/Commands/AtlasCrossDomainBridgeCommand.php
  - app/Console/Commands/AtlasCrossDomainTopologyCommand.php
  - app/Console/Commands/AtlasCrossDomainListDecisionsCommand.php
  - tests/Unit/Ai/CrossDomain/AtlasCrossDomainMeshServiceTest.php
owner: atlas-ai
graph_id: atlas-cross-domain-mesh-arptl
graph_title: Atlas Cross-Domain Mesh and ARPTL Gates
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-retrieval-privacy-trust-layer
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-cross-domain-mesh-arptl.md
  - app/Services/Ai/CrossDomain/AtlasCrossDomainMeshService.php
depends_on: [atlas-retrieval-privacy-trust-layer, atlas-cognition-operating-system]
flows_to: [atlas-cognition-operating-system]
unlocks: [governed_cross_domain_context, arptl_domain_veto]
governs: [cross_domain_bridge_requests, arptl_veto_decisions, mesh_topology]
evidence:
  - docs/engineering-knowledge-base/atlas-cross-domain-mesh-arptl.md
  - app/Services/Ai/CrossDomain/AtlasCrossDomainMeshService.php
  - app/Console/Commands/AtlasCrossDomainBridgeCommand.php
  - app/Console/Commands/AtlasCrossDomainTopologyCommand.php
  - app/Console/Commands/AtlasCrossDomainListDecisionsCommand.php
  - tests/Unit/Ai/CrossDomain/AtlasCrossDomainMeshServiceTest.php
evidence_refs:
  - symbol: AtlasCrossDomainMeshService
  - command: atlas:cross-domain:topology
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:cognition:scorecard --strict --json"
  - "php artisan test tests/Unit/Ai/CrossDomain/AtlasCrossDomainMeshServiceTest.php"
next_actions:
  - Manter matriz de privacy sincronizada com ARPTL.
  - Adicionar novos dominios somente com policy, tests e owner decision.
allowed_changes:
  - Evoluir matriz cross-domain com testes e owner decision.
  - Adicionar dominios somente com politica ARPTL e coverage.
forbidden_changes:
  - Permitir override local do veto ARPTL nesta camada.
  - Transportar conteudo bruto sensivel no envelope de bridge.
requires_evidence: true
risk_level: critical
line_limit: 520
---

# Atlas Cross-Domain Mesh + ARPTL Gates

> **Status**: canonical
> **Authority**: ACOS · Patamar 2 (Cognitive Maturity)
> **Schema**: `atlas.cross_domain.bridge_request.v1` · `atlas.cross_domain.veto_decision.v1` · `atlas.cross_domain.mesh_topology.v1`
> **Service**: `App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService`
> **Owner**: this doc is the **source of truth**.

## 1. Why this exists

Atlas covers 15 professional domains. This runtime governs when one domain
may reference memory or context from another domain without turning private,
secret or cyber material into leakage.

The subsystem is implemented as a **mesh** between domains, with **ARPTL**
(Retrieval Privacy Trust Layer) as **border control**. Memory and context cross
domain boundaries **only when ARPTL approves the crossing for that privacy
class**. Sensitive, secret and cyber data keep deterministic veto paths.

## 2. The 15 canonical domains

| code | name |
|---|---|
| `engineering` | Software Engineering |
| `marketing` | Marketing |
| `finance` | Finance |
| `trading` | Trading |
| `cyber` | Cyber Security |
| `legal` | Legal |
| `ops` | Operations |
| `sales` | Sales |
| `design` | Design |
| `research` | Research |
| `health` | Health |
| `personal` | Personal |
| `learning` | Learning |
| `governance` | Governance |
| `infra` | Infrastructure |

## 3. Hard invariants

- **ARPTL veto is absolute** — if ARPTL says no, the bridge fails. No
  override path exists at this layer.
- **Cyber, secret, sensitive privacy classes** never cross to `marketing`,
  `sales`, `design`, `personal`, `learning` domains.
- **Same-domain bridge** is a no-op (returns `approved=true`, `bridged=false`).
- **Bridge requests** are append-only — every request (approved or vetoed)
  is recorded in JSONL for audit.
- **No content in bridge envelope** — bridges carry references (memory_id,
  snapshot_hash), not raw content. Receiver fetches per its own ARPTL gate.
- **Deterministic decision** — same `(from_domain, to_domain, privacy_class)`
  triple always returns the same veto/approve decision.

## 4. Bridge request schema

`atlas.cross_domain.bridge_request.v1`:

```json
{
  "schema_version": "atlas.cross_domain.bridge_request.v1",
  "request_id": "br_<sha8>",
  "at": "ISO-8601",
  "from_domain": "engineering",
  "to_domain": "finance",
  "privacy_class": "public | normal | sensitive | secret | cyber",
  "memory_refs": ["uuid", "..."],
  "snapshot_hash": "sha256:...",
  "rationale": "free-text",
  "actor": "operator | atlas | ..."
}
```

## 5. Veto decision schema

`atlas.cross_domain.veto_decision.v1`:

```json
{
  "schema_version": "atlas.cross_domain.veto_decision.v1",
  "request_id": "br_<sha8>",
  "decided_at": "ISO-8601",
  "approved": false,
  "reason": ["privacy_class_blocks_target", "..."],
  "bridge_applied": false,
  "decision_hash": "sha256:..."
}
```

## 6. Mesh topology schema

`atlas.cross_domain.mesh_topology.v1`:

```json
{
  "schema_version": "atlas.cross_domain.mesh_topology.v1",
  "generated_at": "ISO-8601",
  "domains": ["engineering", "..."],
  "edges_allowed": [
    {"from": "engineering", "to": "ops", "privacy_classes_allowed": ["public", "normal"]}
  ],
  "topology_hash": "sha256:..."
}
```

## 7. Privacy class × domain crossing matrix

```
                public  normal  sensitive  secret  cyber
engineering → ops  ✓      ✓        ✓        ✗      ✗
engineering → marketing ✓ ✓        ✗        ✗      ✗
finance → trading  ✓      ✓        ✓        ✓      ✗
cyber → anywhere   ✓      ✗        ✗        ✗      ✗   (cyber outbound stays in-domain except public)
sensitive_audience_domains (sales, marketing, design, personal, learning) — never receive sensitive/secret/cyber.
```

(Encoded as constant `CROSS_RULES` in the service.)

## 8. Public API

```php
$svc->bridge(array $request): array     // request a bridge; returns veto_decision
$svc->topology(): array                 // current mesh topology
$svc->listRequests(int $limit = 100): list
$svc->listVetoes(int $limit = 100): list
```

## 9. Persistence

- `storage/atlas/cross_domain/requests.jsonl`
- `storage/atlas/cross_domain/decisions.jsonl`

Append-only, locally-signed.

## 10. Operator workflow

```bash
# View mesh topology
php artisan atlas:cross-domain:topology --json

# Request a bridge (Doctor 3-Tier)
php artisan atlas:cross-domain:bridge \
  --from=engineering --to=finance --privacy-class=normal \
  --memory-ref=<uuid> --rationale="cost model needs eng cost data" \
  --mode=apply --check=cross-domain-bridge --confirm

# List recent decisions
php artisan atlas:cross-domain:list-decisions --json
```

## 11. Test coverage requirements

- Unit: same-domain no-op, allowed pair, vetoed pair per privacy class, deterministic decision hash.
- Feature: artisan topology, bridge plan/apply, list cycle.
- Real-fixture: tests use the real service; no mocks. The cross-rules table is treated as canonical fixture.

## 12. ACOS scorecard integration

```
['ACDM', 'Cross-Domain Mesh', 'cross_domain',
 AtlasCrossDomainMeshService::class, 'ready', 'ready']
```

## 13. Governed Backlog

These items are not active behavior until code, tests and owner decision land:

- **Per-edge cooldowns** — bridge same scope at most N times/day.
- **Capability negotiation** — receiver advertises what privacy classes it can
  hold; sender adapts.
- **Cross-domain delta propagation** — when memory M is bridged, downstream
  changes that depend on M get auto-propagated under ARPTL.

## Resumo

Cross-Domain Mesh governa bridges entre dominios do Atlas usando ARPTL como veto absoluto.

## Papel no Atlas

Ele permite reaproveitar contexto entre dominios sem transformar memoria privada, secreta ou cyber em vazamento operacional.

## Onde Se Encaixa

Fica sobre ARPTL e abaixo dos workflows que pedem contexto multi-dominio.

## Contratos

Schemas `atlas.cross_domain.bridge_request.v1`, `atlas.cross_domain.veto_decision.v1` e `atlas.cross_domain.mesh_topology.v1`.

## Fluxo

Caller pede bridge, ARPTL/matriz decide approve ou veto, o runtime persiste request e decision append-only.

## Regras para IA

Nao criar ponte cross-domain sem consultar a matriz, nao transportar conteudo bruto e nao bypassar veto ARPTL.

## Escopo de Implementacao

Runtime local com comandos de topology, bridge e list decisions, coberto por teste unitario real.

## Dependencias

- `AtlasCrossDomainMeshService`
- `AtlasRetrievalPrivacyTrustLayerService`
- `AtlasCognitionScoreCardService`

## Evidencias

- `php artisan test tests/Unit/Ai/CrossDomain/AtlasCrossDomainMeshServiceTest.php`
- `php artisan atlas:cognition:scorecard --strict --json`

## Riscos

Confundir bridge de referencias com permissao para copiar dados sensiveis entre dominios.

## Exemplos

`engineering -> ops` com privacy `normal` pode aprovar; `engineering -> marketing` com `sensitive` deve vetar.

## Proximas Acoes

Expandir a policy por dominio apenas com recebos, testes e decisao de owner.
