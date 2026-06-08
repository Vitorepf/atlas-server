---
id: atlas-documentation-reality-system
type: engineering_knowledge
title: Atlas Documentation Reality System
status: active
category: documentation-governance
priority: 100
summary: Documento mae da area que organiza a dependencia entre documentacao canonica, ACRUI e Cartografia/AURC para que IAs tenham verdade operacional e humanos tenham acesso visual compreensivel.
human_summary: Garante que existe uma verdade canonica unica: a IA usa documentos confiaveis e o humano enxerga essa verdade pela Cartografia.
human_what: Area-mae que une governanca documental, realidade de codigo e acesso visual humano.
human_purpose: Fazer o Atlas sobreviver a muitas IAs trabalhando sem bagunca, duplicacao ou perda de verdade.
human_input: Recebe docs canonicos, ACRUI, AURC, Code Intelligence, evidence, testes, fontes e regras de projeto.
human_output: Entrega mapa de blocos, ordem de implementacao, regras de verdade e ponte entre IA e humano.
human_change_when: Mexa quando mudar a organizacao da documentacao, os blocos ACRUI/AURC ou o modo como humanos acessam a verdade.
human_block_when: Bloqueie quando existir segunda fonte canonica, cartografia falsa, doc sem prova ou IA tentando implementar a partir de material solto.
human_name: Sistema de Realidade da Documentacao
canonical_name: Atlas Documentation Reality System
technical_name: AtlasDocumentationRealitySystemService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-documentation-reality-system.md
tags:
  - atlas-ai
  - documentation
  - knowledge-governance
  - cartography
  - code-reality
  - human-access
capabilities:
  - documentation_reality_system
  - documentation_human_dependency
  - documentation_code_reality_dependency
  - documentation_cartography_dependency
  - documentation_human_visual_access_dependency
  - ai_safe_documentation_navigation
  - documentation_context_efficiency
  - documentation_reality_scoring
  - cross_project_truth_boundary
  - cross_modal_consistency
  - documentation_working_set
  - obsolete_knowledge_simulation
decisions:
  - Nome canonico/produto obrigatorio: Atlas Documentation Reality System.
  - Acronimo tecnico obrigatorio: ADRS.
  - Nome interno de experiencia/superficie: Atlas Living Documentation Universe.
  - Runtime tecnico atual: AtlasDocumentationRealitySystemService.
  - Existe uma unica documentacao canonica por organizacao/repositorio; ACRUI e AURC nao criam segunda fonte de verdade.
  - ACRUI governa a realidade operacional da documentacao, codigo, testes, rotas, comandos, scaffolds, legados e duplicacoes.
  - AURC governa o acesso humano visual a essa realidade pela Cartografia, do universo ate o componente especifico.
  - Se a documentacao for ruim, a IA implementa errado; se a Cartografia for ruim, o humano direciona errado.
  - Atlas pode consumir documentacao canonica de outras empresas/projetos, mas nao deve copiar essa verdade para dentro dos docs canonicos do Atlas.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando mudarem Documentation OS, Knowledge Governance, ACRUI, Cartografia, System Graph ou regras de projeto/workspace.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - docs/engineering-knowledge-base/domains/programming.md
  - app/Services/Engineering/AtlasDocumentationRealitySystemService.php
  - app/Console/Commands/AtlasDocumentationRealityCommand.php
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-documentation-reality-system
graph_title: Atlas Documentation Reality System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai-knowledge-governance-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
allowed_changes:
  - Organizar blocos da area de documentacao real, ACRUI e Cartografia/AURC.
  - Adicionar novos blocos quando houver necessidade de governar verdade documental ou acesso humano visual.
  - Refinar relacoes entre documentacao canonica, read models, cartografia, codigo, testes e evidence.
