---
id: atlas-temporary-domain-composition
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Temporary Domain Composition (Patamar 4 · 4.6)
slug: atlas-temporary-domain-composition
status: building
implementation_state: runtime_available_acdm_composer
category: cross_domain
priority: 90
summary: Composer de capsulas temporarias cross-domain que valida dominios via ACDM, respeita TTL e passa por Kernel/Admission sem criar permissao paralela.
tags: [atlas-ai, cross-domain, arptl, autonomy, patamar-4]
capabilities: [temporary_domain_capsule, acdm_bridge_composition, ttl_bound_domain_scope, capsule_revoke_ticket]
decisions:
  - Temporary Domain Composition nao cria permissao; cada bridge vem do ACDM.
  - TTL e obrigatorio e capsulas expiradas nao podem ser tratadas como permissao atual.
  - Sensitive, secret e cyber continuam sujeitos ao Constitutional Kernel e ARPTL.
maintenance:
  - Atualizar antes de mudar TTL bounds, capsule schema, bridge evaluation ou revoke behavior.
  - Manter testes cobrindo denial por ACDM, expiracao, revoke e append-only.
risk_level: high
owner: atlas-ai
graph_id: atlas-temporary-domain-composition
graph_title: Atlas Temporary Domain Composition
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cross-domain-mesh-arptl
graph_status: building
graph_source: repo
depends_on:
  - atlas-cross-domain-mesh-arptl
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-temporary-domain-composition.md
  - docs/engineering-knowledge-base/atlas-cross-domain-mesh-arptl.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/CrossDomain/AtlasTemporaryDomainCompositionService.php
  - app/Services/Ai/CrossDomain/AtlasCrossDomainMeshService.php
  - tests/Unit/Ai/CrossDomain/AtlasTemporaryDomainCompositionServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-temporary-domain-composition.md
  - app/Services/Ai/CrossDomain/AtlasTemporaryDomainCompositionService.php
flows_to: [atlas-cognition-operating-system]
unlocks: [ttl_bound_cross_domain_capsule, governed_domain_composition]
governs: [temporary_domain_capsules, capsule_evaluations]
evidence:
  - app/Services/Ai/CrossDomain/AtlasTemporaryDomainCompositionService.php
  - tests/Unit/Ai/CrossDomain/AtlasTemporaryDomainCompositionServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/CrossDomain/AtlasTemporaryDomainCompositionServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Provar consumer real usando evaluateCapsule antes de qualquer leitura cross-domain.
  - Integrar ARPTL evidence receipts sem mover ownership de ACDM.
allowed_changes:
  - Add consumers that evaluate capsule validity before reads.
forbidden_changes:
  - duplicate_arptl_decision_rules
  - allow_capsule_without_ttl
  - allow_silent_capsule_expiry
  - bypass_constitutional_kernel
requires_evidence: true
line_limit: 520
schema:
  - atlas.temporary_domain_composition.capsule.v1
  - atlas.temporary_domain_composition.evaluation.v1
---

# Atlas Temporary Domain Composition — Patamar 4 · 4.6

## Resumo

Composer de capsulas temporarias para compor domains por TTL, sempre validando bridges no ACDM.

## Papel no Atlas

Permitir escopos cross-domain temporarios auditaveis sem criar permissao nova ou permanente.

## Onde Se Encaixa

Fica sobre ACDM/ARPTL e abaixo dos consumidores que precisam ler em multiplos domains.

## Contratos

Schemas `atlas.temporary_domain_composition.capsule.v1` e `atlas.temporary_domain_composition.evaluation.v1`.

## Fluxo

Domains + TTL -> ACDM evaluate por par -> Kernel -> Admission -> capsule JSONL.

## Regras para IA

Nao redefinir DOMAINS, privacy classes ou ARPTL. Nao usar capsula expirada como permissao.

## Escopo de Implementacao

Service e teste unitario existem; consumers reais ainda precisam provar `evaluateCapsule()`.

## Dependencias

ACDM/ARPTL, Constitutional Kernel e Autonomy Admission.

## Evidencias

Service `AtlasTemporaryDomainCompositionService` e teste `AtlasTemporaryDomainCompositionServiceTest`.

## Riscos

Virar bypass cross-domain permanente ou ignorar TTL.

## Exemplos

Use `compose()` para criar capsula e `evaluateCapsule()` antes de qualquer leitura.

## Proximas Acoes

Adicionar receipts de uso por consumer e prova ARPTL antes de qualquer fluxo de dados real.

## Por que existe

