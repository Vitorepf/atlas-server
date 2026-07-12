# ACOS — Mapa de Áreas (nomes claros, canônico)

> Decisão do operador (12/07/2026): o ACOS é organizado e comunicado por **áreas de nome claro**, nunca por acrônimo. Acrônimos históricos (AUCRI, ASEF, AHRI, AEMOR, ACIE...) viram **ids internos** listados entre colchetes dentro da sua área — continuam válidos em código/scorecard, mas nenhum doc, plano ou conversa nova usa acrônimo como nome de área. Este doc é o dicionário entre os dois mundos.

## Regra de uso

- Docs/planos novos: título e organização SEMPRE pela área ("Busca & Ranking"), com os ids internos citados quando precisar apontar código.
- O scorecard (14 módulos / 73 facets) continua sendo a medição; este mapa é a LINGUAGEM. Alinhar o agrupador do scorecard a estas áreas é follow-up opcional (nunca mexer em medidor congelado durante relógio).
- Planos que já usam letras (Max: A–G) mapeiam 1:1 para as áreas 3–8 e 13 abaixo.

## As 18 áreas do ACOS

| # | Área | O que é (uma frase) | Componentes internos | Cobertura hoje |
|---|---|---|---|---|
| 1 | **Memória** | O corpus canônico: o que o Atlas sabe, com tipos, relações, curadoria e remoção reversível | Memory Core, registry, journal, digest semanal, memory-forget | v1: MEM-*, CORP-01, TAXO-01 |
| 2 | **Captura & Imunidade** | O que entra no cérebro e o que NUNCA entra: ingestão de fontes + quarentena anti-falso-aprendizado | [AKIF], Cognitive Immune [G0–G8] | v1 indireto (gates) · fronteira futura |
| 3 | **Embeddings & Índices** | Como conhecimento vira vetor pesquisável: modelos locais, chunking, índices, provenance | [ASEF], pgvector, daemon de embed | **Max A** (10 slices) |
| 4 | **Busca & Ranking** | Encontrar o certo e ordenar por valor real: fusão híbrida, floor, feedback no score | [AHRI], [ACRS], [ACFQ], relevance floor, demotion | **Max B** (10) · v1 RAG-* |
| 5 | **Busca Agentic** | A busca que pensa: decompor a pergunta, buscar em passos, saber quando falta | [AARF], self-check de suficiência, hop-2 | **Max C** (7) |
| 6 | **Grafo de Conhecimento** | As conexões: código ↔ memória ↔ doc ↔ evidência, travessia e comunidades | [AURG], [AGRN], linkers, ponte Code Intelligence | **Max D** (9) · v1 RAG-07/08 |
| 7 | **Composição de Contexto** | Montar o pack perfeito: orçamento por valor, citabilidade, working set, camadas | [ACCR], [ACMF], [ATER], [ACPFR], context-pack | **Max E** (8) · v1 COM-* |
| 8 | **Compactação & Continuidade de Sessão** | Perder zero do que importa ao comprimir: conversa, handoff, longo-horizonte, payload, bootstrap de sessão | [ACIE], 4 mecanismos, [APCR], recovery executor | **Max F** (11) · v1 CPT-* |
| 9 | **Aprendizado por Execução** | Cada run real vira lição: outcome → destilação → delta → memória, feedback ranqueável | [AEMOR], [ARFL], Compounding, espinha OUTC | v1: FEE-*, OUTC-01 · fronteira futura |
| 10 | **Decisão** (o executivo) | O cérebro que escolhe sob incerteza: qual provider/músculo para cada tarefa, qual ação agora — alimentado por outcomes reais, pelo Modelo do Operador (15) e pelo Aprendizado (9). Quer aprender e mudar todo dia. O encanamento do spawn/transport é músculo; a ESCOLHA é cérebro | DECIDE (Atlas Decide, 11 facets), `atlas:atlas-decide:live-feedback` | v1 indireto · fronteira futura |
| 11 | **Evidência & Certificação Longitudinal** | A prova ao longo do tempo: ledger append-only, série diária, gates de 30 dias, Marco Zero | Evidence Ledger, [TEOS-I1], acos-delta-series, long-horizon gate | v1: EVI-01..09 |
| 12 | **Execução Verificada & Qualidade** | Provar que o que rodou rodou de verdade e com que qualidade | [AVCEL], [ACQCG], green-run receipts, selo de mint | v1: PIP-*, ENG-* (indireto) · fronteira futura |
| 13 | **Avaliação & Eficiência** | Medir tudo que importa, barato e contínuo: golden sets, latência real, custo, canários | [AREBA], [ACOP], [ARLCG], golden v2, floors de latência | **Max G** (10) |
| 14 | **Porta do Cérebro & Segurança de Provider** | Como o mundo consulta o Atlas sem vazar nada: MCP/CLI/hooks, projeções, provider-safety | Open Brain [AOBG], [ARPTL], projeções CLAUDE/AGENTS | v1: OPE-* · Max E (hooks) |
| 15 | **Modelo do Operador** | O que o cérebro sabe DE VOCÊ: preferências, estilo, prioridades, objetivos de pé — capturado, revisável e aplicado às decisões | Operator Intelligence (`app/Services/Ai/OperatorIntelligence/`: LearningGate, ProfilePolicyCompiler, SignalCapture), `atlas:operator-learning`, seção operator_profile do digest | **existe em código, sem plano de fronteira** — adicionada 12/07 (estava órfã da taxonomia) |
| 16 | **Consolidação & Verdade Temporal** | O "sono" do cérebro: o que acontece com o conhecimento depois de gravado — supersedência, decaimento, contradição ao longo do tempo, síntese de muitas memórias em poucas canônicas | campos temporais do schema (`valid_from/valid_until/superseded_by/stale_after/observed_at/authority_level`), `atlas:memory:judge`, world-model edges temporais | **schema pronto, disciplina sem dono** — adicionada 12/07; era a causa-raiz do corpus apodrecer |
| 17 | **Originação & Ambição** | A metade cognitiva da auto-construção: decidir O QUE o Atlas constrói em si mesmo a seguir — originar o próximo salto quando o reativo seca (faculdade de ambição, pétrea). A CONSTRUÇÃO em si (materializar/commitar) é músculo e fica fora | `atlas:brain:next` + seed-gate, gap-hunting (ledger de gaps), path de pesquisa (trendshift→github→arxiv), motor de meta-melhoramento (portfólio de paths) | **mecanismos vivos no cérebro do Autônomos** — adicionada 12/07 (a execução segue em consumer_modules, fronteira do scorecard v4 preservada) |
| 18 | **Governança & Constituição** (o judiciário) | As regras que LIMITAM qualquer decisor — floors fail-closed, charter de autonomia, envelope/escada de autonomia, receipts de decisão, rollback pré-declarado. Quer ser previsível e lenta para mudar. **Fronteira pétrea com a 10: quem decide nunca escreve as próprias regras** — mudança de governança é emenda (proposta etiquetada + reversível + rollback escrito antes), nunca decisão comum. Governança ≠ aprovação humana (charter): é constituição de máquina; o humano revisa depois | GOVERNANCE (4 facets), Decision Receipt v2, ConstitutionGate (SEV-1: religar!), `StewardshipAutonomyEnvelope`, `AutonomyLadderRuntimeService`, `HalfOpenRecloseDecision`, as 9 policies (fusão REFUTADA — deltas são o conteúdo) | **separada da 10 em 12/07** (lição do Constitution Gate SEV-1: governança como apêndice do decisor fica órfã) |