forbidden_changes:
  - Criar segunda fonte canonica de documentacao.
  - Permitir que Cartografia, Postgres KB, Obsidian, chat ou provider projection sobrescrevam docs canonicos.
  - Tratar ACRUI como runtime de delecao automatica.
  - Tratar AURC como diagrama bonito sem fonte real.
  - Copiar documentacao canonica de outra empresa para dentro do Atlas como se fosse doc Atlas.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-documentation-operating-system
  - atlas-code-reality-usage-intelligence
  - atlas-cartographic-knowledge-os
flows_to:
  - atlas-cartography
  - atlas-code
  - programming-dev
  - programming-forge
  - ai-safe-implementation-context
unlocks:
  - human-readable-operational-truth
  - ai-safe-doc-navigation
  - anti-duplicate-documentation
  - cross-organization-documentation-consumption
governs:
  - documentation-governance
  - architecture-audit
  - atlas-cartography
  - human-documentation-access
evidence:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
evidence_refs:
  - symbol: AtlasDocumentationRealitySystemService
  - command: atlas:documentation-reality
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - documentation-reality
  - cartography
  - code-reality
ai_entrypoints:
  - Leia este doc antes de criar, mover, limpar, visualizar ou classificar documentacao macro do Atlas.
  - Use este doc para decidir se a tarefa pertence a Documentation OS, ACRUI ou AURC/Cartografia.
ai_usage_notes:
  - Esta doc e indice/governanca da area; o runtime integrado read-only materializa score, fontes, blocos, avaliacoes e readiness sem substituir os docs filhos.
  - Se houver conflito entre mapa visual e doc canonico, o doc canonico vence e a Cartografia deve mostrar drift.
  - Termos active, scaffold, future, headless, legacy, archived, quarantine, deleted e dead candidate sao vocabulario de ciclo de vida governado pelo ADRS; nao declaram drift deste documento.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - IA duplica runtime porque leu Cartografia ou chat como fonte primaria.
  - Humano nao entende o mapa e direciona a IA para a camada errada.
  - ACRUI chama codigo ativo de morto por falta de evidencia de reachability.
  - AURC mostra mapa bonito, mas sem source_path, owner, status ou prova.
  - Docs canonicos de empresa externa sao copiados para o Atlas e viram verdade concorrente.
observability_signals:
  - docs-health status ok
  - docs-authority-audit blockers zero
  - architecture-validate status ok
  - cartography orphan nodes count
  - code-reality duplicate candidates count
next_actions:
  - Manter filhos ACRUI/AURC e Context Pack sincronizados com o runtime ADRS L4 existente.
---
# Atlas Documentation Reality System

## Resumo

Atlas Documentation Reality System, ou ADRS, e a documentacao mae da area que
mantem a documentacao real, limpa, acessivel e verificavel para humanos e IAs.

O problema central e circular:

```text
documentacao ruim -> IA entende errado -> codigo e arquitetura pioram
cartografia ruim -> humano entende errado -> humano direciona IA errado
humano sem acesso visual -> documentacao nao recebe correcao boa
IA sem fonte canonica -> implementacao vira chute, duplicacao ou drift
```

ADRS organiza essa area em dois grandes filhos:

| Filho | Papel | Resultado |
|---|---|---|
| ACRUI | prova a realidade operacional | IA sabe o que esta vivo, scaffold, duplicado, legado ou morto |
| AURC | mostra a realidade visualmente | humano entende o universo, empresas, sistemas, fluxos e componentes |

So existe uma documentacao canonica. ACRUI e AURC sao mecanismos de leitura,
validacao, organizacao e acesso; nao sao fontes paralelas.

## Papel no Atlas

ADRS existe porque Atlas e grande demais para depender de memoria de chat, print,
resumo manual ou leitura solta de arquivo. O Atlas e construido por multiplas
IAs e usado para operar multiplos projetos. Sem uma camada mae de documentacao
real, cada IA cria o seu proprio mapa mental e o sistema degrada.

ADRS responde:

```text
Qual e a fonte canonica?
O que esta implementado de verdade?
O que esta estacionado, legado, scaffold ou duplicado?
Como o humano entende isso visualmente sem ler 200.000 linhas?
Como a IA recebe so o contexto certo sem inventar?
```

## Onde Se Encaixa

```text
Repositorio canonico da organizacao/projeto
  -> Documentation OS
  -> Knowledge Governance
  -> ACRUI: realidade operacional
  -> AURC: cartografia humana/IA
  -> Atlas Dev / Forge / Research / Finance / outros flows
```

No Atlas:

```text
docs/engineering-knowledge-base
  -> docs-health / docs-authority / architecture-validate
  -> Postgres KB / Code Intelligence / Context Packs
  -> Cartografia / Atlas Code / Forge
```

Em outra empresa:

```text
repo canonico da empresa
  -> docs canonicos locais da empresa
  -> Atlas consome, indexa e renderiza
  -> Atlas nao copia essa verdade para docs canonicos do Atlas
```

## Contratos

Nome obrigatorio:

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Documentation Reality System |
| Acronimo tecnico | ADRS |
| Nome interno de experiencia / superficie | Atlas Living Documentation Universe |
| Runtime tecnico atual | `AtlasDocumentationRealitySystemService` |

Filhos canonicos:

| Bloco | Nome canonico | Acronimo | Superficie | Runtime tecnico alvo |
|---|---|---|---|---|
| Operational truth | Atlas Code Reality & Usage Intelligence | ACRUI | Atlas Reality of Code | `AtlasCodeRealityUsageIntelligenceService` |
| Human cartography | Atlas Universal Reality Cartography | AURC | Atlas Universe Map | `AtlasUniversalRealityCartographyService` |
| Software twin | Atlas Software Twin Runtime | ASTR | Atlas Living System Twin | `AtlasSoftwareTwinRuntimeService` |
| Verified evolution | Atlas Verified Evolution Runtime | AVEOR | Atlas Change Safety Kernel | `AtlasVerifiedEvolutionRuntimeService` |

Regra de autoridade:

```text
Repo docs canonicos
  > codigo/testes/migrations/comandos para estado real
  > Evidence Ledger para eventos runtime
  > Postgres KB / Code Intelligence como read models
  > Cartografia como visualizacao
  > Obsidian/AtlasVault como superficie humana
  > AGENTS/CLAUDE como projections
  > chat/prints como source material
```

## Fluxo

### Fluxo para IA

```text
tarefa
-> session-bootstrap
-> feature-placement
-> ADRS identifica doc dono
-> ACRUI prova realidade operacional
-> Context Pack entrega fonte minima correta
-> IA implementa ou responde
-> testes/docs-health/architecture-validate
-> sync read models
-> Cartografia mostra estado atualizado
```

### Fluxo para humano

```text
Atlas Universe Map
-> universo
-> organizacao
-> projeto/workspace
-> sistema
-> dominio/surface/runtime
-> fluxo
-> componente
-> modal humano com fonte, prova, risco e proximo passo
```

O humano nao precisa ler tudo primeiro. Ele precisa ver relacao, fluxo, gargalo,
estado e risco. Texto denso so aparece quando ele pede detalhe.

### Fluxo entre humano e documentacao

```text
humano percebe confusao visual
-> abre modal/fonte
-> corrige doc canonico ou pede correcao
-> docs-health valida
-> ACRUI reclassifica realidade
-> AURC rerenderiza mapa
-> proxima IA recebe contexto correto
```

## Regras para IA

1. Nunca criar doc macro novo sem procurar o doc dono e este ADRS.
2. Nunca tratar Cartografia como fonte primaria; ela e projecao verificavel.
3. Nunca tratar ACRUI como autorizacao de delecao direta.
4. Nunca chamar codigo de morto sem uso, reachability, refs, testes e quarantine plan.
5. Nunca copiar docs canonicos de outro projeto para dentro do Atlas como verdade Atlas.
6. Sempre separar Atlas como plataforma do projeto/empresa operado pelo Atlas.
7. Sempre manter nome canonico, acronimo tecnico, superficie e runtime tecnico.
8. Sempre declarar se algo e active, scaffold, future, headless, legacy ou dead candidate.
9. Sempre preferir doc curto com links a doc gigante que ocupa contexto inteiro.
10. Sempre mostrar lacuna documental em vez de preencher visual ou texto por chute.

