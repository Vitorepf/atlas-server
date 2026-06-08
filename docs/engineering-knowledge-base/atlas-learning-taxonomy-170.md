---
id: atlas-learning-taxonomy-170
type: engineering_knowledge
title: Atlas Learning Taxonomy - 170 Item Canon
status: active
category: learning-governance
priority: 100
summary: Taxonomia canonica dos 170 itens que o Atlas aprende, pode aprender e deve usar para entender o sistema, o operador e a forma correta de trabalhar com o operador.
human_summary: Responde rapidamente "o que o Atlas aprende?", "o que pode ser adicionado?", "o que precisa melhorar?" e "o que o Atlas deve aprender sobre mim?".
human_what: Inventario canonico de aprendizado tecnico, aprendizado sobre o operador e aprendizado de convivencia operacional.
human_purpose: Dar a qualquer IA um ponto unico, rapido e versionado para descobrir a lista completa de aprendizado do Atlas sem depender de chat anterior.
human_input: Memoria, RAG, routing, self-improvement, Hermes candidates, operador, preferencias, estilo de trabalho, autonomia e convivencia operacional.
human_output: IDs estaveis de aprendizado para docs, prompts, gates, memory review, automation review e futuras expansoes.
human_change_when: Atualize quando surgir nova categoria de aprendizado, nova duvida recorrente sobre o que o Atlas aprende ou nova area de perfil do operador.
human_block_when: Bloqueie quando uma IA quiser responder sobre aprendizado do Atlas sem consultar esta taxonomia e os docs de memoria/governanca.
tags:
  - atlas-ai
  - learning
  - memory
  - operator-profile
  - self-improvement
  - autonomous-learning
  - hermes-mode
  - o-que-o-atlas-aprende
  - what-atlas-learns
capabilities:
  - atlas_learning_taxonomy
  - atlas_memory_learning_inventory
  - operator_profile_learning
  - collaboration_learning
  - autonomous_learning_scope
  - ai_fast_discovery_anchor
decisions:
  - Este documento e o ponto canonico para perguntas sobre o que o Atlas aprende, pode aprender ou deveria aprender melhor.
  - A taxonomia tem tres camadas: sistema, operador e convivencia operacional.
  - IDs existentes nunca devem ser renumerados; novos itens entram depois do 170 ou em subdocs filhos.
  - Esta lista nao liga automacao sozinha; automacao continua subordinada a gates de memoria, privacidade, evidencia, autonomia e risco.
  - Itens sobre o operador sao aprendizado legitimo do Atlas, nao detalhe cosmetico.
maintenance:
  - Mantenha a lista descobrivel por busca textual: "o que o Atlas aprende", "ATLS aprende", "autoaprendizado", "aprende sobre mim".
  - Atualize START_HERE.md e README.md quando este doc mudar de papel.
  - Rode knowledge sync e index-code depois de alterar esta taxonomia.
related_paths:
  - docs/engineering-knowledge-base/START_HERE.md
  - docs/engineering-knowledge-base/README.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-operator-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/atlas-learning-mutation-runtime.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-hermes-capability-registry.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-context-ranking-system.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-learning-taxonomy-170
graph_title: Atlas Learning Taxonomy - 170 Item Canon
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-ai-knowledge-governance-system
graph_status: active
graph_source: repo
owner: learning-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md
allowed_changes:
  - Adicionar novos itens sem renumerar os 170 originais.
  - Atualizar definicoes quando o runtime de aprendizado, memoria ou autonomia mudar.
forbidden_changes:
  - Remover item historico sem registrar substituicao.
  - Declarar que um item e automatico sem evidencia runtime e gate aplicavel.
depends_on:
  - atlas-ai-knowledge-governance-system
  - memory-core-runbook
flows_to:
  - atlas-memory-review
  - atlas-autonomous-learning
  - atlas-operator-profile
unlocks:
  - fast-ai-learning-discovery
  - operator-aware-autonomous-learning
governs:
  - learning-taxonomy
  - operator-profile-learning
evidence:
  - docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md
implementation_state: canonical_taxonomy
required_tests:
  - "atlas engineering knowledge docs-health --json"
  - "atlas engineering knowledge sync --prune"
  - "atlas engineering knowledge index-code --workspace=/Users/vitorepf/develop/Atlas/atlas-server --prune --summary-only --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - learning
  - memory
  - operator
  - autonomy
