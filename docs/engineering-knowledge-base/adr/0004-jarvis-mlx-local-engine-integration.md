---
id: adr-0004-jarvis-mlx-local-engine-integration
type: engineering_adr
title: "ADR 0004 - Integração do Motor Local Jarvis-MLX no AI Gateway Plane"
status: source_material
category: architecture_decision
priority: 95
summary: Define o contrato de integração e as fronteiras do motor Jarvis-MLX (Whisper-MLX, MeloTTS e modelos locais Llama-3/Phi-3 via MLX) como um Local Provider Plane governado pelo Kernel Laravel, garantindo latência Turn-to-First-Audio de 450ms sem violar a soberania do Kernel ou persistir áudio raw.
tags:
  - adr
  - jarvis-mlx
  - voice-realtime
  - local-first
  - apple-silicon
capabilities:
  - jarvis_mlx_integration_contract
  - local_provider_plane_routing
decisions:
  - O motor local Jarvis-MLX (Whisper-MLX + MeloTTS + Phi-3/Llama-3 MLX) atuará estritamente como um Local Provider no gateway do Kernel (AiGatewayService).
  - Toda conversão de áudio para texto (STT) e texto para áudio (TTS) é governada pelo Kernel: stubs de tradução local passam pelo normalizador (AtlasVoiceRuntimeEventNormalizer).
  - O Python Worker (LiveKit) nunca chama o Phi-3 ou Whisper-MLX local diretamente para inferência sem passar antes pela validação de políticas do Laravel e emissão de um DecisionReceipt.
  - A latência alvo Turn-to-First-Audio (L1) para o fluxo local é fixada em <= 450ms no p95, com tempo de barge-in local em <= 80ms.
  - Nenhum áudio bruto ou transcrição crua deve ser persistida fora da memória volátil da sessão. Apenas hashes sha256 são registrados no Evidence Ledger.
maintenance:
  - Atualizar quando o contrato Jarvis-MLX mudar de ADR para implementacao runtime.
  - Atualizar quando latencias, workers ou fronteiras de provider local mudarem.
related_paths:
  - docs/engineering-knowledge-base/adr/0004-jarvis-mlx-local-engine-integration.md
  - app/Services/Ai/Gateway
  - app/Services/Ai/Vox
doc_schema: atlas_canonical_module_doc.v1

graph_id: adr-0004-jarvis-mlx-local-engine-integration
graph_title: ADR 0004 - Integracao do Motor Local Jarvis-MLX no AI Gateway Plane
graph_world: atlas
graph_layer: system
graph_kind: adr
graph_parent: atlas-ai-router-runtime-enterprise-upgrade
graph_status: active
graph_source: repo
human_name: ADR 0004 - Integracao do Motor Local Jarvis-MLX no AI Gateway Plane
canonical_name: ADR 0004 - Integracao do Motor Local Jarvis-MLX no AI Gateway Plane
technical_name: adr-0004-jarvis-mlx-local-engine-integration
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/adr/0004-jarvis-mlx-local-engine-integration.md

owner: ai-gateway
repo_paths:
  - docs/engineering-knowledge-base/adr/0004-jarvis-mlx-local-engine-integration.md

allowed_changes:
  - Refinar limites de latencia com evidencia medida.
  - Atualizar fronteiras do provider local quando houver implementacao governada.
  - Adicionar referencias a testes e comandos de certificacao.

forbidden_changes:
  - Permitir inferencia local sem DecisionReceipt.
  - Permitir persistencia de audio bruto.
  - Permitir worker local contornar politicas do Kernel.

depends_on:
  - adr-0003-vox-vs-voice-realtime-surface-boundary
  - atlas-ai-router-runtime-enterprise-upgrade

flows_to:
  - atlas-vox-v6-certification
  - atlas-ai-runtime-readiness

unlocks:
  - jarvis_mlx_integration_contract
  - local_provider_plane_routing

governs:
  - voice_realtime_local_provider_boundary
  - jarvis_mlx_worker_policy_boundary

evidence:
  - docs/engineering-knowledge-base/adr/0004-jarvis-mlx-local-engine-integration.md

required_tests:
  - php artisan atlas:ai:architecture-validate --json

requires_evidence: true
risk_level: high

next_actions:
  - Implementar comandos read-only de certificacao antes de ativar runtime local.
  - Registrar evidencias de latencia antes de promover qualquer executor.
---
# ADR 0004 - Integração do Motor Local Jarvis-MLX no AI Gateway Plane

## Resumo

Esta ADR define como o Jarvis-MLX entra no Atlas como provider local governado pelo Kernel.

## Papel no Atlas

O documento impede que STT, TTS ou modelos locais contornem policies, receipts e evidence.

## Onde Se Encaixa

Ele pertence ao plano de gateway de IA e ao limite entre Vox, Voice Realtime e providers locais.

## Contratos

Toda inferencia local precisa passar por validacao de politica e por DecisionReceipt antes de afetar runtime critico.

## Fluxo

Audio entra por cliente/worker, vira transcript, passa pelo Kernel, recebe decisao e so entao aciona modelo local ou TTS.

## Regras para IA

