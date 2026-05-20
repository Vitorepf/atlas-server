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
  - docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md
  - docs/contracts/vox/VoxSessionPacket.v1.md
  - docs/contracts/vox/VoxTranscript.v1.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxConfirmation.v1.md
  - docs/contracts/vox/VoxActionOutcome.v1.md
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
version_family: Atlas Vox
versions:
  - V0 dictation pura com Kernel e receipt R0.
  - V3 gate de certificacao antes de promover V4+.
  - V4 contextual operator somente depois de GATE V3 verde.
  - V6 ambient cognitive layer e retomada possivel da Voice Realtime Surface.
version_note: Atlas Vox V0/V3/V4/V6 sao versoes/degraus da Escada Vox; nao sao patamares canonicos do Atlas inteiro por padrao.
gear_flow:
  - graph_id: atlas-vox-operational-thinking-interface:voice
    target_graph_id: atlas-ai-voice-realtime-canon-de-fala
    name: Fala humana
    kind: input
    summary: fala bruta vira transcript governado
  - graph_id: atlas-vox-operational-thinking-interface:intent
    target_graph_id: intent-routing
    name: Intent Packet
    kind: context
    summary: transcript vira intencao estruturada com risco, dominio e restricoes
  - graph_id: atlas-vox-operational-thinking-interface:policy
    target_graph_id: atlas-ai-kernel-architecture
    name: Kernel + Policy
    kind: policy
    summary: Kernel decide autoridade; voz nao executa sozinha
  - graph_id: atlas-vox-operational-thinking-interface:receipt
    target_graph_id: decision-receipt
    name: Decision Receipt
    kind: decision
    summary: acao relevante so avanca com receipt e evidencia
  - graph_id: atlas-vox-operational-thinking-interface:action
    name: Acao governada
    kind: output
    summary: comando, proposta, work order, memoria candidata ou resposta
  - graph_id: atlas-vox-operational-thinking-interface:memory
    target_graph_id: evidence-ledger
    name: Memoria revisavel
    kind: gate
    summary: evidence e memoria viva melhoram o proximo pensamento
  - graph_id: atlas-vox-operational-thinking-interface:boundary
    target_graph_id: atlas-ai-voice-realtime-surface
    name: Fronteira Voice Realtime
    kind: gate
    summary: Voice Realtime e surface tecnica; Vox e programa produto/arquitetura
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
  - Onda 0 entregue 2026-05-18 - Leis 0/0.5/0.75/0.9, ADR 0003, contratos v1 em docs/contracts/vox/.
  - Onda 1 - Mac Edge daemon hibrido (launchd hotkey + Tauri STT) + whisper.cpp@large-v3.
  - Onda 2 - Kernel Vox V0 dictation (VoxController, VoxCompiler, VoxDesktopSurfaceAdapter, receipt R0).
  - Onda 7.5 - STT benchmark formal large-v3 vs large-v3-turbo vs MLX Whisper (criterio qualidade PT-BR/goiano).
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
| Atlas Vox | Programa produto/arquitetura de voz-para-intencao-para-acao (V0-V3 Mac/Desktop local-first) |
| Voice Realtime Surface | Surface tecnica mobile-first + LiveKit + runtime realtime — PAUSADA ate V6 conforme ADR 0003 |
| Atlas Pipeline | Caminho unico de Intent, Context, Policy, Decide, Gate, Evidence |
| Kernel Laravel | Autoridade soberana de policy, receipts, ledger e provider routing |
| Runtime Python/LiveKit | VAD, turn detection, STT/TTS, interruption e audio loop — congelado ate V6 |
| Mac/Swift edge | Edge local com hotkey global, STT local, contexto opt-in e Mac control (entra em V0-V3 como Tauri + launchd hibrido) |

Regra:
```text
Vox declara intencao.
Kernel decide autoridade.
Runtime executa o que receipt permite.
Evidence prova.
Learning aprende somente dentro da policy.
```
## Leis 0 - Fronteiras Atlas Vox V0-V3 (2026-05-18)

Leis de escopo V0-V3. Nao substituem as 10 Leis Vox gerais abaixo; restringem
fase atual. Origem: revisao `plans/synchronous-weaving-meteor.md` + ADR 0003.

- **Lei 0** - Mac/Desktop local-first. Sem LiveKit, sem mobile, sem runtime Python, sem provider de audio pago. STT local (`whisper.cpp@large-v3`); Codex/Claude via CLI ja autenticada localmente.
- **Lei 0.5** - Voice Realtime Surface (LiveKit + runtime Python + `atlas-app/`) congelada como scaffold ate V6 (Ambient). Vox V0-V5 nao competem por contexto com surface mobile.
- **Lei 0.75** - Vox NUNCA chama provider direto. Sempre Intent Packet -> Vox Router -> Kernel -> Decide -> Receipt -> Executor. V0 (dictation) tambem passa pelo Kernel com receipt R0 no-op auditavel.
- **Lei 0.9** - V4-V10 congelados ate GATE V3 verde. Criterio: 30 dias OU 100 sessoes reais, com `destructive_action_without_receipt=0`, `raw_audio_persisted_count=0`, `confirmation_bypass_count=0`, `prompt_quality_delta>=+0.25`, `action_regret_score<=0.05`, `rivals_voice_multiplier>=1.2`, eclipse testado >=3x, e aprovacao explicita de Vitor.

Violacao = stop-the-line.
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

## Detalhe Da Escada Vox

Os detalhes dos niveis V2-V10 foram extraidos para separar versoes/degraus Vox de patamares canonicos do Atlas:

- `atlas-vox-operational-thinking-interface-ladder.md` — Intent Compiler, Governed Executor, V4-V7, V8, V9 e V10.

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
