---
id: policy-profile
type: engineering_knowledge
title: Policy Profile
status: active
category: kernel
priority: 92
summary: Aplica permissao, privacidade, autonomia, custo, ferramentas e sandbox antes do Atlas Decide.
tags: [atlas, kernel, policy]
capabilities: [policy_profile, sandbox_policy]
decisions:
  - Policy Profile governa permissao antes de qualquer provider executar.
maintenance:
  - Atualizar quando sandbox, autonomia ou politica de custo mudarem.
related_paths:
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: policy-profile
graph_title: Policy Profile
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/policy-profile.md
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
allowed_changes:
  - Ajustar policy com evidencia e compatibilidade.
forbidden_changes:
  - Permitir ferramenta destrutiva sem policy e receipt.
depends_on:
  - context-builder
flows_to:
  - atlas-decide
unlocks:
  - atlas-decide
governs:
  - sandbox
  - autonomy
evidence:
  - docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar Policy Profile aos modos reais do Tool Runtime.
visual_tags:
  - module
  - policy
  - system-graph

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
---
# Policy Profile

## Resumo

Policy Profile define o que pode ser feito, com quais ferramentas, autonomia, custo, privacidade e sandbox.

## Papel no Atlas

Ele impede que uma decisao tecnicamente boa viole limites de seguranca, custo ou governanca.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe `context-builder` e alimenta `atlas-decide`.

## Contratos

Entrada: context pack, intent, risco e ambiente. Saida: perfil de permissao, sandbox, autonomia, custo e tools. Invariante: ferramenta perigosa exige policy explicita.

## Fluxo

Contexto chega, policy restringe ou autoriza. O Atlas Decide escolhe provider dentro desses limites.

## Regras para IA

IA nao deve interpretar permissao como implicita. Se policy nao autoriza, deve pedir assinatura ou reduzir escopo.

## Escopo de Implementacao

Permitido: regras de sandbox, autonomia e custo. Proibido: bypass por prompt ou preferencia do provider.

## Dependencias

- `context-builder`
- `resolver-corpus/policy-profile-model`

## Evidencias

- `docs/engineering-knowledge-base/resolver-corpus/policy-profile-model.md`
- `docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md`

## Riscos

- Policy frouxa demais gerar acao destrutiva.
- Policy rigida demais bloquear trabalho normal.

## Exemplos

Alteracao de codigo pode exigir workspace-write; apagar arquivos exige autorizacao maior.

## Proximas Acoes

Mapear Policy Profile para UI de assinatura no Atlas Code.
