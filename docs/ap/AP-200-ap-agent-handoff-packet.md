---
title: AP Agent Handoff Packet
status: foundation-read-model-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentHandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentHandoffPacketTest.php
---

# AP-200 - AP Agent Handoff Packet

## 1. Proposito

AP-200 cria um pacote read-only de passagem de bastao para agentes trabalharem em APs.

Ele agrega intake, contexto do AP alvo e checklist inicial de completion em uma unica resposta.

## 2. Escopo Implementado

- `AtlasApAgentHandoffPacket`
- consumo do AP-195 para intake
- consumo do AP-199 para contexto de AP alvo
- consumo do AP-196 para checklist inicial
- resolucao automatica do primeiro AP impactado
- suporte a AP alvo explicito
- testes para AP existente, AP novo, input invalido e alvo explicito

## 3. Autoridade

Schema: `atlas.ap_agent_handoff_packet.v1`  
Modo: `read_only_agent_handoff_packet`  
Autoridade: `ap_agent_handoff_only_no_file_writes`

O pacote nao executa comandos, nao escreve arquivos e nao substitui julgamento do agente.

## 4. Regras

- intake bloqueado bloqueia o handoff
- AP alvo explicito tem prioridade sobre alvo inferido
- AP existente exige context pack pronto
- AP novo exige criacao via AP-192 antes de codigo
- completion checklist sempre inicia incompleto ate evidencia real ser fornecida

## 5. Beneficio

Outro agente recebe um roteiro operacional compacto antes de tocar em codigo ou doc.

Isso reduz perda de contexto entre Codex, AP duplicado e entrega declarada sem validacao.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao roda testes
- nao cria AP automaticamente
- nao altera Architecture Operations

## 7. Definition of Done

- path coberto resolve AP existente
- path descoberto aponta AP novo
- input invalido bloqueia
- alvo explicito carrega context pack
