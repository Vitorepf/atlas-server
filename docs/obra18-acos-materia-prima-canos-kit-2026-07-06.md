# Obra #18 — ACOS em outro nível: matéria-prima, canos e o kit de delegação

Data: 2026-07-06 · Método: 3 investigadores adversariais (Anatomista do Dado / Engenheiro de Implementabilidade / Caçador de Costuras) + verificação cruzada · Status: aprovável
Papel na linha: a **#17** conserta o MOTOR de retrieval; a **#18** garante que (a) existe conhecimento real para recuperar, (b) ele chega a TODAS as superfícies de entrega, (c) cada slice é executável por modelo barato. As duas obras são complementares e parcialmente paralelas.

## A tese em três achados (todos provados em auditoria de 06/07)

1. **O cérebro é um esqueleto** (auditoria censitária do pgsql vivo): `atlas_memory_entries` tem **55 rows — 54 são stubs do restore pós-wiper** (projeção de 1 linha round-tripped de volta pro DB); 60% título=resumo; 5% com porquê; priority/confidence 100% flat; tags 1/55; **`atlas_memory_entry_relations` = 0 rows**; nós de decisão no reality graph = 0; **18.320 recalls logados com feedback NULL em 100%** e 90% deles devolvendo UMA memória (o incidente wiper); **9 tabelas do pipeline cognitivo G0→G8 com 0 rows**; e o `AtlasMemoryQualityService` dando **93/100** para esse estado. Escrita orgânica: 0,25 memória/dia. *Mesmo o retrieval perfeito da #17 devolveria pouco além do wiper.*
2. **A cognição morre nos canos** (trace de código das 5 costuras): o Dev pipeline CONSULTA o pack rico, mas `OpenBrainProjectionAdapter::translateRefs()` (:158-214) **descarta title/summary/body e entrega ao provider um URI opaco** (`atlas-memory://entry/<id>`) que nada dereferencia — num run diff-only SEM tools; o próprio `PromptSectionsMapper` já consertou esse exato defeito 2× para outras fontes (discovery :210-214, exemplars :225-229) e nunca para memória; o `RepairPromptComposer` tem zero cognição. A esteira (`AgentControlPlaneTaskPacketBuilder` + serving) **nunca pede** memória — 4 fontes advisory de ledger local, nenhuma do registry. As missões decompõem **léxico puro**. E o fechamento é assimétrico: só a sessão Claude Code (Stop hook) devolve aprendizado ao registry; Dev/esteira/chat fecham para silos próprios. *Toda melhoria da #17 é multiplicada por ZERO nas superfícies onde a entrega acontece sem o operador olhando.*
3. **A linha frontier→barato precisa de trilhos** (auditoria da #17 na pele de um implementador barato): slices citam siglas sem path, "religar X" sem call site consumidor, gates não-mecânicos sem rótulo; o formato de ordem de trabalho que protege contra as falhas históricas (Codex quebrando callers, agentes alucinando oráculo, poison-packets, wiper) **já existe a ~80% no envelope do task packet** — falta estender 4 órgãos vivos, não construir harness novo. *Prova viva da necessidade: o próprio agente auditor acusou 2 "símbolos fantasmas" na #17 que EXISTEM (`heartbeat.jsonl`, `decisionViolationCheck` em AtlasOpenBrainGuardService:243) — grep no lugar errado; a regra "verifique com rg no workspace certo e cole a evidência na ordem" vale para todos, frontier incluso.*

## Frente D — MATÉRIA-PRIMA (a qualidade do dado)

Baselines = números da auditoria censitária (acima). Todos os slices são executáveis por modelo barato com ordem de trabalho do Kit (Frente K).

| Slice | Entrega | Baseline → Gate |
|---|---|---|
| **D1 Re-hidratação das 44 memórias ativas** | cada stub ganha corpo real (o quê + PORQUÊ + quando se aplica + evidence ref para doc/commit), priority/confidence/scope reais, marcador "corpo pode estar truncado" removido. Fonte da verdade para re-hidratar: docs canônicos + specs de obra + git (o conhecimento existe em prosa; só não está no DB) | 60% título=resumo, 5% com porquê → **0 marcadores de truncamento; ≥80% com porquê; ≥3 níveis de priority em uso; teste cego "por que X?" respondido pelo corpo** |
| **D2 Captura que preserva estrutura** | Stop hook grava learning ESTRUTURADO (claim + porquê + arquivos + evidence) em vez de `mb_substr(280)`; `post_execution_update` do APCR passa a ser preenchido (contrato existe, 0/117 cumprido) | 0,25 memória/dia; corpo ~250 chars; APCR 0/117 → **≥1 memória estruturada/dia de sessão real; mediana ≥600 chars estruturados; ≥50% dos packs APCR novos com update** |
| **D3 Relações vivas** | religar `AtlasMemoryRelationsCommand`/linker (construído, 0 uso): toda memória nova referencia módulo(s) do code graph; supersede/conflita explícito | relations=0; memória↔código=0 edges; superseded=0 → **≥70% das ativas com ≥1 relação; ≥1 cadeia superseded real; edges memória↔código consultáveis no pack** |
| **D4 Feedback fechado ou desligado** | 18.320 usages de ~4KB sem 1 feedback = escrita morta cara. Feedback implícito no hook (citada no pack ∧ presente no diff = útil) + dedup do recall dominante | feedback 0/18.320; 90% recalls = 1 entrada → **≥20% dos usages novos com feedback_action; nenhuma entrada >50% dos recalls da semana; métrica no maintenance-status** |
| **D5 Instrumento honesto** | recalibrar `AtlasMemoryQualityService` com os achados como componentes de 1ª classe (taxa título=resumo, taxa com-porquê, densidade de relações, fill de feedback) — contagens verificáveis por SQL, publicadas no snapshot | score 93/"ready" sobre o estado auditado → **score sobre o estado de HOJE ≤50; sobe só quando D1-D4 movem os números crus** |

## Frente C — CANOS (a mesma cognição em toda superfície de entrega)

| Slice | Entrega | Baseline → Gate |
|---|---|---|
| **C1 Dereferenciar memória no prompt do Dev** (dias — a alavanca máxima) | inline `title + summary` provider-safe de cada memory ref na seção Context Refs — a MESMA manobra que `PromptSectionsMapper` já fez 2× (discovery/exemplars), reusando a redação do `DevFailureCapsulePromptInjector` e o guard de sendability (:251-256). Incluir o `RepairPromptComposer` (hoje zero cognição). Slice-irmão barato: auditar refs `awis_cache:` opacos do Forge | 0% dos prompts Dev com texto de decisão (provado por construção) → **≥80% dos runs em zona com decisão registrada contêm o TEXTO no prompt renderizado; colisões tardias em runs Dev caem vs. baseline** |
| **C2 Quinta fonte advisory na esteira** | `AtlasTaskServingService::next()` já tem o padrão fail-open (:188-206) com 4 fontes; adicionar `relevant_memory` (decisões+refutações escopadas pelo `allowed_files` do packet). Sequência: depois do T0 da #17 (precisa do retrieval por query) | packets com decisão de zona: 0% → **≥80% dos packets em zonas com decisão carregam-na; give_backs por decisão-já-registrada → 0 na janela** |
| **C3 Fechamento simétrico** | outcome de QUALQUER superfície (report da esteira, completion do Dev) emite candidato G0 pelo MESMO canal do Stop hook, com delta-de-surpresa como filtro anti-inflação; G0 continua nunca auto-promovendo | entregas não-Claude-Code gerando candidato: ~0 → **alvo definido com precision G0 sem queda (verify-then-absorb)** |
| Fora com razão | Chat gateway (única costura fail-closed — herda o T0 da #17 de graça); Forge (costurado nos 2 sentidos); missões (decomposição cognitiva é obra futura, não remendo) | — |

## Frente K — KIT DE DELEGAÇÃO (a linha frontier→barato como produto)

Nada de harness novo: **4 extensões de órgãos vivos** + disciplina de escrita.

| Slice | Entrega |
|---|---|
| **K1 Schema da ordem = extensão do envelope do packet** (`AtlasTaskServingService` ~:990 já tem allowed/forbidden_files, acceptance, evidence, delivery_rules) | adicionar: `frozen_callers` (output de rg colado pelo planejador, com destino declarado por caller — mudança **aditiva-only**, trocar assinatura = give_back), `acceptance_test_ref` (path+hash do teste PRÉ-ESCRITO pelo planejador, que entra em forbidden_files — o implementador o faz passar, nunca o edita), `stop_and_return` (critérios pare-e-devolva das famílias de poison-packet), `glossary` (toda sigla → path absoluto; sigla não resolvida = ordem inválida), `baseline_artifact` |
| **K2 Scaffolder `atlas:obra:work-order <slice>`** | gera a ordem do doc da obra + auto-preenche callers via code graph + **valida que todo path/símbolo citado existe** (teria pego os 2 falsos-fantasmas desta rodada — nos dois sentidos) |
| **K3 Linter de ordem = extensão do `AtlasTaskPacketQualityInspector`** | recusa na FONTE: sigla sem path, path inexistente, aceitação citando arquivo fora de allowed∪forbidden, sem teste pré-escrito, gate sem rótulo `mecânico`/`evento-operador` (o implementador NUNCA fecha gate de operador) |
| **K4 Gate de conformidade = extensão do admission gate v2** (mesmo enforce; `wired_or_tagged`/`no_duplicate_logic` continuam) | hash do teste pré-escrito intocado; diff ⊆ allowed_files; suítes dos frozen_callers verdes; sem colisão de nome de comando artisan |
| **K5 Disciplina do planejador** (contrato, não código) | teste de aceitação escrito ANTES da ordem (não consegue escrever = slice não está pronto); todo símbolo verificado com rg antes de citado (workspace certo, `--no-ignore` em storage/); "religar X" sempre com call site consumidor + teste que prova leitura; 1 slice = 1 commit com prova; gates rotulados; assumir implementador SEM hooks (Codex não tem sentinel/capture — tudo vai no texto da ordem) |

## Sequência executável (cruzada com a #17)

```
#17 T0.1 retrieval-por-query ─┐
#18 C1 dereferenciar Dev ─────┼── paralelos, ambos "dias" — os dois maiores retornos do sistema inteiro
#18 D1+D2 matéria-prima ──────┘
→ #18 K1-K4 kit (antes das ordens do T1 da #17)
→ #17 T1 retomada (com dado D1/D2 e kit K)
→ #18 D3+D4, C2 (pós-T0) 
→ #17 T2-T3 → #18 C3, D5 → #17 T4 saltos
```

**Por quê essa ordem:** C1 e D1/D2 não dependem do retrieval novo e multiplicam-no quando chegar; o Kit precisa existir ANTES da primeira ordem delegada ao modelo barato (as ordens do T0/T1 da #17 são as cobaias do K2/K3).

## Medição da obra (régua do operador, herdada da #17)

Os 3 contadores (TPE, perguntas evitáveis, colisões tardias) + dois específicos: **taxa de prompt-com-cognição** (% de runs Dev/esteira em zona com decisão que carregam o texto dela — baseline 0%) e **memórias estruturadas/dia** (baseline 0,25 stubs/dia). Fase vira ready só com evento externo (regra da #17).

## Pétreas
Byte-prova; baseline antes do código; G0 nunca auto-promove; aditivo-only em símbolo público sem lista de callers congelada; testes nunca no pgsql vivo; vocabulário proibido; push só com OK; `rg --no-ignore` em storage/.
