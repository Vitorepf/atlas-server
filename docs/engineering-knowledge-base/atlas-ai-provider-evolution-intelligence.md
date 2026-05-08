---
id: atlas-ai-provider-evolution-intelligence
type: engineering_knowledge
title: Atlas AI Provider Evolution Intelligence
status: active
category: strategic-governance
priority: 100
summary: Contrato canonico para detectar, classificar, medir e absorver lancamentos de Claude, ChatGPT, Gemini, Codex e futuros labs sem transformar Atlas em wrapper fragil.
tags:
  - atlas-ai
  - provider-evolution
  - release-ingestion
  - rivals
  - antifragile
capabilities:
  - provider_release_ingestion
  - provider_evolution_intelligence
  - rivals_benchmarking
  - vertical_skill_pack_protocol
  - provider_capability_absorption
decisions:
  - Atlas nao compete com labs no nivel do modelo; substitui o uso direto deles como canal operacional.
  - Todo lancamento relevante de provider vira Provider Release Envelope antes de virar codigo.
  - Release forte deve ser catalogado, comparado, posicionado, medido e absorvido ou descartado.
  - Nenhuma novidade de provider pode virar hardcode em surface/domain; entra via Decide, skill pack, connector, runtime, AP ou benchmark.
  - Rivals valida se Atlas+provider multiplica o provider direto; multiplicador negativo e stop-the-line no escopo afetado.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar quando providers lancarem nova classe de agente, connector, realtime, memory, coding, design, finance, marketing ou managed agent.
related_paths:
  - app/Console/Commands/AtlasAiProviderReleaseReviewCommand.php
  - app/Services/Ai/Kernel/Architecture/AtlasProviderReleaseIntelligenceService.php
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/marketing.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/ap/AP-172-provider-release-source-watchlist.md
owner: atlas-ai
layer: 0.5-and-2
line_limit: 260
---

# Atlas AI Provider Evolution Intelligence

Este documento governa como Atlas reage a novidades de Anthropic, OpenAI,
Google/Gemini, Codex, Cursor, Apple, Meta, xAI e futuros labs.

O objetivo nao e acompanhar noticias por curiosidade. O objetivo e transformar
cada avanco externo em vantagem interna do Atlas.

## Tese Operacional

Atlas nao compete no nivel do modelo bruto. Atlas substitui o uso direto dos
providers como canal operacional.

Quando um provider melhora, Atlas deve melhorar junto e multiplicar o ganho por:

- memoria soberana;
- contexto pessoal/empresarial;
- Atlas Decide;
- skill packs;
- connectors;
- Evidence Ledger;
- Quality Gates;
- Curator;
- Rivals;
- human review;
- continuidade multi-provider.

Se uma novidade deixa um modulo Atlas obsoleto, o modulo deve virar adapter,
benchmark ou ser removido. Nunca defender codigo por orgulho.

## Provider Release Envelope

Todo release relevante deve virar envelope antes de implementacao:

```json
{
  "schema_version": "atlas.provider_release.v1",
  "provider": "anthropic|openai|google|codex|cursor|apple|other",
  "release_id": "anthropic-finance-agents-2026-05",
  "release_type": "vertical_agents|model|connector|tool_use|realtime|memory|coding|design|marketing",
  "affected_domains": ["finance"],
  "affected_surfaces": ["cli", "office", "api"],
  "affected_runtimes": ["provider_driver", "connector_registry"],
  "capabilities": ["pitch_builder", "kyc_screener"],
  "connectors": ["factset", "capital_iq"],
  "threat_to_wrappers": "high",
  "threat_to_atlas": "low|medium|high",
  "potential_multiplier": "low|medium|high",
  "recommended_action": "absorb|benchmark|bypass|replace|exploit_gap"
}
```

## Pipeline Canonico

```text
Provider release
-> Detectar
-> Catalogar capabilities / agents / skills / connectors / surfaces
-> Comparar com domains Atlas
-> Criar gap report
-> Criar APs ou backlog governado
-> Rodar Rivals / benchmark quando afeta qualidade real
-> Atualizar Atlas Decide, skill packs, connectors ou runtime adapters
-> Medir uso real no Evidence Ledger
-> Curator revisa resultado e propoe proxima acao
```