ACDM (Cross-Domain Mesh) avalia bridges 1-para-1 entre domains. Em Patamar 4, há cenários onde Atlas precisa **compor temporariamente** K domains (K=2..5) numa cápsula com TTL — ex.: "engineering + marketing por 2 horas para diagnóstico de incidente". Após o TTL, a cápsula expira automaticamente. Toda leitura cross-domain dentro da cápsula continua sob ARPTL/ACDM.

A cápsula **não inventa permissões** — apenas agrega bridges válidos com escopo de tempo + rationale + auditoria.

## Princípio de não-duplicação (canon)

| Conceito                          | Fonte canon (NÃO duplicar)                                   |
|-----------------------------------|--------------------------------------------------------------|
| Domain list                       | `AtlasCrossDomainMeshService::DOMAINS`                       |
| Privacy classes                   | `AtlasCrossDomainMeshService::PRIVACY_CLASSES`               |
| Bridge decision rules             | `AtlasCrossDomainMeshService::evaluate()`                    |
| Pétreo gate                       | `AtlasConstitutionalKernelService::validateChange()`         |
| Autonomy admission                | `AtlasAutonomyAdmissionService::admit()`                     |

Regra: nunca redefinir DOMAINS/PRIVACY_CLASSES nem regras de cross-domain. Cada par (source, target) da cápsula é validado via `ACDM::evaluate()` ao criar e a cada `evaluateCapsule()`.

## API

```php
compose(array $input): array        // atlas.temporary_domain_composition.capsule.v1
evaluateCapsule(string $capsuleId): array  // atlas.temporary_domain_composition.evaluation.v1
expireCapsule(string $capsuleId, string $reason): array
listActiveCapsules(): array
listAllCapsules(): array
```

### `compose` input

```json
{
  "domains": ["engineering", "marketing"],
  "purpose": "incident_diagnostics",
  "privacy_class": "normal",
  "ttl_seconds": 7200,
  "actor": "operator|ASCB|ACDM|...",
  "requested_autonomy": "execute_with_approval|autonomous",
  "rationale": "free-form"
}
```

### Capsule envelope

```json
{
  "schema_version": "atlas.temporary_domain_composition.capsule.v1",
  "capsule_id": "tdc_...",
  "created_at": "ISO",
  "expires_at": "ISO",
  "domains": ["engineering","marketing"],
  "privacy_class": "normal",
  "purpose": "...",
  "bridges": [
    { "source":"engineering","target":"marketing","decision":"allow","reason":[...] }
  ],
  "kernel_decision": "allow|block|allow_with_human_approval",
  "admission_decision": "...",
  "status": "active|expired|revoked",
  "capsule_hash": "sha256:..."
}
```

### Regras de criação

1. `domains` ∈ [2, 5] valores distintos de `ACDM::DOMAINS`.
2. `ttl_seconds` ∈ [60, 86400] (1 min a 24h).
3. Para cada par (source, target) distinto, chama `ACDM::evaluate()` — se qualquer par retornar `decision=deny`, cápsula **inteira** é negada.
4. Constitutional Kernel gate sobre `change_kind=temporary_domain_composition`.
5. Autonomy Admission sobre o mesmo.
6. `privacy_class=sensitive|secret|cyber` exige aprovação humana (Kernel já enforce).

### `evaluateCapsule(capsuleId)`

- Retorna `valid=true` se: `status=active` AND `now < expires_at` AND todas as bridges ainda permitidas por ACDM (re-eval).
- Se TTL expirado e status ainda `active`, retorna `valid=false`, `expired=true` (mas não muta status — quem persiste status é o consumer; este serviço só projeta).

### `expireCapsule(capsuleId, reason)`

- Marca cápsula como `revoked` em append-only log (não muta o registro original; grava um ticket de revogação).

## Storage

- Append-only JSONL: `storage/atlas/cross_domain/capsules.jsonl`
- Não usa DB; consumer faz cache se quiser.

## Não-objetivos

- **NÃO** persiste leituras nem dados que fluem na cápsula — só a cápsula em si.
- **NÃO** substitui ACDM/ARPTL — usa.
- **NÃO** permite outbound de classes sensitive/secret/cyber (Constitutional Kernel já bloqueia).
- **NÃO** prorroga TTL automaticamente — nova cápsula = novo compose().

## Replay / audit

- `listAllCapsules()` + cross-reference com `ACDM::listDecisions()` reconstrói qualquer cápsula retroativamente.
- `capsule_hash` permite verificação determinística.
