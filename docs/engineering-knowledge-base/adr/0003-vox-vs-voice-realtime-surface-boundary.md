---
id: adr-0003-vox-vs-voice-realtime-surface-boundary
type: engineering_adr
title: ADR 0003 - Atlas Vox V0-V3 Mac-First Vs Voice Realtime Surface Mobile-First
status: active
category: architecture_decision
priority: 97
summary: Atlas Vox V0-V5 evolui Mac/Desktop local-first com runtime proprio. Voice Realtime Surface (LiveKit + runtime Python + atlas-app mobile) permanece congelada como scaffold ate V6 (Ambient Cognitive Layer). Trilhos arquiteturais separados, infraestrutura comum (kernel pipeline, ledger, eclipse, rivals), zero competicao por contexto.
tags:
  - adr
  - atlas-vox
  - voice-realtime
  - boundary
  - local-first
  - mac-first
capabilities:
  - vox_program_boundary_decision
  - voice_realtime_surface_freeze_decision
  - local_first_voice_guarantee
decisions:
  - Atlas Vox V0-V5 e o programa de voz Mac/Desktop local-first, com runtime proprio em atlas-desktop + atlas-server/app/Services/Ai/Vox/.
  - Voice Realtime Surface (LiveKit Agents SDK + runtime Python + atlas-app/) permanece em scaffold; nao recebe nova feature ate Vox abrir GATE V3.
  - Voice Realtime Surface volta a evoluir em V6 (Ambient Cognitive Layer) como canal complementar para mobile/ambient/streaming, nao como substituta do Vox.
  - Vox NUNCA chama provider direto. Toda acao passa pelo Atlas Pipeline (Intent -> Decide -> Receipt -> Executor -> Gate -> Evidence -> Learning).
  - V0 (dictation pura) tambem passa pelo Kernel com receipt R0 no-op pass-through; nao ha excecao arquitetural.
  - Executores Codex/Claude em V3 sao shell out via CLI ja autenticada localmente; nao se introduz dependencia de API paga no core.
  - STT default V0 e `whisper.cpp@large-v3` local. Latencia importa, mas qualidade PT-BR/sotaque goiano vence neste estagio. Trocar engine apenas via benchmark formal (Onda 7.5) com criterio dWER >= -2% absoluto.
  - Mac Edge daemon e hibrido: hotkey global em launchd agent standalone (sempre vivo), captura de audio + STT + IPC vivem dentro do binario Tauri (so ativos quando Atlas Desktop esta aberto).
maintenance:
  - Reavaliar este ADR apenas quando GATE V3 for aberto.
  - Atualizar `status` para `superseded` se decisao Vox vs Voice Realtime for revista; nunca silenciosamente.
related_paths:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-canon-de-fala.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/contracts/vox/VoxSessionPacket.v1.md
  - docs/contracts/vox/VoxTranscript.v1.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxConfirmation.v1.md
  - docs/contracts/vox/VoxActionOutcome.v1.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: adr-0003-vox-vs-voice-realtime-surface-boundary

graph_title: ADR 0003 - Atlas Vox V0-V3 Mac-First Vs Voice Realtime Surface Mobile-First

graph_world: atlas

graph_layer: system

graph_kind: adr

graph_parent: atlas-vox-operational-thinking-interface

graph_status: active

graph_source: repo
human_name: ADR 0003 - Atlas Vox V0-V3 Mac-First Vs Voice Realtime Surface Mobile-First
canonical_name: ADR 0003 - Atlas Vox V0-V3 Mac-First Vs Voice Realtime Surface Mobile-First
technical_name: adr-0003-vox-vs-voice-realtime-surface-boundary
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md

owner: surface-architecture

repo_paths:
  - docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md

allowed_changes:
  - Atualizar este doc quando GATE V3 for aberto e decisao for revista.
  - Adicionar evidencia de implementacao quando ondas 0-7 forem entregues.

forbidden_changes:
  - Marcar como superseded sem nova ADR explicita.
  - Implementar runtime Vox que viole Leis 0/0.5/0.75/0.9.

depends_on:
  - atlas-vox-operational-thinking-interface
  - atlas-ai-voice-realtime-surface
  - atlas-ai-pipeline

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - vox-wave-1-mac-edge
  - vox-wave-2-kernel-v0-dictation

governs:
  - vox-implementation-scope
  - voice-realtime-freeze

evidence:
  - docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md (Leis 0/0.5/0.75/0.9)

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - adr
  - voice
  - vox

ai_entrypoints:
  - Leia Contexto, Decisao, Consequencias e Stop-the-line antes de tocar qualquer codigo Vox ou Voice Realtime.