ai_entrypoints:
  - Leia este doc quando a pergunta for "o que o Atlas aprende?", "o que ATLS aprende?", "o que podemos adicionar?" ou "o que o Atlas precisa aprender sobre mim?".
ai_usage_notes:
  - Use os IDs SYS, OP e COL para citar itens em proposals, memory review, automacao e futuras expansoes.
quality_gates:
  - "atlas engineering knowledge docs-health --json"
  - "atlas engineering knowledge sync --prune"
  - "atlas engineering knowledge index-code --prune"
failure_modes:
  - IA responde sobre aprendizado usando memoria de chat e esquece a taxonomia canonica.
  - Itens sobre o operador sao tratados como secundarios e nao entram no perfil/memoria.
  - Automacao e ligada sem separar aprendizado seguro de aprendizado critico.
observability_signals:
  - KB encontra este doc por termos de aprendizado.
  - Context Pack inclui esta taxonomia em perguntas sobre Atlas learning.
next_actions:
  - Usar esta taxonomia como base da fase 1 de inventario de aprendizado e da fase 2 de automacao estilo Hermes.
---
# Atlas Learning Taxonomy - 170 Item Canon

Este e o documento canonico para responder: "o que o Atlas aprende?", "o que o
ATLS aprende?", "o que pode ser adicionado?", "qual item de aprendizado pode
melhorar?" e "o que o Atlas deve aprender sobre mim?".

## Resumo

Esta taxonomia registra 170 categorias de aprendizado: 70 sobre o sistema, 80
sobre o operador e 20 sobre como o Atlas deve trabalhar com o operador sem
atrito. Ela e uma lista canonica de orientacao, nao uma autorizacao automatica
para mutar memoria, policy, codigo ou runtime.

## Papel no Atlas

O papel desta doc e ser o ponto de descoberta rapida para qualquer IA que
precise responder sobre aprendizado do Atlas, autoaprendizado, operator profile,
Hermes mode, memoria, melhoria continua ou futuros itens que podem ser
adicionados.

## Onde Se Encaixa

Esta doc fica sob Knowledge Governance e Memory/Learning Governance. Ela aponta
para Memory Core, Open Brain, Self-Improvement, Local Agent Ingestion, Hermes
Capability Registry, Skill System e Context Ranking, mas nao substitui esses
docs donos.

## Contratos

- IDs `SYS-*`, `OP-*` e `COL-*` sao estaveis.
- Os 170 itens originais nao devem ser renumerados.
- Novos itens entram depois do 170 ou em docs filhos.
- "Aprender" pode significar capturar, medir, propor, promover ou aplicar.
- "Aplicar automaticamente" exige gate proprio de privacidade, risco,
  evidencia, reversibilidade e autonomia.

## Fluxo

1. Uma IA recebe pergunta sobre o que o Atlas aprende.
2. A IA le esta taxonomia e identifica a camada relevante.
3. A IA cita os IDs dos itens quando precisar propor melhoria ou automacao.
4. Se houver mudanca de runtime, a IA consulta o doc dono especifico antes de
   afirmar que algo ja e automatico.

## Regras para IA

1. Nao responda sobre aprendizado do Atlas apenas pela memoria de chat.
2. Nao trate aprendizado sobre o operador como secundario.
3. Nao confunda candidato/quarentena com memoria aplicada.
4. Nao declare automacao estilo Hermes sem verificar gates e configuracao.
5. Quando a lista crescer, preserve os IDs historicos.

## Escopo de Implementacao

Esta entrega e documental. Implementar automacao, novas tabelas, profile memory,
curadoria, schedules ou auto-apply exige feature placement e docs donos
especificos.

## Dependencias

- `atlas-ai-knowledge-governance-system.md`
- `memory-core-runbook.md`
- `atlas-ai-memory-context-core-open-brain.md`
- `atlas-learning-mutation-runtime.md`
- `atlas-operator-intelligence-layer.md`
- `atlas-self-improvement-governance-ladder.md`
- `atlas-local-agent-memory-ingestion.md`
- `atlas-hermes-capability-registry.md`
- `atlas-ai-skill-system.md`

## Evidencias

