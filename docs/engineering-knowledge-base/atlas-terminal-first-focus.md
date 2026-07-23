---
id: atlas-terminal-first-focus
type: engineering_knowledge
title: Atlas — FOCO ESTRATÉGICO (Terminal-First, Casca e o Moat de Verificação)
status: active
category: strategy
priority: 100
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "DOC DE FOCO — leia ANTES de qualquer trabalho de casca/shell/UI/superfície/terminal do Atlas. Decisão pétrea: Atlas NÃO constrói casca própria; roda com poder cheio em host de terminal neutro (Maestri/iTerm/tmux) porque é dirigível por CLI. Modelo de 3 camadas: L1 harness (Claude Code/Codex/grok) = COMMODITY, alugar; L2 superfície funcional do Atlas em CLI (849 comandos + 600 rotas) = SEU, investir; L3 seams brain-behind (memória/gates/evidence) = o M do Atlas. O MOAT e o gargalo de 2026 = VERIFICAÇÃO/review (confiar na saída do agente), NÃO gerar código nem editar inline. Foco concreto: (1) cockpit de review no terminal [recomendado], (2) ergonomia CLI, (3) fechar bypass do AiProviderManager. App Mac/Mobile depois = superfície IRMÃ (mesmo backend), não pai-filho. NÃO construir shell própria; NÃO edição inline (a IDE ganha)."
tags:
  - atlas-ai
  - strategy
  - terminal-first
  - casca
  - shell
  - verification
  - review
  - moat
  - maestri
  - north-star
capabilities:
  - terminal_first_strategy
  - verification_moat_focus
  - three_layer_surface_model
decisions:
  - Atlas NÃO constrói casca própria (veredito 6-agentes must_build_own_shell=false); roda em host de terminal neutro com poder cheio. A alavanca é endurecer o contrato, não fazer UI.
  - O foco de produto é VERIFICAÇÃO/review (o gargalo de 2026), não geração nem edição inline — é onde vive o M do Atlas (gates/evidence/proof).
  - Modelo de 3 camadas — L1 harness=commodity (alugar), L2 superfície CLI=investir (é a API do produto), L3 seams brain-behind=o M.
  - App Mac/Mobile é superfície IRMÃ da CLI (ambos chamam o backend), não pai-filho; mobile força o app; o recurso-matador do app = review.
maintenance:
  - Atualizar quando a decisão de casca, o alvo do moat, ou o foco das 3 frentes mudarem; manter os números de superfície (comandos/rotas) e o inventário do que já existe alinhados ao código.
related_paths:
  - app/Console/Commands/AtlasReviewDeepCommand.php
  - app/Support/TerminalMarkdownRenderer.php
  - app/Services/Ai/SelfConstruction/TerminalLoopProof
  - app/Services/Ai/AiProviderManager.php
graph_id: atlas-terminal-first-focus
graph_title: Atlas Terminal-First Focus
graph_world: atlas
graph_kind: policy
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: Atlas Terminal-First Focus
canonical_name: Atlas Terminal-First Focus
technical_name: atlas-terminal-first-focus
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-terminal-first-focus.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-terminal-work-charter.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Console/Commands/AtlasReviewDeepCommand.php
  - app/Support/TerminalMarkdownRenderer.php
allowed_changes:
  - Atualizar frentes/moat quando a decisao de casca ou inventario CLI mudar.
forbidden_changes:
  - Propor casca propria do Atlas como plataforma.
  - Tratar edicao inline/IDE como moat primario.
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-autonomos-live-system
flows_to:
  - atlas-terminal-work-charter
  - atlas-open-gaps-regressions-ledger
unlocks:
  - terminal-review-cockpit-focus
governs:
  - terminal-first-strategy
  - verification-moat-focus
evidence:
  - app/Console/Commands/AtlasReviewDeepCommand.php
  - docs/engineering-knowledge-base/atlas-terminal-work-charter.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Investir em review/verification no terminal; nao construir shell propria.
---

# Atlas — FOCO ESTRATÉGICO: Terminal-First, Casca e o Moat de Verificação

> **⚠️ LEIA ISTO ANTES de propor/implementar qualquer coisa de casca, shell, UI, "app", superfície,
> IDE-integration ou terminal do Atlas.** Este doc é o north-star dessa área. Divergir dele = retrabalho.
> Fonte viva: memórias `terminal-first-strategy-validated`, `atlas-shell-independence-verdict`,
> `maestri-cockpit-adoption`. Validado por pesquisa web (08/07) + veredito multi-agente.

