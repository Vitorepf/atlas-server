---
id: atlas-vox-operational-thinking-interface
type: engineering_knowledge
title: Atlas Vox Operational Thinking Interface
status: active
category: architecture
priority: 98
summary: Contrato canonico do Atlas Vox como programa de voz, intencao, acao governada e memoria longitudinal, com objetivo final de virar interface de pensamento operacional do Atlas.
tags:
  - atlas-ai
  - atlas-vox
  - voice
  - intent
  - operational-thinking
  - qualitative-levels
capabilities:
  - atlas_vox_program
  - voice_to_intent
  - governed_voice_action
  - operational_thinking_interface
  - longitudinal_voice_memory
decisions:
  - Atlas Vox e o nome canonico do programa de voz do Atlas.
  - Voice Realtime Surface continua sendo a surface/runtime tecnica; Atlas Vox e a camada produto/arquitetura que transforma voz em intencao, acao governada e memoria.
  - O objetivo final de Atlas Vox e interface de pensamento operacional, nao ditado, assistente de voz generico ou controle cego do Mac.
  - Todo avanco de Vox deve preservar canal unico, Kernel soberano, receipts, privacy, eclipse e agencia humana.
  - Mac voice edge e controle ambiental so entram depois dos gates canonicos de Voice Realtime e privacy.
maintenance:
  - Atualizar antes de promover qualquer fase V2+ para implementacao.
  - Manter alinhado com atlas-ai-voice-realtime-surface.md, qualitative-levels e pipeline.
  - Rodar docs-health depois de alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/atlas-local-agent-surface.md
  - docs/engineering-knowledge-base/atlas-native-mac-agent.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-vox-operational-thinking-interface
graph_title: Atlas Vox Operational Thinking Interface
graph_world: atlas
graph_layer: flow
graph_kind: module
graph_parent: atlas-ai-pipeline
graph_status: active
graph_source: repo
owner: product-architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
allowed_changes:
  - Atualizar este doc quando estrategia, runtime de voz, pipeline, evidencia ou decisao canonica mudar.
forbidden_changes:
  - Declarar runtime, maturidade, always-on, controle do Mac ou memoria longitudinal sem evidencia verificavel e gates verdes.
depends_on:
  - atlas-ai-pipeline
  - atlas-ai-voice-realtime-surface
  - atlas-ai-qualitative-levels-roadmap
flows_to:
  - atlas-ai-pipeline
  - atlas-code
  - atlas-cognitive-runtime
  - atlas-cartography
unlocks:
  - voice-to-intent
  - governed-mac-control
  - operational-thinking-interface
governs:
  - voice
  - input
  - intent
  - surface
evidence:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - surface
  - voice
  - program
ai_entrypoints:
  - Leia Tese, Fronteiras, Escada V0-V10, Gates e Anti-padroes antes de implementar qualquer trabalho Vox.
ai_usage_notes:
  - Atlas Vox e programa de longo prazo. Implementacoes concretas devem passar pelo Pipeline, Voice Realtime Surface e receipts.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir Vox com ditado universal.
  - Criar runtime paralelo que pula Kernel.
  - Ativar presenca ambiental antes de opt-in, eclipse e no-surveillance.
  - Usar voz como autorizacao em vez de declaracao de intencao.
observability_signals:
  - VOX_* events
  - VOICE_* events
  - Decision Receipt por acao relevante
  - Rivals-Voice score
  - regret/friction score
  - prompt quality score
next_actions:
  - Criar AP/implementation packet para Vox Canonical Program Scaffold.
  - Mapear VOX_* event taxonomy.
  - Definir Vox Intent Packet v1 e Vox Thought Packet v1.
---
# Atlas Vox Operational Thinking Interface

Atlas Vox e a camada soberana de voz, intencao, acao governada e memoria
longitudinal do Atlas.

Frase canonica:
```text
Atlas Vox transforma fala humana em intencao estruturada, acao governada,
evidencia e memoria viva ate virar interface de pensamento operacional.
```
## Tese

