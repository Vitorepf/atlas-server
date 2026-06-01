---
id: atlas-cartography-nomenclature-contract
type: engineering_knowledge
title: Atlas Cartography Nomenclature Contract
status: active
category: documentation
priority: 99
summary: Contrato de nomenclatura da Cartografia para separar patamar, versao, camada, fonte, risco, regra, teste e documento canonico. Impede que a IA transforme numero de versao, camada visual ou arquivo-fonte em evolucao de maturidade.
human_summary: Separa patamar, versao, camada, fonte, risco, regra e teste para a Cartografia nao misturar conceitos.
human_what: Contrato de nomes usados pela Cartografia e pelos modais humanos.
human_purpose: Impedir que IA ou interface chamem versao de patamar, fonte de verdade ou maturidade que nao existe.
human_input: Recebe frontmatter, nomes canonicos, status, patamar, versoes, camada visual, fonte e risco declarado.
human_output: Entrega leitura consistente para mapa, modal, legenda e deep links da Cartografia.
human_change_when: Mexa quando surgir novo tipo visual, novo patamar, nova versao, novo status ou nova regra de nomenclatura.
human_block_when: Bloqueie quando a Cartografia inventar patamar, esconder fonte, misturar status ou usar nome tecnico como verdade humana.
human_name: Contrato de Nomes da Cartografia
canonical_name: Atlas Cartography Nomenclature Contract
technical_name: CartographyNomenclatureContract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
tags:
  - atlas
  - cartography
  - nomenclature
  - documentation
  - patamar
capabilities:
  - cartography_nomenclature
  - patamar_version_disambiguation
  - ai_safe_modal_structure
decisions:
  - Patamar significa salto de capacidade/maturidade, nao versao, camada, arquivo ou fonte.
  - Proximo patamar significa evolucao de maturidade, nao proxima etapa visual, proxima camada, proximo arquivo ou proximo item do fluxo.
  - Versao significa revisao, schema, fase ou release de uma mesma superficie/contrato.
  - Camada significa localizacao visual/conceitual no grafo.
  - Fonte significa arquivo canonico onde a verdade vive.
  - Cartografia deve mostrar claramente quando uma peca nao declara patamar.
  - Atlas Vox V0/V3/V4/V6 sao versoes/degraus da Escada Vox, nao patamares canonicos do Atlas inteiro por padrao.
  - Voice Realtime Surface nao e Atlas Vox; e surface/runtime tecnico mobile-first + LiveKit.
maintenance:
  - Atualizar antes de mudar modais, visualizacao, frontmatter ou parser da Cartografia.
  - Rodar docs-health e testes de cartografia depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cartography-nomenclature-contract
graph_title: Atlas Cartography Nomenclature Contract
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-cartographic-knowledge-os
graph_status: active
graph_source: repo
owner: documentation-operating-system
repo_paths:
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
allowed_changes:
  - Adicionar novos exemplos canonicos de patamar, versao, camada ou fonte.
  - Refinar categorias do modal quando a documentacao ganhar campos melhores.
forbidden_changes:
  - Tratar versao, release, schema, camada ou arquivo-fonte como patamar canonico.
  - Tratar Atlas Vox V0/V3/V4/V6 como patamares canonicos fora da Escada Vox.
  - Misturar Patamares, Versoes, Camadas, Fontes, Riscos, Regras, Testes ou Documentacao canonica em uma categoria so.
depends_on:
  - atlas-canonical-glossary-and-naming
  - atlas-canonical-module-doc-v1
flows_to:
  - atlas-cartography
  - atlas-ai-documentation-operating-system
unlocks:
  - ai-safe-cartography-reading
governs:
  - cartography.modal.naming
  - cartography.semantic_graph.reading
evidence:
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
evidence_refs:
  - symbol: AtlasCartographyNomenclatureContractService
  - command: atlas:aaeos:atlas-cartography-nomenclature-contract
  - test: AtlasCartographyNomenclatureContractTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "npm run test:cartografia"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este contrato antes de preencher modal, patamar, versao, camada, fonte ou relacao visual da Cartografia.
ai_usage_notes:
  - Se a documentacao nao declara patamar, diga que nao declara. Nao invente.
quality_gates:
  - docs-health-passes
  - cartografia-contract-tests-pass
