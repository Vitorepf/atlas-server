---
id: atlas-desktop
type: engineering_knowledge
title: Atlas Desktop
status: active
category: surface
priority: 98
summary: Raiz canonica das superficies desktop do Atlas: Cartografia desktop, Atlas Code Operating Room, backend bridge e contratos anti-mock.
tags:
  - atlas
  - atlas-desktop
  - surface
  - cartography-root
capabilities:
  - atlas_desktop_root
  - desktop_cartography_parent
decisions:
  - Atlas Desktop e surface operacional do Atlas, nao Kernel e nao fonte primaria de verdade.
  - Documentos desktop ativos devem ter parent visual existente para a Cartografia.
  - Backend e code surface ficam abaixo deste node para nao aparecerem como orfaos.
  - macOS deve ter um unico app instalado: `/Applications/Atlas Code.app`.
maintenance:
  - Atualizar quando contratos desktop, bridge ou surface mudarem.
  - Rodar docs-health e testes de cartografia apos alterar hierarquia.
related_paths:
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-desktop
graph_title: Atlas Desktop
graph_world: atlas
graph_layer: system
graph_kind: surface
graph_parent: atlas
graph_status: active
graph_source: repo
human_name: Atlas Desktop
canonical_name: Atlas Desktop
technical_name: atlas-desktop
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-desktop.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-desktop.md
allowed_changes:
  - Adicionar contratos e surfaces desktop como filhos.
forbidden_changes:
  - Tratar Desktop como Kernel.
  - Inventar dados no Desktop fora do atlas-server.
  - Deixar doc ativo de desktop apontar para parent inexistente.
  - Criar, copiar ou deixar bundles/pastas timestampadas do app em `/Applications`.
  - Usar nomes como `Atlas Code.app.backup-*`, `Atlas Code.app-YYYY*` ou `Atlas Code.app...` em `/Applications`.
depends_on:
  - atlas-ai-documentation-operating-system
  - atlas-canonical-module-doc-v1
unlocks:
  - atlas-desktop-cartography-root
flows_to:
  - atlas-desktop-backend-contract
  - atlas-desktop-code-surface
governs:
  - atlas_desktop.semantic_root
evidence:
  - docs/engineering-knowledge-base/atlas-desktop.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - npm run test:cartografia
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Use este node como raiz visual quando uma peca declara graph_parent atlas-desktop.
quality_gates:
  - cartography-orphan-count-zero
failure_modes:
  - Backend desktop ou code surface ficam invisiveis/soltos e a IA entende fronteira errada.
observability_signals:
  - atlas-cartography audit orphan_count
next_actions:
  - Manter contratos desktop ativos apontando para este graph_id quando forem surfaces desktop.
line_limit: 220
---
# Atlas Desktop

## Resumo

Atlas Desktop e a raiz visual das superficies desktop do Atlas. Ele agrupa a
Cartografia desktop, Atlas Code Operating Room, backend bridge e contratos
anti-mock.

## Regra

Se um documento ativo declara `graph_parent: atlas-desktop`, a Cartografia deve
encaixa-lo abaixo deste node. Desktop nao decide como Kernel; ele apresenta e
opera contratos vindos do `atlas-server`.

## Papel no Atlas

Raiz canonica das superficies desktop do Atlas.

## Onde Se Encaixa

Fica abaixo da raiz `atlas` e agrupa backend bridge, Atlas Code Operating Room,
Cartografia desktop e surfaces nativas.

## Contratos

- Desktop apresenta e opera contratos do servidor; nao vira fonte primaria.
- Filhos desktop ativos precisam de `graph_parent: atlas-desktop`.
- Dados exibidos precisam vir de fontes canonicas ou receipts auditaveis.

## Fluxo

Contratos desktop apontam para este parent; Cartografia usa o parent para
montar a arvore visual e evitar docs soltos.

## Regras para IA

Nao criar mocks como verdade operacional e nao mover responsabilidades do Kernel
para o Desktop.

### macOS Build / Install Guardrail

`/Applications/Atlas Code.app` e o unico bundle instalado permitido para o
Atlas Desktop. Agentes nao devem criar backups timestampados, copiar bundles
com sufixo ou deixar pastas como `Atlas Code.app.backup-*`,
`Atlas Code.app-YYYY*` ou `Atlas Code.app...` em `/Applications`.

Builds locais devem ficar em
`atlas-desktop/target/release/bundle/macos/Atlas Code.app`. Para testar, abra
esse bundle diretamente ou substitua explicitamente o unico bundle canonico
`/Applications/Atlas Code.app`. Nunca polua o Launchpad/Finder com copias.

## Escopo de Implementacao

Documentacao e navegacao de surface; runtime continua nos modulos proprietarios.

## Dependencias

- `atlas-ai-documentation-operating-system`
- `atlas-canonical-module-doc-v1`
- Contratos desktop filhos.

## Evidencias

- `php artisan atlas:engineering:knowledge docs-health --json`
- Testes de Cartografia quando surfaces forem alteradas.

## Riscos

Sem este parent, backend desktop e Atlas Code podem aparecer como docs orfaos e
confundir ownership.

## Exemplos

`atlas-desktop-backend-contract` e `atlas-desktop-code-surface` devem apontar
para `graph_parent: atlas-desktop`.

## Proximas Acoes

Manter surfaces novas com parent, owner, evidencias e limites claros contra
autoridade indevida do Desktop.