## Resumo

North-star: Atlas nao constroi casca propria; roda em terminal neutro; moat = verificacao/review. Detalhe nas secoes numeradas abaixo. Glossary: `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md` (Dev/Forge).

## Papel no Atlas

Politica de produto para casca/terminal/UI — leia antes de qualquer trabalho nessa area.

## Onde Se Encaixa

Estrategia → charter de sessao (`atlas-terminal-work-charter.md`) → gaps no ledger → implementacao CLI/review.

## Contratos

| Decisao | Regra |
|---|---|
| Casca | must_build_own_shell=false |
| Moat | verificacao/review |
| Camadas | L1 alugar / L2 CLI investir / L3 seams = M |

## Fluxo

1. Ler este north-star.
2. Abrir charter de sessao.
3. Census reuse-first do que ja existe.
4. Fechar gaps de review/CLI sem construir shell.

## Amendment 2026-07-23 — L2a Terminal Dev (operador)

**must_build_own_shell=false** continues to mean: do **not** build an IDE casca
(file-tree product, inline editor as moat).

**Allowed and required:** **Atlas Terminal Dev** = multi-turn **agent session TUI**
(Grok-class programming surface) as **L2a**. Providers remain L1 muscle. Brain
remains L3 in atlas-server. See `atlas-terminal-dev-product.md` and AAP v0.

Hermes is **not** the default path for Terminal Dev until hang P3 is closed.

## Regras para IA

- Nao propor shell propria.
- Nao priorizar edicao inline sobre review.
- Endurecer contrato (AiProviderManager) em vez de UI.

## Escopo de Implementacao

Inclui estrategia terminal-first e foco de verificacao. Nao inclui app shell nova como pai da CLI.

## Dependencias

- CLI Atlas (comandos/rotas)
- Review/inbox surfaces existentes
- Hosts neutros (Maestri/iTerm/tmux)

## Evidencias

Veredito multi-agente de casca; inventario CLI; comandos de review citados em related_paths.

## Riscos

Retrabalho por construir casca; confundir harness commodity com produto Atlas.

## Exemplos

Sessao focada: ler este doc + `atlas-terminal-work-charter.md`, depois ligar produtor de review dormente.

## Proximas Acoes

- Seguir as 3 frentes do corpo do doc.
- Manter alinhado ao ledger de gaps.

## 0. O foco em uma frase

**Atlas é um cérebro soberano que roda em cima de qualquer terminal alugado; não construímos casca própria — investimos na superfície CLI + nos seams brain-behind, e o produto-matador é VERIFICAÇÃO/review (o gargalo real de 2026), não gerar nem editar código.**

## 1. A tese (por que isto importa)

Atlas é o **cérebro**; providers (Claude Code, Codex, Cursor, Antigravity, Composer, Gemini, MiniMax/Hermes)
são **motor alugado**. A interface humana é **linguagem natural** (Mobile + Desktop Mac). A vantagem é
**antifragilidade composta N×M**: quando um provider salta N×, Atlas captura via wrapper governance E
multiplica pelo próprio M× (memória governada, evidence, compounding, self-construction). Atlas **substitui**
Claude Code/Cursor/Codex *como produtos* — não concorre; usa eles por baixo.

Consequência direta para casca/terminal: **a casca é motor; o valor é o cérebro + a prova.** Qualquer minuto
gasto construindo uma casca do zero é minuto não-gasto no M (o multiplicador). Ver `atlas-no-moral-limit-in-engine`
e a Tese Canônica no umbrella `Atlas/CLAUDE.md`.

## 2. Decisão de casca (PÉTREA, validada)

**Atlas NÃO constrói casca própria** (veredito 6-agentes: `must_build_own_shell=false`). Motivo: Atlas já é
**dirigível headless** — todo o poder está exposto por CLI (comandos `goal`/`mission`/`brain`/`task`/`dev`/`forge`)
+ API HTTP. Construir uma casca é reimplementar terminal que já existe e é grátis.

- **Host de terminal NEUTRO (Maestri / iTerm / tmux)** → Atlas Dev/Forge/Autônomo rodam com **PODER CHEIO**
  (dirigíveis ponta-a-ponta por CLI, ex.: `atlas:dev:senior-loop:run`). **Este é o modo certo.** Maestri hoje =
  cockpit/mãos; Atlas = cérebro. Não empurrar a casca do Maestri; ligar via CLI dentro dela (`MAESTRI_SOCKET`).