failure_modes:
  - IA confunde patamar com versao/camada/fonte e a Cartografia passa a mentir sobre maturidade real.
  - Novato da equipe entende versao Vox como capacidade pronta do Atlas inteiro.
observability_signals:
  - cartografia-modal-contract-tests
  - docs-health-status
next_actions:
  - Evoluir frontmatter para campos explicitos de patamar quando a documentacao estiver pronta.
  - Manter a categoria Patamares dos modais separada de Versoes, Camadas e Fontes.
  - Criar exemplos canonicos sempre que uma familia de produto tiver versoes internas, como Atlas Vox.
line_limit: 320
---
# Atlas Cartography Nomenclature Contract

## Resumo

Cartografia e a forma visual da verdade documental do Atlas. Ela nao pode
misturar conceitos. Se misturar, a IA ve um mapa falso e implementa errado.

## Papel no Atlas

Este contrato governa os nomes que aparecem na Cartografia e nos modais de
longa pressao. Ele existe para qualquer pessoa da equipe entender se uma peca
e maturidade, versao, camada, fonte, risco, regra ou teste.

## Onde Se Encaixa

Fica abaixo do Glossario Canonico e acima dos modais/visualizacoes. O glossario
define os nomes gerais; este contrato define como a Cartografia deve separar
esses nomes na leitura humana e no grafo semantico.

## Conceitos

| Conceito | Pergunta que responde | Regra canonica |
|---|---|---|
| Patamar | Qual novo nivel de capacidade, autonomia, verdade ou governanca foi desbloqueado? | E salto de maturidade/capacidade. Nao nasce por numero de arquivo, camada ou release. |
| Versao | Qual revisao, schema, fase ou release desta mesma superficie/contrato esta sendo descrita? | E geracao da mesma coisa. Pode existir sem mudar patamar. |
| Proximo patamar de | Esta peca e a evolucao de maturidade de qual peca anterior? | Aponta relacao de maturidade entre capacidades. Ex.: Self-Programming OS e proximo patamar de Self-Construction OS. |
| Outros patamares depois | Que saltos de capacidade podem vir depois deste? | E horizonte de maturidade, nao backlog, versao nem lista de arquivos. |
| Camada | Onde esta peca aparece no grafo/arquitetura? | E localizacao visual/conceitual. Nao prova maturidade. |
| Fonte | Onde a verdade vive no repositorio? | E arquivo canonico. Nao e patamar, risco nem regra. |
| Governanca | Quem cuida e qual contrato documental rege esta peca? | Usa owner, category, priority, doc_schema, maintenance e line_limit. Nao e patamar nem fonte. |

## Regra De Bolso

Antes de preencher modal, frontmatter ou grafo, aplique esta regra:

| Se voce quer dizer... | Use | Nunca use |
|---|---|---|
| Salto de maturidade/capacidade | `patamar_current`, `patamar_next_of`, `patamar_next`, `patamar_after` | `versions`, `schema_version`, `graph_layer`, `flows_to`, `unlocks`, `repo_paths` |
| Release, fase, schema ou degrau da mesma coisa | `version_family`, `versions`, `version_note`, `schema_version` | `patamar_current`, `patamar_next_of`, `patamar_next`, `patamar_after` |
| Arquivo onde a verdade vive | `source_path`, `repo_paths` | patamar, versao, risco ou regra |
| Leitura auxiliar antes de mexer | `related_paths` | fonte principal, prova, patamar ou versao |
| Caminho operacional no mapa | `input`, `output`, `depends_on`, `flows_to`, `unlocks`, `gear_flow` | proximo patamar |
| Alias visual de lane | `graph_id` da peca lateral + `frontmatter_id` resolvido | documento raso inventado quando existe fonte canonica real |

Exemplo de bolso: `Self-Construction OS -> Self-Programming OS` e patamar
porque a documentacao declara salto de maturidade. `Atlas Vox V0/V3/V4/V6`
e versao/degrau porque descreve geracoes da mesma familia Vox. Atlas Vox pode
ter muitas versoes e nenhum patamar declarado; nesse caso o modal deve mostrar
`Patamar ainda nao declarado` e listar `V0/V3/V4/V6` somente em `Versoes`.

## Contratos