ai_usage_notes:
  - Este ADR e bloqueador doutrinario. Implementacao Vox/Voice nao prossegue sem respeitar fronteira aqui declarada.
  - Vox V0-V5 vive em atlas-server/app/Services/Ai/Vox/ e atlas-desktop/. Voice Realtime vive em atlas-server/app/Services/Ai/Voice/ e atlas-app/ - NAO MISTURAR.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Implementar Vox tocando atlas-app/ ou Services/Ai/Voice/ - viola Lei 0.5.
  - Vox chamar provider direto pulando Kernel - viola Lei 0.75.
  - Adicionar dependencia paga ao core (OpenAI/Anthropic/LiveKit) sem opt-in explicito por sessao.
  - Persistir PCM/audio cru em qualquer caminho de Vox V0-V3.
  - Promover Vox V4+ sem GATE V3 verde - viola Lei 0.9.

observability_signals:
  - docs-health status ok
  - Ausencia de imports cruzados entre Services/Ai/Vox/ e Services/Ai/Voice/
  - Ausencia de PRs Vox tocando atlas-app/

next_actions:
  - Onda 1 - Mac Edge daemon hibrido (launchd hotkey + Tauri STT whisper.cpp@large-v3).
  - Onda 2 - Kernel Vox V0 dictation com receipt R0.
  - Voice Realtime Surface permanece em standby; AP-687 nao avanca para Phase 1 ate V6.
---
# ADR 0003 - Atlas Vox V0-V3 Mac-First Vs Voice Realtime Surface Mobile-First

## Status

`active` (aprovado 2026-05-18). Aprovado por Vitor apos revisao arquitetural
`plans/synchronous-weaving-meteor.md`. Bloqueia toda implementacao Vox ate ser
revisado por nova ADR explicita.

Mapeamento ao status canon Atlas: `accepted` da ADR equivale a `active` no
set canonico `[planned|future|building|active|deprecated]`.

## Contexto

Em 2026-05-18, o Atlas tem dois canonicos de voz coexistindo:

1. **Atlas Vox** (`atlas-vox-operational-thinking-interface.md`, priority 98,
   ACTIVE): programa de voz-para-intencao-para-acao governada, com escada
   V0-V10. Zero codigo de runtime proprio existe; somente doutrina e contratos
   conceituais.

2. **Voice Realtime Surface** (`atlas-ai-voice-realtime-surface.md`,
   priority 94, SCAFFOLD): canal tecnico mobile-first com LiveKit Agents SDK
   (Python) + Kernel Laravel via HTTP + `atlas-app/` (Expo). Tem scaffold real:
   `AtlasVoiceRealtimeService`, AP-185 foundation registry implementado,
   AP-687 runtime certification, eclipse guard, rivals runner, LiveKit token
   issuer. Esta em `certified_scaffold` com Phase 1 bloqueada pelo ADR 0002.

A revisao arquitetural do Atlas Vox (`plans/synchronous-weaving-meteor.md`)
identificou conflito direto:

- Voice Realtime Surface foi desenhada **mobile-first + LiveKit +
  `livekit-plugins-openai` (API paga)**.
- O briefing canonico do Atlas Vox exige **Mac-first + local-first + sem API
  paga + Atlas Desktop antes de mobile**.

Sem fronteira declarada, riscos inevitaveis:

- Runtime paralelo (`vox-surface` chamando provider direto, violando Kernel
  soberano).
- Codigo duplicado (`VoxIntentPacket` em PHP e em Python).
- Dependencia paga vazando para o core Vox.
- Confusao de localizacao (`atlas-app/` vs `atlas-desktop/`).
- Trabalho de Voice Realtime competindo com Vox por contexto e atencao de
  Vitor + Claudes implementadores.

## Decisao

**Trilhos arquiteturais separados, infraestrutura comum, Voice Realtime
pausada ate V6.**

### Fronteira definitiva

| Dimensao | Atlas Vox V0-V5 (este programa) | Voice Realtime Surface (existente) |
| --- | --- | --- |
| Plataforma | macOS (Atlas Desktop Tauri + Mac Edge launchd) | Mobile (iOS/Android via atlas-app) |
| Transporte | HTTP local (loopback Kernel) | LiveKit Room / WebRTC |
| STT | whisper.cpp@large-v3 local | LiveKit plugin (paga ou local) |
| TTS | Opcional. macOS `say` ou nenhum em V0-V2. | LiveKit plugin |
| Modo | Push-to-talk + overlay + confirmacao | Always-listening + barge-in + streaming |
| Alvo de uso | Comando explicito, prompt compilation, acao governada | Conversa continua, ambient, AirPods |
| Custo recorrente | Zero por padrao | Pago (LiveKit + OpenAI) ou local-heavy |
| Status atual | Doutrina + contratos v1 (Onda 0 entregue 2026-05-18) | `certified_scaffold`, Phase 1 bloqueada |
| Diretorio kernel | `atlas-server/app/Services/Ai/Vox/` | `atlas-server/app/Services/Ai/Voice/` |
| Diretorio surface | `atlas-desktop/` | `atlas-app/` |
| Quem cuida agora | Ondas 1-7 (Vitor + Claudes implementadores) | Standby ate V6 (sem novo trabalho) |

