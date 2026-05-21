---
id: atlas-cartographic-knowledge-os
type: engineering_knowledge
title: Atlas Cartographic Knowledge OS
status: future
category: cartography
priority: 100
implementation_state: future_target_not_current_runtime
summary: Especificacao canonica da Cartografia como sistema operacional visual da verdade do Atlas: navegacao por escala, zoom semantico, engrenagens, fluxos, fonte real, links e estados epistemicos para humanos e IAs.
human_name: Sistema Visual da Cartografia
canonical_name: Atlas Cartographic Knowledge OS
technical_name: AtlasCartographicKnowledgeOS
cartography_type: os
canonical_source: docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
tags:
  - atlas
  - cartography
  - visual-knowledge
  - semantic-zoom
  - human-interface
  - ai-navigation
capabilities:
  - cartographic_knowledge_os
  - semantic_zoom
  - visual_truth_navigation
  - graph_lod
  - gear_flow_visualization
  - human_readable_system_map
  - ai_navigable_context_graph
  - epistemic_visual_state
decisions:
  - Cartographic Knowledge OS e a interface visual primaria para humanos entenderem o Atlas.
  - Cartografia nao e desenho, wiki ou grafo decorativo; e sistema operacional visual da verdade canonica.
  - O humano deve conseguir entender Atlas por imagem, escala, fluxo, posicao e relacao, com o minimo de leitura.
  - A IA deve usar os mesmos links e nodes para navegar contexto com mais precisao e menor desperdicio.
  - Todo node visual precisa apontar para fonte real, owner, status, links, evidencia e estado epistemico/soberano quando existir.
  - Zoom semantico deve esconder o resto do mundo e revelar o funcionamento interno da engrenagem escolhida.
  - Tap em qualquer node/engrenagem deve trocar a cena para o fluxo visual daquela peca, sem manter o canvas anterior competindo por atencao.
  - Long press em qualquer node/engrenagem/lane deve abrir documentacao humana nas 7 camadas canonicas: Essencial, Fluxo, Relacoes, Evolucao, Patamares, Versoes, Prova e Seguranca.
maintenance:
  - Atualizar quando Cartografia, Atlas Semantic Graph, Knowledge Governance ou Desktop mudarem contrato visual.
  - Manter abaixo de 520 linhas; dividir detalhes de UI, API e layout em specs filhas quando iniciar implementacao.
  - Rodar docs-health depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - app/Services/Vault/GraphAssembler.php
  - app/Services/Vault/RepoVaultReader.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cartographic-knowledge-os
graph_title: Atlas Cartographic Knowledge OS
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-semantic-graph
graph_status: future
graph_source: repo
owner: atlas-cartography
repo_paths:
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
allowed_changes:
  - Evoluir contratos de zoom semantico, mapas por escala, visualizacao de engrenagens e navegacao por humanos/IAs.
  - Criar specs filhas para viewport, LOD, inspector, graph API, visual grammar e interaction model.
forbidden_changes:
  - Tratar Cartografia como mock, arte estatica ou diagrama manual sem fonte real.
  - Mostrar node sem source_path, owner, status ou estado de confianca quando a fonte exigir.
  - Permitir que visual sobrescreva docs canonicos.
  - Esconder ausencia de informacao com layout bonito.
depends_on:
  - atlas-semantic-graph
  - atlas-canonical-module-doc-v1
  - atlas-ai-knowledge-governance-system
  - atlas-epistemic-operating-system
  - atlas-sovereign-operating-system
flows_to:
  - atlas-desktop-code-surface
  - atlas-code
  - atlas-vault
unlocks:
  - human-readable-atlas
  - visual-ai-navigation
  - zoomable-operational-truth
governs:
  - atlas-cartography
  - semantic-graph
  - visual-knowledge-navigation
evidence:
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-semantic-graph.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - npm run test:cartografia
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - cartography
  - visual-os
  - semantic-zoom
ai_entrypoints:
  - Leia este doc antes de criar tela, grafo, visualizacao ou endpoint de Cartografia.
  - Use a metafora de cidade como regra de design: mundo, setor, bairro, predio, engrenagem.
ai_usage_notes:
  - Se um node nao tem fonte real, nao invente visual; marque ausencia ou crie proposta de doc.
  - Links sao infraestrutura de navegacao e execucao, nao detalhes esteticos.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
  - future: atlas cartography graph validate --json
  - future: atlas cartography visual-lod-audit --json