O modal de longa pressao e o unico lugar textual da Cartografia. Ele deve ser
organizado para humano novato entender rapido.

O modal deve seguir o contrato de 7 camadas do
`atlas-documentation-creation-gate.md`: Essencial, Fluxo, Relacoes, Evolucao,
Patamares, Versoes, Prova e Seguranca.

- `Essencial`: deve mostrar nome humano, nome tecnico/canonico, tipo, status e
  fonte canonica antes de qualquer explicacao longa. Se a origem for
  `derived_fallback`, mostrar como legado/derivado, nao como declaracao plena.
- `Fluxo`: deve mostrar entrada -> peca -> saida sem converter seta visual em
  patamar.
- `Relacoes`: deve mostrar pai, filhos, dependencias e desbloqueios sem
  confundir relacao operacional com maturidade.
- `Evolucao`: deve separar o que existe, o que falta e a proxima acao segura.
- `Prova e Seguranca`: deve manter fonte, teste, evidence, risco, regra e owner
  em categorias distintas.
- `Patamares`: patamar atual, e proximo patamar de alguem, proximo
  patamar, outros patamares depois. Nao colocar versao aqui.
  - Campos canonicos: `patamar_current`, `patamar_next_of`,
    `patamar_next`, `patamar_after`.
  - No modal, a categoria deve responder quatro perguntas separadas:
    "qual patamar esta peca representa?", "ela e o proximo patamar de quem?",
    "qual e o proximo patamar dela?" e "que patamares podem vir depois?".
  - "Proximo" aqui sempre significa proximo salto de maturidade/capacidade,
    nao a proxima etapa desenhada no fluxo visual.
  - Se nenhum desses campos existir, escrever que o patamar ainda nao foi
    declarado. Nao usar `flows_to`, `unlocks`, `graph_layer`, nome da pasta,
    numero de versao ou source path como substituto.
- `Versoes`: V0/V1/V4/V6, schema_version, release, fase, geracao e nota
  explicita de que versao nao e patamar.
  - Campos canonicos: `version_family`, `versions`, `version_note`,
    `schema_version`.
  - No modal, a categoria deve deixar claro que versao e revisao/degrau da
    mesma familia, nao salto de maturidade. Atlas Vox pode ter varias versoes
    sem ter varios patamares.
- `Governanca`: owner, category, priority, doc_schema, maintenance e
  line_limit. Nao colocar patamar, versao, fonte ou risco aqui.
- `Camadas`: graph_layer, layer, mundo, pai visual, posicao no mapa.
- `Fontes`: repo_paths, source_path, evidence docs.
- `Riscos`: risk_level e failure_modes.
- `Regras`: allowed_changes e forbidden_changes.
- `Testes`: required_tests, quality_gates e observability_signals.
- `Documentacao relacionada`: `related_paths`; arquivos auxiliares que a IA
  deve ler antes de mexer. Nao e fonte principal, patamar, versao ou prova.
- `Alias visual`: lanes podem ter ids curtos de mapa (`dom-prog`,
  `cap-prog`) e, ao mesmo tempo, apontar para o documento real por
  `frontmatter_id` (`atlas-ai-programming-domain`,
  `atlas-ai-self-construction-os`). Tap e longa pressao devem preferir o
  documento real quando ele existir. O alias visual serve para desenhar o
  mapa; ele nao substitui a documentacao canonica.

## Fluxo

Quando uma IA ou humano abre a Cartografia:

1. Tap em uma peca abre o fluxo visual daquela peca.
2. Longa pressao abre o modal textual.
3. O modal separa patamar, versao, camada, fonte, regra, risco e teste.
4. Se a doc nao declara algo, o modal declara a ausencia em vez de inventar.
5. Alteracao so pode partir dos arquivos canonicos e testes declarados.
6. Se uma peca lateral tiver `frontmatter_id`, tap e longa pressao devem abrir
   o fluxo/modal desse documento real, nao uma ficha sintetica do alias.

## Regras para IA

- Nunca inferir patamar a partir de `graph_layer`, `layer`, `V4`, `V6` ou path.
- Nunca inferir patamar a partir de `flows_to`, `unlocks`, dependencias ou
  proxima etapa visual. Esses campos mostram relacao operacional, nao salto de
  maturidade.
