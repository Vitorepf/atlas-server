# AP-100 — Context Pack Manifest / Self-Reflection Contract

Status: implemented-operational-gate

## Objetivo

Transformar o `AiContextPack` em um artefato auditavel, expiravel e
autoavaliado antes de chegar ao provider. O Atlas nao deve injetar contexto
opaco no Open Brain sem saber quando ele foi montado, quais fontes carrega, se
esta vazio, se possui contradicoes ou se a execucao exige revisao humana.

## Nao Objetivo

Nao implementar:

- Graph RAG;
- fanout paralelo de especialistas;
- cache remoto de provider;
- nova memoria paralela;
- novo Open Brain;
- alteracao automatica de comportamento critico sem proposal/review.

## Fluxo

```text
Context Builder
-> AiContextPack
-> Context Pack Manifest
-> ContextPackSelfReflectionGate
-> Open Brain Context Injection
-> warnings / fail-closed quando required
-> audit summary / replay hash estavel
```

## Contrato Do Manifest

Todo `AiContextPack` deve carregar:

```text
schema_version: atlas.context_pack.manifest.v1
context_pack_id
created_at
expires_at
ttl_seconds
source_count
sources
context_ref_count
context_ref_hash
builder
```

Esse manifest serve para cache, auditoria, replay, expiracao local e
diagnostico de fonte. Timestamps existem no artefato, mas sao removidos do hash
operacional de replay para nao gerar drift entre execucoes equivalentes.

## Contrato Do Self-Reflection Gate

`ContextPackSelfReflectionGate` emite:

```text
schema_version: atlas.context_pack.self_reflection.v1
status: sufficient | insufficient | contradictory | risky
reasons
counts
recommended_action
assessed_at
```

Regras atuais:

- `sufficient`: ha contexto reutilizavel e sem sinal critico.
- `insufficient`: contexto vazio/fraco; em modo `required`, falha fechado.
- `contradictory`: sinais de conflito/contradicao; em modo `required`, falha fechado.
- `risky`: risco alto, aprovacao humana ou indicio sensivel; em modo `required`, falha fechado.

## Critérios De Aceite

- [x] `AiContextPack` adiciona manifest automaticamente quando o payload ainda
      nao possui um.
- [x] Manifest preserva `created_at`, `expires_at`, `ttl_seconds`, `sources` e
      `context_ref_hash`.
- [x] `ContextPackSelfReflectionGate` classifica contexto em quatro estados
      fechados.
- [x] Open Brain Context Injection inclui `summary.self_reflection`.
- [x] Prompt provider-safe inclui bloco `Context Pack Self-Reflection Gate`.
- [x] Warnings canonicos sinalizam contexto insuficiente, contraditorio ou
      arriscado.
- [x] Modo Open Brain `required` falha fechado para contexto insuficiente,
      contraditorio ou arriscado.
- [x] Hash operacional continua deterministico apesar de timestamps de manifest
      e `assessed_at`.
- [x] `atlas:ai:architecture-validate` expoe
      `ap100_context_pack_manifest_reflection_contract`.

## Arquivos

```text
app/Services/Ai/ValueObjects/AiContextPack.php
app/Services/Ai/Context/ContextPackSelfReflectionGate.php
app/Services/Ai/AtlasOpenBrainContextInjectionService.php
app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
app/Services/Ai/Kernel/Architecture/AtlasAiArchitectureValidationService.php
tests/Unit/Ai/Context/ContextPackSelfReflectionGateTest.php
tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateCommandTest.php
tests/Feature/Ai/AtlasAiArchitectureValidateApiTest.php
docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
```

## Status Operacional

AP-100 esta implementado como gate operacional do Open Brain. O Atlas agora
transporta manifest de contexto, avalia suficiencia/risco/contradicao antes da
injecao e preserva replay deterministico separando metadados temporais do hash
de decisao.