- **Agente-shell PRÓPRIO (Cursor / Copilot / Windsurf)** → Atlas entra só como **tool/MCP**, **poder PARCIAL**
  (o loop deles dirige; o poder do Dev É o loop, então precisa ser o motorista). Usar só como conveniência, não
  como plataforma.

A alavanca não é UI — é **endurecer o contrato** (fechar o bypass do `AiProviderManager` pra todo músculo passar
pela governança). Ver `atlas-shell-independence-verdict`, `maestri-cockpit-adoption`.

## 3. Modelo de 3 camadas (onde investir vs. alugar)

| Camada | O que é | Postura |
|---|---|---|
| **L1 — Harness do terminal** | O runtime CLI-agente (Claude Code, Codex, grok build) que hospeda a sessão | **COMMODITY — ALUGAR.** Nunca construir. Envelopar hoje = Claude Code/Codex (grok=caro/beta/atrás → watch-list, não adoção). |
| **L2 — Superfície funcional do Atlas em CLI** | 849 comandos artisan + 600 rotas HTTP (`goal`, `mission`, `brain`, `task`, `dev`, `forge`, `review`…) | **SEU — INVESTIR/POLIR.** É a API do produto. Legibilidade e ergonomia de dirigir na mão importam. |
| **L3 — Seams brain-behind** | Memória-projeção, context pack, gates, evidence ledger, proof, cross-model critique | **SEU — é o M.** O multiplicador. A maior parte do valor de longo prazo mora aqui. |

CLI-first-then-GUI é textbook: Claude Code lançou terminal (fev/2025) e só depois VS Code/JetBrains/desktop/web
**sobre o MESMO engine**. Atlas segue o mesmo caminho: cérebro headless → N superfícies finas sobre o mesmo backend.

## 4. O MOAT = verificação / review (o gargalo REAL de 2026)

Consenso da indústria: **gerar código é rápido e barato; CONFIAR na saída do agente é o gargalo.** Isso é
**exatamente onde vive o M do Atlas** — governança, evidence, proof-gates, crítica cross-model. Então:

- **O recurso-matador (terminal agora, app Mac depois) deve mirar REVIEW/verificação**, não edição inline (a IDE
  já ganha em edição). Atlas não só pega carona no padrão — mira o buraco que todo mundo tem.
- Disciplina: terminal = **control-plane/engine**, não a UX final. A interface humana continua linguagem natural
  (Mobile + Desktop). Mobile força o app (nenhum terminal cabe no bolso).

Ver `terminal-first-strategy-validated` (insight-ouro).

## 5. O que JÁ EXISTE hoje (grounded — mapear antes de construir)

O cockpit de review/prova **parcialmente já existe** — NÃO reinventar; mapear e ligar o que estiver dormente:

- **`app/Console/Commands/AtlasReviewDeepCommand.php`** (`atlas:review:deep`) — "deep engineering review com severity,
  confidence e category thresholds". Ponto de partida do cockpit de review.
- **Família `TerminalLoopProof*`** (`app/Services/Ai/SelfConstruction/TerminalLoopProof/`): `TerminalLoopProofCanonicalizer`,
  `TerminalLoopProofDigestInterpreter`, `TerminalLoopProofReadinessMatrixBuilder` — maquinário de PROVA operacional no terminal.
- **`TerminalLoopHealthDigest*`** (`ControlPlane/`): `QueueReader`, `PayloadNormalizer`, `CommandComposer`, +
  `AgentControlPlaneTerminalLoopOperationalProofService` — digest de saúde/estado no terminal.
- **`app/Support/TerminalMarkdownRenderer.php`** — render de markdown no terminal (a camada de apresentação).
- **`AtlasSelfConstructionReadinessService` / `ReadinessTerminalLoopProofResolver`** — resolvem readiness/prova.

Números de superfície (2026-07-09): **849 comandos artisan · 600 rotas HTTP · 511 docs KB.**

**Ação:** antes de qualquer construção de cockpit, fazer um census read-only desse maquinário (o que está vivo,
o que é dormente, o que falta) — como fizemos com o loop-morto. Não presumir que é greenfield.

