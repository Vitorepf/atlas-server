---
id: atlas-axis-n-area-focus-helper-surface
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Axis N AreaFocus Helper Surface
slug: atlas-axis-n-area-focus-helper-surface
status: building
implementation_state: helper_surface_extracted_from_axis_n_doc
category: agentic-engineering
priority: 92
summary: >
  Catalogo canonico enxuto da superficie de helpers AreaFocus que o Axis N usa
  para nao duplicar normalizacao, leitura/escrita JSON/JSONL, slugs, paths,
  providers, branch refs, evidence refs, clocks e payload parsing. Este doc
  existe para manter o doc principal Axis N abaixo do line_limit sem perder as
  regras de composicao que impedem wrappers locais e canos paralelos.
tags: [atlas-ai, axis-n, area-focus-loop, helper-surface, dedupe, documentation-split]
capabilities: [area_focus_helper_catalog, normalization_dedupe_contract, jsonl_io_surface_contract]
decisions:
  - Axis N e consumidores AreaFocus devem compor helpers existentes antes de criar wrappers locais de path, slug, scalar, list, provider, branch ou JSONL.
  - Helpers de leitura tolerante nao substituem leitores que precisam fail-closed, limite de bytes por linha ou semantica de corrupcao propria.
  - Helpers de escrita JSONL cobrem append/rewrite fisico; dedupe por id, prerequisito de status e lookup existente continuam no consumidor.
  - Owner-flow provider-proof continua com helper local proprio quando a regra e honestidade de merge, nao normalizacao generica.
maintenance:
  - Atualizar este doc quando helper AreaFocus novo virar superficie compartilhada ou quando um helper deixar de ser contrato canonico.
  - Nao re-expandir o catalogo dentro do doc Axis N; apontar para este arquivo.
risk_level: medium
owner: agentic_engineering_os/dev_forge
graph_id: atlas-axis-n-area-focus-helper-surface
graph_title: Atlas Axis N AreaFocus Helper Surface
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-axis-n-fleet-live-pilar2-foundry
graph_status: building
graph_source: repo
human_name: Atlas Axis N AreaFocus Helper Surface
canonical_name: Atlas Axis N AreaFocus Helper Surface
technical_name: AtlasAxisNAreaFocusHelperSurface
cartography_type: helper_surface
canonical_source: docs/engineering-knowledge-base/atlas-axis-n-area-focus-helper-surface.md
depends_on: [atlas-axis-n-fleet-live-pilar2-foundry, atlas-software-company-stewardship-stack]
flows_to: [atlas-axis-n-fleet-live-pilar2-foundry]
unlocks: [area_focus_dedupe_hygiene, axis_n_helper_composition]
governs: [area_focus_helper_usage, axis_n_normalization_surface]
authority_class: planner
related_paths:
  - docs/engineering-knowledge-base/atlas-axis-n-fleet-live-pilar2-foundry.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusAppendOnlyJsonlRecorder.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusJsonFileReader.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusJsonlReader.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusJsonlWriter.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopPayloadNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusPathNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusProviderNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusScalarNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusSlugNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusStringListNormalizer.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusUtcClock.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-axis-n-area-focus-helper-surface.md
evidence:
  - docs/engineering-knowledge-base/atlas-axis-n-fleet-live-pilar2-foundry.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Manter este doc como catalogo curto sempre que helper AreaFocus compartilhado mudar.
allowed_changes:
  - Refinar catalogo de helpers e regras de composicao quando o codigo AreaFocus mudar.
forbidden_changes:
  - Duplicar catalogo longo no doc Axis N.
  - Tratar helper tolerante como substituto de contrato fail-closed.
  - Trocar regra de honestidade de merge por normalizador generico.
requires_evidence: false
line_limit: 260
---

# Atlas Axis N AreaFocus Helper Surface

## Resumo

Superficie canonica curta dos helpers AreaFocus usados por Axis N e pelo loop de
stewardship. O objetivo e evitar wrappers locais para normalizacao, JSON/JSONL,
paths, slugs, providers, branch refs, evidence refs, clocks e listas.

## Papel no Atlas

Este doc guarda a superficie compartilhada de helpers AreaFocus que antes deixava
o doc Axis N inchado. O contrato e simples: quando um consumidor Axis N ou
AreaFocus precisa normalizar payload, path, slug, provider, listas, evidencia,
branch refs, JSON ou JSONL, ele consulta esta superficie antes de criar helper
local.

## Onde Se Encaixa