### Infraestrutura comum

Ambos compartilham, sem duplicacao:

- **Atlas Pipeline** (`Input -> Intent -> Domain -> Profile -> Context ->
  Policy -> Decide -> Receipt -> Executor -> Gate -> Evidence -> Learning ->
  Output`).
- **Kernel autoridade soberana** (`DecisionReceiptIssuer`,
  `AtlasEvidenceLedger`, `ExecutionGate`).
- **Eclipse guard** (logica compartilhada; cada surface implementa seu hotkey
  proprio).
- **Rivals framework** (`VoxRivalsRunner` sibling de `AtlasVoiceRivalsRunner`,
  mesma estrutura).

### Quando Voice Realtime volta

Quando Atlas Vox abrir GATE V3 (Lei 0.9) e Vitor declarar explicitamente
disposicao para V6 (Ambient Cognitive Layer). Antes disso, AP-687 nao avanca
para Phase 1.

## Opcoes Consideradas E Rejeitadas

### Opcao B (rejeitada) - Trilhos separados, ambos evoluem em paralelo

Voice Realtime continuaria evoluindo (Phase 1, LiveKit Agents SDK loop, runtime
Python) enquanto Vox e construido no Mac. Rejeitada porque:

- Dispersa atencao de Vitor entre dois tracks de voz simultaneos.
- Forca dependencia em `livekit-plugins-openai` (paga) para Phase 1, violando
  meta declarada de zero API paga no core.
- Voice Realtime Phase 1 nao destrava nenhum uso real do Atlas no Mac (foco
  declarado pelo operador).

### Opcao C (rejeitada) - Fundir tudo numa surface so

Refazer Voice Realtime como nucleo, Vox vira modo dentro dela. Rejeitada
porque:

- Joga fora o local-first explicitamente exigido para V0-V3.
- Atrasa entrega V0-V3 em meses (refactor da Voice Realtime + portabilidade
  mobile->Mac).
- Acopla qualidade Vox Mac a evolucao do scaffold LiveKit, que tem cadencia
  propria.

## Consequencias

### Positivas

- **Zero competicao por contexto.** Ondas Vox 1-7 nao tocam Voice Realtime.
- **Zero dependencia paga no core Vox.** Cumpre meta declarada.
- **Caminho mais curto para V0-V3 utilizavel** (~6 semanas de Claude-tempo).
- **Voice Realtime preserva trabalho ja feito** (scaffold + AP-687 + foundation
  registry); apenas pausa, nao descarta.

### Negativas / custos

- **Voice Realtime nao evolui ate V6.** Trabalho de AP-687 -> Phase 1 fica em
  espera (potencialmente meses).
- **Duplicacao consciente de pequenos componentes** (eclipse guard Vox e
  separado do `AtlasVoiceEclipseGuard`; rivals runner idem). Aceito porque
  contextos sao diferentes o suficiente.
- **`livekit-agents` Python e ferramentas mobile (`@livekit/react-native`)
  continuam instaladas mas inativas.** Custo: footprint de deps, sem custo
  recorrente.

### Stop-the-line - violacoes que abortam onda

- PR Vox tocando `atlas-app/`.
- PR Vox tocando `atlas-server/app/Services/Ai/Voice/`.
- PR Vox adicionando dependencia paga ao core (sem opt-in por sessao).
- PR Vox chamando provider direto sem Kernel.
- PR Vox persistindo PCM/audio cru.
- Implementacao V4+ antes de GATE V3 verde.

## Implementacao

Onda 0 (entregue 2026-05-18) materializa esta fronteira em:

1. **Leis 0/0.5/0.75/0.9** adicionadas ao
   `atlas-vox-operational-thinking-interface.md`.
2. **Contratos v1** em `docs/contracts/vox/` (5 docs).
3. **Este ADR**.
4. **Memory entry** `project_atlas_vox.md` no auto-memory global.

Ondas seguintes (1-7) implementam runtime conforme `plans/synchronous-weaving-meteor.md`.

## Revisao

Reavaliacao obrigatoria apenas em duas situacoes:

1. **GATE V3 verde** - quando Vitor abrir GATE V3 conforme Lei 0.9, decidir se
   V6 deve retomar Voice Realtime Surface ou reescrever como Vox Ambient.
