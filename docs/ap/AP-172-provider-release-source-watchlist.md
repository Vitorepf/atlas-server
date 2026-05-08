# AP-172 - Provider Release Source Watchlist

Status: foundation-registry-implemented

## Problema

Atlas precisa detectar diariamente lancamentos de Anthropic, OpenAI, Google,
Cursor, Microsoft, Meta, Apple, xAI e outros labs sem depender de memoria de
chat, hype no X/Twitter ou scraping solto. A lista de fontes deve ser governada,
versionada e ligada ao Provider Release Envelope.

## Regra

Monitorar fonte nao autoriza implementacao. Toda novidade relevante deve passar
por:

```text
Source Watch
-> Provider Release Candidate
-> Provider Release Envelope
-> owner docs/AP/backlog
-> benchmark/Rivals quando afetar qualidade
-> Atlas Decide signal, nunca hardcode direto
-> Curator/Inbox para promocao revisavel
```

O crawler deve respeitar robots, rate limit, termos do site, cache, ETag/Last
Modified quando disponivel, e nunca burlar login, paywall, beta privada ou URL
nao publicada. Fonte nao oficial vira sinal fraco ate confirmacao primaria.

## Tier 1 - Fontes Oficiais Diarias

| Provider | Fonte | URL | Rastrear |
|---|---|---|---|
| Anthropic | Research | `https://www.anthropic.com/research` | research, safety, evals, agents |
| Anthropic | News | `https://www.anthropic.com/news` | launches, managed agents, verticals |
| Anthropic | Release notes | `https://docs.anthropic.com/en/release-notes/overview` | API, models, tools, deprecations |
| Anthropic | API release notes | `https://docs.anthropic.com/en/release-notes/api` | breaking changes, pricing-adjacent limits |
| OpenAI | News | `https://openai.com/news` | models, products, agents |
| OpenAI | Research news | `https://openai.com/newsroom/research/` | research, evals, safety |
| OpenAI | API changelog | `https://platform.openai.com/docs/changelog` | API, models, platform features |
| OpenAI | Models docs | `https://platform.openai.com/docs/models` | model availability/deprecation |
| Google | AI blog | `https://blog.google/technology/ai/` | Gemini, product AI, agents |
| Google DeepMind | Blog | `https://deepmind.google/blog/` | frontier research, agents, science |
| Google | Gemini API release notes | `https://ai.google.dev/gemini-api/docs/changelog` | Gemini API, model changes |
| Microsoft | Copilot release notes | `https://learn.microsoft.com/en-us/microsoft-365/copilot/release-notes` | Office/Copilot enterprise |
| Microsoft | Copilot blog releases | `https://www.microsoft.com/en-us/microsoft-copilot/blog/content-type/release-notes/` | Copilot capabilities |
| GitHub | Copilot changelog | `https://github.blog/changelog/label/copilot/` | coding agent/coding UX |
| Cursor | Changelog | `https://www.cursor.com/changelog` | coding IDE, agent modes |
| Meta | AI blog | `https://ai.meta.com/blog/` | Llama, agents, open models |
| Apple | Machine Learning Research | `https://machinelearning.apple.com/` | on-device AI, privacy, local models |
| Apple | Developer News | `https://developer.apple.com/news/` | APIs, Apple Intelligence, platform changes |
| xAI | News | `https://x.ai/news` | Grok/model/platform launches |

## Tier 2 - Fontes Tecnicas Semanais

| Fonte | URL | Uso |
|---|---|---|
| OpenAI Docs MCP | `https://platform.openai.com/docs/docs-mcp` | checar docs oficiais via MCP quando possivel |
| Google AI for Developers | `https://ai.google.dev/` | docs tecnicas e Gemini API |
| Microsoft Learn AI | `https://learn.microsoft.com/en-us/ai/` | Copilot/AI platform docs |
| GitHub Blog AI | `https://github.blog/ai-and-ml/` | coding, Copilot, developer workflows |
| Hugging Face Blog | `https://huggingface.co/blog` | open models/ecossistema, confirmar depois em fonte primaria |
| Papers with Code | `https://paperswithcode.com/` | benchmark/research signal, nao release authority |