Nao promover execucao local sem receipt, sem sanitizacao de resposta final e sem evidencia de privacidade.

## Escopo de Implementacao

O escopo cobre contrato arquitetural, limites de latencia e fronteiras de privacidade para uma futura implementacao.

## Dependencias

Depende do AI Gateway, de normalizadores de eventos de voz e das ADRs de fronteira entre Vox e Voice Realtime.

## Evidencias

A evidencia atual e documental. Implementacao futura deve anexar comandos, testes e medicoes p95.

## Riscos

O risco principal e bypass de governanca por workers locais ou persistencia indevida de audio bruto.

## Exemplos

Um worker pode transcrever localmente, mas nao pode executar resposta de modelo sem aprovacoes do Kernel.

## Proximas Acoes

Criar certificacao read-only para latencia, privacidade e roteamento antes de liberar dogfood do provider local.

## Status

`active` (Proposto em 2026-05-19 para acoplamento na Fase 1 do Voice Realtime).

## Contexto

Para atingir a experiência definitiva de voz sem latência perceptível ("Jarvis-like"), o Atlas necessita de um pipeline de inferência local ultra-rápido otimizado para o hardware Apple Silicon. O projeto offline `jarvis-mlx` (contido em `dissecar/huw-prosser/jarvis-mlx/`) oferece Whisper-MLX (STT), MeloTTS (TTS) e Phi-3/Llama-3 otimizados via MLX rodando localmente na GPU unificada do Mac com velocidade superior a 60 tokens por segundo.

No entanto, integrar um executor de IA local pode facilmente contornar as políticas cognitivas e de segurança do Kernel do Atlas se o runtime Python ou a interface local chamarem os modelos diretamente, violando o princípio de centralização de controle e a **ADR 0003**.

## Decisão

Implementar o **Jarvis-MLX** estritamente como um **Local Provider Plane** conectado de forma fechada e governada ao Kernel Laravel do Atlas.

### 1. Fronteira de Fluxo e Dependência

```
[ Microfone ] ──(Áudio Chunks)──> [ LiveKit Client (Tauri/Mobile) ]
                                          │
                                       (WebRTC)
                                          ▼
                                 [ LiveKit Worker ]
                                          │ (Local Whisper-MLX STT)
                                          ▼
                                 (Transcript Text)
                                          │
                                          ▼
                               ┌──────────────────────┐
                               │ Atlas Kernel Laravel │
                               │                      │
                               │ - Policy Engine      │
                               │ - Decision Receipt   │
                               │ - Normalizer Event   │
                               └──────────┬───────────┘
                                          │
                                 (Aprovado por Receipt)
                                          ▼
                               ┌──────────────────────┐
                               │    Phi-3 MLX Local   │
                               └──────────┬───────────┘
                                          │
                                   (Texto Gerado)
                                          ▼
                               ┌──────────────────────┐
                               │  MeloTTS Local (TTS) │
                               └──────────┬───────────┘
                                          │
                                       (Áudio)
                                          ▼
                                 [ Speaker Playback ]
```

### 2. Contrato Técnico e Latências Alvo

O motor Jarvis-MLX deve operar com os seguintes limites rigorosos:

*   **Turn-to-First-Audio (L1)**: $\le 450\text{ms}$ no p95 (do silêncio final do usuário até o primeiro som do robô).
*   **Barge-in (Interrupção)**: $\le 80\text{ms}$ no p95.
*   **Velocidade do Llama-3/Phi-3**: $\ge 55$ tokens/segundo estáveis.
*   **VAD local**: $\le 100\text{ms}$ para cortar captura no silêncio.

### 3. Garantias de Privacidade e Soberania

1.  **Sem Persistência de Áudio**: O áudio PCM nunca atinge o disco ou banco de dados. Qualquer depuração deve ser feita em buffers de memória volátil e limpa na destruição da sessão.
2.  **Ledger Hashing**: Apenas o hash `sha256(raw_audio)` e o hash do transcript são enviados ao `Evidence Ledger` como parte da auditoria de turno.
3.  **Normalização de Eventos**: O payload gerado pelo motor MLX passa pela classe `AtlasVoiceRuntimeEventNormalizer` para validação fail-closed de tokens, chaves e caminhos locais antes de emitir o evento `VOICE_TURN_DECIDED`.

## Consequências

### Positivas
*   **Maturidade Jarvis Real**: Latência de milissegundos sem qualquer delay perceptível para o operador.
*   **Custo Zero e Offline**: 100% livre de conexões ou assinaturas de APIs de terceiros.
*   **Preservação Total de Políticas**: O Kernel Laravel continua comandando o que pode ou não ser executado.

### Negativas / Custos
*   **Uso de Recursos no Mac**: Consumo de RAM unificada no Apple Silicon para manter Phi-3 e Whisper-MLX em cache na GPU.

## Stop-the-line

Interromper a promoção caso:
*   O runtime Python realize inferência Phi-3/Whisper local sem requisição HTTP com Receipt assinado pelo Kernel.
*   Mensagens em texto livre geradas pelo modelo MLX contornem o `AtlasFinalResponseSanitizer`.
*   Mais de 1 falso positivo de ativação de escuta por dia ocorra em ambiente doméstico comum.