- Se a tarefa fala de `Atlas Vox`, ler `atlas-vox-operational-thinking-interface.md`.
- Se a tarefa fala de `Voice Realtime`, ler `atlas-ai-voice-realtime-surface.md`.
- Se a tarefa mistura Vox e Voice Realtime, ler ADR 0003 antes de alterar.
- Quando a doc nao declara patamar, escrever "patamar ainda nao declarado".
- Quando a peca e o proximo patamar de outra, escrever isso em
  `E proximo patamar de`. Exemplo: `Self-Programming OS` e o proximo
  patamar de `Self-Construction OS`.
- Quando a peca tem um proximo patamar possivel, escrever isso em
  `Proximo patamar`. Exemplo: `Self-Construction OS` tem como proximo
  patamar `Self-Programming OS`.
- Quando a peca tem versoes, escrever isso em `Versoes`, nunca em
  `Patamares`. Exemplo: `Atlas Vox V0/V3/V4/V6` sao versoes/degraus da
  escada Vox; nao sao patamares canonicos por padrao.

## Escopo de Implementacao

Este contrato governa nomenclatura e apresentacao. Ele nao cria runtime, nao
promove status e nao substitui os docs donos de cada modulo.

## Dependencias

- `atlas-canonical-glossary-and-naming.md`
- `atlas-canonical-module-doc-v1.md`
- `GraphAssembler`
- Modais e cenas da Cartografia mobile/desktop.

## Evidencias

- `npm run test:cartografia`
- `php artisan atlas:engineering:knowledge docs-health --json`
- Presenca da categoria `Patamares` no modal.
- Presenca da categoria `Versoes` no modal, separada de `Patamares`.
- Separacao explicita entre versao Vox e patamar canonico.

## Riscos

- Patamar confundido com versao faz a IA implementar maturidade falsa.
- Camada confundida com patamar faz a Cartografia parecer ordem evolutiva falsa.
- Fonte confundida com regra esconde onde a verdade real vive.
- Vox confundido com Voice Realtime mistura programa produto com runtime tecnico.

## Exemplos

## Contrato De Frontmatter

Use estes campos para que a Cartografia consiga separar nomenclatura sem
adivinhar:

```yaml
patamar_current: Self-Construction OS
patamar_next_of: null
patamar_next: Self-Programming OS
patamar_after:
  - Self-Programming OS so avanca para autonomia maior com safety, receipts, gates e evidencia real.
version_family: Atlas Vox
versions:
  - V0 dictation pura com Kernel e receipt R0.
  - V3 gate de certificacao antes de promover V4+.
  - V4 contextual operator.
  - V6 ambient cognitive layer.
version_note: Atlas Vox tem versoes/degraus internos; isso nao declara patamar canonico do Atlas inteiro.
```

Regras:

- `patamar_current` descreve a maturidade/capacidade atual da peca.
- `patamar_next_of` so entra quando esta peca e explicitamente o proximo
  patamar de outra.
- `patamar_next` descreve o salto de maturidade que pode vir depois desta
  peca.
- `patamar_after` descreve horizonte de maturidade, nao backlog comum.
- `version_family` agrupa versoes de uma mesma superficie/produto.
- `versions` lista versoes, degraus, releases ou fases da familia.
- `version_note` explica a leitura correta quando ha risco de confundir
  versao com patamar.

### Patamar

- `Self-Construction OS` -> `Self-Programming OS`.
  - Significa: sair de construcao governada para auto-modificacao governada.
  - No modal, Self-Construction aparece como patamar atual; Self-Programming
    aparece como proximo patamar.
  - Se a peca for Self-Programming OS, ela pode aparecer como "e proximo
    patamar de Self-Construction OS".
- `Knowledge Governance System` -> `Epistemic OS`.
  - Significa: sair de governanca de conhecimento para verdade computavel,
    auditavel e acionavel por IA.
- `Hyperflow` -> `Compounding Engineering Intelligence`.
  - Significa: sair de executar um fluxo para aprender estruturalmente com
    cada execucao.

### Versao

- `Atlas Vox V0/V3/V4/V6` sao versoes/degraus da Escada Vox.
- Uma versao Vox pode ser importantissima, mas nao vira patamar canonico do
  Atlas inteiro por padrao.
- `schema_version: v1` e contrato de formato, nao maturidade do sistema.