## Escopo de Implementacao

ADRS organiza 52 blocos em 6 planes oficiais. Eles sao contratos da area; runtime so nasce quando o bloco tiver owner, evidencia, teste e regra de consumo clara. O registro navegavel dos ids, planes, tipos, status, fontes e avaliadores vive em `atlas-documentation-reality-block-registry.md`.

## Planes Oficiais

| Plane | Papel | Blocos |
|---|---|---|
| Truth Authority Plane | decide fonte, owner, vocabulario e ciclo de vida | 1-4, 38, 42, 48, 50 |
| Operational Reality Plane | prova estado real, drift, scaffold, legado, readiness e evidence | 5, 11, 12, 14, 16, 19-21, 24, 27, 33-35, 37, 39-41, 45, 47, 49, 51, 52 |
| AI Context Efficiency Plane | reduz token e entrega contexto minimo seguro para IAs/subagentes | 10, 17, 22, 23, 25, 28, 30, 31, 43, 44 |
| Human Cartography Plane | mostra a verdade para humano por mapa, zoom, modal e coverage visual | 6-9, 18, 26, 29, 32, 36, 46 |
| Feedback & Learning Plane | fecha ciclo entre correcao humana, uso real e promocao de aprendizado | 15, 26, 45, 47, 50 |
| Governance & Lifecycle Plane | controla multiempresa, acesso, privacidade, SLO e estados documentais | 13, 43, 44, 48, 49, 50 |

Regra: cada bloco pode participar de mais de um plane, mas deve ter um owner
primario quando virar runtime ou doc filha.

