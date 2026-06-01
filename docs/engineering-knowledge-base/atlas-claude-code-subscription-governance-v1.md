---
id: atlas-claude-code-subscription-governance-v1
type: engineering_knowledge
title: Atlas Claude Code Subscription Governance v1
status: active
category: provider-governance
priority: 100
summary: Regra canonica para usar Claude Code no Atlas sem API e sem creditos extras, separando uso interativo observado, uso programatico experimental/Rivals antes do cutoff e bloqueio futuro por flag/data.
tags:
  - atlas
  - atlas-code
  - claude-code
  - anthropic
  - subscription-only
  - provider-governance
capabilities:
  - dynamic_provider_bootstrap_defaults
  - claude_code_subscription_governance
  - gemini_interactive_research_fallback
  - subscription_only_provider_mode
  - no_api_no_extra_credits
  - provider_policy_guardrails
decisions:
  - A partir de 15 de junho de 2026, Claude Agent SDK, `claude -p`, Claude Code GitHub Actions e apps terceiros via Agent SDK usam credito mensal separado.
  - Claude Code interativo no terminal/IDE continua usando os limites normais da assinatura Claude.
  - No modo sem API e sem creditos extras, Codex 5.5, Claude Code e Gemini podem iniciar com papeis bootstrap, mas runtime canonico deve evoluir para dynamic provider role assignment.
  - Claude Code interativo observado e o modo alvo para uso diario dentro de Obras.
  - Antes do cutoff de 15 de junho de 2026 ou antes de flag explicita de bloqueio, uso programatico/headless Claude ainda pode ser permitido para Rivals, benchmarks, testes de integracao e experimentos aprovados pelo operador.
  - Gemini e provider interativo de apoio permitido; nao substitui o estado governado do Atlas.
  - Nenhum provider deve ter papel permanente; Atlas Decide deve aprender por Projeto, Obra, work packet, role slot, risco, capacidade e evidencia.
  - IA local fica fora de escopo por enquanto.
  - Para trabalho produtivo diario, Atlas deve preferir Claude Code interativo observado, nao backend headless silencioso.
  - Sem API e sem creditos extras, Atlas deve preparar bloqueio/pausa de caminhos Claude headless para o cutoff, mas nao deve desabilitar agora usos programaticos explicitamente autorizados de Rivals/teste.
  - O fluxo eficiente e Atlas governar Obra, contexto, escopo, gates, evidencia e review enquanto o operador usa Claude Code interativo local.
  - A UX canonica e abrir Claude Code como sessao interativa observada a partir da Obra, com packet, prompt, terminal, importacao, gates e evidence.
maintenance:
  - Atualize este doc quando Anthropic alterar Claude Code, Agent SDK, billing, termos, uso programatico ou limites de assinatura.
  - Leia este doc antes de alterar `claude_cli`, `ClaudeCliProvider`, AI workers, Forge Provider Invocation ou Atlas Code composer.
  - Nao declarar fluxo Claude subscription-only como ilimitado, autonomo, backend permanente, worker de producao ou substituto de policy.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
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
human_name: Atlas Claude Code Subscription Governance v1
canonical_name: Atlas Claude Code Subscription Governance v1
technical_name: atlas-claude-code-subscription-governance-v1
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
owner: provider-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-claude-code-subscription-governance-v1.md
allowed_changes:
  - Adicionar novos modos de provedor desde que mantenham separacao explicita entre interativo, Agent SDK/headless e API.
  - Adicionar preflights, gates, labels de UX, packet builder, terminal adapter e watchers para Claude Code interativo observado.
forbidden_changes:
  - Tratar `claude -p`, Agent SDK, GitHub Actions ou worker background como Claude Code interativo.
  - Bloquear imediatamente `claude -p`, Agent SDK ou worker Claude usados explicitamente para Rivals/testes antes do cutoff sem decisao nova do operador.
  - Fazer fallback silencioso para API key, PAYG, extra usage ou credito extra.
  - Reintroduzir IA local como alternativa default enquanto a estrategia atual mantiver IA local fora de escopo.
  - Tratar Claude Code, Codex, Gemini ou qualquer provider como cerebro principal permanente do Atlas em modo subscription-only.
  - Transformar papeis bootstrap em politica fixa de runtime.
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
evidence_refs:
  - symbol: ClaudeCliProvider
  - test: ClaudeCliProviderTest
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
  - Se o operador exigir sem API e sem creditos extras, use papeis bootstrap apenas ate existir evidence suficiente; depois siga Dynamic Provider Role Assignment. Nao use `claude_cli` headless, Agent SDK, API ou IA local por fallback implicito. Antes do cutoff, uso programatico Claude segue permitido apenas quando explicitamente marcado como Rivals/teste/experimento aprovado.
quality_gates:
  - no-api-key-fallback
  - no-unapproved-claude-headless
  - rivals-programmatic-exception-preserved
  - operator-presence-required
  - evidence-ledger-required