## Glossario De Nomenclatura Para Modal

Use estes nomes no modal. Nao trocar uma palavra pela outra.

Regra de nomenclatura para modais: preencher patamar apenas com campos
`patamar_*` ou exemplos canonicos deste contrato; preencher versao apenas com
campos de versao/schema/release. Se a peca e o proximo patamar de outra,
declarar `patamar_next_of`. Atlas Vox pode ter varias versoes, mas essas
versoes nao sao patamares sem campos `patamar_*` explicitos.

| Categoria | O que pode entrar | O que nao pode entrar |
|---|---|---|
| Patamares | `patamar_current`, `patamar_next_of`, `patamar_next`, `patamar_after`; exemplos canonicos documentados. | Versoes V0/V3/V6, schema, release, camada, fonte, dependencia, `flows_to`, `unlocks`. |
| Versoes | `version_family`, `versions`, `version_note`, `schema_version`, V0/V1/V4/V6, release, fase. | Proximo patamar, maturidade, autonomia, governanca evolutiva. |
| Camadas | `graph_layer`, `layer`, mundo, pai visual, posicao no mapa. | Patamar ou status de prontidao. |
| Fontes | `source_path`, `repo_paths`, evidence docs, arquivo canonico. | Regra, risco, patamar ou versao. |
| Documentacao relacionada | `related_paths`; leitura auxiliar para contexto antes de mudar. | Fonte principal, prova, patamar ou versao. |
| Governanca | owner, category, priority, doc_schema, maintenance, line_limit. | Fonte, risco, patamar ou versao. |
| Fluxo | input, output, depends_on, flows_to, unlocks, governs, gear_flow, target_graph_id. | Prova de maturidade ou patamar. |
| Alias visual | `graph_id` lateral usado para desenhar e `frontmatter_id` usado para achar a doc real. | Fonte canonica falsa, patamar, versao ou resumo inventado. |
| Riscos | `risk_level`, `failure_modes`. | Regra, fonte canonica, versao ou patamar. |
| Regras | `allowed_changes`, `forbidden_changes`. | Risco, fonte canonica, versao ou patamar. |
| Testes e evidencias | required_tests, quality_gates, observability_signals, evidence. | Patamar, camada ou versao. |

## Regra De Patamar

Patamar e uma relacao de maturidade/capacidade entre sistemas ou engrenagens.
Ele precisa ser declarado pela documentacao. A Cartografia nao pode inventar
patamar olhando ordem visual, numero da etapa, pasta, `flows_to`, `unlocks`,
`depends_on`, `graph_layer`, versao ou fonte.

## Regra De Proximo Patamar

Proximo patamar nao significa "proximo bloco", "proxima camada", "proximo
arquivo", "proximo item da lista" ou "proxima seta do fluxo". Proximo patamar
significa que a documentacao declarou um salto de maturidade/capacidade.

Exemplo correto:

- `Self-Construction OS` declara `patamar_next: Self-Programming OS`.
- Portanto o proximo patamar de `Self-Construction OS` e
  `Self-Programming OS`.
- A etapa visual que vem depois de `Self-Construction OS` no mapa pode ser
  outra coisa. Isso nao muda o patamar.

Exemplo incorreto:

- `Atlas Vox` tem `V0`, `V3`, `V4` e `V6`.
- Essas sao versoes/degraus da familia Vox.
- Como a documentacao nao declara `patamar_current`, `patamar_next_of`,
  `patamar_next` ou `patamar_after` para essas versoes, elas nao devem
  aparecer como patamares no modal.

No modal, a categoria `Patamares` deve ter estas linhas separadas:

- `Patamar atual`: o nivel de capacidade que esta peca representa agora.
- `E proximo patamar de`: a peca anterior que esta peca supera em maturidade.
- `Proximo patamar`: o salto de maturidade esperado depois desta peca.
- `Outros patamares depois`: horizonte de maturidade, quando a documentacao
  declarar uma cadeia maior.

Essas quatro linhas sao independentes. Uma peca pode ser o proximo patamar de
outra e, ao mesmo tempo, ainda ter outros patamares depois dela. Uma peca
tambem pode ter versoes sem declarar nenhum patamar.