| # | Bloco | Funcao | Saida |
|---:|---|---|---|
| 1 | Documentation Authority Kernel | resolve conflito entre docs, codigo, evidence, read models, Cartografia e chat | veredito de autoridade |
| 2 | Canonical Source Registry | registra organizacao, repo, workspace, owner docs e fronteira de verdade | mapa de fontes canonicas |
| 3 | Documentation Operating System | governa frontmatter, tamanho, split, status, archive e docs-health | docs implementaveis por IA |
| 4 | Knowledge Governance System | define autoridade entre repo docs, Postgres KB, Code Intelligence, Ledger, Obsidian e projections | hierarquia de verdade |
| 5 | ACRUI Operational Reality | classifica codigo/docs como active, headless, scaffold, legacy, duplicate, quarantine ou dead candidate | realidade operacional |
| 6 | AURC Visual Reality | renderiza universo, organizacao, projeto, sistema, fluxo, componente, prova e risco | mapa humano/IA |
| 7 | Human Modal Contract | estrutura o texto de long press para humano entender fonte, regra, risco, teste e proximo passo | modal legivel |
| 8 | Semantic Zoom Contract | troca nivel semantico em vez de apenas escalar pixels | drilldown visual real |
| 9 | Visual Grammar & Nomenclature | separa patamar, versao, camada, fonte, regra, risco, teste e evidencia | mapa sem mentira semantica |
| 10 | AI Context Projection | entrega so docs/codigo/testes/riscos necessarios para a tarefa | menor custo de contexto |
| 11 | Drift & Duplication Guard | detecta divergencia entre doc, codigo, routes, commands, tests e Cartografia | blockers de drift |
| 12 | Legacy & Quarantine Governance | protege archive, source material, adapters legados e candidatos de delete | plano de quarantine |
| 13 | Cross-Organization Boundary | separa Atlas plataforma de projetos operados como Blackink ou outros repos | fronteira multiempresa |
| 14 | Evidence & Runtime Proof Bridge | liga docs a testes, commands, traces, receipts, ledger e readiness | prova operacional |
| 15 | Human Correction Loop | transforma confusao humana em patch canonico, sync, reindex e melhoria de contexto | feedback fechado |
| 16 | Documentation Reality Score | pontua completude, clareza, autoridade, freshness, uso real, drift, prova e Cartografia | score por area |
| 17 | Context Minimality Ledger | registra quais fontes foram usadas e quais foram omitidas com motivo | economia auditavel |
| 18 | Visual Completeness Auditor | encontra nodes orfaos, fluxos sem destino, modais pobres e areas invisiveis | lacunas de Cartografia |
| 19 | Source Freshness Gate | bloqueia contexto quando doc, KB, Code Intelligence ou Cartografia estao stale | freshness status |
| 20 | Contradiction Resolver | detecta docs contraditorios e exige owner decision ou supersede explicito | fila de contradicoes |
| 21 | Implementation Readiness Matrix | diz se a area esta pronta para codar, precisa doc, precisa prova ou esta bloqueada | readiness por bloco |
| 22 | Multi-Agent Handoff Projection | gera pacote curto para Claude, Codex, Gemini e subagentes sem sujar contexto | handoff seguro |
| 23 | Documentation Budget Governor | controla tamanho, granularidade, prioridade e custo de recuperar docs | budget de contexto |
| 24 | Reality Change Journal | registra mudancas de classificacao: active -> legacy, scaffold -> wired, doc -> superseded | historico de realidade |
| 25 | Retrieval Audit Trail | registra por que cada doc/codigo entrou ou saiu do context pack | explicabilidade de retrieval |
| 26 | Human Attention Heatmap | mostra onde humano/IA mais travam, clicam, corrigem ou pedem explicacao | prioridade visual real |
| 27 | Semantic Deduplication Engine | detecta docs diferentes que dizem a mesma coisa ou brigam pelo mesmo owner | merge/supersede plan |
| 28 | Documentation Compression Tiers | gera camadas L0/L1/L2/L3: resumo, contrato, detalhe e evidencia | leitura por budget |
| 29 | Cartography Task Simulator | testa se um humano/IA consegue achar fluxo, fonte e prova por caminho visual | QA de navegacao |
| 30 | Provider Misread Defense | cria projection curta com proibicoes, owner e drift para reduzir erro de Claude/Codex/Gemini | defesa anti-misread |
| 31 | Documentation Working Set Cache | mantem em RAM/disco quente os docs, hashes e edges mais usados sem virar fonte de verdade | recall rapido |
| 32 | Cross-Modal Consistency Gate | compara doc canonico, modal humano, mapa visual e context pack | consistencia visual/textual |
| 33 | Auto-Split Planner | detecta doc grande/confuso e propoe docs filhos com backlinks e ownership | split seguro |
| 34 | Obsolete Knowledge Simulator | simula impacto de arquivar, fundir ou ocultar doc antes da mudanca | prevencao de buraco |
| 35 | Context Pack Regression Test | replaya tarefas antigas com novo context pack e mede perda de fonte/prova | regressao de contexto |
| 36 | Cartography Cognitive Load Meter | mede se o mapa esta visualmente compreensivel ou virou nuvem de nodes | carga cognitiva |
| 37 | Documentation Entropy Monitor | mede crescimento, repeticao, staleness e dispersao de ownership | entropia documental |
| 38 | Canonical Question Router | transforma pergunta humana/IA em owner docs, mapa visual e evidence alvo | roteamento de leitura |
| 39 | Evidence Sufficiency Gate | decide se ha prova suficiente para afirmar pronto, ativo, morto ou seguro | bloqueio de claim fraco |
| 40 | Reality Diff Engine | compara estado anterior vs atual de doc/codigo/mapa/context pack | diff de realidade |
| 41 | Orphaned Decision Finder | acha decisoes sem owner, sem implementation path ou sem evidencia | decisoes orfas |
| 42 | Vocabulary Alignment Guard | garante que humano, IA, docs e Cartografia usem os mesmos nomes | linguagem unica |
| 43 | Privacy & Redaction Gate | remove segredo, dado sensivel e contexto privado antes de projection/context pack | contexto seguro |
| 44 | Access Policy Resolver | decide quem pode ver doc, evidence, mapa, modal ou detalhe tecnico por projeto | acesso correto |
| 45 | Documentation Adoption Meter | mede se IAs, humanos e surfaces realmente usam o owner doc certo | uso real |
| 46 | Surface Coverage Matrix | mostra quais docs aparecem em Cartografia, Atlas Code, mobile, desktop e CLI | cobertura de surface |
| 47 | Learning-to-Doc Promotion Gate | promove aprendizado de runs para doc canonico somente com prova e owner | aprendizado seguro |
| 48 | Documentation Lifecycle State Machine | governa draft, active, scaffold, superseded, archived, quarantine e deleted | ciclo de vida |
| 49 | Documentation SLO & Alerting | define metas de freshness, coverage, warning_count, orphan_count e context cost | alertas operacionais |
| 50 | Owner Escalation Queue | encaminha drift, contradicao ou doc sem owner para humano/sistema responsavel | fila de responsabilidade |
| 51 | Synthetic Reader Tests | testa se uma IA limpa consegue responder perguntas canonicas usando so ADRS e filhos | prova de legibilidade |
| 52 | Canonical Example Corpus | mantem exemplos curtos de bom/ruim para doc, mapa, context pack e classification | padrao replicavel |

