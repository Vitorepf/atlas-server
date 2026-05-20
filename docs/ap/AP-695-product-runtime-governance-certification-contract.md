---
title: AP-695 Product Runtime Governance Certification Contract
status: active
owner: atlas-kernel
line_limit: 220
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
  - docs/engineering-knowledge-base/domains/self_improvement.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - app/Services/Ai/Product/AtlasAiProductCertificationService.php
  - tests/Feature/Ai/Product/AtlasAiProductCertificationServiceTest.php
depends_on:
  - AP-204
  - AP-691
  - AP-692
  - AP-694
---

# AP-695 - Product Runtime Governance Certification Contract

## Proposito

AP-695 declara o contrato de certificacao da meta macro atual do Atlas AI:

- UX operacional limitada ao Atlas Desktop;
- subagentes/metagentes via Agent Control Plane como runtime padrao governado;
- execucao externa real bloqueada por policy, approval e receipts;
- empresa autonoma interna certificavel sem claim externo;
- capabilities usadas, medidas e melhoradas por outcome.

Este AP nao cria runtime novo. Ele amarra evidencias ja existentes em uma
certificacao de produto para evitar duplicacao entre Product Cert, Self
Improvement, Agent Control Plane, Engineering Company, AEMOR e Intelligence
Factory.

## Posicao

Pertence ao Kernel/Product Governance e ao dominio `self_improvement` como
contrato de auditoria. A implementacao operacional continua nos owners reais:

- Product Certification;
- Desktop Control Plane;
- Agent Control Plane;
- External Execution Governance;
- Engineering Company Runtime;
- AEMOR;
- Intelligence Factory OS.

## Decisao Anti-Duplicacao

AP-695 e um agregador de evidencia, nao um sexto runtime.

Proibido criar:

- novo Agent Control Plane paralelo;
- novo Self-Improvement domain;
- novo Company Runtime paralelo;
- nova tabela de capability usage se AEMOR/Intelligence Factory ja registram o
  evento;
- UX mobile obrigatoria nesta fase;
- benchmark/rivals run.

## Contrato de Entrada

Entrada canonica:

```json
{
  "schema_version": "atlas.ap695.product_runtime_governance_request.v1",
  "scope": "product_runtime_governance",
  "surfaces": ["desktop"],
  "forbidden_runs": ["benchmark", "rivals"],
  "required_evidence": [
    "product_certification",
    "desktop_control_plane",
    "agent_control_plane",
    "external_execution_governance",
    "engineering_company_runtime",
    "aemor",
    "intelligence_factory"
  ]
}
```

## Contrato de Saida

Saida canonica:

```json
{
  "schema_version": "atlas.ap695.product_runtime_governance_certification.v1",
  "status": "ready|partial|blocked",
  "checks": [],
  "blockers": [],
  "evidence_refs": [],
  "claims": {
    "runs_rivals": false,
    "declares_external_superiority": false,
    "covers_desktop_runtime_ux": true,
    "covers_agent_control_plane_runtime": true,
    "covers_external_execution_governance": true,
    "covers_internal_autonomous_company_runtime": true,
    "covers_capability_usage_evolution": true
  }
}
```

## Checks Obrigatorios

1. Product Certification retorna `status=ready`.
2. Desktop Control Plane renderiza governanca operacional.
3. Agent Control Plane possui task packets, leases, evidence e runtime safety.
4. External execution fica bloqueada por default e aparece como blocker quando
   unsafe.
5. Engineering Company Runtime nao declara claim externo.
6. AEMOR registra outcome e learning candidate.
7. Intelligence Factory registra usage/evolution de capability.
8. Mobile nao e requisito de UX desta fase, exceto manter contrato canonico de
   payload quando ja existir.

## Regras Para IA

- Use AP-695 para auditar a meta macro, nao para iniciar refactor amplo.
- Se um check falhar, corrija o owner real do check.
- Nao rode benchmark/rivals nesta fase.
- Nao transforme certificacao interna em claim publico.
- Nao duplique docs: `domains/self_improvement.md` e alias; o owner real e
  `domains/self-improvement.md`.

## Evidencia Minima

Comandos aceitos:

- `php artisan atlas:ai:product-certify --json`;
- `php artisan atlas:engineering:knowledge docs-health --json`;
- testes focados em Product, ControlPlane, Agent Control Plane, Engineering
  Company, AEMOR e Intelligence Factory;
- `git diff --check`.

## Criterios de Aceite

- O Product Certification cobre a meta macro em `product_runtime_governance`.
- O placement de `self_improvement` encontra owner doc com underscore.
- Nenhum runtime novo e criado para satisfazer AP-695.
- A documentacao deixa claro que benchmark/rivals seguem bloqueados.
- As IAs futuras sabem onde auditar a meta e onde implementar cada correcao.