Se todos esses campos estiverem vazios, escrever "patamar ainda nao declarado".
Isso e diferente de dizer que a peca nao e importante. Significa apenas que a
documentacao ainda nao declarou uma relacao de maturidade.

## Regra De Versao

Versao e revisao, schema, release, fase ou degrau interno da mesma familia.
Versao pode existir em produto, API, prompt, documento, schema ou runtime sem
criar novo patamar.

Atlas Vox e o exemplo canonico: `V0`, `V3`, `V4` e `V6` sao versoes/degraus da
familia Vox. Eles nao viram patamares canonicos do Atlas inteiro por padrao.
Se um dia uma versao Vox tambem for declarada como patamar, a documentacao deve
trazer campo de patamar explicito alem do campo de versao.

Portanto, no modal de Atlas Vox, `V0/V3/V4/V6` deve aparecer em `Versoes`.
Em `Patamares`, so pode aparecer algo se houver `patamar_current`,
`patamar_next_of`, `patamar_next` ou `patamar_after` declarado pela doc.

### Exemplos De Leitura Correta

- `Self-Construction OS` tem `patamar_next: Self-Programming OS`; portanto o
  modal mostra Self-Programming OS em `Proximo patamar`.
- `Self-Programming OS` pode declarar `patamar_next_of: Self-Construction OS`;
  portanto o modal mostra que ele e proximo patamar de Self-Construction OS.
- `Self-Programming Safety Contract` nao e automaticamente o patamar
  `Self-Programming OS`. Ele e contrato de safety/ponte. Se o doc dono nao
  declarar campos `patamar_*`, o modal deve explicar a funcao do contrato em
  Versoes/Nomenclatura ou Governanca, mas nao deve inventar patamar.
- `Atlas Vox` pode declarar `versions: V0, V3, V4, V6`; portanto o modal mostra
  isso em `Versoes`, nao em `Patamares`.
- `Atlas Vox` pode ter varias versoes sem ter patamares declarados. Nesse caso
  o modal mostra `Patamar ainda nao declarado` e lista as versoes em categoria
  propria.
- Uma engrenagem que tem `flows_to: decision-receipt` apenas entrega para
  Decision Receipt. Isso nao quer dizer que Decision Receipt e o proximo
  patamar dela.
- Uma engrenagem visual com `target_graph_id: atlas-decide` deve abrir o fluxo
  canonico de `atlas-decide` quando tocada. Isso e navegacao visual, nao
  patamar e nao versao.

### Camada

- `graph_layer: flow`, `module`, `surface`, `documentation` dizem onde a peca
  aparece no mapa.
- Camada nao responde se a peca esta mais madura, mais autonoma ou mais capaz.

### Fonte

- `repo_paths`, `source_path` e `evidence` dizem onde verificar a verdade.
- Fonte nao e proximo patamar. Fonte e prova/localizacao.

## Vox vs Voice Realtime

| Nome | O que e | O que nao e |
|---|---|---|
| Atlas Vox | Programa produto/arquitetura de voz, intencao, acao governada e memoria. | Nao e apenas ditado, nem Voice Realtime Surface. |
| Voice Realtime Surface | Surface/runtime tecnico mobile-first + LiveKit/turnos de voz. | Nao e o programa Atlas Vox inteiro. |

Regra de IA:

- Se a tarefa fala de `Atlas Vox`, ler `atlas-vox-operational-thinking-interface.md`.
- Se a tarefa fala de `Voice Realtime`, ler `atlas-ai-voice-realtime-surface.md`.
- Se a tarefa mistura os dois, ler tambem ADR 0003 antes de qualquer alteracao.

## Quando A Documentacao Estiver Incompleta

A Cartografia deve dizer:

- "Patamar ainda nao declarado" quando nao houver patamar.
- "Versao declarada, nao patamar" quando houver V*, schema ou release.
- "Fonte ausente" quando o arquivo canonico estiver faltando.
- "Fluxo nao declarado" quando a doc nao tem filhos, gear_flow ou relacoes.

Nunca completar buraco documental com adivinhacao.

## Proximas Acoes

- Evoluir frontmatter para campos explicitos de patamar quando a documentacao
  declarar relacoes de maturidade de forma estruturada.
- Fazer a Cartografia listar versoes em categoria propria quando o grafo trouxer
  `schema_version`, `V*` ou release.