failure_modes:
  - Worker Claude consumir Agent SDK credit sem o operador perceber.
  - `ANTHROPIC_API_KEY` presente transformar Claude Code em PAYG/API.
  - Atlas Code composer enfileirar job `claude_cli` por default em modo subscription-only.
  - Qualquer provider virar cerebro principal permanente e ponto unico de falha do Atlas.
  - IA local ser reativada como fallback implicito sem decisao nova.
  - Produto chamar Claude como backend multiusuario usando assinatura pessoal.
observability_signals:
  - orchestrator_provider
  - provider_mode
  - claude_invocation_mode
  - gemini_support_mode
  - external_provider_call
  - operator_presence
  - api_key_detected
  - agent_sdk_credit_risk
  - evidence_ledger_event
next_actions:
  - Implementar modo `claude_code_interactive_observed`.
  - Adicionar preflight para bloquear API keys/PAYG no modo sem creditos extras.
  - Adicionar flag futura para hard block de Claude programatico apenas quando o cutoff/policy exigir ou quando o operador ativar.
  - Preservar caminho programatico de Rivals/teste ate decisao explicita de migracao.
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
Atlas Decide: autoridade de roteamento, escopo, gates, evidencia e aceite.
Providers: candidatos dinamicos por role slot.
Claude Code interativo observado: permitido.
Claude headless/programatico: permitido agora apenas para Rivals/testes/experimentos aprovados; bloqueio duro e fase futura/flag.
Gemini/Codex interativos: permitidos conforme capacidade, evidencia e modo.
IA local: fora de escopo por enquanto.
```

## Papel no Atlas

Atlas nao compete com Claude no nivel de modelo. Atlas substitui o uso direto
como canal operacional: Obra, contexto, escopo, gates, evidencia, review,
memoria e learning.

Neste modo, Atlas e o cerebro operacional: mantem contexto, decide escopo,
monta pacotes, revisa, integra e valida. Codex 5.5, Claude Code e Gemini sao
providers candidatos. Eles podem receber papeis bootstrap enquanto nao ha
evidencia suficiente, mas o alvo canonico e Dynamic Provider Role Assignment:
o Atlas aprende quem e melhor por Projeto, Obra, work packet, role slot, risco,
capacidade e evidencia.

Providers sao aceleradores. Atlas e o sistema operacional.

## Onde Se Encaixa

Este doc governa:

- provider roles no modo sem API e sem creditos extras;
- Atlas Code composer quando uma intent poderia virar `claude_cli`;
- AI workers que executam providers;
- `ClaudeCliProvider`;
- Forge Provider Invocation;
- qualquer futuro adapter Anthropic;
- UX de "usar minha assinatura Claude Code".

Nao governa API/PAYG de producao. API fica fora do limite declarado pelo
operador. Tambem nao governa IA local, que fica fora do escopo atual.

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

## Politica De Fases

Antes de 15 de junho de 2026, ou enquanto o operador nao ativar bloqueio duro,
Atlas nao deve quebrar o uso programatico existente de Claude quando ele for
necessario para:

- Rivals / baseline Claude Code;
- benchmarks comparativos;
- testes de driver/integracao;
- experimentos controlados de provider;
- validacao de prompts/packets.

Esse uso deve ser marcado como `programmatic_experiment`, `rivals_baseline` ou
`provider_integration_test`, nunca como runtime produtivo silencioso. Deve rodar
com workspace isolado, approval explicito, evidence e custo/risco visiveis.

Depois do cutoff, ou quando o operador ativar hard block, o mesmo caminho deve
pausar ou exigir decisao nova. A migracao para interativo observado e o alvo
operacional diario, nao uma proibicao imediata que destrua Rivals.

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

Bootstrap inicial enquanto a memoria de performance ainda e insuficiente:

| Papel bootstrap | Provider inicial | Modo | Funcao inicial |
| --- | --- | --- | --- |
| Integracao/revisao | Codex 5.5 | interativo / workspace atual | contexto, decisao assistida, prompts, revisao, integracao, validacao |
| Arquitetura/implementacao | Claude Code | interativo observado | arquitetura, implementacao, refactor, reparo complexo |
| Pesquisa/comparacao | Gemini | interativo | pesquisa, comparacao, segunda opiniao |
| Local | nenhum | fora de escopo | nao usar como fallback automatico agora |

Esses papeis nao sao cargos fixos. Quando houver evidencia suficiente, Atlas
Decide deve escolher dinamicamente o provider por role slot e registrar receipt.

## Fluxo

Fluxo eficiente para programacao pesada sem API e sem creditos extras:

```text
Atlas Code / Forge com Atlas Decide como orquestrador
-> Obra
-> Forge Workspace
-> Spec / Plan / Task Contract
-> Work Packet
-> Dynamic Provider Role Assignment
-> provider interativo observado quando permitido
-> Atlas observa diff/testes/evidencias
-> Atlas valida gates
-> Atlas aprova, bloqueia ou pede reparo
```

O Work Packet projetado para Claude Code, Codex, Gemini ou outro provider deve conter:

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

Gemini pode ser usado antes ou durante o fluxo para pesquisa e comparacao, mas
nao vira fonte de verdade. O estado canonico fica no Atlas: Obra, docs, evidence,
receipts e gates.

## Regras para IA

Agentes e implementacoes devem respeitar:

- nunca chamar `claude -p` como fallback silencioso, completion automatica ou backend produtivo escondido;
- nunca usar Agent SDK como worker produtivo escondido;
- preservar `claude -p`/Agent SDK quando explicitamente usados para Rivals/testes antes do cutoff ou antes da flag de bloqueio duro;
- nunca fazer fallback para `ANTHROPIC_API_KEY`;
- nunca iniciar worker Claude em background;
- nunca tratar assinatura pessoal como backend multiusuario;
- nunca mascarar automacao como interacao humana;
- nunca usar IA local como fallback implicito neste modo;
- nunca tornar Claude Code, Codex, Gemini ou qualquer provider o cerebro principal persistente do Atlas;
- nunca transformar papeis bootstrap em politica fixa;
- usar Gemini apenas como provider interativo quando Atlas Decide ou o operador escolherem;
- pausar quando limite acabar;
- registrar evidencia antes de completion claim.

Config alvo:

```text
ATLAS_CLAUDE_SUBSCRIPTION_ONLY=true
claude_headless_enabled=operator_approved_test_only
agent_sdk_enabled=operator_approved_test_only
anthropic_api_enabled=false
workers_claude_enabled=rivals_or_test_only
queue_execution_for_claude=false
claude_programmatic_hard_block=false_until_cutoff_or_operator_decision
provider_assignment_mode=dynamic_provider_role_assignment
claude_allowed_mode=interactive_observed
codex_allowed_mode=interactive_or_workspace
gemini_allowed_mode=interactive_support
local_ai_enabled=false
concurrency=1
require_operator_presence=true
pause_on_limit=true
api_fallback=false
```

## Escopo de Implementacao

Permitido:

- Atlas Decide preparar contexto, prompt, plano, revisao e validacao;
- Codex 5.5, Claude Code e Gemini atuarem como providers interativos quando selecionados por role slot;
- Claude programatico/headless em Rivals, baseline, benchmark ou teste de integracao explicitamente aprovado antes do cutoff/flag;
- preflight local de ambiente;
- packet builder por Obra;
- terminal adapter que abre workspace para uso interativo;
- git/file watcher;
- gates locais;
- Evidence Ledger;
- UX clara de "Modo Local Assistido";
- Gemini interativo para pesquisa/comparacao quando o operador decidir.

Bloqueado como uso produtivo silencioso:

- `claude_cli` via worker sem approval/test label;
- `claude -p` sem Rivals/teste/experimento aprovado;
- Agent SDK sem Rivals/teste/experimento aprovado;
- GitHub Actions Claude sem decisao explicita;
- API/PAYG;
- provider invocation `execute` com Claude externo sem approval/test label;
- fallback silencioso para outra conta ou credito extra;
- fallback automatico para IA local.

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
- dynamic provider assignment id quando aplicavel;
- invocation mode, por exemplo `claude_code_interactive_observed`;
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
- `ClaudeCliProvider` usar `-p --output-format stream-json` como runtime produtivo silencioso;
- AI worker chamar provider real sem operador;
- LaunchAgent manter worker Claude vivo;
- `ANTHROPIC_API_KEY` transformar uso em PAYG/API;
- Forge Provider Invocation executar `claude_cli` sem label Rivals/teste/experimento aprovado;
- hard block prematuro quebrar Rivals, baseline Claude Code ou testes programaticos antes do cutoff/decisao do operador;
- Claude Code virar ponto unico de falha e memoria principal;
- Gemini ou IA local serem tratados como fonte de verdade;
- IA local voltar como fallback implicito sem decisao canonica;
- produto virar passthrough multiusuario de assinatura pessoal.

Consequencias oficiais possiveis em violacoes de policy/termos incluem
throttling, suspensao, encerramento de acesso, bloqueio/modificacao de outputs
e encerramento de assinatura conforme termos.

## Exemplos

Permitido:

```text
Atlas gera packet -> seleciona provider por role slot -> operador roda sessao interativa permitida -> Atlas valida diff/testes.
```

Bloqueado como runtime produtivo silencioso:

```text
Atlas worker -> `claude -p` -> prompt via stdin -> resposta stream-json.
```

Permitido ate cutoff/decisao explicita quando aprovado:

```text
Rivals baseline -> workspace isolado -> `claude -p`/driver programatico -> evidence -> comparacao honesta.
```

Ao bater limite:

```text
pausar -> registrar motivo -> manter Obra aberta -> aguardar reset.
```

## Proximas Acoes

1. Implementar kill switch futuro `ATLAS_CLAUDE_PROGRAMMATIC_HARD_BLOCK`.
2. Preservar Claude programatico para Rivals/testes enquanto a flag estiver desligada.
3. Criar preflight para API keys e operador presente.
4. Criar Claude Code Packet Builder por Obra.
5. Criar terminal adapter para sessao interativa.
6. Criar watchers de diff/arquivos proibidos.
7. Ligar gates e Evidence Ledger ao fluxo observado.
8. Ligar este modo ao Dynamic Provider Role Assignment e registrar papeis bootstrap apenas como sinal inicial.
