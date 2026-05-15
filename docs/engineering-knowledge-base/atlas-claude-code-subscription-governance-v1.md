---
id: atlas-claude-code-subscription-governance-v1
type: engineering_knowledge
title: Atlas Claude Code Subscription Governance v1
status: active
category: provider-governance
priority: 100
summary: Regra canonica para usar Claude Code no Atlas sem API, sem creditos extras e sem confundir uso interativo com `claude -p`, Agent SDK ou worker headless.
tags:
  - atlas
  - atlas-code
  - claude-code
  - anthropic
  - subscription-only
  - provider-governance
capabilities:
  - claude_code_interactive_observed
  - subscription_only_provider_mode
  - no_api_no_extra_credits
  - provider_policy_guardrails
decisions:
  - A partir de 15 de junho de 2026, Claude Agent SDK, `claude -p`, Claude Code GitHub Actions e apps terceiros via Agent SDK usam credito mensal separado.
  - Claude Code interativo no terminal/IDE continua usando os limites normais da assinatura Claude.
  - Para o limite pessoal do operador, Atlas deve tratar Claude Code como runtime interativo observado, nao como backend headless.
  - Sem API e sem creditos extras, Atlas deve bloquear ou pausar caminhos Claude headless em modo subscription-only.
  - O fluxo eficiente e Atlas governar Obra, contexto, escopo, gates, evidencia e review enquanto o operador usa Claude Code interativo local.
maintenance:
  - Atualize este doc quando Anthropic alterar Claude Code, Agent SDK, billing, termos, uso programatico ou limites de assinatura.
  - Leia este doc antes de alterar `claude_cli`, `ClaudeCliProvider`, AI workers, Forge Provider Invocation ou Atlas Code composer.
  - Nao declarar fluxo Claude subscription-only como ilimitado, autonomo, backend, worker ou producao.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md
  - config/atlas.php
  - app/Services/Ai/ClaudeCliProvider.php
  - app/Services/Ai/AiWorker.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
external_sources:
  - https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan
  - https://support.claude.com/en/articles/11145838-use-claude-code-with-your-pro-or-max-plan
  - https://www.anthropic.com/legal/consumer-terms
  - https://www.anthropic.com/legal/aup
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-claude-code-subscription-governance-v1
graph_title: Atlas Claude Code Subscription Governance v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-ai-provider-evolution-intelligence
graph_status: active
graph_source: repo
owner: provider-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
allowed_changes:
  - Adicionar novos modos de provedor desde que mantenham separacao explicita entre interativo, Agent SDK/headless e API.
  - Adicionar preflights, gates, labels de UX, packet builder, terminal adapter e watchers para Claude Code interativo observado.
forbidden_changes:
  - Tratar `claude -p`, Agent SDK, GitHub Actions ou worker background como Claude Code interativo.
  - Fazer fallback silencioso para API key, PAYG, extra usage ou credito extra.
  - Usar assinatura pessoal Claude como backend multiusuario, servico compartilhado ou automacao de produto.
  - Compartilhar, poolar ou rotear contas Claude para aumentar capacidade.
  - Mascarar automacao headless como uso humano interativo.
depends_on:
  - atlas-ai-thesis-multiplier-channel
  - atlas-ai-provider-evolution-intelligence
  - atlas-programming-forge-flow
flows_to:
  - atlas-code
  - atlas-forge-operating-system
unlocks:
  - claude_code_interactive_observed_runtime
governs:
  - claude_cli
  - atlas_code_composer
  - ai_worker
  - forge_provider_invocation
evidence:
  - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - provider-governance
  - claude-code
  - subscription-only
ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA e Riscos antes de implementar qualquer uso de Claude Code no Atlas.
ai_usage_notes:
  - Se o operador exigir sem API e sem creditos extras, escolha `claude_code_interactive_observed` ou `atlas-local`; nao escolha `claude_cli` headless.
quality_gates:
  - no-api-key-fallback
  - no-claude-headless-in-subscription-only
  - operator-presence-required
  - evidence-ledger-required
