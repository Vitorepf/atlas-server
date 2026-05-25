---
id: atlas-forge-rivals-battery-modes-and-human-prompts-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Battery Modes and Human Prompts v1
status: active
category: programming-forge
priority: 95
summary: Canon dos modos de bateria do Atlas Forge Rivals: spec-perfect, human-normal, messy-real, enterprise-change, provider-arena, atlas-power, fair-mode, power-mode, category-battery e difficulty-ladder. Define quando cada modo e valido, que tipo de verdade mede, e como isso alimenta Provider Performance Ledger e Atlas Decide sem destravar external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - provider-arena
  - human-prompts
  - difficulty-ladder
capabilities:
  - rivals_prompt_realism_modes
  - rivals_competition_modes
  - rivals_category_batteries
  - rivals_difficulty_ladder_l1_l5
  - rivals_atlas_decide_advisory_signal
decisions:
  - Rivals mede realidade por mais de uma lente: spec perfeita, prompt humano normal, prompt baguncado e mudanca enterprise.
  - Fair-mode mede justica sob mesmo provider/modelo; power-mode mede o valor total do Atlas como sistema.
  - Provider Arena compara qualquer runner canonico contra qualquer runner canonico, inclusive Claude, Codex, Gemini, Opus, Sonnet e Atlas Forge em modos diferentes.
  - Category-battery separa dominios; difficulty-ladder L1-L5 separa profundidade real de volume bruto.
  - Resultados do Rivals alimentam Atlas Decide e Provider Performance Ledger como sinal consultivo, nunca como autoridade runtime isolada.
maintenance:
  - Atualizar quando novos modos, categorias, arms ou niveis de dificuldade forem implementados.
  - Nunca marcar modo como executavel sem comando, evidence pack, replay e report validos.
  - Manter esta doc em sincronia com benchmark-strategy, corpus, adjudicator, report e provider-performance-ledger.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-corpus-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-battery-modes-and-human-prompts-v1
graph_title: Atlas Forge Rivals · Battery Modes and Human Prompts v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-benchmark-strategy-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Battery Modes and Human Prompts v1"
canonical_name: "Atlas Forge Rivals · Battery Modes and Human Prompts v1"
technical_name: atlas-forge-rivals-battery-modes-and-human-prompts-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
allowed_changes:
  - Adicionar novo modo quando houver contrato de prompt, evidence, replay e report.
  - Refinar pesos de categorias e difficulty ladder com base em baterias reais.
forbidden_changes:
  - Declarar vencedor global usando apenas quick, smoke ou local_fake.
  - Tratar resultado estranho de provider forte como verdade sem triage.
  - Destravar external_rivals_certification a partir de score do Rivals.
  - Misturar prompt spec-perfect com prompt human-normal sem rotular no report.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-provider-arena-corpus-v1
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
flows_to:
  - atlas-forge-rivals-provider-performance-ledger-v1
  - atlas-decide
unlocks:
  - rivals_real_provider_comparison_by_prompt_realism
  - rivals_category_specific_provider_choice
governs:
  - rivals_battery_modes
  - rivals_prompt_contracts
  - rivals_difficulty_ladder
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-modes-and-human-prompts-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Rodar messy-real release 40 com provider real e hidden oracle.
  - Rodar enterprise-change release 40 com provider real e hidden oracle.
  - Registrar resultados validos no Intelligence Ledger por modo, categoria e dificuldade.
  - Implementar messy-real com hidden oracle e triage de ambiguidade.
  - Registrar resultados validos no Provider Performance Ledger por modo, categoria e dificuldade.
---
# Atlas Forge Rivals · Battery Modes and Human Prompts v1

## Resumo

Rivals existe para responder com evidencia: **quem constroi melhor software
para esta classe de tarefa?** A resposta pode mudar por prompt, dominio,
dificuldade, custo, tempo e modo de operacao. Por isso Rivals nao pode ser
apenas "Atlas vs Claude" em um caso unico. Ele precisa comparar runners,
providers e modos sob contratos claros.

Esta doc e o canon dos modos:

- `spec-perfect`
- `human-normal`
- `messy-real`
- `enterprise-change`
- `provider-arena`
- `atlas-power`
- `fair-mode`
- `power-mode`
- `category-battery`
- `difficulty-ladder`

Nenhum desses modos destrava `external_rivals_certification`. Todos produzem
sinal consultivo para o Provider Performance Ledger e, depois, para Atlas
Decide.

