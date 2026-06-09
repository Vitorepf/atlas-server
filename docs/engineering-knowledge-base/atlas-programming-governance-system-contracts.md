---
id: atlas-programming-governance-system-contracts
type: engineering_knowledge
title: Atlas Programming Governance System Contracts
status: active
category: programming-governance
priority: 99
summary: Contratos canonicos de programacao governada por IA: placement, spec antes do codigo, task contracts, Code Intelligence, evidence, AHCL, learning e cartografia.
tags:
  - atlas
  - programming
  - governance
  - contracts
capabilities:
  - programming_governance_contracts
  - programming_contract_feature_placement_gate
  - programming_contract_spec_before_code
  - programming_contract_task_contracts
  - programming_contract_evidence_required
  - programming_contract_hierarchical_control
decisions:
  - Programacao estrutural exige placement, contexto, spec, contrato e evidence.
  - Spec retroativa nao fecha gate.
  - Task contract e a fronteira minima antes de execucao por agente.
  - Completion exige AHCL action=`submit`; qualquer outra action bloqueia fechamento.
  - AAHCP v2-v5 orienta sessao, Forge, replay, learning e optimization twin, mas nao substitui o contrato AHCL de completion.
  - Forge promotion deve consultar `atlas.forge_governed_promotion.aahcp_guard.v1` antes de mutar workspace.
  - Learning candidates de AAHCP entram em review queue governada e nunca autoaplicam.
maintenance:
  - Atualize quando contratos de programacao governada mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
  - docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-governance-system-contracts

graph_title: Atlas Programming Governance System Contracts

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-programming-governance-system

graph_status: active

graph_source: repo
human_name: Atlas Programming Governance System Contracts
canonical_name: Atlas Programming Governance System Contracts
technical_name: atlas-programming-governance-system-contracts
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md

allowed_changes:
  - Atualizar contratos quando gates ou fluxos de programacao mudarem.

forbidden_changes:
  - Reduzir exigencia de evidence para mudanca estrutural.
  - Declarar runtime pronto sem prova mecanica.

depends_on:
  - atlas-programming-governance-system
  - atlas-ai-spec-operating-system
  - code-intelligence

flows_to:
  - atlas-programming-governance-system-runbook
  - atlas-forge-operating-system

unlocks:
  - ai-safe-programming-contracts

governs:
  - programming.contracts

evidence:
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
evidence_refs:
  - symbol: AtlasProgrammingGovernanceSystemContractsService
  - command: atlas:aaeos:programming-governance-system-contracts
  - test: AtlasProgrammingGovernanceSystemContractsTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - contracts
  - governance

ai_entrypoints:
  - Leia este doc antes de desenhar ou alterar gates de programacao.

ai_usage_notes:
  - Este doc define contratos futuros/autorais; estado real ainda depende de codigo, testes e receipts.

quality_gates:
  - feature-placement
  - spec-before-code
  - task-contract
  - code-intelligence-context
  - evidence-required
  - hierarchical-control

failure_modes:
  - Codigo antes da spec.
  - Contract sem allowed files.
  - Evidence textual sem comando ou receipt.

observability_signals:
  - placement result
  - spec id
  - task contract id
  - evidence receipt

next_actions:
  - Reusar estes contratos em APs e services de programacao governada.
---
# Atlas Programming Governance System Contracts

## Resumo

Este documento contem os contratos do Programming Governance System. O indice
canonico fica em `atlas-programming-governance-system.md`; o fluxo operacional
fica em `atlas-programming-governance-system-runbook.md`.

## Papel no Atlas

Definir as fronteiras minimas para que uma IA programe no Atlas com contexto,
limite, prova e rastreabilidade.

## Onde Se Encaixa

Este doc e filho do Programming Governance System e antecede o Forge OS. Forge
consome estes contratos quando trabalho pesado ou multiagente exige fabrica.

## Contratos

### Contrato 1: Feature Placement

Antes de implementacao estrutural, a IA deve descobrir onde a feature pertence:

- dominio;
- modulo/servico;
- docs canonicos existentes;
- arquivos provaveis;
- arquivos proibidos ou perigosos;
- se a mudanca pertence a Programming, Kernel, Memory, Surface, Domain,
  Cartography, Obras, Self-Construction ou outro sistema.

Comando esperado quando aplicavel:

```bash
php artisan atlas:ai:place-feature "<feature>" --json
```

### Contrato 2: Spec Antes Do Codigo

Mudanca estrutural deve declarar:

- objetivo;
- contexto canonico;
- comportamento esperado;
- arquivos e modulos provaveis;
- entradas e saidas;
- riscos;
- testes;
- evidence exigido;
- rollback ou contencao;
- criterios de conclusao.

Spec retroativa e falha de processo.

### Contrato 3: Task Contracts

Cada pacote de trabalho precisa de:

- `allowed_files`;
- `forbidden_files`;
- `expected_files`;
- `owner`;
- `dependencies`;
- `risk_level`;
- `validation_commands`;
- `acceptance_criteria`;
- `rollback`;
- `evidence_required`;
- `docs_required`;
- `cartography_required`.

Diff fora do contrato e bloqueado, justificado ou escalado.

### Contrato 4: Code Intelligence Obrigatorio

Trabalho estrutural deve consultar contexto real:

- arquivos provaveis;
- simbolos relacionados;
- rotas/comandos/jobs/migrations quando aplicavel;
- testes existentes;
- docs canonicos;
- dependencias;
- historico/evidence se existir;
- riscos;
- forbidden zones.

### Contrato 5: Evidence Obrigatorio

Evidence minima:

- spec id;
- task id;
- agente/runner;
- arquivos alterados;
- comandos executados;
- resultado dos testes;
- docs atualizadas;
- cartografia atualizada;
- erros;
- risco residual;
- decisao de conclusao.

### Contrato 6: Learning Pos-Execucao

Falha, reparo, contexto faltante, gate fraco, teste ausente ou drift devem virar
learning proposal. Learning nao autoaplica governanca; ele passa por review.

### Contrato 7: Cartografia Da Programacao

Mudanca estrutural deve declarar impacto cartografico e relacionar feature,
spec, modulo, arquivo, simbolo, teste, evidence e decisao.

### Contrato 8: Hierarchical Control Antes Do Completion

Antes de fechar trabalho, a IA deve produzir ou consultar uma decisao AHCL:

- H-state com spec, plan, risk, scope, review e gates;
- L-state com evidence, task contracts e gate posture;
- `halt_decision.action`;
- `halt_decision.reason`;
- `next_step`;
- `next_command`;
- required repairs;
- flags `h_cycle_required` e `l_cycle_required`.

Completion so pode fechar quando `halt_decision.action = submit`. Se action for
`continue`, `repair`, `replan` ou `escalate`, a IA deve seguir o next_step e
registrar nova evidence antes de tentar fechar.

AAHCP estende este contrato para v2/v3/v4: a sessao viva recebe `next_tick`,
Forge recebe schedule/control decision e Learning recebe candidatos revisaveis,
sempre sem autoaplicar mutacoes criticas.

## Fluxo

1. Identificar se a mudanca e pequena ou estrutural.
2. Rodar placement quando aplicavel.
3. Consultar Code Intelligence.
4. Escrever spec/delta antes do codigo.
5. Criar task contract.
6. Executar dentro do contrato.
7. Registrar evidence, docs, index e cartografia quando afetados.
8. Rodar AHCL.
9. Fechar somente se AHCL retornar `submit`.

## Regras para IA

- Nao programar por memoria quando existe contexto indexado.
- Nao usar spec retroativa.
- Nao tratar ausencia de teste como sucesso; declarar gap ou criar teste.
- Nao reduzir evidence para resumo textual.
- Nao promover learning sem review.

## Escopo de Implementacao

Contratos se aplicam a code generation, code review, repair, refactor,
programming domain, Forge OS e Self-Construction quando produzem codigo ou docs
estruturais.

## Dependencias

- Programming Domain;
- Spec Operating System;
- Code Intelligence;
- Evidence Ledger;
- Cartographic Knowledge OS;
- Forge OS.

## Evidencias

Evidencias aceitas incluem placement JSON, context pack, spec, task contract,
diff, teste/comando, docs-health, index-code, Evidence Ledger, AHCL halt
decision e cartography artifact.

## Riscos

- Excesso de cerimonia em patch pequeno.
- Pouca cerimonia em mudanca estrutural.
- Contexto stale.
- Evidence incompleta.
- Cartografia fora de sincronia.

## Exemplos

Valido: refactor multiarquivo com placement, context pack, spec, task contract,
testes, docs impactadas e evidence.

Invalido: alterar service central porque "parece o lugar certo" sem placement,
sem buscar simbolos e sem teste proporcional.

## Proximas Acoes

1. Vincular estes contratos a comandos e services existentes.
2. Manter foco em evidence verificavel.
3. Atualizar runbook quando um contrato ganhar runtime real.