failure_modes:
  - Cartografia virar diagrama bonito mas incompleto.
  - Zoom mostrar detalhe sem preservar caminho de volta.
  - Links ruins fazerem IA navegar contexto errado.
  - Humano acreditar que algo nao existe porque nao aparece no mapa.
  - Nodes demais sem LOD virarem nuvem ilegivel.
observability_signals:
  - docs-health status ok
  - future: graph completeness percent
  - future: orphan nodes count
  - future: missing source paths count
  - future: stale visual nodes count
next_actions:
  - Definir schema de node visual por escala e endpoint de semantic zoom.
---
# Atlas Cartographic Knowledge OS

## Resumo

Atlas Cartographic Knowledge OS e a evolucao da Cartografia para sistema
operacional visual da verdade do Atlas. Ele deve permitir que humano e IA
naveguem o Atlas como uma cidade viva: de cima, por setores; com zoom, por
bairros; com mais zoom, por predios; e no nivel final, por engrenagens internas
em funcionamento.

O humano nao deve precisar ler centenas de docs para entender onde esta uma
peca, o que ela faz, quem a governa, de onde recebe entrada, para onde envia
saida, qual fluxo executa e o que falta. A imagem deve carregar essa compreensao.

## Papel no Atlas

Cartographic Knowledge OS define as regras visuais gerais da Cartografia.
`atlas-universal-reality-cartography.md` e a doc filha de produto/superficie
que aplica essas regras ao mapa universal de organizacoes, projetos, sistemas,
fluxos, componentes e provas.

Cartographic Knowledge OS e a interface humana principal para entender um Atlas
construido por IAs. Como o humano nao implementa tudo manualmente, a Cartografia
precisa mostrar a realidade de forma visual, navegavel e verificavel.

Para IAs, ela tambem funciona como mapa de contexto: reduz busca cega,
melhora retrieval, revela owner, links, dependencias, riscos e limites de
implementacao.

## Onde Se Encaixa

```text
Docs Canonicos / AtlasVault / Codigo / Evidence / Epistemic OS
        |
        v
Atlas Semantic Graph
        |
        v
Cartographic Knowledge OS
        |
        +--> World View
        +--> Sector View
        +--> System View
        +--> Flow View
        +--> Gear View
        +--> Evidence View
        +--> AI Navigation Context
```

Ele consome verdade; nao cria verdade. A fonte continua nos arquivos, codigo,
ledger e Epistemic OS. A Cartografia organiza visualmente.

## Contratos

1. Todo node visual precisa apontar para fonte real.
2. Todo link visual precisa ter tipo e significado.
3. Todo zoom muda o nivel semantico, nao apenas o tamanho da tela.
4. Ao focar uma engrenagem, o resto do mundo deve sair do caminho visual.
5. Ausencia de fonte deve aparecer como lacuna, nao como silencio.
6. O humano deve conseguir entender posicao e funcao por imagem.
7. A IA deve conseguir pedir contexto por node, link, fluxo, owner ou risco.
8. Visual nunca vence doc canonico, codigo, teste ou Evidence Ledger.
9. Estado epistemico deve aparecer como cor, opacidade, selo, alerta ou camada.
10. Cartografia precisa permitir ida e volta: mundo -> engrenagem -> mundo.
11. Tap navega visualmente: mundo -> sistema -> fluxo -> engrenagem -> subfluxo.
12. Long press explica textualmente: o que e, para que existe, como entra, como sai,
    patamares, versoes, regras, riscos, provas, testes e docs relacionados.
13. Fluxo operacional, dependencia, unlock, camada e versao nunca podem ser tratados
    como patamar. Patamar e salto de maturidade declarado pela documentacao.
14. Se a documentacao nao declara subfluxo interno, a cena deve mostrar lacuna
    documental de forma honesta em vez de inventar engrenagens.

## Metafora Canonica

Cartografia deve funcionar como conhecer Sao Paulo por escalas.

```text
Cidade inteira
  -> setores
  -> bairros
  -> quarteiroes
  -> predios
  -> supermercado
  -> caixa / estoque / pessoas / fluxo de mercadoria / dinheiro
```

No Atlas:

