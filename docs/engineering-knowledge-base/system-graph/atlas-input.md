---
id: atlas-input
type: engineering_knowledge
title: Atlas Input
status: active
category: kernel
priority: 90
summary: Entrada canonica do Kernel para texto, imagem, audio, arquivo, paste e contexto bruto.
tags: [atlas, kernel, input]
capabilities: [atlas_input, multimodal_input]
decisions:
  - Atlas Input preserva conteudo e metadados antes de formar envelope operacional.
maintenance:
  - Atualizar quando novos tipos multimodais forem aceitos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-input
graph_title: Atlas Input
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/atlas-input.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
allowed_changes:
  - Adicionar tipos aceitos e validacoes de input.
forbidden_changes:
  - Descartar metadados necessarios para auditoria.
depends_on:
  - surface-adapter
flows_to:
  - operation-envelope
unlocks:
  - operation-envelope
governs:
  - input-contract
evidence:
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Formalizar limites de tamanho e tipos aceitos por surface.
---

# Atlas Input

## Resumo

Atlas Input e a etapa que recebe conteudo normalizado e preserva sua forma canonica antes de criar um envelope operacional.

## Papel no Atlas

Ele garante que texto, arquivo, imagem, audio, paste e contexto bruto tenham rastreabilidade suficiente para decisao e evidencia.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `surface-adapter` e alimenta `operation-envelope`.

## Contratos

Entrada: payload normalizado. Saida: input canonico com conteudo, tipo, origem, anexos e limites. Invariante: nenhum input pode perder origem.

## Fluxo

Recebe o payload, valida estrutura, preserva conteudo bruto e prepara o pacote para o envelope.

## Regras para IA

IA deve consultar o input como fonte primaria do pedido, mas nao assumir objetivo final sem roteamento de intencao.

## Escopo de Implementacao

Permitido: validacao, limite, sanitizacao e preservacao. Proibido: transformar input em plano sem Intent Routing.

## Dependencias

- `surface-adapter`
- `atlas-ai-cli-multimodal`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`

## Riscos

- Entrada grande demais explodir contexto.
- Anexo perder relacao com a Obra ou thread.

## Exemplos

Um prompt com print, arquivo e texto entra como um input composto, mas ainda sem decisao de execucao.

## Proximas Acoes

Documentar schema de `input_ref` usado em receipts e evidencia.