Regra de eficiencia: bloco novo so entra no ADRS se reduzir erro, duplicacao ou
token, ou aumentar acesso humano verificavel; se for so detalhe, pertence a doc filha.

Ordem recomendada: Truth Authority -> Operational Reality -> AI Context
Efficiency -> Human Cartography -> Feedback & Learning -> Governance & Lifecycle.

## Modos De Saida

ADRS deve produzir quatro saidas diferentes a partir da mesma verdade:

| Modo | Consumidor | Tamanho alvo | Conteudo |
|---|---|---:|---|
| `human_visual` | Cartografia | visual primeiro | mapa, estado, gargalo, foco e modal curto |
| `ai_context_minimal` | provider/subagente | minimo possivel | owner docs, provas, proibicoes, arquivos alvo |
| `audit_full` | operador/reviewer | completo | fontes, contradicoes, freshness, score e trail |
| `implementation_ready` | Atlas Dev/Forge | acionavel | DoD, testes, riscos, comandos e blockers |

Regra: a mesma area pode ter contexto completo no audit e contexto minimo no
provider. Eficiência vem de projetar a mesma verdade para cada consumidor, nao
de resumir tudo igual para todos.

## Matriz De Aceite Dos 52 Blocos

`block_acceptance_matrix` impede que bloco seja completo só por existir no
markdown. Cada item exige owner doc, evaluation ref, comando, teste, quality
floor read-only, evidence refs e claim conservador.

```bash
php artisan atlas:documentation-reality acceptance --strict --json
```

`accepted` significa contrato ADRS materializado em runtime read-only e coberto
por teste; nao declara produto filho completo.

`atlas-documentation-reality-block-registry.md` e o catalogo compacto que a
Cartografia deve usar para renderizar os 52 blocos sem depender de inferencia
livre sobre nomes, planes, fontes ou evaluation refs.

## Definition Of Complete Da Area

ADRS so pode ser tratado como completo quando:

1. Todo doc macro tem owner, status, parent, fonte, evidence e next action.
2. Toda peca visivel na Cartografia tem source path e status verificavel.
3. Toda feature nova passa por owner lookup, ACRUI e anti-duplicate.
4. Todo context pack registra fontes usadas, omitidas e motivo.
5. Toda classificacao de legacy/dead/scaffold tem prova e quarantine policy.
6. Todo projeto externo fica separado por boundary de organizacao/repositorio.
7. Todo provider projection e gerado a partir da verdade canonica atual.
8. Todo drift entre doc/codigo/mapa aparece como blocker ou review item.
9. O humano acha a fonte pela Cartografia em poucos passos; a IA implementa sem receber chat como fonte primaria.

Estado runtime atual (`acceptance --strict --json`): 52 blocos CATALOGADOS, classificados pela acceptance matrix em **21 `L4_integrated` / 4 `L3_partial` / 27 `L2_declared` / 0 incomplete**. NAO sao "52 em L4": L4 = o bloco executa o verbo declarado sobre input real (test-backed); declared/partial = catalogado mas ainda nao executa. (NAO declara ACRUI/AURC completos como produto final.)

### ADRS Runtime Completeness (tres eixos, nunca um "100%" unico)

"L0->L-inf 100% / 10/10" era insatisfazivel por **conflar** tres coisas; a leitura
honesta as **desambigua** (taxonomia na `evolution-ladder`): **(a)
`runtime_completeness`** = mecanismos BUILDABLE presentes + ligados + drift-0, **cada
um** drift-checado contra seu owner doc real (sem exemcao) — a completude de RUNTIME,
distinta da assintota, hoje **MET (15/15)**; **(b) `asymptote`** = `linf_complete`
**hard `false` para sempre** (bussola, fora do 10/10); **(c) `reality_dependent`** =
`outcome_grounded` (O1), funcao do mundo, honestamente **0**, reportado e **nunca**
contado. A honestidade repousa no drift-check por mecanismo + no conjunto travado por
teste; o guard (`...CompletenessService`) e defesa em profundidade.

## Dependencias

| Dependencia | Uso |
|---|---|
| Documentation OS | regras de formato e tamanho |
| Knowledge Governance | autoridade e conflito |
| ACRUI | realidade operacional e anti-duplicacao |
| Cartographic Knowledge OS | visualizacao humana/IA |
| System Graph | hierarquia macro |
| Living Architecture Graph | nodes reais e status |
| Code Intelligence | simbolos, rotas, comandos e testes |
| Evidence Ledger | prova runtime |
| Context Packs | entrega minima para provider |

## Evidencias
Evidencia minima para ADRS:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:documentation-reality score --strict --json
php artisan atlas:documentation-reality evaluations --strict --json
php artisan atlas:documentation-reality acceptance --strict --json
php artisan atlas:documentation-reality blocks --strict --json
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
php artisan atlas:ai:docs-authority-audit --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune --json
```

## Riscos

| Risco | Efeito | Controle |
|---|---|---|
| Documentacao canonica ruim | IA implementa errado | docs-health + owner docs + ACRUI |
| Cartografia ruim | humano entende errado | AURC + visual completeness audit |
| Context pack excessivo | custo e perda de foco | AI Context Projection |
| Context pack pobre | IA inventa | ACRUI + retrieval obrigatorio |
| Duplicacao documental | IAs criam fluxos paralelos | docs-authority + ADRS |
| Codigo estacionado esquecido | sistema parece menor ou maior do que e | ACRUI |
| Mapa bonito falso | humano confia em mentira visual | source_path + evidence + status |
| Projeto externo misturado ao Atlas | verdade concorrente | Cross-Organization Boundary |

## Exemplos

Atlas interno usa ADRS -> ACRUI -> AURC; projeto externo fica no repo canonico externo; codigo possivelmente morto vira quarantine candidate, nao delete direto.

## Proximas Acoes

1. Manter ADRS como doc mae e runtime integrado read-only da area, sem criar segunda fonte de verdade.
2. Manter ACRUI, AURC e Block Registry sincronizados com runtime, testes e Cartografia.
3. Promover blocos criticos para L5 quando houver feedback real de uso, adoption meter e repair loop.