```text
Atlas inteiro
  -> sistemas
  -> surfaces/domains/runtimes
  -> fluxos
  -> modulos
  -> engrenagens
  -> codigo / teste / evidence / receipt / risco
```

## Interacao Canonica

O modelo de interacao da Cartografia e recursivo:

```text
tap
  -> substitui a cena atual pelo fluxo visual da peca tocada
  -> se a peca tiver gear_flow, mostra esse gear_flow
  -> se tiver filhos no semantic_graph, mostra os filhos como fluxo interno
  -> se nao tiver subfluxo, mostra fluxo terminal documentado
  -> se nem isso existir, mostra lacuna documental explicita

long press
  -> abre modal de documentacao humana
  -> nao navega, nao altera patamar e nao muda a fonte da verdade
```

Regra visual: o canvas anterior pode ficar desfocado como contexto somente se
nao competir com o fluxo atual. A experiencia primaria deve sempre deixar claro
"estou dentro desta peca agora".

Regra textual: o modal e o unico lugar de texto denso. Ele deve abrir pelas 7
camadas canonicas, com Essencial primeiro e Prova/Seguranca no fechamento.
Detalhe completo so entra depois da identidade, fluxo e acao segura.

## Nomenclatura Obrigatoria

Cartografia precisa seguir `atlas-cartography-nomenclature-contract.md`.

- `Patamar`: salto de capacidade/maturidade declarado por `patamar_*`.
- `Versao`: V0/V1/V4/V6, schema, release, fase ou degrau da mesma familia.
- `Camada`: posicao visual/conceitual no mapa.
- `Fonte`: arquivo canonico onde a verdade vive.
- `Documentacao relacionada`: leitura auxiliar antes de mexer.
- `Fluxo`: entrada, saida, dependencia, entrega, unlock e governanca operacional.
- `Risco`: o que quebra.
- `Regra`: o que pode ou nao pode mudar.
- `Teste/prova`: como validar.

Exemplo: `Self-Construction OS -> Self-Programming OS` e patamar porque a
documentacao declara salto de maturidade. `Atlas Vox V0/V3/V4/V6` sao versoes
ou degraus Vox; nao sao patamares canonicos por padrao. `Voice Realtime Surface`
e surface tecnica de audio; `Atlas Vox` e programa produto/arquitetura.

## Modulos

| Modulo | Funcao | Saida |
|---|---|---|
| Visual Knowledge Graph | Grafo visual da verdade canonica | nodes e edges renderizaveis |
| Semantic Zoom Engine | Troca nivel de realidade conforme zoom/foco | cenas por escala |
| Level of Detail Engine | Decide o que aparece/some em cada escala | visual LOD |
| Gear Flow Renderer | Mostra funcionamento interno de uma engrenagem | fluxo interno |
| Link Semantics Engine | Classifica relacoes visuais e operacionais | typed edges |
| Source Inspector | Mostra fonte real e evidencias de um node | painel verificavel |
| Epistemic/Sovereign Overlay | Mostra confianca, drift, risco, maturidade e bloqueios soberanos | estado visual |
| Human Navigation Shell | Permite navegar sem ler docs longos | interface humana |
| AI Navigation API | Permite IA navegar por graph_id e relacoes | context slices |
| Visual Completeness Auditor | Detecta lacunas, orfaos e links quebrados | audit report |
| Scenario Replay Layer | Mostra fluxo vivo como animacao/replay | replay operacional |
| Map Authoring Contract | Define como docs viram nodes bons | authoring rules |

## Submodulos

Os detalhes dos submodulos foram extraidos para manter esta doc como contrato visual principal e facilitar leitura humana:

- `atlas-cartographic-knowledge-os-submodules.md` — Visual Knowledge Graph, Semantic Zoom, Level of Detail, Gear Flow, Link Semantics, Source Inspector, overlays, shell humana, AI Navigation API, auditor e replay.

## Fluxo

```text
Doc ou codigo muda
  -> docs-health / index-code
  -> Semantic Graph atualiza nodes e edges
  -> Epistemic OS calcula confidence/drift/maturity
  -> Cartographic Knowledge OS atualiza cena
  -> Humano ve mapa
  -> IA usa graph_id para navegar contexto
```