Wispr Flow transforma voz em texto bom. Atlas Vox deve transformar voz em
trabalho cognitivo governado.
```text
fala bruta
-> transcript
-> intencao
-> contexto
-> policy
-> decision receipt
-> acao
-> evidence
-> memoria
-> melhoria do proximo pensamento
```
O objetivo final nao e "ditado melhor". O objetivo final e:
```text
Atlas Vox como interface de pensamento operacional.
```
Isso significa que Vitor pode pensar em voz, e o Atlas compila esse pensamento
em estruturas operacionais seguras: prompt, tarefa, investigacao, decisao,
comando, captura, proposta, work order ou memoria candidata.
## Fronteira Com Voice Realtime Surface

| Camada | Papel |
|---|---|
| Atlas Vox | Programa produto/arquitetura de voz-para-intencao-para-acao |
| Voice Realtime Surface | Surface tecnica mobile-first + LiveKit + runtime realtime |
| Atlas Pipeline | Caminho unico de Intent, Context, Policy, Decide, Gate, Evidence |
| Kernel Laravel | Autoridade soberana de policy, receipts, ledger e provider routing |
| Runtime Python/LiveKit | VAD, turn detection, STT/TTS, interruption e audio loop |
| Mac/Swift edge | Futuro edge local com wake word, AirPods, contexto opt-in e Mac control |

Regra:
```text
Vox declara intencao.
Kernel decide autoridade.
Runtime executa o que receipt permite.
Evidence prova.
Learning aprende somente dentro da policy.
```
## Leis Vox

1. Voz nao e autoridade; voz e declaracao de intencao.
2. Nenhuma acao relevante sem Decision Receipt.
3. Nenhum provider direto fora do Kernel.
4. Audio cru nao e memoria duravel por padrao.
5. Presenca ambiental exige opt-in, indicador claro e eclipse.
6. Controle do Mac exige policy por risco, confirmacao e receipt.
7. Terminal por voz nunca executa comando destrutivo sem revisao explicita.
8. Memoria longitudinal deve preservar agencia: observar drift, nunca corrigir identidade.
9. Esquecer precisa ser tao disponivel quanto lembrar.
10. Multiplicador negativo e stop-the-line.
## Escada Vox V0-V10

| Nivel | Nome | Sinal qualitativo | Status alvo |
|---|---|---|---|
| V0 | Dictation | fala vira texto | baseline |
| V1 | Prompt Polish | fala vira prompt melhor | utilidade inicial |
| V2 | Intent Compiler | fala vira intencao estruturada | primeiro salto real |
| V3 | Governed Executor | intencao vira acao com gates e receipts | operacional |
| V4 | Contextual Operator | Vox entende tela, projeto, terminal, estado e memoria | outro patamar inicial |
| V5 | Symbiotic Interlocutor | Vox questiona intencao com evidencia | salto Atlas |
| V6 | Ambient Cognitive Layer | Vox aparece no canal certo sem vigilancia | presenca governada |
| V7 | Longitudinal Voice Memory | padroes de fala/decisao viram memoria revisavel | espelho temporal |
| V8 | Cognitive Nervous System | Vox reconhece estado, risco e intencao incompleta | sistema nervoso cognitivo |
| V9 | Shared Inner Loop | Atlas participa do loop de formacao do pensamento | pensamento compartilhado |
| V10 | Sovereign Voice OS | voz opera IA, Mac, terminal, memoria e mundo digital sob governanca | interface de pensamento operacional |

V4/V5 definem "outro patamar" para Vox. V10 define o objetivo final.
## V2: Intent Compiler

Vox cria um `Vox Intent Packet`:
```text
schema: atlas.vox.intent_packet.v1
surface:
raw_transcript_ref:
clean_text:
intent_type:
goal:
context_refs:
constraints:
risk:
suggested_domain:
suggested_flow:
suggested_provider:
confirmation_required:
evidence_contract:
memory_candidate:
```
Tipos iniciais:

- `capture_thought`;
- `improve_prompt`;
- `coding_task`;
- `terminal_intent`;
- `mac_action`;
- `research`;
- `strategic_decision`;
- `daily_checkin`;
- `memory_update`;
- `proposal_request`.
## V3: Governed Executor

O Intent Packet entra no Atlas Pipeline.

Fluxo:
```text
Vox Intent Packet
-> Intent/Domain/Profile
-> Context Pack
-> Policy
-> Atlas Decide
-> Decision Receipt
-> Executor
-> Gate
-> Evidence
-> Learning
```
Categorias de risco:

| Risco | Exemplo | Comportamento |
|---|---|---|
| R0 read-only | resumir tela, melhorar prompt | executar ou mostrar resultado |
| R1 reversible | abrir app, criar nota | executar com undo/receipt |
| R2 state-changing | criar task, rodar comando seguro | mostrar plano ou pedir confirmacao |
| R3 destructive/external | apagar arquivo, git push, enviar email | confirmacao explicita + receipt |
| R4 critical | dinheiro, saude, credenciais, deploy prod | bloquear ou exigir fluxo dedicado |
## V4-V7: Outro Patamar Inicial

V4 Contextual Operator: entende "isso", "aqui", "esse erro", "esse projeto" e
"aquela decisao" por meio de workspace, arquivo, selecao, terminal, screenshot
opt-in, browser opt-in, memory refs, traces e Ledger quando policy permitir.

V5 Symbiotic Interlocutor: ganha direito governado de discordar. Ele nao
bloqueia por gosto; questiona com evidencia, policy ou padrao longitudinal.

- Discordancia deve citar evidencia ou policy.
- Vitor decide.
- Strategy permanece proposal-only.
- Sem gaslighting: Atlas observa drift, nao corrige identidade.

V6 Ambient Cognitive Layer: fica disponivel no canal certo: mobile, Mac
Desktop, terminal, AirPods, Watch futuro, StackChan futuro, browser/app
surfaces.
```text
available != always recording
```
Requisitos: opt-in explicito, indicador de escuta, hotkey/wake word local,
eclipse manual, quiet hours, privacy class por dominio, retention/forgetting e
no-surveillance default.

V7 Longitudinal Voice Memory: cria memoria revisavel sobre padroes de fala, nao
audio cru.
```text
schema: atlas.vox.thought_pattern.v1
pattern:
evidence_refs:
confidence:
domain:
risk:
suggested_intervention:
human_review_status:
forgetting_policy:
```
Nenhum padrao vira regra forte sem revisao humana quando afetar autonomia,
identidade, saude, dinheiro, relacoes ou estrategia.
## V8: Cognitive Nervous System

Vox reconhece estado operacional sem invadir privacidade.

Inferencias permitidas: baixa clareza por linguagem contraditoria, urgencia por
vocabulario, risco por tipo de acao, cansaço apenas com sinal autorizado e
tarefa grande sendo tratada como comando pequeno.

Proibido:

- diagnostico psicologico;
- monitoramento ambiente amplo por default;
- inferir estado sensivel sem base e sem incerteza;
- usar estado para controlar decisao.

Saida correta:
```text
"Parece modo pressa. Posso acelerar com um caminho seguro: diagnostico curto,
patch pequeno e gate. Quer esse fluxo?"
```
## V9: Shared Inner Loop

Vox participa da formacao do pensamento com intervencoes pequenas, raras e uteis.

Sinais:

- Vitor fala ideia incompleta;
- Atlas identifica forma conceitual;
- Atlas oferece estrutura sem roubar autoria;
- Atlas preserva voz original e versao estruturada;
- Atlas pergunta antes de transformar em plano.

Exemplo:
```text
"Parece camada, nao feature. Voce esta falando de entrada, intencao, policy e
memoria. Quer que eu estruture como modulo canonico?"
```
Medida de sucesso:

- mais clareza;
- menos retrabalho;
- Vitor sente autoria preservada;
- baixa taxa de interrupcao irritante;
- regret baixo em revisitas.
## V10: Sovereign Voice OS / Interface De Pensamento Operacional

Estado final:
```text
Vitor pensa em voz.
Atlas compila intencao.
Kernel governa autoridade.
Providers executam como motores.
Mac/terminal/apps viram atuadores.
Evidence prova.
Memory aprende.
Vitor mantem agencia.
```
Capacidades integradas: operar providers pelo canal unico, criar work orders,
iniciar investigacoes, operar terminal com confirmacao por risco, controlar Mac
com policy, capturar pensamento, revisar estrategia, propor discordancia, salvar
memoria revisavel, coordenar tasks longas, medir Rivals-Voice e reduzir
retrabalho/regret.

Definition of done de V10:

1. 80%+ dos comandos cognitivos diarios de Vitor podem entrar por Vox sem
   perda de qualidade.
2. Prompt quality assistido por Vox supera uso direto de ditado/provider em
   Rivals-Voice.
3. Tarefas operacionais por voz geram Decision Receipt e evidence completas.
4. Controle de terminal/Mac respeita risk gates e tem zero execucao destrutiva
   sem confirmacao.
5. Longitudinal memory gera propostas revisadas por humano com valor real.
6. Intervencoes discordantes reduzem retrabalho/regret sem reduzir agencia.
7. Eclipse/forgetting/privacy sao usados de verdade e auditaveis.
8. Vitor reconhece Vox como forma natural de pensar com Atlas, nao como app de
   ditado.
## Workstreams De Implementacao

| Workstream | Entrega |
|---|---|
| `vox-core` | Vox Intent Packet, Thought Packet, event taxonomy |
| `vox-surface` | UX push-to-talk, transcript, modes e status |
| `vox-compiler` | voice-to-intent, constraint extraction, risk classification |
| `vox-policy` | risk gates, confirmations, privacy class, eclipse |
| `vox-router` | Atlas Pipeline integration, domain/profile selection |
| `vox-terminal` | command proposal, explanation, confirmation, execution receipt |
| `vox-mac-edge` | future Swift edge, hotkey/wake word local, context opt-in |
| `vox-memory` | memory candidates, review, forgetting, longitudinal patterns |
| `vox-rivals` | Wispr/baseline/provider direct comparison, regret/friction metrics |
| `vox-cartography` | visual map of Vox sessions, intents, receipts and patterns |
## Eventos Minimos

`VOX_SESSION_STARTED`, `VOX_TRANSCRIPT_READY`, `VOX_INTENT_COMPILED`,
`VOX_POLICY_EVALUATED`, `VOX_CONFIRMATION_REQUESTED`, `VOX_ACTION_DISPATCHED`,
`VOX_ACTION_BLOCKED`, `VOX_EVIDENCE_RECORDED`,
`VOX_MEMORY_CANDIDATE_CREATED`, `VOX_DISCORDANCE_PROPOSED`,
`VOX_ECLIPSE_ACTIVATED`, `VOX_FORGETTING_REQUESTED` e
`VOX_RIVALS_CASE_RECORDED`.
## Metricas

| Metrica | Por que importa |
|---|---|
| speech_to_intent_p95 | friccao de pensamento |
| intent_accuracy_human_score | qualidade do compiler |
| prompt_quality_delta | multiplicador sobre ditado/provider direto |
| action_regret_score | se Vox causou acao ruim |
| interruption_usefulness_rate | calibragem do V5/V9 |
| confirmation_bypass_count | seguranca |
| destructive_action_without_receipt | deve ser zero |
| raw_audio_persisted_count | deve ser zero por default |
| memory_candidate_acceptance_rate | valor longitudinal |
| rivals_voice_multiplier | valida se Vox supera uso direto |
## Roadmap Governado

