---
title: AP Agent Context Pack
status: foundation-read-model-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentContextPack.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentContextPackTest.php
---

# AP-199 - AP Agent Context Pack

## 1. Proposito

AP-199 cria um pacote read-only de contexto para agentes antes de editar um AP.

Ele reune manifesto, governanca e dependencias em uma resposta compacta para um AP alvo.

## 2. Escopo Implementado

- `AtlasApAgentContextPack`
- busca por numero, `AP-123` ou slug
- resumo do AP alvo
- dependencias e dependentes via AP-198
- paths relacionados do AP alvo
- ordem recomendada de revisao
- bloqueio para AP inexistente ou mapa em `attention`

## 3. Autoridade

Schema: `atlas.ap_agent_context_pack.v1`  
Modo: `read_only_agent_context_pack`  
Autoridade: `ap_agent_context_pack_only_no_file_writes`

O pacote nao carrega corpo completo do doc, nao escreve arquivo e nao cria AP.

## 4. Regras

- AP alvo precisa existir no AP-193 manifest
- governanca precisa estar `ok`
- dependency map precisa estar `ok`
- dependentes devem ser revisados antes de alterar AP compartilhado
- paths relacionados sao contexto, nao autorizacao automatica para editar tudo

## 5. Beneficio

Antes de mexer em um AP, outro agente recebe o minimo contexto operacional certo.

Isso reduz leitura manual, esquecimento de dependentes e edicao fora de escopo.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao substitui leitura do AP alvo
- nao infere dependencia semantica
- nao executa testes

## 7. Definition of Done

- AP real carrega por slug
- AP de fixture carrega por numero
- AP inexistente bloqueia
- dependencia quebrada gera `attention`
