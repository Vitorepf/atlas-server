---
title: AP Dependency Map
status: foundation-read-model-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApDependencyMap.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApDependencyMapTest.php
---

# AP-198 - AP Dependency Map

## 1. Proposito

AP-198 cria um mapa read-only de dependencias entre APs.

Ele mostra quais APs citam ou dependem explicitamente de outros APs antes de uma mudanca ser feita.

## 2. Escopo Implementado

- `AtlasApDependencyMap`
- consumo do AP-193 manifest
- leitura de `depends_on` em frontmatter
- leitura de referencias explicitas `AP-123` no corpo
- agregacao de `dependencies_by_ap` e `dependents_by_ap`
- deteccao de referencias para AP ausente
- testes com repo real e fixtures temporarias

## 3. Autoridade

Schema: `atlas.ap_dependency_map.v1`  
Modo: `read_only_dependency_map`  
Autoridade: `ap_dependency_map_only_no_file_writes`

O mapa nao infere dependencia semantica, nao normaliza docs e nao escreve arquivo.

## 4. Regras

- somente referencias explicitas viram arestas
- auto-referencia do proprio AP e ignorada
- `depends_on` e tratado como fonte forte da aresta
- referencia em corpo e tratada como fonte informativa
- AP citado e ausente gera `missing_references`

## 5. Beneficio

Antes de editar um AP, o agente consegue ver quais docs dependem dele.

Isso reduz quebra indireta, duplicacao de escopo e mudanca em cadeia sem revisao.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera Architecture Operations
- nao usa matching semantico
- nao substitui AP-194

## 7. Definition of Done

- repo real gera mapa read-only
- fixture com `depends_on` gera aresta
- fixture com referencia no corpo gera aresta
- AP ausente aparece em `missing_references`
