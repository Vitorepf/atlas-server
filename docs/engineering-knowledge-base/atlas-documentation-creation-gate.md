---
id: atlas-documentation-creation-gate
type: engineering_knowledge
title: Atlas Documentation Creation Gate
status: active
category: documentation-governance
priority: 100
summary: Gate obrigatorio para criar, migrar ou promover documentacao do Atlas sem quebrar Cartografia, contexto de IA, nomenclatura, fonte canonica ou prova operacional.
human_name: Gate de Criacao Documental
canonical_name: Atlas Documentation Creation Gate
technical_name: DocumentationCreationGate
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-documentation-creation-gate.md
tags:
  - atlas
  - documentation
  - cartography
  - governance
  - docs-health
capabilities:
  - documentation_creation_gate
  - cartography_readiness_gate
  - ai_context_safety
  - nomenclature_governance
decisions:
  - Nenhuma peca navegavel deve entrar na Cartografia sem fonte canonica, fluxo, modal humano, prova e nomenclatura separada.
  - Todo doc novo que governa sistema, fluxo, modulo, engrenagem ou subcomponente deve usar `atlas_canonical_module_doc.v1`.
  - Quando o fluxo real ainda nao existir, a documentacao deve declarar a lacuna explicitamente em vez de deixar a Cartografia inventar.
  - Patamar, versao, camada, fonte, regra, risco, teste e documentacao relacionada devem continuar em categorias separadas.
maintenance:
  - Atualizar antes de mudar template, docs-health, Cartografia ou processo de criacao documental.
  - Rodar docs-health, testes de cartografia e testes de KB depois de alterar este gate.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/templates/atlas-canonical-module-doc-v1-template.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-creation-gate
graph_title: Atlas Documentation Creation Gate
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-documentation-operating-system
graph_status: active
graph_source: repo
owner: documentation-operating-system
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-creation-gate.md
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
allowed_changes:
  - Refinar gates quando a Cartografia ou o schema canonical_module evoluir.
  - Adicionar exemplos de criacao documental que evitem ambiguidade para humanos e IAs.
forbidden_changes:
  - Remover obrigatoriedade de fonte canonica, fluxo, prova ou nomenclatura para pecas navegaveis.
  - Permitir docs ativos que alimentam IA sem testes ou evidencia declarada.
  - Permitir que fallback visual substitua documentacao canonica.
depends_on:
  - atlas-ai-documentation-operating-system
  - atlas-canonical-module-doc-v1
  - atlas-cartography-nomenclature-contract
flows_to:
  - atlas-cartography
  - atlas-code
unlocks:
  - cartography-readiness-review
  - ai-safe-documentation-creation
governs:
  - engineering-knowledge-base
  - cartography.semantic_graph
  - cartography.gear_modal
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-creation-gate.md
  - app/Services/Engineering/EngineeringDocumentationHealthService.php
evidence_refs:
  - symbol: EngineeringDocumentationHealthService
  - command: atlas:engineering:knowledge
  - test: EngineeringDocumentationHealthServiceTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - npm run test:cartografia
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este gate antes de criar, migrar, promover ou renomear qualquer doc que apareca na Cartografia.
ai_usage_notes:
  - Se uma informacao faltar, registre lacuna documental em vez de inventar fluxo, patamar, fonte ou prova.
quality_gates:
  - docs-health status ok
  - cartografia-contract-tests-pass
failure_modes:
  - Doc novo entra sem fluxo real e a Cartografia abre tela vazia ou enganosa.
  - IA usa fonte errada porque repo_paths, related_paths e evidence foram misturados.
  - Patamar e versao viram a mesma categoria e a equipe entende maturidade falsa.
observability_signals:
  - docs-health summary sem violacoes
  - cartografia coverage audit sem documentacao viva ausente
next_actions:
  - Manter este gate como leitura obrigatoria para novos docs e alteracoes estruturais.
---
# Atlas Documentation Creation Gate

## Resumo

Este gate define quando uma documentacao do Atlas esta pronta para existir como
fonte de verdade, aparecer na Cartografia e orientar uma IA implementadora. Ele
impede que o Atlas vire uma pasta bonita de arquivos que ninguem consegue usar.

## Papel no Atlas

