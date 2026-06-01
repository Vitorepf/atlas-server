---
id: atlas-vox-operational-thinking-interface-ladder
type: engineering_knowledge
title: Atlas Vox Operational Thinking Interface Ladder
status: future
category: architecture
priority: 90
summary: Detalhes extraidos da Escada Vox V2-V10; registra versoes/degraus Vox sem confundir com patamares canonicos do Atlas inteiro.
tags:
  - atlas-ai
  - atlas-vox
  - voice
  - qualitative-levels
capabilities:
  - vox_ladder
  - voice_to_intent
  - governed_voice_action
decisions:
  - V2-V10 sao versoes/degraus da familia Atlas Vox.
  - V4/V5 marcam outro patamar interno de Vox, mas nao patamar canonico do Atlas inteiro por padrao.
  - Voice Realtime Surface continua fronteira tecnica separada de Atlas Vox.
maintenance:
  - Atualize quando a Escada Vox, gates ou fronteira com Voice Realtime mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-vox-operational-thinking-interface-ladder
graph_title: Atlas Vox Operational Thinking Interface Ladder
graph_world: atlas
graph_layer: flow
graph_kind: module
graph_parent: atlas-vox-operational-thinking-interface
graph_status: active
graph_source: repo
human_name: Atlas Vox Operational Thinking Interface Ladder
canonical_name: Atlas Vox Operational Thinking Interface Ladder
technical_name: atlas-vox-operational-thinking-interface-ladder
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface-ladder.md
owner: product-architecture
version_family: Atlas Vox
version_note: Esta doc descreve versoes/degraus Vox; nao transforme V0/V3/V4/V6 em patamares canonicos globais.
repo_paths:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface-ladder.md
allowed_changes:
  - Refinar descricoes dos niveis Vox preservando fronteira com patamar global.
forbidden_changes:
  - Chamar versao Vox de patamar global sem declaracao canonica explicita.
  - Confundir Atlas Vox com Voice Realtime Surface.
depends_on:
  - atlas-vox-operational-thinking-interface
flows_to:
  - atlas-vox-operational-thinking-interface
unlocks:
  - governed-mac-control
  - operational-thinking-interface
governs:
  - atlas_vox.ladder
evidence:
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface-ladder.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia esta doc para entender a diferenca entre versao Vox, degrau Vox e patamar canonico.
ai_usage_notes:
  - Patamar e salto de maturidade declarado; versao Vox e evolucao dentro da familia Atlas Vox.
quality_gates:
  - docs-health-pass
failure_modes:
  - Cartografia mostrar V6 como patamar global.
observability_signals:
  - docs_health_status
next_actions:
  - Manter nomenclatura Vox sincronizada com Cartografia.
line_limit: 520
---
# Atlas Vox Operational Thinking Interface Ladder

## Resumo

Esta doc filha guarda os detalhes dos niveis V2-V10 da Escada Vox. Ela existe para impedir a confusao que a cartografia ja sofreu: versao/degrau Vox nao e automaticamente patamar canonico do Atlas inteiro.

## Papel no Atlas

Explicar como Atlas Vox evolui de intent compiler ate interface de pensamento operacional, preservando Kernel, receipts, privacy e agencia humana.

## Onde Se Encaixa

Atlas Vox programa -> Escada Vox -> Pipeline/Kernel/Voice Realtime conforme gates.

## Contratos

- Pai: `atlas-vox-operational-thinking-interface`.
- Nomenclatura: versao/degrau Vox separado de patamar global.

## Fluxo

1. Fala vira transcript.
2. Transcript vira intent packet.
3. Kernel decide autoridade.
4. Executor age apenas com receipt.
5. Evidence e memoria revisavel alimentam proximos ciclos.

## Regras para IA

- Nao chame V0/V3/V4/V6 de patamar global por padrao.
- Nao implemente provider direto por voz.
- Nao avance ambient/always-on sem gates de privacy e eclipse.

## Escopo de Implementacao

Detalhar os niveis V2-V10 da familia Vox e seus limites de governanca.

## Detalhes Da Escada

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

## Dependencias

- `atlas-vox-operational-thinking-interface`.
- Voice Realtime Surface.
- Pipeline, Kernel e Evidence Ledger.

## Evidencias

- Esta doc filha.
- Contratos Vox e docs de fronteira.

## Riscos

- Misturar versao Vox com patamar.
- Criar runtime de voz sem Kernel.
- Transformar fala em autorizacao.

## Exemplos

V6 e uma versao/degrau da familia Vox sobre ambient cognitive layer; so vira patamar global se uma doc canonica declarar esse salto explicitamente.

## Proximas Acoes

- Refletir esta distincao nos modais da Cartografia.