## 6. Foco concreto — as 3 frentes (com prioridade)

1. **[RECOMENDADO] Cockpit de review/verificação no terminal.** Superfície onde o operador vê *o que o
   autônomo/Dev/Forge fez + a evidência + aprova/rejeita*. É o moat + o gargalo + o que faz o Maestri virar
   cockpit de verdade (operador observa e decide, não edita). Provável reuso de `AtlasReviewDeepCommand` +
   `TerminalLoopProof*`.
2. **Ergonomia da superfície CLI (L2).** Comandos-núcleo legíveis e agradáveis de dirigir na mão (via
   `TerminalMarkdownRenderer`). Alto valor, mas polish.
3. **Endurecer o contrato (L3).** Fechar o bypass do `AiProviderManager` — nomeado pelo veredito como *a*
   alavanca (todo músculo passa pela governança). Fundacional, invisível no dia-a-dia.

## 7. App Mac / Mobile — depois, e como

Superfície **IRMÃ** da CLI: ambos chamam o **mesmo backend** (não pai-filho, não "GUI liga a CLI"). Formulação
correta: **cérebro headless + N superfícies finas sobre o mesmo engine.** Mobile é obrigatório no fim (linguagem
natural no bolso; nenhum terminal cabe lá). O recurso-matador do app = **review/verificação**, não edição.

## 8. O que NÃO fazer (anti-padrões — vários já refutados com prova)

- **NÃO construir casca/shell própria** (veredito pétreo). Reimplementar terminal = desperdício.
- **NÃO mirar edição inline como diferencial** — a IDE já ganha; o diferencial é review/prova.
- **NÃO tratar o app Mac como pai da CLI** — são irmãos sobre o mesmo backend.
- **NÃO confundir loop-morto (ACDE) com o autônomo vivo** — ver `atlas-autonomos-live-system.md` e `loop-morto-autonomos-vivo`.
- **NÃO "unificar tudo"** num big-refactor — a Obra #6 refutou "lógica removível" por medição; reuse-first.
- **Vocabulário PROIBIDO** (zero tolerância, ver umbrella): "Jarvis", "Rivals", "benchmark", "superiority",
  "concurrent", "Atlas concorre com Claude Code/Codex" (é: *substitui como produto, usa como engine*), "wrapper de IA".
- Refutados herdados (NÃO re-propor): `Number::clamp` NaN, spec-tables 1-linha-por-dado, unificação epsilon cross-domínio.

## 9. Regras para qualquer IA que tocar esta área (imperativas)

1. **Leia este doc + as 3 memórias-fonte ANTES de propor casca/terminal/UI/review.**
2. **Verificação-first:** se a proposta é sobre gerar/editar código mais rápido, PARE — o foco é confiar na saída.
3. **Host-neutro, não casca própria.** Se a proposta é "construir uma UI/shell do Atlas", PARE e releia §2.
4. **Reuse-first:** faça o census do maquinário `Terminal*`/`Review*` existente (§5) antes de construir.
5. **Consulte o Atlas MCP** (`atlas-open-brain`) antes de decisão de arquitetura — não confie em grep cego nem
   em memória de treino (ver `CLAUDE.md` → "Atlas MCP: consultar ANTES de implementar").

## 10. Filtro de 5 perguntas (aplicar a qualquer proposta de casca/terminal)

1. Aumenta o wrapper multiplicador composto (o M) ou só otimiza um ponto isolado?
2. É antifrágil (cresce com mudança) ou frágil?
3. Aproxima da execução fim-a-fim em linguagem natural ou afasta?
4. Destrava substituir uma função/empresa ou é polish incremental?
5. Preserva soberania local-first ou cria dependência externa?

Se a resposta empurra pra "construir casca própria" ou "editar código inline mais rápido" → provavelmente ERRADO.

## 11. Referências

- Memórias: `terminal-first-strategy-validated`, `atlas-shell-independence-verdict`, `maestri-cockpit-adoption`,
  `atlas-autonomos-live-system` (o vivo), `loop-morto-autonomos-vivo`.
- Umbrella `Atlas/CLAUDE.md`: Tese Canônica + Vocabulário Proibido + Filtro de 5 + Coluna Vertebral.
- Código: `AtlasReviewDeepCommand`, `TerminalLoopProof*`, `TerminalLoopHealthDigest*`, `TerminalMarkdownRenderer`, `AiProviderManager`.