## Onde Se Encaixa

O papel do Rivals e separar opiniao de medicao. Ele mede quando um provider
puro vence, quando o Atlas Forge realmente cria vantagem, e quando a vantagem
some porque o prompt esta perfeito demais ou porque o caso favorece um arm.

Atlas Decide pode usar esse historico para escolher provider/modelo por role,
mas precisa continuar emitindo seu proprio Decision Receipt. Rivals e fonte de
evidencia, nao autoridade runtime.

## Papel no Atlas

O Atlas usa Rivals como sistema de medida comparativa: ele mostra onde a
arquitetura Forge supera um provider cru, onde ela empata, onde perde, e quais
providers sao melhores por categoria, dificuldade e custo. Esse papel e
consultivo, mas fundamental para evoluir Provider Performance Ledger, Atlas
Decide e as decisoes de roteamento futuras.

## Contratos

- Toda bateria precisa declarar prompt mode, competitive mode, categoria,
  dificuldade, provider/modelo, evidence pack, replay manifest e report.
- Score so vale quando scope, evidence, replay, clean workspace, custo, tempo e
  intervencao humana estiverem registrados.
- `fair-mode` exige simetria de provider/modelo ou uma restricao equivalente e
  audita essa equivalencia no report.
- `power-mode` e `atlas-power` podem usar topologia e governanca completa do
  Atlas, mas devem rotular explicitamente que nao sao comparacao justa de
  provider cru.
- Resultado do Rivals e sinal consultivo para Atlas Decide; nao substitui
  Decision Receipt e nao desbloqueia `external_rivals_certification`.

## Escopo de Implementacao

Esta doc define a semantica dos modos e o shape minimo dos desafios. A
implementacao deve aparecer em corpus, runner, adjudicator, report, evidence
pack, replay, Provider Performance Ledger e UI do Atlas Code quando o modo for
exposto ao operador.

Fora de escopo: chamar provider sem tres confirmacoes, aceitar score sem replay,
declarar benchmark trusted com quick/local_fake, ou usar a bateria como
completion claim de produto.

## Dependencias

- `atlas-forge-rivals-benchmark-strategy-v1`: estrategia de release, quick,
  deep e provider arena.
- `atlas-forge-rivals-provider-arena-corpus-v1`: casos, categorias, difficulty
  ladder e manifests.
- `atlas-forge-rivals-perfect-battery-and-adjudicator-v1`: hard gates,
  adjudication e report.
- `atlas-forge-rivals-provider-performance-ledger-v1`: persistencia dos sinais
  por provider/modelo/categoria/dificuldade.
- `atlas-decide`: consumo consultivo dos resultados em decisoes futuras.

## Modos de prompt

| Modo | O que entrega ao competidor | O que mede | Claim permitido |
| --- | --- | --- | --- |
| `spec-perfect` | Spec completa, criterios claros, escopo, comandos, arquivos esperados e oracle explicito. | Execucao tecnica quando o problema ja foi perfeitamente pensado. | Resultado por caso/categoria; bom para validar harness. |
| `human-normal` | Pedido humano comum: claro o bastante, mas sem schema perfeito nem checklist completo. Executavel via `--prompt-mode=human-normal`. | Capacidade de transformar pedido normal em plano, implementacao e evidencia. | Comparacao mais realista para produto. |
| `messy-real` | Pedido incompleto, ruido, ambiguidade, informacao faltando e possiveis contradicoes leves. | Investigacao, assuncoes minimas, perguntas certas e fail-closed honesto. | Claim somente com hidden oracle e triage. |
| `enterprise-change` | Mudanca longa com risco, docs, testes, migracao, compatibilidade, rollback e evidencia. | Continuidade, governanca, planejamento e execucao multi-etapa. | Claim forte quando release/deep passa. |

### Regra central

`spec-perfect` nao prova que o Atlas e melhor em produto real; prova que o
harness e os arms conseguem executar uma spec clara. `human-normal`,
`messy-real` e `enterprise-change` medem onde o Atlas deveria criar maior
vantagem: planejar, governar, recuperar, evidenciar e sustentar trabalho longo.

## Modos competitivos