## Regras para IA
1. Para fazer uma peca aparecer, atualize a fonte canonica, nao o desenho.
2. Para mudar posicao visual, preserve graph_id e source_path.
3. Para criar link, declare tipo e motivo.
4. Para zoom de engrenagem, mostre fluxo interno e esconda ruido externo.
5. Zoom em engrenagem abre visual proprio, com relacoes, estados e fluxo compreensiveis por imagem; ficha textual fica no long press.
6. Para area sem evidencia, marque scaffold/future/unknown.
7. Para visual de runtime, use evidence real ou replay.
8. Para nodes criticos, mostre estado epistemico.

## Escopo de Implementacao

Fase 0: especificacao canonica e alinhamento com Semantic Graph.  
Fase 1: schema de visual node e typed edge.  
Fase 2: endpoint de semantic zoom por `graph_id`.  
Fase 3: LOD e cenas world/sector/flow/module/gear/evidence.  
Fase 4: inspector com source real, evidence e confidence.  
Fase 5: AI Navigation API para context slices.  
Fase 6: Visual Completeness Auditor.  
Fase 7: Scenario Replay com Evidence Ledger.  
Fase 8: Authoring tools para sugerir links faltantes.

## Definition of Done

Considerar concluido somente quando:

1. Todo doc canonico relevante vira node visual com source_path.
2. Todo node tem parent, layer, kind, owner, status e graph_id.
3. Edges possuem tipo semantico e direcao.
4. Semantic zoom funciona em pelo menos 5 niveis: world, sector, flow, module, gear.
5. Gear view mostra fluxo interno completo de uma engrenagem real.
6. Inspector mostra markdown real, frontmatter, evidence, tests e related paths.
7. Epistemic overlay mostra confidence, drift, maturity e bloqueios.
8. Human shell permite navegar sem depender de leitura longa.
9. AI Navigation API retorna context slices por node e profundidade.
10. Auditor encontra orfaos, source ausente, links quebrados e nodes nao renderizados.
11. Cartografia diferencia active, implemented, scaffold, future e archived.
12. Replays basicos mostram fluxo do Kernel Pipeline com evidencia real ou fixture auditada.
13. Testes cobrem graph assembly, semantic zoom, LOD, inspector e edge semantics.
14. Performance suporta grafo grande sem travar a UI.
15. Nenhum dado visual importante e inventado quando fonte falta.

## Dependencias

- `atlas-semantic-graph`
- `atlas-canonical-module-doc-v1`
- `atlas-ai-knowledge-governance-system`
- `atlas-epistemic-operating-system`
- `atlas-desktop-backend-contract`
- `vault/atlas-vault-cartography-schema`

## Evidencias

Evidencia atual: este documento e o Semantic Graph existente.  
Evidencia futura exigida: endpoints, frontend, testes, screenshots, audits de
completude e consumo real por Atlas Desktop.

## Riscos

- Ficar visualmente lindo e epistemicamente fraco.
- Mostrar menos do que existe e fazer humano acreditar que falta.
- Mostrar demais e perder compreensao.
- Links fracos degradarem navegacao de IA.
- Zoom virar pan/scale comum sem troca semantica.
- Cartografia virar editor e disputar autoridade com docs.

## Exemplos

### Kernel Pipeline

Visao L0 mostra `Atlas AI Kernel System`.  
Zoom L1 mostra `Surface`, `Context`, `Decide`, `Runtime`, `Evidence`, `Render`.  
Zoom L2 mostra o pipeline inteiro.  
Zoom L3 em `Atlas Decide` mostra modulos internos.  
Zoom L4 mostra criteria, provider selection, budget, autonomy e receipt.  
Zoom L5 mostra codigo, testes, evidence e drift.

### Knowledge Governance

Visao L1 mostra fontes: repo docs, Postgres KB, Code Intelligence, Ledger,
Vault, projections e chat.  
Zoom em `repo docs` mostra docs canônicos e owners.  
Zoom em `projection` mostra freshness e drift.  
Zoom em `Evidence Ledger` mostra provas runtime.

## Proximas Acoes

1. Criar AP para Visual Node + Typed Edge schema.
2. Definir endpoint `GET /atlas-cartography/zoom/{graph_id}`.
3. Adicionar auditor de nodes orfaos e links sem tipo.
4. Fazer Cartografia consumir estado epistemico quando existir.
5. Criar primeira Gear View completa para `Atlas Decide`.