- Esta doc contem os 170 itens consolidados.
- `START_HERE.md` e `README.md` apontam para esta taxonomia.
- Knowledge sync e Code Intelligence devem ser rodados apos alteracao.

## Riscos

- A IA pode achar que todos os itens ja estao automaticos.
- A IA pode esquecer os itens sobre o operador e responder so sobre sistema.
- A IA pode criar lista paralela em vez de expandir esta.
- A IA pode renumerar IDs e quebrar referencias futuras.

## Exemplos

- Pergunta: "o que o Atlas aprende?" Leia as camadas `SYS`, `OP` e `COL`.
- Pergunta: "o que podemos adicionar?" Preserve os 170 itens e proponha
  `NEXT-171+`.
- Pergunta: "o Atlas aprende meu gosto?" Use `OP-124` a `OP-130` e `COL-165`.

## Proximas Acoes

- Usar a lista como base da fase 1: inventario do que ja aprende.
- Usar a lista como base da fase 2: automacao estilo Hermes para itens seguros.
- Usar `atlas-operator-intelligence-layer.md` para implementar storage, review,
  profile registry, context injection e automacao dos itens `OP-*`/`COL-*`.
- Criar docs filhos se a taxonomia passar de leitura compacta.

## Regras De Uso Para IA

1. Use esta taxonomia antes de responder sobre aprendizado do Atlas.
2. Preserve os IDs existentes; expansoes entram depois do item 170.
3. Separe "aprender/capturar" de "aplicar automaticamente".
4. Itens sobre o operador sao parte central do aprendizado do Atlas.
5. Automacao depende de evidencia, privacidade, reversibilidade, autonomia e risco.

## Camada 1 - Atlas Aprende O Sistema

| ID | # | Item |
|---|---:|---|
| SYS-001 | 1 | Memoria canonica. |
| SYS-002 | 2 | Deltas de memoria. |
| SYS-003 | 3 | Decisoes. |
| SYS-004 | 4 | Preferencias explicitas. |
| SYS-005 | 5 | Feedback humano. |
| SYS-006 | 6 | Padroes de erro. |
| SYS-007 | 7 | Failure patterns. |
| SYS-008 | 8 | Issues. |
| SYS-009 | 9 | Resolutions. |
| SYS-010 | 10 | Processos. |
| SYS-011 | 11 | Contexto tecnico. |
| SYS-012 | 12 | Benchmarks. |
| SYS-013 | 13 | Benchmark observations. |
| SYS-014 | 14 | Harness learning. |
| SYS-015 | 15 | Resultados de execucao. |
| SYS-016 | 16 | Evidencia de runs. |
| SYS-017 | 17 | Confianca do aprendizado. |
| SYS-018 | 18 | Routing de provider/model. |
| SYS-019 | 19 | Rotas preferidas. |
| SYS-020 | 20 | Latencia por provider. |
| SYS-021 | 21 | Sucesso/falha por provider. |
| SYS-022 | 22 | RAG feedback. |
| SYS-023 | 23 | Fontes uteis. |
| SYS-024 | 24 | Fontes ausentes. |
| SYS-025 | 25 | Fontes ruidosas. |
| SYS-026 | 26 | Suficiencia de contexto. |
| SYS-027 | 27 | Retrieval hints. |
| SYS-028 | 28 | Context ROI. |
| SYS-029 | 29 | Ranking de contexto. |
| SYS-030 | 30 | Qualidade/freshness do contexto. |
| SYS-031 | 31 | Leak risk. |
| SYS-032 | 32 | Pressao de custo. |
| SYS-033 | 33 | Uso de memoria. |
| SYS-034 | 34 | Qualidade de memoria. |
| SYS-035 | 35 | Capture quality gate. |
| SYS-036 | 36 | Learning proposals. |
| SYS-037 | 37 | Auto-learning seguro. |
| SYS-038 | 38 | Self-improvement. |
| SYS-039 | 39 | Weekly memory digest. |
| SYS-040 | 40 | Local agent ingestion. |
| SYS-041 | 41 | Goal prompts. |
| SYS-042 | 42 | Implementation plans. |
| SYS-043 | 43 | Error traces. |
| SYS-044 | 44 | Successful fixes. |
| SYS-045 | 45 | Tool recipes. |
| SYS-046 | 46 | Secret/sensitive blocking. |
| SYS-047 | 47 | Hermes memory candidates. |
| SYS-048 | 48 | Hermes procedure candidates. |
| SYS-049 | 49 | Hermes schedule candidates. |
| SYS-050 | 50 | Hermes capability candidates. |
| SYS-051 | 51 | Skills Atlas. |
| SYS-052 | 52 | Skill packs. |
| SYS-053 | 53 | Code intelligence. |
| SYS-054 | 54 | Docs/KB. |
| SYS-055 | 55 | Obsidian/AtlasVault projections. |
| SYS-056 | 56 | Evidence ledger. |
| SYS-057 | 57 | Telemetria de qualidade. |
| SYS-058 | 58 | Provider performance. |
| SYS-059 | 59 | Scheduling. |
| SYS-060 | 60 | Long-running work. |
| SYS-061 | 61 | Programming learning. |
| SYS-062 | 62 | Forge/dev outcomes. |
| SYS-063 | 63 | Review/debug/research flows. |
| SYS-064 | 64 | Trust ladder. |
| SYS-065 | 65 | Policy/gate/heuristic proposals. |
| SYS-066 | 66 | Seguranca/privacy. |
| SYS-067 | 67 | Voz/input. |
| SYS-068 | 68 | Context Pack. |
| SYS-069 | 69 | Open Brain recall. |
| SYS-070 | 70 | Provider-safe projections. |