| Modo | Definicao | Exemplo |
| --- | --- | --- |
| `fair-mode` | Atlas e rival usam o mesmo provider/modelo ou restricao equivalente. Mede arquitetura sob igualdade. | Atlas Forge Sonnet vs Claude Code Sonnet. |
| `power-mode` | Atlas usa todo poder permitido: topologia, roteamento, fallback governado, multi-etapa e review. Rival usa runner declarado. | Atlas Forge full_power vs Claude Code Sonnet. |
| `atlas-power` | Caso especial de power-mode focado no delta Atlas como sistema contra provider puro. | Atlas Forge completo vs Codex CLI puro. |
| `provider-arena` | Qualquer runner canonico contra qualquer runner canonico, com registry, receipts e evidence. | Claude Code vs Codex; Opus vs Sonnet; Codex vs Gemini. |

`fair-mode` e o modo mais conservador. `power-mode` e o modo mais importante
para produto, porque mede se o Atlas como sistema supera o provider cru.

## Category Battery

`category-battery` roda casos de um dominio especifico para responder "quem e
melhor para isto?", nao apenas "quem e melhor em media".

Categorias canonicas iniciais:

1. `planning`
2. `frontend_ui`
3. `backend_logic`
4. `realistic_bugfix`
5. `refactor`
6. `test_design`
7. `architecture`
8. `integration_performance`

Cada categoria deve ter pelo menos 5 desafios no release matrix: L1, L2, L3,
L4 e L5. Assim um empate agregado pode revelar diferencas reais: Claude pode
ganhar L1/L2 em velocidade, Atlas pode ganhar L4/L5 em governanca e plano.

## Difficulty Ladder

`difficulty-ladder` impede que quantidade esconda profundidade.

| Level | Nome | Mede | Prompt tipico |
| --- | --- | --- | --- |
| `L1` | Mechanical | Edicao local, bug obvio, criterio fechado. | "Corrija este off-by-one e rode o teste." |
| `L2` | Local reasoning | Dois ou tres passos, TDD simples, pequenas variantes. | "Adicione fallback e cubra edge case." |
| `L3` | Product integration | Regra de negocio, estado, contrato entre modulos. | "Faça webhook idempotente com teste." |
| `L4` | Architectural | Boundary, schema, compatibilidade, fail-closed. | "Versione receipt mantendo v1 e v2." |
| `L5` | Strategic planning | Decomposicao, migracao, risco, rollback e continuidade. | "Planeje e implemente migracao faseada." |

Todo resultado serio deve mostrar:

- score bruto;
- score ponderado por dificuldade;
- tempo;
- custo estimado;
- provider/modelo;
- modo de prompt;
- categoria;
- taxa de empate tecnico por nivel L1-L5;
- custos, tokens e eficiencia como telemetria somente.

Politica adaptativa anti-empate:

- Cada nivel L1, L2, L3, L4 e L5 tem orcamento maximo de 55% de empate
  tecnico.
- Cada caso deve carregar `anti_tie_pressure` com `max_technical_tie_rate=0.55`
  e minimo de dimensoes medidas por nivel: L1 >= 8, L2 >= 9, L3 >= 10,
  L4 >= 11, L5 >= 12. O corpus pode exceder esses minimos.
- Se qualquer nivel passar de 55%, a bateria atual deve parar para aquele
  nivel e emitir `per_level_tie_escalation` com os niveis afetados.
- A proxima bateria deve aumentar complexidade do nivel afetado, misturando
  mais capacidades no mesmo caso: planejamento, implementacao, teste,
  evidencia, rollback, compatibilidade, risco, docs e debugging.
- A cada 10 empates tecnicos agregados, a bateria tambem deve cancelar e
  elevar o piso basico, mesmo que o empate esteja espalhado entre niveis.
- Custo, tokens, latencia e eficiencia aparecem no relatorio para auditoria,
  mas nao podem decidir vencedor nem servir como desempate.
- evidence/replay status.

## Baterias canônicas

| Bateria | Conteudo | Uso |
| --- | --- | --- |
| `quick` | 3 casos. | Provar que harness, receipts, replay e report ligam. |
| `release` | 40 casos, 8 categorias x 5 niveis. | Comparacao seria e primeiro winner confiavel. |
| `deep` | 25+ por dominio ou multiplas rodadas release. | Ranking robusto e tendencia por provider. |
| `frontend` | 5+ casos UI. | Decidir provider para UI/polish/acessibilidade. |
| `backend` | 5+ casos logica/infra. | Decidir provider para regra, dados e estado. |
| `architecture` | 5+ casos L3-L5. | Decidir provider para design sistemico. |
| `provider-arena` | Matriz runner x runner. | Alimentar Provider Performance Ledger. |
| `atlas-power` | Atlas full_power vs provider puro. | Medir vantagem real da arquitetura Atlas. |

