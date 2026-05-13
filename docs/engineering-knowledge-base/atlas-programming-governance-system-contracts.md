---
id: atlas-programming-governance-system-contracts
type: engineering_knowledge
title: Atlas Programming Governance System Contracts
status: future
category: programming-governance
priority: 99
summary: Contratos canonicos de programacao governada por IA: placement, spec antes do codigo, task contracts, Code Intelligence, evidence, learning e cartografia.
tags:
  - atlas
  - programming
  - governance
  - contracts
capabilities:
  - programming_governance_contracts
  - feature_placement_gate
  - spec_before_code
  - task_contracts
  - evidence_required
decisions:
  - Programacao estrutural exige placement, contexto, spec, contrato e evidence.
  - Spec retroativa nao fecha gate.
  - Task contract e a fronteira minima antes de execucao por agente.
maintenance:
  - Atualize quando contratos de programacao governada mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
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

graph_status: future

graph_source: repo

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

## Fluxo

1. Identificar se a mudanca e pequena ou estrutural.
2. Rodar placement quando aplicavel.
3. Consultar Code Intelligence.
4. Escrever spec/delta antes do codigo.
5. Criar task contract.
6. Executar dentro do contrato.
7. Registrar evidence, docs, index e cartografia quando afetados.

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
diff, teste/comando, docs-health, index-code, Evidence Ledger e cartography
artifact.

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