Fica abaixo do doc `atlas-axis-n-fleet-live-pilar2-foundry.md` e acima dos
consumidores AreaFocus. Ele nao governa runtime; governa composicao de helpers
para impedir duplicacao de canos e normalizadores.

## Contratos

- Helper tolerante nao substitui contrato fail-closed.
- Helper de escrita fisica nao substitui dedupe, prerequisito de status ou lookup.
- Provider-proof de merge continua regra local do owner-flow.
- Novo wrapper local precisa provar diferenca de semantica.

## Fluxo

Consumidor identifica uma borda comum, consulta este catalogo, compoe o helper
existente e mantem local apenas a regra de dominio realmente propria.

## Normalizacao Compartilhada

- `AreaFocusLoopPayloadNormalizer` cobre merge explicito de fixture, resolucao
  canonica de `repo_root`, variantes `repoRootOrEmpty` e `repoRootRaw`, remocao
  de campos volateis antes de hash e leitura comum de listas/mapas de payload.
- `AreaFocusPathNormalizer` cobre path repo-relativo, remocao de `./`, path local
  sem sufixo ` (deleted)`, conversao absoluto <-> repo-relativo e tokens de path.
- `AreaFocusScalarNormalizer` cobre strings opcionais, numeros finitos,
  booleanos estritos, clamps, choices e aliases com fallback explicito.
- `AreaFocusStringListNormalizer` cobre listas brutas, listas trimadas,
  dedupe em ordem de primeira aparicao, stringificacao de escalares, truthy
  legacy values e merges de blockers/warnings.
- `AreaFocusSlugNormalizer` cobre tokens `lower_snake`, fallbacks canonicos,
  slugs com hifen, tokens de fila/auditoria e componentes de path.
- `AreaFocusProviderNormalizer`, `AreaFocusCircuitStateNormalizer`,
  `AreaFocusScopeProfileNormalizer`, `AreaFocusAdmissionCandidateNormalizer`,
  `AreaFocusBranchRefNormalizer` e `AreaFocusEvidenceRefNormalizer` cobrem seus
  enums/aliases especificos sem espalhar parser local.

## JSON e JSONL

- `AreaFocusJsonlReader` e leitura tolerante append-only: linhas validas, filtro
  por schema, chave presente, chave string obrigatoria, ultimo registro por
  schema+chave, indices fisicos e streaming por schema. Nao usar quando o
  consumidor precisa fail-closed em corrupcao ou limite proprio de bytes/linha.
- `AreaFocusJsonFileReader` cobre somente o caso "arquivo JSON existe e decodifica
  para array"; fallbacks tipados continuam locais.
- `AreaFocusJsonlWriter` cobre append e rewrite fisico com diretorio garantido,
  lock exclusivo e `JSON_THROW_ON_ERROR`.
- `AreaFocusAppendOnlyJsonlRecorder` cobre o caso simples `projected` vs
  `recorded` com `schema_version`, `recorded_at` e status de storage. Dedupe por
  id, prerequisito de status, lookup existente e payload especifico continuam no
  consumidor.

## Tempo e Owner Flow

`AreaFocusUtcClock` centraliza timestamps UTC em `DateTimeInterface::ATOM` e
offset simples em segundos para TTL/retry-after. Classes com clock injetavel
mantem seu proprio seam.

No owner-flow, `owner_cli_provider_calls` permanece atras de helper local porque
provider-proof e regra de honestidade de merge. Consumidores nao devem reabrir
esse path com `data_get` espalhado.

## Regras para IA

Antes de adicionar helper local em AreaFocus ou Axis N, procure nesta superficie.
Se a regra nova for realmente diferente, documente a diferenca no consumidor; se
for a mesma, componha o helper existente.

## Escopo de Implementacao

Doc de catalogo e governanca de composicao. Nao cria runtime, scheduler, provider
ou gate novo.

## Dependencias

Depende dos helpers AreaFocus listados em `related_paths` e do doc Axis N que
referencia esta superficie.

## Evidencias

Evidencia vem dos paths de codigo listados, dos testes de helpers AreaFocus e de
`docs-health`.

## Riscos

Risco principal: trocar regra de dominio por helper generico e enfraquecer
contrato. Quando houver duvida, manter regra local e documentar a diferenca.

## Exemplos

Leitura JSONL tolerante deve usar `AreaFocusJsonlReader`; dedupe por `tick_id` ou
`decision_id` continua no servico que conhece o idempotency key.

## Proximas Acoes

Manter este doc curto. Se a superficie crescer, dividir por familia de helper em
docs menores em vez de re-inchar o doc Axis N.