Fontes padrao de monitoramento diario vivem em
`docs/ap/AP-172-provider-release-source-watchlist.md`. Elas geram candidates,
nao implementacao direta.

## Cinco Acoes Possiveis

| Acao | Quando usar | Saida |
|---|---|---|
| `absorb` | capability multiplica Atlas | driver, skill pack, connector, runtime adapter |
| `benchmark` | parece forte, mas sem evidencia | Rivals suite + AP-99 signal |
| `bypass` | marketing/wrapper sem ganho real | source material ou archive |
| `replace` | modulo Atlas ficou inferior | adapter/substituicao governada |
| `exploit_gap` | release revela zona que provider nao cobre | AP proprio em Core/Domain/Surface |

## Exemplo: Anthropic Finance Agents

Classificacao desejada:

```text
release_type: vertical_agents
domain: finance
potential_multiplier: high
threat_to_wrappers: high
threat_to_atlas: low, se Atlas absorver
recommended_action: benchmark_and_absorb
```

Absorver:

- 10 workflows como gaps/flows Finance;
- formato skill + connector + subagent;
- Claude plugin como provider runtime opcional;
- Office/Excel/PPT como surface futura;
- human sign-off e audit log como gates;
- Rivals-Finance como benchmark obrigatorio.

Nao fazer:

- chamar Claude Finance direto fora do Atlas;
- hardcodar "finance sempre Claude";
- declarar Atlas Finance pronto sem Rivals-Finance;
- copiar skill sem adaptar a policy, privacy, evidence e review do Atlas.

## Vertical Skill Pack Protocol

Quando provider lancar vertical forte, Atlas deve extrair:

1. flows nomeados;
2. skills reutilizaveis;
3. slash commands ou comandos equivalentes;
4. connectors;
5. specialist profiles/subagents;
6. templates;
7. gates;
8. evidence schema;
9. human review policy;
10. rivals benchmark.

Isso vira `Domain Skill Pack`, nao prompt solto.

## Impacto Em Atlas Decide

Release novo entra em Decide como sinal temporario:

- aumenta prioridade de benchmark;
- sugere provider/runtime candidato;
- adiciona capability matrix;
- nunca muda roteamento critico sem evidence, policy ou manual override auditado.

AP-99 e Rivals decidem a promocao de sinal para policy.

## Superficie Executavel

Use antes de qualquer implementacao baseada em lancamento externo:

```bash
php artisan atlas:ai:provider-release-review \
  --provider=anthropic \
  --title="Anthropic Finance Agents" \
  --type=vertical_agents \
  --domain=finance \
  --json
```

Para iniciar sessao sem contexto antigo:

```bash
php artisan atlas:ai:session-bootstrap \
  --task="analisar Anthropic Finance Agents" \
  --json
```

O placement correto deve retornar `layer=provider_evolution` e
`flow=provider_evolution.review`.

Para surfaces externas, use API ou MCP read-only:

```bash
GET /ai/provider-release-review?provider=anthropic&title=Anthropic%20Finance%20Agents&domain[]=finance
```

```json
{
  "tool": "atlas_provider_release_review",
  "title": "Anthropic Finance Agents",
  "provider": "anthropic",
  "domain": ["finance"]
}
```

O Curator dedicado roda sem autoaplicar mudancas:

```bash
atlas ai self-improve --flow=provider_release_review --hours=168 --json
```

## Onde Atlas Deve Construir, Nao Absorver

Mesmo com verticals dos labs, Atlas deve construir onde providers nao podem:

- canal unico pessoal;
- memoria soberana multi-decade;
- continuidade entre providers;
- Evidence Ledger proprio;
- business contexts privados;
- cognitive development;
- governanca documental;
- AtlasVault humano;
- Curator longitudinal;
- neutralidade real;
- privacy local/mac/mobile;
- Rivals cross-provider.

## Definition Of Done

Um release foi processado corretamente quando:

1. existe Provider Release Envelope;
2. domains/surfaces/runtimes afetados foram declarados;
3. owner docs foram atualizados;
4. gaps viraram AP/backlog ou foram descartados com motivo;
5. Rivals existe quando impacto em qualidade e alto;
6. Decide recebeu sinal sem hardcode;
7. Evidence Ledger mede uso real;
8. Curator tem acao revisavel.
