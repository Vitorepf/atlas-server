---
title: Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 2
status: source_material
owner: atlas-code
updated_at: 2026-05-16
canonical: false
line_limit: 300
source_parent: docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md
note: source_material split from frozen handoff; evidence only, not a canonical module doc.
---
# Atlas Forge Continuum OS Session Handoff 2026-05-16 · Parte 2

## Resumo

Recorte de material de sessão: 3. Níveis até o produto final.

## Fonte

Arquivo pai: `docs/engineering-knowledge-base/atlas-forge-continuum-os-session-handoff-2026-05-16.md`.

## Conteudo Extraido

## 3. Níveis até o produto final

### Nível 0: Scaffold e contratos

Objetivo: criar schemas, CLIs, docs canônicas, services e tests mínimos sem chamar provider externo.

Capacidades esperadas:

- comandos existem;
- schemas versionados;
- receipts declarados;
- docs canônicas;
- tests unit/feature de contratos;
- blockers honestos.

Critérios de conclusão:

- `php artisan test --filter='Rivals|ForgeRivals|ForgeNativeRivals|FairClaudePolicy'` verde no escopo;
- `docs-health` sem violação no doc novo;
- `git diff --check` limpo.

Riscos:

- criar read-model que parece runtime real;
- mascarar bloqueios;
- inventar score sintético.

Não fazer ainda:

- provider real;
- claim de superioridade;
- auto-update de Atlas Decide.

### Nível 1: Fluxo local mínimo

Objetivo: provar que o harness roda sem provider real usando `local_fake` e gera evidence/replay/report comparável.

Capacidades esperadas:

- setup de worktrees;
- preflight;
- dry-run;
- run local fake;
- evidence pack;
- replay;
- report.

Critérios de conclusão:

- bateria local fake multi-case passa;
- dirty workspace before/after bloqueia corretamente;
- missing artifact invalida case;
- score só aparece quando a evidência é válida.

Riscos:

- local fake esconder bug do provider real;
- single-case v2 parecer multi-case release.

Não fazer ainda:

- dizer que Atlas venceu provider real.

### Nível 2: Memória/contexto confiável

Objetivo: preservar manifests, receipts, logs e histórico de runs em formato reexecutável.

Capacidades esperadas:

- run path resolver canônico;
- evidence por case;
- replay manifest;
- matrix lock;
- report v3;
- provider performance ledger.

Critérios de conclusão:

- report/replay conseguem reabrir uma bateria real existente;
- hashes e paths sobrevivem à troca de sessão;
- qualquer 0-byte receipt ou UTF-8 inválido não derruba o report silenciosamente.

Riscos:

- registros parciais virarem claim;
- report agregar case inválido como score válido.

Não fazer ainda:

- ledger alimentar routing automático.

### Nível 3: Orquestração e ferramentas reais

Objetivo: rodar providers reais em worktrees isolados e coletar custo/tempo/evidência.

Capacidades esperadas:

- `run-battery` release real;
- model lock: sonnet/opus/codex/gemini quando configurados;
- provider policy labels;
- streaming logs;
- stall detector;
- real provider receipt obrigatório;
- custo e duração por arm/case;
- after-clean-check obrigatório.

Critérios de conclusão:

- bateria real Atlas vs Claude Sonnet executada;
- report trusted;
- replay passes;
- no hard failures;
- custo e tempo registrados.

Riscos:

- provider programmatic policy bloquear Rivals;
- provider stdout inválido gerar JSON vazio;
- prompt muito “spec-perfect” favorecer sistemas governados demais.

Não fazer ainda:

- auto-promover conclusão externa.

### Nível 4: Autonomia supervisionada

Objetivo: permitir que Atlas Forge execute e repare sob governança, com humano aprovando quando necessário.

Capacidades esperadas:

- repair loop;
- review gate;
- completion gate;
- rollback;
- provider fallback com child receipt;
- capacity/failure memory;
- operator approval explícito para provider externo.

Critérios de conclusão:

- completion claim só aparece com evidência + review;
- fallback nunca é silencioso;
- external rivals continua separado e bloqueado.

Riscos:

- over-automation;
- “passar no teste” sem entregar produto.

Não fazer ainda:

- auto-merge sem humano.

### Nível 5: Produto diário confiável

Objetivo: tornar Atlas Code/Forge usável por horas de trabalho humano real.

Capacidades esperadas:

- UI com feedback enterprise;
- tela nunca morta;
- “o que está acontecendo” sempre visível;
- próximo passo seguro;
- milestones da Obra;
- safety strip;
- status de custo/provider/token;
- terminal/logs integrados;
- evidence/review/provas legíveis.

Critérios de conclusão:

- operador consegue usar sem explicação externa;
- UX mínima 8.5/10;
- build desktop verde;
- Playwright/browser visual check quando houver mudança frontend.

Riscos:

- UI bonita mas sem semântica;
- informação técnica demais no caminho principal.

Não fazer ainda:

- esconder diagnósticos avançados sem acesso.

### Nível final: Atlas local-first completo

Objetivo: Atlas operar como sistema pessoal local-first completo para programação, memória, avaliação e melhoria contínua.

Capacidades esperadas:

- Forge Continuum OS;
- Atlas Decide com runtime topology;
- Memory/Open Brain;
- Inbox;
- Tool Runtime;
- Autonomy;
- Self-Construction OS;
- Evaluation/Rivals;
- UI premium;
- provider arena e provider ledger como fonte consultiva.

Critérios de conclusão:

- Atlas consegue propor, executar, medir, aprender e melhorar com auditoria;
- Atlas Decide usa dados reais, mas continua dono de routing;
- humano mantém controle de custo, risco e aprovação.

Riscos:

- misturar medição com decisão;
- enfraquecer gates para parecer mais autônomo.

Não fazer ainda:

- permitir que Rivals escreva topology final;
- desbloquear external rivals sem aprovação explícita.
