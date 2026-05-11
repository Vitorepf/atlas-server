---
id: huw-prosser-projects-source-material
type: engineering_knowledge
title: Huw Prosser Projects Source Material
status: source_material
category: external-research
priority: 42
summary: Dissecacao dos projetos publicos de Huw Prosser para extrair padroes aproveitaveis pelo Atlas sem criar dependencia ou autoridade paralela.
tags:
  - atlas
  - source-material
  - voice
  - agents
  - browser-runtime
capabilities:
  - voice_realtime
  - python_ai_data_runtime
  - browser_surface_candidate
  - tool_runtime
decisions:
  - Fury/JARVIS/DropVR sao fontes de padroes, nao substitutos do Kernel Atlas.
  - Nenhum projeto externo pode chamar provider, gravar memoria ou executar tool sem Decision Receipt.
  - Projetos pesados de audio/modelos devem ser instalados apenas em sprint dedicado, com contrato de privacy e Evidence.
maintenance:
  - Atualize se os repositorios forem revisados novamente ou se um padrao virar AP ativo.
  - Nao copie codigo externo para runtime do Atlas sem licenca, AP, teste e boundary review.
related_paths:
  - /Users/vitorepf/develop/Atlas/dissecar/huw-prosser/README.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
---

# Huw Prosser Projects Source Material

Esta nota registra o que foi extraido dos projetos publicos de Huw Prosser.
Ela nao governa implementacao. Promocoes precisam virar doc ativo, AP ou teste.

## Material Preservado

| Projeto | Caminho De Dissecacao | Status | Valor Para Atlas |
|---|---|---|---|
| Fury SDK | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/fury-sdk` | instalado + testado | agent runner, interruption, tool UI, compaction, memory scopes |
| Fury | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/fury` | clone equivalente | duplicado do Fury SDK |
| Jarvis MLX | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/jarvis-mlx` | clone static | STT -> LLM local -> TTS no Apple Silicon |
| Web Whisper | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/web-whisper` | clone static | browser VAD + WebSocket + Whisper |
| DropVR | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/dropvr` | snapshot | WebRTC local-first e baixa friccao |
| Carter Labs | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/carter-labs` | snapshot quando disponivel | IA relacional/persona/presenca |
| Clap Detection | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/clap-detection` | clone static | trigger acustico local por micro-modelo |
| Cluster-FK | `/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/cluster-fk` | clone static | clustering multimodal por embeddings |

## Achados Promoviveis

### 1. Agent Runner Com Interruption

Fury mostra um runner simples com streaming, cancelamento, interrupcao e resposta
parcial preservavel. Para o Atlas, isto deve entrar no `Python AI/Data Runtime`
como worker subordinado ao Kernel.

Regra: runner executa; Kernel decide.

### 2. Tool UI Events

Ferramentas podem emitir eventos humanos durante execucao. Isto e valioso para
CLI, voice e mobile porque a IA entende progresso sem esperar o resultado final.

Promocao correta: `Super Tool Runtime` deve expor eventos normalizados para
Evidence Ledger e Output Renderer.

### 3. Memory Scopes Airgapped

Fury confirma que memorias por escopo reduzem contaminacao. O Atlas ja tem Memory
Core mais robusto; o padrao util e manter escopo explicito e tool limitada.

Nao promover file-store externo como fonte primaria.

### 4. Voice Local Stack

Jarvis MLX e Web Whisper reforcam a sequencia VAD/STT/LLM/TTS, mas o Atlas precisa
de LiveKit/Voice Surface, `DecisionReceipt`, eclipse mode e eventos `VOICE_*`.

### 5. Browser/WebRTC Como Surface Futura

DropVR e Browser/WebGPU/WebRTC mostram que o navegador pode virar surface forte
para voz, tela, transferencia local e prototipos. Isto deve virar AP proprio antes
de qualquer implementacao.

### 6. Micro-Modelos Locais

Clap Detection e Cluster-FK sao exemplos pequenos de sensores/classificadores
locais. Valor: triggers, deduplicacao multimodal e curadoria. Risco: privacy.

## O Que Nao Deve Ser Feito

- Nao substituir Atlas Kernel por Fury.
- Nao permitir provider direto em worker Python.
- Nao gravar audio bruto ou imagem sensivel sem privacy class.
- Nao criar memoria paralela fora de Open Brain/Memory Core.
- Nao transformar Carter/persona em produto separado do Atlas.

## Backlog Recomendado

| Item | Prioridade | Dono Futuro |
|---|---:|---|
| Tool progress events normalizados no Super Tool Runtime | Alta | Tool Runtime |
| Browser/WebRTC Surface AP | Media | Surface Plane |
| Voice local fallback MLX/Kokoro/WhisperKit review | Media | Voice Realtime |
| Micro-model trigger local review | Baixa | Swift Native Mac / Voice |
| Multimodal clustering for content intelligence | Baixa | Marketing / Research |

## Evidencia De Validacao

Fury SDK foi instalado em venv isolada e testado:

```bash
cd /Users/vitorepf/develop/Atlas/dissecar/huw-prosser/fury-sdk/repo
/Users/vitorepf/develop/Atlas/dissecar/huw-prosser/fury-sdk/.venv/bin/python -m pytest -q
```

Resultado: `89 passed`.