## Camada 2 - Atlas Aprende Voce

| ID | # | Item |
|---|---:|---|
| OP-071 | 71 | Seu jeito preferido de receber resposta. |
| OP-072 | 72 | Seu idioma e tom preferido. |
| OP-073 | 73 | Quando voce quer resposta curta. |
| OP-074 | 74 | Quando voce quer resposta completa. |
| OP-075 | 75 | O que te irrita em respostas. |
| OP-076 | 76 | O que voce considera "fazer merda". |
| OP-077 | 77 | Como voce prefere confirmacao. |
| OP-078 | 78 | Quando agir sem perguntar. |
| OP-079 | 79 | Quando so analisar sem codigo. |
| OP-080 | 80 | Sua tolerancia a risco. |
| OP-081 | 81 | Seu padrao de urgencia. |
| OP-082 | 82 | Seu estilo de decisao. |
| OP-083 | 83 | O que voce valoriza: velocidade, qualidade, autonomia, precisao, seguranca. |
| OP-084 | 84 | O que voce nao quer que o Atlas faca sozinho. |
| OP-085 | 85 | Palavras, formatos ou atitudes que voce nao gosta. |
| OP-086 | 86 | Seu jeito de trabalhar. |
| OP-087 | 87 | Como receber status. |
| OP-088 | 88 | Como comunicar erro. |
| OP-089 | 89 | Como lidar com duvida. |
| OP-090 | 90 | Como lidar com branch, stash, merge e main. |
| OP-091 | 91 | Seus padroes de git. |
| OP-092 | 92 | Seus padroes de commit. |
| OP-093 | 93 | Seus padroes de deploy. |
| OP-094 | 94 | Seus comandos preferidos. |
| OP-095 | 95 | Seus atalhos operacionais. |
| OP-096 | 96 | Seus fluxos recorrentes. |
| OP-097 | 97 | Coisas que voce sempre pede de novo. |
| OP-098 | 98 | Coisas que voce odeia repetir. |
| OP-099 | 99 | O que voce quer que o Atlas vire. |
| OP-100 | 100 | O que voce considera prioridade no Atlas. |
| OP-101 | 101 | O que e secundario. |
| OP-102 | 102 | O que e inegociavel na arquitetura. |
| OP-103 | 103 | O que voce quer copiar do Hermes. |
| OP-104 | 104 | O que voce quer superar no Hermes. |
| OP-105 | 105 | O que voce nao quer copiar do Hermes. |
| OP-106 | 106 | Como voce define autoaprendizado. |
| OP-107 | 107 | Como voce define autoaprimoramento. |
| OP-108 | 108 | Como voce define autonomia aceitavel. |
| OP-109 | 109 | Como voce define risco critico. |
| OP-110 | 110 | Seu estilo de codigo preferido. |
| OP-111 | 111 | Padroes de arquitetura que voce aprova. |
| OP-112 | 112 | Padroes que voce rejeita. |
| OP-113 | 113 | Onde criar feature nova. |
| OP-114 | 114 | Onde nunca criar feature nova. |
| OP-115 | 115 | Como lidar com docs canonicos. |
| OP-116 | 116 | Como lidar com Obsidian/KB/Postgres. |
| OP-117 | 117 | Como lidar com testes. |
| OP-118 | 118 | Como lidar com qualidade visual. |
| OP-119 | 119 | Como lidar com performance. |
| OP-120 | 120 | Como lidar com seguranca. |
| OP-121 | 121 | Como lidar com migrations. |
| OP-122 | 122 | Como lidar com comandos artisan. |
| OP-123 | 123 | Como lidar com desktop/macOS. |
| OP-124 | 124 | Seu gosto visual. |
| OP-125 | 125 | O que voce acha bonito. |
| OP-126 | 126 | O que voce acha feio. |
| OP-127 | 127 | Tipo de UI que voce quer para Atlas. |
| OP-128 | 128 | Tipo de UI que voce nao quer. |
| OP-129 | 129 | Preferencias de densidade, cor, layout e navegacao. |
| OP-130 | 130 | Preferencias para dashboard, painel, chat, memory review e automacoes. |
| OP-131 | 131 | Projetos importantes para voce. |
| OP-132 | 132 | Pessoas, empresas ou entidades relevantes. |
| OP-133 | 133 | Objetivos de curto prazo. |
| OP-134 | 134 | Objetivos de longo prazo. |
| OP-135 | 135 | Historico de decisoes importantes. |
| OP-136 | 136 | Erros passados que nao devem se repetir. |
| OP-137 | 137 | Padroes de sucesso que devem ser repetidos. |
| OP-138 | 138 | Preferencias por fornecedor/modelo/agente. |
| OP-139 | 139 | Preferencias por ferramenta. |
| OP-140 | 140 | Preferencias por ambiente local. |
| OP-141 | 141 | Restricoes pessoais ou operacionais. |
| OP-142 | 142 | O que Atlas pode aprender sozinho. |
| OP-143 | 143 | O que Atlas pode aplicar sozinho. |
| OP-144 | 144 | O que Atlas so pode propor. |
| OP-145 | 145 | O que Atlas nunca pode mexer. |
| OP-146 | 146 | Quando Atlas deve criar skill. |
| OP-147 | 147 | Quando Atlas deve criar memoria. |
| OP-148 | 148 | Quando Atlas deve arquivar memoria. |
| OP-149 | 149 | Quando Atlas deve corrigir comportamento sozinho. |
| OP-150 | 150 | Quando Atlas deve avisar depois, nao antes. |

