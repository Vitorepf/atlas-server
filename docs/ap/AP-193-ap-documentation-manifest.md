---
title: AP Documentation Manifest
status: foundation-read-model-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApDocumentationManifest.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApDocumentationManifestTest.php
---

# AP-193 - AP Documentation Manifest

## 1. Proposito

AP-193 cria um manifesto read-only dos APs existentes.

Ele oferece contexto estruturado para agentes e humanos sem exigir leitura manual de todos os markdowns.

## 2. Escopo Implementado

- `AtlasApDocumentationManifest`
- leitura de numero, slug, titulo, status, owner, linhas, limite e `related_paths`
- contagem por status e owner
- integracao com AP-188 para estado de governanca
- testes com repositorio real e fixture temporaria bloqueada

## 3. Autoridade

Schema: `atlas.ap_documentation_manifest.v1`  
Modo: `read_only_manifest`  
Autoridade: `ap_documentation_manifest_only_no_file_writes`

O manifesto nao substitui AP-188. Ele consome governanca e organiza contexto.

## 4. Regras

- nao escreve arquivo
- nao normaliza markdown
- nao cria AP
- ignora filename malformado e deixa AP-188 reportar o blocker
- nao permite trabalho novo quando a governanca esta em `attention`

## 5. Beneficio

Antes de mexer em uma frente, um agente pode ver quais APs existem, quais donos aparecem e qual e o proximo numero seguro.

Isso reduz duplicacao, reabertura de escopo antigo e implementacao fora de trilho.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera Architecture Operations
- nao altera scanner estatico
- nao substitui docs-health

## 7. Definition of Done

- manifesto real retorna `ok`
- fixture com duplicata retorna `attention`
- status e owner sao agregados
- guardrails provam ausencia de escrita