## Tier 3 - Sinais Fracos

Noticias, Reddit, X/Twitter, Hacker News, newsletters, podcasts e leaks podem
abrir candidate, mas nao podem virar AP, policy ou routing sem fonte primaria.

Regras:

- classificar como `unconfirmed_signal`;
- exigir `primary_source_required=true`;
- nao alimentar Atlas Decide;
- nao alterar provider projection;
- nao executar benchmark pago sem confirmacao.

## Classificacao Automatica

Cada item detectado deve virar candidate com:

- `provider`;
- `source_url`;
- `source_tier`;
- `published_at`;
- `detected_at`;
- `title`;
- `hash`;
- `release_type`;
- `affected_domains`;
- `affected_surfaces`;
- `affected_runtimes`;
- `possible_capabilities`;
- `recommended_triage_action`.

`release_type` deve usar o vocabulario do Provider Evolution:

- `vertical_agents`;
- `model`;
- `connector`;
- `tool_use`;
- `realtime`;
- `memory`;
- `coding`;
- `design`;
- `marketing`;
- `finance`;
- `capability_update`;
- `deprecation`;
- `pricing_or_limits`;
- `safety_policy`.

## Frequencia

- Tier 1: diario.
- Tier 2: semanal ou quando release Tier 1 apontar para docs.
- Tier 3: opcional, nunca bloqueante.
- Deprecations, pricing, safety policy e model availability: daily + alert
  quando mudar hash.

## Saidas Do Atlas

O watcher deve produzir apenas:

- Provider Release Candidate;
- Provider Release Envelope draft;
- Inbox item para review humano;
- Curator finding;
- Governed Backlog candidate;
- AP suggestion;
- Rivals benchmark suggestion.

Nao deve produzir:

- codigo direto;
- policy patch automatico;
- provider routing automatico;
- memoria canonica sem review;
- claims de superioridade sem benchmark.

## Definition Of Done Para Implementacao

1. Criar source registry versionado com URL, tier, provider e cadence.
2. Criar fetcher idempotente com cache/hash e rate limit.
3. Persistir candidates append-only ou auditaveis.
4. Dedupar por canonical URL + content hash.
5. Classificar release_type e domains com heuristica conservadora.
6. Gerar Provider Release Envelope draft.
7. Abrir Inbox/Curator para releases high-impact.
8. Integrar com `atlas:ai:provider-release-review`.
9. Expor CLI/API/MCP read-only de status e candidates.
10. Testar no-network fixtures para cada provider Tier 1.

## Fundacao Implementada

Implementado como bloco lateral seguro, sem crawler, API, MCP, scheduler,
Architecture Operations ou Atlas Decide:

- `AtlasProviderReleaseSourceRegistry`: registry read-only com fontes, filtros,
  contagens, guardrails e candidate a partir de URL/titulo;
- `ProviderReleaseCandidateFingerprint`: canonical URL, content hash e dedupe
  key deterministico para o crawler futuro;
- `ProviderReleaseSourceTrustPolicy`: policy Tier 1/2/3, outputs permitidos e
  outputs proibidos;
- `ProviderReleaseSource`: value object provider-safe;
- `tests/Fixtures/Ai/provider-release-source-candidates.json`: corpus offline para
  releases/candidates sem depender de rede;
- `AtlasProviderReleaseSourceRegistryTest`: cobre fontes oficiais, filtros,
  candidate Tier 1, weak signal, fixtures offline, fingerprint/dedupe e
  proibicao de policy/routing/code/memory write.

O Codex principal deve conectar depois, se aprovado, em command/API/MCP,
Architecture Operations, Curator, Evidence Ledger e scanner. Esta fundacao nao
faz rede e nao promove release sozinha.

## Nao Escopo

Esta AP nao autoriza scraping agressivo, bypass de site, ingestao de conteudo
copyright extenso, rumores como verdade, auto-implementacao ou mudanca de
Atlas Decide. O objetivo e vigilancia governada, nao news feed.