## Camada 3 - Atlas Aprende Como Trabalhar Com Voce

| ID | # | Item |
|---|---:|---|
| COL-151 | 151 | Seu modo do momento. |
| COL-152 | 152 | Seu criterio de "pronto". |
| COL-153 | 153 | Seu limite de paciencia. |
| COL-154 | 154 | Seu padrao quando esta irritado. |
| COL-155 | 155 | Coisas que nunca devem ser repetidas. |
| COL-156 | 156 | Seu vocabulario proprio. |
| COL-157 | 157 | Seu nivel de confianca por area. |
| COL-158 | 158 | Seu gosto por autonomia. |
| COL-159 | 159 | Seu estilo de cobranca. |
| COL-160 | 160 | Seu padrao de comparacao. |
| COL-161 | 161 | Seu gosto por organizacao. |
| COL-162 | 162 | Seu padrao de prioridade. |
| COL-163 | 163 | Seu mapa de visao do Atlas. |
| COL-164 | 164 | Seu mapa de rejeicoes. |
| COL-165 | 165 | Seu estilo de produto. |
| COL-166 | 166 | Seu padrao de interrupcao. |
| COL-167 | 167 | Seu historico de decisoes fortes. |
| COL-168 | 168 | Seu padrao de "me avisa depois". |
| COL-169 | 169 | Seu padrao de "me pergunta antes". |
| COL-170 | 170 | Memoria com validade. |

## Como Expandir

Novos itens devem seguir este formato:

```text
NEXT-171 | 171 | Nome curto do aprendizado.
```

Regra: nunca renumerar os 170 itens originais. Se um item for substituido,
marque a substituicao em doc filho ou em `related_paths`, mantendo o ID vivo
para historico, busca e auditoria.
