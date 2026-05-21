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
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/domains/finance.md
  - docs/engineering-knowledge-base/domains/marketing.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/ap/AP-172-provider-release-source-watchlist.md
owner: atlas-ai
layer: 0.5-and-2
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-provider-evolution-intelligence

graph_title: Atlas AI Provider Evolution Intelligence

graph_world: atlas

graph_layer: system

graph_kind: policy

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Provider Evolution Intelligence
canonical_name: Atlas AI Provider Evolution Intelligence
technical_name: atlas-ai-provider-evolution-intelligence
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - strategic-governance

evidence:
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - policy
  - strategic-governance

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Provider Evolution Intelligence

Este documento governa como Atlas reage a novidades de Anthropic, OpenAI,
Google/Gemini, Codex, Cursor, Apple, Meta, xAI e futuros labs.

O objetivo nao e acompanhar noticias por curiosidade. O objetivo e transformar
cada avanco externo em vantagem interna do Atlas.

## Tese Operacional

Atlas nao compete no nivel do modelo bruto. Atlas substitui o uso direto dos
providers como canal operacional.

Quando um provider melhora, Atlas deve melhorar junto e multiplicar o ganho por
memoria soberana, contexto pessoal/empresarial, Atlas Decide, skill packs,
connectors, Evidence Ledger, Quality Gates, Curator, Rivals, human review e
continuidade multi-provider.

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

Superficies read-only disponiveis:

- `php artisan atlas:ai:provider-release-sources --json`;
- `GET /ai/provider-release-sources`;
- `php artisan atlas:ai:provider-release-sources --url="<url>" --title="<title>" --json`.

Essas superficies apenas listam watchlist ou candidate preview. Elas nao fazem
crawler, nao escrevem envelope, nao alteram policy e nao mudam Atlas Decide.
O output inclui `continuous_ingestion_contract` fail-closed com `promotion_allowed=false`: crawler futuro
precisa de AP dedicado, rate limits, canonical URL/hash, source gate, Ledger,
Rivals/AP-99 e review humano antes de qualquer rede, write ou signal.
O `future_activation_review_contract` tambem carrega `promotion_allowed=false` e bloqueia background crawler, Decide
signal, policy patch, default model, domain maturity e credenciais sem AP dedicado, rate limit, source gate, Ledger, AP-99/Rivals e review humano.

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

Absorver: workflows como gaps/flows Finance, skill + connector + subagent,
provider runtime opcional, Office/Excel/PPT como surface futura, human sign-off,
audit log e Rivals-Finance. Nao chamar Claude Finance direto fora do Atlas,
hardcodar "finance sempre Claude", declarar Atlas Finance pronto sem benchmark
ou copiar skill sem policy, privacy, evidence e review.

## Vertical Skill Pack Protocol

Quando provider lancar vertical forte, Atlas extrai flows, skills, commands,
connectors, specialist profiles/subagents, templates, gates, evidence schema,
human review policy e rivals benchmark. Isso vira `Domain Skill Pack`, nao
prompt solto.

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
  --url="https://www.anthropic.com/news/finance-agents" \
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

O output inclui tres blocos de fonte:

- `source_candidate`: candidato derivado da URL/titulo, com fonte, tier,
  canonical URL, hash e acao de triagem;
- `source_gate`: diz se pode criar envelope draft ou se a classificacao fica
  pendente de fonte primaria;
- `source_registry_context`: contexto read-only do watchlist governado. Ele nao
  faz rede, nao muda policy e nao altera roteamento.

Sem fonte primaria, o review pode classificar, mas o envelope fica
`classification_only_pending_primary_source` e `source_trust_allows_signal=false`.
Isso evita que rumor ou noticia secundaria vire implementacao.

O output tambem inclui:

- `anti_wrapper_contract`: contrato que fixa Atlas como camada acima dos
  providers. Release externo deve virar benchmark, skill pack, adapter,
  signal, proposal ou archive; nunca canal direto, default de modelo ou
  maturidade de domain por marketing;
- `review_signal`: status, severidade, evidence required e stop-the-line para
  impedir rota de Decide antes de fonte primaria/Rivals/AP-99/human review;
- `absorption_plan`: plano proposal-only com stages de envelope, Rivals, skill
  pack/adapter, Decide signal e review humano. Nunca altera routing, default
  model, policy, credenciais ou maturidade; `promotion_gate` exige source, owner
  doc, Rivals/AP-99, review humano e novo Decision Receipt. O
  `promotion_gate` exige source, owner doc, Rivals/AP-99, review humano e novo
  `promotion_review_packet` fixa decisao humana, rollback, evidence e proibicoes;
- `curator_proposal`: proposta revisavel para
  `self_improvement.provider_release_review`, sempre `auto_apply=false`.

Para surfaces externas, use API ou MCP read-only:
`GET /ai/provider-release-sources?...`, `GET /ai/provider-release-review?...`,
tool `atlas_provider_release_sources` ou tool `atlas_provider_release_review`.

O Curator dedicado roda sem autoaplicar mudancas:

```bash
php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json
```

O flow tambem faz parte do schedule default de Self-Improvement via
`AtlasSelfImprovementScheduleService`. Isso significa observacao recorrente e
proposal-only, nao crawler: a watchlist continua read-only, rede continua
desligada neste contrato, e nenhuma mudanca de policy/Decide acontece sem
envelope, source gate, Rivals/AP-99 e review humano.

## Onde Atlas Deve Construir, Nao Absorver

Mesmo com verticals dos labs, Atlas deve construir onde providers nao podem:
canal unico pessoal, memoria soberana multi-decade, continuidade entre
providers, Evidence Ledger proprio, business contexts privados, cognitive
development, governanca documental, AtlasVault humano, Curator longitudinal,
neutralidade real, privacy local/mac/mobile e Rivals cross-provider.

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

## Resumo

Contrato canonico para detectar, classificar, medir e absorver lancamentos de Claude, ChatGPT, Gemini, Codex e futuros labs sem transformar Atlas em wrapper fragil.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