failure_modes:
  - Worker Claude consumir Agent SDK credit sem o operador perceber.
  - `ANTHROPIC_API_KEY` presente transformar Claude Code em PAYG/API.
  - Atlas Code composer enfileirar job `claude_cli` por default em modo subscription-only.
  - Produto chamar Claude como backend multiusuario usando assinatura pessoal.
observability_signals:
  - provider_mode
  - claude_invocation_mode
  - external_provider_call
  - operator_presence
  - api_key_detected
  - agent_sdk_credit_risk
  - evidence_ledger_event
next_actions:
  - Implementar modo `claude_code_interactive_observed`.
  - Adicionar preflight para bloquear API keys no modo subscription-only.
  - Desabilitar workers Claude por default quando `ATLAS_CLAUDE_SUBSCRIPTION_ONLY=true`.
---
# Atlas Claude Code Subscription Governance v1

## Resumo

Este documento governa o uso de Claude Code no Atlas quando o operador define:

```text
sem Anthropic API
sem creditos extras
sem PAYG
somente assinatura Claude Code do operador
```

Decisao central:

```text
Claude Code interativo observado: permitido.
Claude headless/programatico como backend: bloqueado no modo subscription-only.
```

## Papel no Atlas

Atlas nao compete com Claude no nivel de modelo. Atlas substitui o uso direto
como canal operacional: Obra, contexto, escopo, gates, evidencia, review,
memoria e learning.

Neste modo, Claude Code e o executor interativo local. Atlas continua sendo o
sistema operacional enterprise acima do provider.

## Onde Se Encaixa

Este doc governa:

- Atlas Code composer quando uma intent poderia virar `claude_cli`;
- AI workers que executam providers;
- `ClaudeCliProvider`;
- Forge Provider Invocation;
- qualquer futuro adapter Anthropic;
- UX de "usar minha assinatura Claude Code".

Nao governa API/PAYG de producao. API fica fora do limite declarado pelo
operador.

## Contratos

Fontes oficiais:

- Agent SDK credit: <https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan>
- Claude Code Pro/Max: <https://support.claude.com/en/articles/11145838-use-claude-code-with-your-pro-or-max-plan>
- Consumer Terms: <https://www.anthropic.com/legal/consumer-terms>
- Usage Policy: <https://www.anthropic.com/legal/aup>

Regra que comeca em **15 de junho de 2026**:

| Categoria | Exemplo | Regime |
| --- | --- | --- |
| Interativo | `claude` no terminal/IDE | limite normal da assinatura |
| Programatico/headless | Agent SDK, `claude -p`, GitHub Actions, apps terceiros via Agent SDK | credito mensal Agent SDK |
| API | `ANTHROPIC_API_KEY`, Console, cloud providers | PAYG/API |

Credito mensal Agent SDK anunciado:

| Plano | Credito |
| --- | ---: |
| Pro | US$20 |
| Max 5x | US$100 |
| Max 20x | US$200 |
| Team Standard | US$20 por usuario elegivel |
| Team Premium | US$100 por usuario elegivel |
| Enterprise usage-based | US$20 |
| Enterprise Premium seat-based | US$200 |

Creditos sao por usuario, nao podem ser poolados, renovam mensalmente e nao
acumulam. Se acabarem, chamadas param ate renovar quando extra usage estiver
desativado; com extra usage ativado, passam para cobranca extra/API rates.

## Fluxo

Fluxo eficiente para programacao pesada sem API e sem creditos extras:

```text
Atlas Code / Forge
-> Obra
-> Forge Workspace
-> Spec / Plan / Task Contract
-> Claude Code Packet
-> terminal interativo local
-> operador inicia `claude`
-> Atlas observa diff/testes/evidencias
-> Atlas valida gates
-> Atlas aprova, bloqueia ou pede reparo
```

O Claude Code Packet deve conter:

- objetivo em uma frase;
- contexto tecnico essencial destilado pelo Atlas;
- arquivos permitidos e proibidos;
- criterios de aceite;
- comandos de verificacao;
- formato do relatorio final;
- regra de parar quando sair do escopo.