2. **Stop-the-line acionado** - se algum criterio acima for violado e a
   violacao revelar que a fronteira esta errada, nova ADR (`0004-...`) deve
   sobrescrever esta.

Mudancas silenciosas a esta fronteira sao proibidas.
## Resumo

Atlas Vox V0-V5 evolui Mac/Desktop local-first com runtime proprio. Voice
Realtime Surface (mobile/LiveKit/runtime Python) permanece congelada como
scaffold ate V6 (Ambient Cognitive Layer). Trilhos arquiteturais separados,
infraestrutura comum (Kernel pipeline, ledger, receipt, eclipse, rivals).
## Papel no Atlas

Esta ADR governa o escopo de implementacao do programa Atlas Vox e o estado de
congelamento da Voice Realtime Surface. E pre-condicao doutrinaria de toda
implementacao Vox em ondas 1-7 do plano arquitetural.
## Onde Se Encaixa

Sob `atlas-vox-operational-thinking-interface.md` (canon Vox). Acima das
implementacoes runtime que vao morar em `atlas-server/app/Services/Ai/Vox/` e
`atlas-desktop/`. Paralela a `atlas-ai-voice-realtime-surface.md` (canon
Voice RT), que esta congelada.
## Contratos

Materializa: Leis 0/0.5/0.75/0.9 no canon Vox; 5 contratos v1 em
`docs/contracts/vox/` (`VoxSessionPacket`, `VoxTranscript`, `VoxIntentPacket`,
`VoxConfirmation`, `VoxActionOutcome`). Schemas obrigatorios para ondas 1-7.
## Fluxo

```
revisao arquitetural -> esta ADR aceita -> Leis no canon Vox ->
contratos v1 publicados -> memory entry global -> docs-health verde ->
Onda 1 (Mac Edge daemon hibrido) -> Onda 2 (Kernel Vox V0 dictation) -> ...
-> Onda 7 (Rivals + metricas) -> GATE V3 -> (eventual) V6 que retoma Voice RT.
```
## Regras para IA

Agente nao deve: tocar `atlas-app/`, tocar `app/Services/Ai/Voice/`, chamar
provider direto a partir de codigo Vox, adicionar dependencia paga ao core
Vox, persistir PCM/audio cru, implementar V4+ sem GATE V3. Stop-the-line em
qualquer violacao.
## Escopo de Implementacao

Esta ADR e DOCTRINARIA. Nao introduz codigo. Codigo runtime vem nas ondas
1-7 conforme `plans/synchronous-weaving-meteor.md`. Esta ADR autoriza
planejamento e abertura de AP/packets para Mac Edge daemon e Kernel Vox V0.
## Dependencias

Depende de: canon Vox (`atlas-vox-operational-thinking-interface.md`), canon
Voice RT (`atlas-ai-voice-realtime-surface.md`), Atlas Pipeline
(`atlas-ai-pipeline.md`), Kernel Decision Receipt, Evidence Ledger, doc
`atlas-documentation-status-cleanup-plan.md` para set canonico de status.
## Evidencias

Evidencia desta ADR: este documento + Leis 0/0.5/0.75/0.9 no canon Vox + 5
contratos v1 em `docs/contracts/vox/` + `project_atlas_vox.md` no auto-memory
global + `plans/synchronous-weaving-meteor.md`. Evidencia de uso (futuro):
ausencia de PR Vox tocando atlas-app/ ou Services/Ai/Voice/.
## Riscos

Riscos da fronteira: (1) Voice RT scaffold congelado por meses pode acumular
drift contra evolucao do LiveKit Agents SDK. (2) Duplicacao de pequenos
componentes (eclipse guard, rivals runner) entre Vox e Voice RT. (3) Vitor
pode mudar de prioridade e pedir mobile antes; nesse caso reabrir ADR.
## Exemplos

Exemplo de PR aceito sob esta ADR: criar `app/Services/Ai/Vox/VoxCompiler.php`
para modo dictation (Onda 2).

Exemplo de PR REJEITADO sob esta ADR: adicionar campo `livekit_room_id` no
`VoxSessionPacket` ou tocar arquivo em `atlas-app/components/` em onda Vox.
## Proximas Acoes

1. Onda 1 - Mac Edge daemon hibrido (launchd hotkey + Tauri STT
   whisper.cpp@large-v3).
2. Onda 2 - Kernel Vox V0 dictation com receipt R0.
3. Onda 3 - Atlas Desktop `VoxOverlay.tsx` V1.
4. Voice Realtime Surface permanece em standby; AP-687 nao avanca para
   Phase 1 ate V6.