## Contrato de entrega do desafio

Cada desafio precisa declarar duas camadas:

1. **Prompt publico**: o que cada competidor recebe.
2. **Oracle privado**: como o harness valida resultado sem entregar a resposta.

Campos minimos:

- prompt_mode (`spec-perfect`, `human-normal`, `messy-real`, `enterprise-change`);
- public_prompt;
- hidden_oracle;
- fixture_seed_path;
- allowed_files_scope;
- forbidden_files_scope;
- test_command;
- quality_weights;
- difficulty_level;
- evidence_requirements;
- replay_requirements;
- fairness_notes;
- invalid_if.

Prompt humano normal nao deve virar spec perfeita escondida. Se o texto publico
for simples, o oracle pode ser forte, mas o competidor nao recebe checklist
completo. Isso mede produto real.

## Relação com Atlas Decide

Rivals deve alimentar o Provider Performance Ledger com linhas por:

- provider;
- modelo;
- categoria;
- dificuldade;
- modo de prompt;
- modo competitivo;
- custo;
- tempo;
- confidence;
- freshness.

Atlas Decide pode usar isso como sinal para escolher `primary_builder`,
`critical_reviewer`, `context_scout` ou `repair_agent`, mas sempre emite um
Decision Receipt novo. Score antigo nunca substitui decisao runtime.

## Regras para IA

- Nao declarar `trusted_battery` com `quick`.
- Nao misturar `spec-perfect` e `human-normal` no mesmo agregado sem filtro.
- Nao aceitar score baixo improvavel de Claude/Codex/Opus sem triage.
- Nao esconder custo, tempo, timeout, retry ou intervencao humana.
- Nao pontuar qualidade se evidence/replay/scope estiver quebrado.
- Nao transformar falha do harness em derrota do provider.
- Nao transformar derrota legitima do provider em invalidacao global da bateria.

## Fluxo

1. Escolher prompt mode.
2. Escolher competitive mode.
3. Escolher category-battery ou release/deep.
4. Rodar preflight e worktrees isolados.
5. Executar arms com provider real quando autorizado.
6. Coletar evidence, replay e score deterministico.
7. Classificar suspicious/invalid/hard fail.
8. Emitir report humano e ledger rows.
9. Só entao alimentar Atlas Decide como advisory signal.

## Evidencias

Resultado valido exige:

- provider receipt por arm;
- diff por arm;
- test log por arm;
- workspace hash before/after;
- after-clean-check;
- replay manifest;
- scorecard deterministico;
- report com modo, categoria, dificuldade, tempo e custo.

## Riscos

O maior risco e o harness contaminar o jogo. A regra e simples: **score so vale
se o harness nao estiver contaminando a partida**. Se a evidencia esta suja,
resultado e invalid. Se um arm falhou honestamente dentro de uma evidencia
limpa, isso e derrota daquele arm, nao falha do harness.

## Exemplos

```bash
# Fair: Atlas Forge Sonnet vs Claude Code Sonnet, release matrix.
php artisan atlas:forge:rivals run-battery \
  --case-set=release --mode=fair \
  --prompt-mode=spec-perfect \
  --atlas-model=claude_sonnet --rival=claude_sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# Human-normal: mesmos 40 casos, mas prompt publico em formato de pedido humano comum.
php artisan atlas:forge:rivals run-battery \
  --case-set=release --mode=fair \
  --prompt-mode=human-normal \
  --atlas-model=claude_sonnet --rival=claude_sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# Category battery: frontend only.
php artisan atlas:forge:rivals run-battery \
  --case-set=frontend --mode=fair \
  --prompt-mode=human-normal \
  --atlas-model=claude_sonnet --rival=codex \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# Power: Atlas full system vs provider pure.
php artisan atlas:forge:rivals run-battery \
  --case-set=architecture --mode=full_power \
  --atlas-model=auto --rival=claude_opus \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict
```

## Proximas Acoes

1. Rodar release `spec-perfect` 40 casos ate report valido.
2. Rodar release `human-normal` 40 casos com os mesmos 8 dominios via `--prompt-mode=human-normal`.
3. Criar bateria `messy-real` com hidden oracle e triage de ambiguidade.
4. Fazer `provider-arena` Claude vs Codex vs Gemini vs Opus por categoria.
5. Alimentar Provider Performance Ledger, Intelligence Ledger e Atlas Decide como advisory signal.