Atlas deve observar localmente:

- `git diff`;
- arquivos modificados;
- testes/lint/build;
- arquivos sensiveis tocados;
- saida de gates;
- relatorio final importado pelo operador.

## Regras para IA

Agentes e implementacoes devem respeitar:

- nunca chamar `claude -p` no modo subscription-only;
- nunca usar Agent SDK nesse modo;
- nunca fazer fallback para `ANTHROPIC_API_KEY`;
- nunca iniciar worker Claude em background;
- nunca tratar assinatura pessoal como backend multiusuario;
- nunca mascarar automacao como interacao humana;
- pausar quando limite acabar;
- registrar evidencia antes de completion claim.

Config alvo:

```text
ATLAS_CLAUDE_SUBSCRIPTION_ONLY=true
claude_headless_enabled=false
agent_sdk_enabled=false
anthropic_api_enabled=false
workers_claude_enabled=false
queue_execution_for_claude=false
provider_mode=claude_code_interactive_observed
concurrency=1
require_operator_presence=true
pause_on_limit=true
api_fallback=false
```

## Escopo de Implementacao

Permitido:

- preflight local de ambiente;
- packet builder por Obra;
- terminal adapter que abre workspace para uso interativo;
- git/file watcher;
- gates locais;
- Evidence Ledger;
- UX clara de "Modo Local Assistido".

Bloqueado no modo subscription-only:

- `claude_cli` via worker;
- `claude -p`;
- Agent SDK;
- GitHub Actions Claude;
- API/PAYG;
- provider invocation `execute` com Claude externo;
- fallback silencioso para outra conta ou credito extra.

## Dependencias

Dependencias arquiteturais:

- tese do canal soberano;
- provider evolution intelligence;
- programming.forge;
- Forge Provider Invocation;
- Fast Path e Live Execution locais;
- Evidence Ledger.

Dependencias operacionais:

- Obra valida;
- Forge Workspace;
- operador presente;
- Claude Code logado por assinatura;
- API keys ausentes do ambiente da sessao;
- gates locais configurados.

## Evidencias

Cada execucao deve registrar:

- `obra_id`;
- operador;
- provider mode `claude_code_interactive_observed`;
- packet id/hash;
- decision receipt;
- diff hash;
- comandos executados;
- resultado dos gates;
- blockers;
- aprovacao humana ou motivo de pausa.

Nao registrar OAuth tokens, cookies, API keys ou credenciais.

## Riscos

Riscos principais:

- `config/atlas.php` usar `claude_cli` como default;
- `ClaudeCliProvider` usar `-p --output-format stream-json`;
- AI worker chamar provider real sem operador;
- LaunchAgent manter worker Claude vivo;
- `ANTHROPIC_API_KEY` transformar uso em PAYG/API;
- Forge Provider Invocation executar `claude_cli`;
- produto virar passthrough multiusuario de assinatura pessoal.

Consequencias oficiais possiveis em violacoes de policy/termos incluem
throttling, suspensao, encerramento de acesso, bloqueio/modificacao de outputs
e encerramento de assinatura conforme termos.

## Exemplos

Permitido:

```text
Atlas gera packet -> abre terminal -> operador roda `claude` -> Atlas valida diff/testes.
```

Bloqueado:

```text
Atlas worker -> `claude -p` -> prompt via stdin -> resposta stream-json.
```

Ao bater limite:

```text
pausar -> registrar motivo -> manter Obra aberta -> aguardar reset.
```

## Proximas Acoes

1. Implementar kill switch `ATLAS_CLAUDE_SUBSCRIPTION_ONLY`.
2. Bloquear Claude headless quando subscription-only estiver ativo.
3. Criar preflight para API keys e operador presente.
4. Criar Claude Code Packet Builder por Obra.
5. Criar terminal adapter para sessao interativa.
6. Criar watchers de diff/arquivos proibidos.
7. Ligar gates e Evidence Ledger ao fluxo observado.