**Fundações transversais** (servem todas as áreas, não são área): runtime Python governado [APDR], watchdog unificado [WDG-01], snapshots do substrato [SUB-01], regras medidor≠produtor [MED-01] e rollback pré-declarado [ROL-01].

## Horizontes nomeados (capacidades dentro de áreas existentes, viram área quando tiverem ≥2 mecanismos vivos)

- **Proatividade/Antecipação** — prefetch de contexto da obra ativa, briefs antecipatórios; hoje TUDO é reativo-por-query. Vive em Busca Agentic (5) + Composição (7).
- **Multimodalidade** — imagem/áudio/PDF como memória e contexto; zero hoje; necessário quando os 15 domínios saírem de engenharia. Vive em Captura & Imunidade (2).
- **Modelo de Si** — o Atlas sabendo o que ELE MESMO comprovadamente consegue (≠ área 15, que é o modelo do OPERADOR): capability manifest, gap-ledger, causal self-model, calibração de confiança. Hoje = 5 fragmentos órfãos sem consumidor (auditoria 5-lentes 12/07); o programa ASI-Substrato F2 (ASI-12/13 no plano Max §viii) liga os fragmentos num contrato consumido pela Decisão (10) — quando ≥2 mecanismos vivos + consumidor real existirem, vira a área 19 por mérito. Até lá, vive entre Avaliação (13, que mede) e Decisão (10, que consome).

**Rejeitadas como área (com motivo, para não re-propor):**
- **Raciocínio generativo** — o cérebro JÁ raciocina do jeito auditável (travessia, contradição, julgamento, suficiência — áreas 5/6/9/10/16); o raciocínio GENERATIVO fica fora por três princípios pétreos: tudo no ACOS é auditável/reproduzível (geração não é — entra só depois de julgada); author≠judge (cérebro que gera E julga o próprio raciocínio quebra o floor); e a equação N×M (raciocínio generativo é o N que salta a cada provider — acoplá-lo congela o multiplicador; o cérebro guarda o M). MiniMax self-host futuro entra como MOTOR local residente atrás dos mesmos gates — não como área cognitiva.
- ~~Roteamento de provider~~ — **RESOLVIDO 12/07**: a DECISÃO de roteamento é cérebro e vive na área 10 (Atlas Decide); só o encanamento (transport/spawn/driver) é músculo.
- ~~Auto-construção~~ — **RESOLVIDO 12/07**: a metade cognitiva (originação/ambição) virou a área 17; a metade executora segue fora (consumer_modules).
- **Defesa adversarial** — é a razão de existir das áreas 2 e 14, não área própria.

## Fronteira ainda não mergulhada (candidatas a "Max fase 2")

Áreas 2 (Captura & Imunidade), 9 (Aprendizado por Execução — no nível de profundidade que o Max fez com busca), 10 (Decisão, incluindo Atlas Decide), 12 (Execução Verificada — tirar o AVCEL de shadow), **15 (Modelo do Operador)**, **16 (Consolidação & Verdade Temporal)**, **17 (Originação & Ambição)** e **18 (Governança & Constituição — religar o ConstitutionGate é dívida SEV-1 conhecida)**. O v1 cobre parte em largura; nenhuma tem ainda leitura de fronteira ao limite.

**Separação de poderes do cérebro (pétrea):** Decisão (10, executivo) propõe · Governança (18, constituição/judiciário) limita · Evidência (11, cartório) registra. É o author≠judge aplicado a quem manda.

## Referências

- Plano largura: `atlas-acos-excellence-10-10-plan-v1.md` (98 slices, 9 dimensões)
- Plano profundidade: `atlas-acos-max-frontier-plan-v1.md` (65 slices, áreas 3–8 e 13)
- Autoridade-mãe: `atlas-cognition-operating-system.md`