Cartografia e documentacao sao o mesmo sistema visto por interfaces diferentes.
O arquivo `.md` e a verdade canonica. A Cartografia transforma essa verdade em
fluxo visual. Atlas Code e outras IAs usam a mesma verdade para decidir o que
podem alterar, como validar e onde parar.

Por isso, criar documentacao no Atlas nao e escrever texto solto. Criar
documentacao e registrar identidade, fluxo, fonte, prova, risco, regras e
nomenclatura para humanos e agentes.

## Onde Se Encaixa

Este gate fica entre o Documentation OS e qualquer doc novo. A ordem de
autoridade e:

1. `atlas-ai-documentation-operating-system.md`: politica geral.
2. `atlas-documentation-creation-gate.md`: condicao de entrada e promocao.
3. `atlas-canonical-module-doc-v1.md`: schema forte para docs navegaveis.
4. `atlas-cartography-nomenclature-contract.md`: separacao de nomes.
5. Doc especifico da peca.

## Contratos

Um doc novo so pode governar sistema, fluxo, modulo, engrenagem ou
subcomponente quando responder estas perguntas:

| Pergunta | Campo ou secao | Regra |
|---|---|---|
| O que e? | `summary`, Resumo | Deve caber em linguagem humana simples. |
| Onde aparece? | `graph_id`, `graph_parent`, `graph_layer`, `graph_kind` | Identidade visual estavel, sem id duplicado. |
| De onde vem e para onde vai? | `depends_on`, `flows_to`, `unlocks`, `governs`, `gear_flow` | Fluxo real ou lacuna explicita. |
| Onde a verdade vive? | `repo_paths`, `source_path` | Fonte canonica separada de prova e leitura auxiliar. |
| O que pode mudar? | `allowed_changes`, `forbidden_changes` | Limite operacional antes da IA mexer. |
| Como provar? | `evidence`, `required_tests`, `quality_gates`, `observability_signals` | Prova antes de promocao ou execucao. |
| Quem cuida? | `owner`, `category`, `maintenance`, `line_limit` | Governanca explicita. |
| Qual e o nome macro? | `macro_layer`, `product_name`, `runtime_acronym`, `internal_product_name`, `technical_runtime` | Obrigatorio para OS, Engine, Runtime, Factory, Layer ou camada estrutural nova. |
| Qual maturidade? | `patamar_*` | So quando houver salto de capacidade/maturidade declarado. |
| Qual versao? | `version_*`, `versions`, `schema_version` | Revisao/degrau separado de patamar. |

Se o doc nao tem fluxo interno, ele deve dizer isso claramente. Se tem fluxo,
o tap na Cartografia deve abrir fluxo visual. Se nao ha filhos, a Cartografia
deve explicar que a lacuna e documental ou que a peca e terminal.

## Contrato Do Modal Humano

Long press abre a explicacao humana da peca. Ele nao pode ser dump de
frontmatter, tabela por area, markdown completo ou texto promocional. O modal
deve responder rapido, em camadas, o que a peca e e como uma pessoa ou IA deve
agir com seguranca.

A ordem canonica obrigatoria tem 7 camadas:

| Camada | Perguntas obrigatorias | Regra |
|---|---|---|
| 1. Essencial | nome humano, nome tecnico/canonico, tipo, status, fonte canonica, o que e, para que serve, quando usar | Primeira dobra. Sem isso, a peca nao e legivel. |
| 2. Fluxo | entrada, peca atual, saida, quem consome e onde trava | Mostrar antes/depois sem texto longo. |
| 3. Relacoes | pai, filhos, dependencias, desbloqueios e fronteiras | Separar relacao operacional de patamar. |
| 4. Evolucao | o que existe, o que falta e proxima acao segura | Nao transformar backlog em maturidade. |
| 5. Patamares | patamar atual, proximo patamar de, proximo patamar e cadeia futura | Usar somente campos `patamar_*` declarados. |
| 6. Versoes | schema, release, familia e versoes internas | Nunca misturar com Patamares. |
| 7. Prova e Seguranca | testes, evidence, riscos, allowed/forbidden changes, owner e lacunas | Fechar com como validar e quando bloquear. |

Campos essenciais obrigatorios para a Camada 1:

```yaml
human_name:
canonical_name:
technical_name:
cartography_type: system|flow|contract|runtime|surface|policy|module|engine|os|layer|registry
status: active|building|deprecated|planned|scaffold|future|unknown
canonical_source:
```

Docs novos devem declarar esses campos. Docs legados podem receber fallback
derivado no grafo (`cartography_essential_source: derived_fallback`) a partir de
titulo, tipo e caminho, mas a Cartografia nao deve tratar isso como declaracao
canonica. Nunca mover fonte para patamar, patamar para versao, risco para regra,
teste para fonte ou fluxo operacional para proximo patamar.

## Fluxo

Fluxo obrigatorio para criar ou migrar documentacao:

1. Definir a peca real e o dono.
2. Confirmar se ela e sistema, fluxo, modulo, engrenagem, subcomponente,
   runbook, contrato ou ADR.
3. Criar o `.md` com frontmatter base.
4. Se for navegavel ou usado por IA, aplicar `doc_schema:
   atlas_canonical_module_doc.v1`.
5. Declarar grafo, fonte, escopo, limites, risco, prova e proximas acoes.
6. Se for camada macro estrutural, declarar `macro_layer: true` e os quatro
   nomes obrigatorios: produto, acronimo, superficie e runtime tecnico.
7. Declarar patamar somente com `patamar_*`; declarar versao somente com
   `version_*`, `versions` ou `schema_version`.
8. Se existir fluxo interno, declarar filhos ou relacoes que a Cartografia
   consiga renderizar.
9. Se faltar fluxo, prova ou fonte, registrar lacuna em `next_actions` e
   `failure_modes`.
10. Rodar docs-health e testes de cartografia.
11. So depois usar o doc como contexto de implementacao.

## Regras para IA

- Nunca criar doc navegavel sem `doc_schema: atlas_canonical_module_doc.v1`.
- Nunca criar OS, Runtime, Engine, Factory, Layer ou camada macro sem
  `macro_layer: true`, `product_name`, `runtime_acronym`,
  `internal_product_name` e `technical_runtime`.
- Nunca preencher Cartografia com fallback bonito quando existe fonte canonica
  ausente ou incompleta.
- Nunca tratar `flows_to`, `unlocks`, camada ou proximo bloco visual como
  proximo patamar.
- Nunca misturar `repo_paths`, `related_paths`, `evidence` e `required_tests`
  na mesma categoria.
- Quando a documentacao estiver incompleta, escrever a lacuna e propor o
  menor patch documental correto.
- Antes de implementar, ler fonte canonica, regras proibidas, provas e riscos.

## Escopo de Implementacao

Este gate governa documentacao e cartografia. Ele nao cria runtime por si so.
Ele garante que runtime, doc, visualizacao e IA tenham o mesmo mapa de verdade.

## Dependencias

- `atlas-ai-documentation-operating-system.md`
- `atlas-canonical-module-doc-v1.md`
- `atlas-cartography-nomenclature-contract.md`
- `EngineeringDocumentationHealthService`
- Testes de cartografia mobile

## Evidencias

- `php artisan atlas:engineering:knowledge docs-health --json`
- `npm run test:cartografia`
- `AtlasEngineeringKnowledgeBaseTest`
- `EngineeringDocumentationHealthServiceTest`

## Riscos

- Doc sem fluxo faz tap abrir tela vazia.
- Doc sem fonte faz IA alterar arquivo errado.
- Doc sem prova faz status parecer pronto sem ser.
- Doc sem nomenclatura mistura patamar, versao, fonte e camada.
- Doc sem dono apodrece sem manutencao.

## Exemplos

Exemplo correto: uma engrenagem `Atlas Decide` declara fonte canonica,
dependencias, saidas, provas, regras proibidas e fluxo interno. Tap abre fluxo
visual; longa pressao abre modal humano.

Exemplo incorreto: uma lane usa alias visual `cap-prog`, mas ignora
`frontmatter_id` real. Isso cria ficha rasa e esconde a documentacao canonica.

## Proximas Acoes

- Manter este gate como documento requerido no docs-health.
- Usar este gate em revisoes de docs novos, migracoes e promocoes para
  `active` ou `building`.
- Evoluir docs-health para transformar novas lacunas recorrentes em violacoes
  automatizadas quando o repositorio estiver pronto para bloquear.
