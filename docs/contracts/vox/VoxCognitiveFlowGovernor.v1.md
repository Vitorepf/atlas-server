---
id: vox-cognitive-flow-governor-v1
type: contract
title: VoxCognitiveFlowGovernor.v1
status: active
category: contracts
priority: 95
summary: Contrato additive V6.8 que consolida intencao, fluxo, contexto, risco, execucao, qualidade e preview humano para a UI Atlas Vox.
tags:
  - atlas-vox
  - contracts
  - v6.8
  - cognitive-flow-governor
maintenance:
  - Campo additive em /ai/vox/intent; nao substituir flow_decision.
  - Mudanca breaking exige nova versao ou ADR explicita.
related_paths:
  - app/Services/Ai/Vox/Governor/VoxCognitiveFlowGovernor.php
  - app/Http/Controllers/AtlasAiVoxController.php
  - tests/Unit/Ai/Vox/VoxCognitiveFlowGovernorTest.php
  - tests/Feature/Ai/Vox/AtlasAiVoxControllerTest.php
doc_schema: atlas_canonical_module_doc.v1
owner: surface-architecture
requires_evidence: true
risk_level: high
quality_gates:
  - "php artisan test tests/Unit/Ai/Vox/VoxCognitiveFlowGovernorTest.php"
  - "php artisan test tests/Feature/Ai/Vox/AtlasAiVoxControllerTest.php"
  - "php artisan atlas:vox:v6-8-certify --json --strict"
---
# VoxCognitiveFlowGovernor.v1

Schema: `atlas.vox.cognitive_flow_governor.v1`.

Nome da versao: **Atlas Vox V6.8 - Cognitive Flow Governor**.

## Objetivo

Gerar a politica final de fluxo que a UI deve obedecer depois que o Kernel ja
compilou a fala. O contrato e additive no response de `/ai/vox/intent`:

```json
{
  "cognitive_flow_governor": {
    "schema": "atlas.vox.cognitive_flow_governor.v1",
    "version": "0.1.0",
    "engine": "deterministic_local_rules",
    "local_only": true,
    "v7_unlock_allowed": false
  }
}
```

Clientes antigos podem ignorar o campo. Clientes V6.8 usam este envelope para
reduzir botoes errados, evitar acao ambigua e mostrar uma proxima acao clara.

## Campos

| Campo | Tipo | Obrigatorio quando presente | Descricao |
| --- | --- | --- | --- |
| `schema` | string | sim | Literal `atlas.vox.cognitive_flow_governor.v1`. |
| `version` | string | sim | Versao do governador. |
| `engine` | string | sim | Sempre `deterministic_local_rules`. |
| `local_only` | bool | sim | Sempre `true`. |
| `v7_unlock_allowed` | bool | sim | Sempre `false` em V6.8. |
| `intent` | object | sim | Intencao primaria, secundarias e palavras que nao podem ser perdidas. |
| `flow` | object | sim | Modo, destino, confianca e motivo. |
| `context` | object | sim | Lacunas e pergunta de clarificacao. |
| `risk` | object | sim | Classe R0-R4, motivo, confirmacao e bloqueio. |
| `execution` | object | sim | Politica e acoes permitidas para UI. |
| `quality` | object | sim | Se prompt quality e obrigatorio e se precisa revisao. |
| `human_preview` | object | sim | O que ouvi, entendi, farei e aviso humano. |
| `guards` | object | sim | Garantias locais e de seguranca. |

## Politicas de execucao

| Politica | Significado |
| --- | --- |
| `single_safe_action` | R0/R1 seguro; UI pode oferecer copiar/inserir/enviar. |
| `preview_only` | Mostra proposta; nao executa automaticamente. |
| `step_by_step_confirmation` | Precisa confirmacao antes de agir. |
| `no_action` | Falta contexto; UI deve perguntar. |
| `blocked` | R4 ou hard safety; UI deve cancelar/bloquear. |

## Guards obrigatorios

```json
{
  "raw_audio_accepted": false,
  "cloud_stt": false,
  "paid_api_required": false,
  "terminal_execute": false,
  "voice_realtime_touched": false,
  "mobile_touched": false
}
```

## Exemplo

```json
{
  "schema": "atlas.vox.cognitive_flow_governor.v1",
  "version": "0.1.0",
  "engine": "deterministic_local_rules",
  "local_only": true,
  "v7_unlock_allowed": false,
  "intent": {
    "primary": "Investigar Atlas Vox sem editar nada",
    "secondary": [],
    "user_words_preserved": ["não mexer", "codex"]
  },
  "flow": {
    "mode": "intent_compile",
    "destination": "codex",
    "confidence": "high",
    "reason": "Fala pede prompt para Codex sem execucao."
  },
  "context": {
    "missing": false,
    "missing_items": [],
    "clarifying_question": null
  },
  "risk": {
    "class": "R1",
    "reason": "Leitura/analise sem edicao.",
    "requires_confirmation": false,
    "blocked": false
  },
  "execution": {
    "policy": "single_safe_action",
    "next_step": "Preparar o resultado para o destino escolhido sem executar automaticamente.",
    "allowed_actions": ["copy", "send_to_atlas", "cancel"]
  },
  "quality": {
    "prompt_quality_required": true,
    "needs_review": false,
    "issues": []
  },
  "human_preview": {
    "heard": "cria um prompt pro Codex investigar o Atlas Vox sem editar nada",
    "understood": "Investigar Atlas Vox sem editar nada",
    "will_do": "Preparar o resultado para o destino escolhido sem executar automaticamente.",
    "warning": null
  },
  "guards": {
    "raw_audio_accepted": false,
    "cloud_stt": false,
    "paid_api_required": false,
    "terminal_execute": false,
    "voice_realtime_touched": false,
    "mobile_touched": false
  }
}
```