1. Canonical scaffold: este documento + indice + event taxonomy.
2. Vox Intent Packet v1 read-only: gerar pacote a partir de texto/transcript.
3. Prompt Compiler: transformar fala em prompt/brief/WorkOrder sem execucao.
4. Pipeline integration dry-run: Decision Receipt dry-run para intents Vox.
5. Mobile Voice Realtime Fase 0/1 conforme contrato existente.
6. Terminal proposal mode: comando sugerido, explicado e bloqueado por default.
7. Governed execution: confirmacao por risco + evidence.
8. Contextual operator: workspace, terminal, screenshot/selection opt-in.
9. Symbiotic discordance: policy/evidence-backed question layer.
10. Longitudinal memory: pattern candidates + human review.
11. Ambient channels: Mac/AirPods/Watch somente apos gates de privacy.
12. Operational thinking interface: V10 com Rivals positivo e agency preservada.
## Exemplos
```text
"Atlas, pega esse erro e pede pro Codex corrigir sem mexer no banco."
```
Vox resolve: erro = output/selection atual; Codex = provider; intent =
programming repair; constraint = sem schema/database; gate = testes/review;
evidence = patch + comandos.
```text
"Atlas, faz logo qualquer coisa ai."
```
Vox responde com discordancia util: "Parece modo pressa. Sugiro diagnostico
curto + patch pequeno + gate. Quer seguir?"
## Anti-Padroes

- "Vamos clonar Wispr Flow".
- Surface de voz chamando provider direto.
- Mac always-on antes de mobile-first, eclipse e privacy.
- Voz executando terminal como string literal.
- Salvar audio cru como memoria.
- Tratar fala emocional como diagnostico.
- Interromper muito e chamar isso de proatividade.
- Transformar discordancia em controle.
- Declarar V10 por demo bonita sem Rivals/evidence/regret.
## Resumo

Atlas Vox e o programa que leva o Atlas de voz como input para voz como
interface de pensamento operacional. Ele nasce subordinado ao Kernel, ao
Pipeline e ao Voice Realtime Surface, e so avanca de patamar com evidence,
Rivals, privacy, receipts e agencia humana preservada.
## Papel no Atlas

Vox e a camada de entrada cognitiva que transforma pensamento falado em
unidades operacionais governadas.
## Onde Se Encaixa

Vox fica acima das surfaces tecnicas de voz e abaixo da tese/camadas soberanas:
ele chama o Pipeline, nao o substitui.
## Contratos

Os contratos iniciais sao Vox Intent Packet, Vox Thought Pattern, VOX_* events,
risk gates, Decision Receipt e Rivals-Voice.
## Fluxo

O fluxo canonico e fala -> transcript -> intent packet -> pipeline -> receipt
-> executor -> gate -> evidence -> learning -> output.
## Regras para IA

Agentes nao devem implementar runtime, Mac control, always-on, provider direto
ou memoria longitudinal sem antes verificar Voice Realtime Surface, Pipeline,
privacy, receipts e gates.
## Escopo de Implementacao

Este documento autoriza planejamento e pacotes de implementacao. Implementacao
real deve ser feita por AP/packets menores com write set e evidence.
## Dependencias

Depende de Voice Realtime Surface, Pipeline, Qualitative Levels, Kernel,
Evidence Ledger, Cognitive Runtime e privacy/eclipsemodes.
## Evidencias

Evidencias aceitas: docs, receipts, VOX_*/VOICE_* events, Rivals-Voice, SLOs,
gates de privacy, screenshots/recordings redigidos quando necessario e reports.
## Riscos

Os maiores riscos sao vigilancia, autonomia sem Kernel, dependencia cognitiva,
execucao destrutiva por voz, memoria invasiva e demos sem evidência.
## Proximas Acoes

1. Criar AP para Vox Canonical Program Scaffold.
2. Definir `atlas.vox.intent_packet.v1` e `atlas.vox.thought_pattern.v1`.
3. Implementar compiler read-only de texto/transcript para Intent Packet.
4. Ligar dry-run ao Pipeline/Decision Receipt sem executar acao.
5. Criar Rivals-Voice baseline contra ditado/provider direto.
