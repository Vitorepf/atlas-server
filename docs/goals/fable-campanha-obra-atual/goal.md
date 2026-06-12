# Campanha Fable — obra atual via GoalBuddy

## Objective

Executar a próxima obra da Campanha Fable do Atlas, sem pular a ordem do ledger canônico, usando GoalBuddy como camada durável de campanha: PM/Fable para governança, Judge/Fable para refutação e auditoria, Worker/Codex para implementação bounded, Scout/MiniMax para mapeamento barato, receipts por slice, prova concreta e retomada fria.

## Original Request

"ep Leia e execute /Users/vitorepf/develop/Atlas/atlas-server/docs/fable-campanha-goalbuddy-prep-request.md"

## Intake Summary

- Input shape: `existing_plan`
- Audience: operador do Atlas / execução local da Campanha Fable no `atlas-server`
- Authority: `requested`
- Proof type: `test`
- Completion proof: o board executa a primeira obra `em andamento` ou `pendente` do ledger canônico até DoD completo, com testes/artefatos/leituras/outputs reais, captura integral, ledger atualizado e auditoria final registrando `full_outcome_complete: true`.
- Goal oracle: o ledger em `docs/fable-campanha-11-dias-nxm.md` + DoD da obra alvo + regressões/artefatos/captura exigidos pelo prompt mestre. No estado atual lido durante o prep, a primeira obra é O-1, status `em andamento`.
- Likely misfire: GoalBuddy completar apenas planejamento, discovery, um slice isolado ou um board bonito sem realmente concluir a obra alvo com prova, captura e ledger canônico atualizado.
- Blind spots considered: risco de pular ordem O-1→O-2→O-3; risco de gastar Fable em boilerplate; risco de Criação medir Criação; risco de avançar sem AOBG/bootstrap; risco de não capturar N como M; risco de decisões `[OPERADOR]` serem tomadas autonomamente.
- Existing plan facts: preservar integralmente `docs/fable-campanha-execution-prompt.md`; plano governante `docs/fable-campanha-11-dias-nxm.md`; workspace `/Users/vitorepf/develop/Atlas/atlas-server`; PHP sempre `/opt/homebrew/bin/php`; `index-code` sempre com `--workspace "$(pwd)"`; decisões `[OPERADOR]` não-autônomas; modelo policy Fable/Codex/MiniMax; captura obrigatória por obra.

## Goal Oracle

The oracle for this goal is:

`A próxima obra do ledger canônico da Campanha Fable está concluída com DoD item-a-item provado, regressões verdes, captura realizada, memória/KB/Code Intelligence sincronizados quando aplicável, ledger atualizado em docs/fable-campanha-11-dias-nxm.md, e auditoria Judge/PM final registrando full_outcome_complete: true.`

The PM must keep comparing task receipts to this oracle. Planning, discovery, a passing tiny slice, or a clean-looking board is not enough. The goal finishes only when a final Judge/PM audit maps receipts and verification back to this oracle and records `full_outcome_complete: true`.

## Goal Kind

`existing_plan`

## Current Tranche

Tranche atual: executar a primeira obra `em andamento` ou `pendente` do ledger canônico, começando pela O-1 se ela ainda estiver `em andamento` quando `/goal` iniciar. O primeiro `/goal` deve re-ler o plano e ledger antes de tocar código, porque o estado pode mudar entre prep e execução.

Durante o prep, o ledger indicava:

- O-1 Sweep + Marco Zero — status: `em andamento` — prova já existente de Marco Zero (`storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json`, ledger `01KTWCPTNZQDQ2NGMD8G8NP83K`).
- O-2 e seguintes — `pendente`.

A execução deve avançar continuamente até a obra alvo estar completa por DoD, captura e auditoria; depois pode preparar continuidade para a próxima obra, mas não deve pular ordem.

## Non-Negotiable Constraints

- Não implementar nada fora do Atlas engenharia / AAEOS / Atlas Code nesta campanha.
- Nunca pular a ordem do ledger: primeira obra `em andamento` ou `pendente` governa.
- Antes de qualquer implementação futura, rodar em `/Users/vitorepf/develop/Atlas/atlas-server`:
  - `/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="<obra>" --json`
  - `bin/atlas open-brain context "<obra>" --json`
- Antes de criar peça nova de runtime/domínio/surface, rodar:
  - `/opt/homebrew/bin/php artisan atlas:ai:place-feature "<peça>" --json`
- PHP sempre `/opt/homebrew/bin/php`.
- `bin/atlas engineering knowledge index-code --prune` sempre com `--workspace "$(pwd)"`.
- Criação ≠ Medição: quem cria não mede; todo slice entregue precisa de verificação adversarial independente.
- Decisões `[OPERADOR]` nunca são tomadas autonomamente; bloquear/registrar a tarefa específica e continuar trabalho local seguro quando possível.
- Merge livre + fix-forward-first só depois dos gates/vereditos aplicáveis; código de medição/gates/imune exige verificação adversarial reforçada; alargar autonomia exige operador.
- Após O-3, soak contínuo deve rodar até o fim da campanha, com health-check ~10min e kill-switch.
- Não gastar Fable em boilerplate, refactor mecânico ou tarefas repetitivas; mandar isso para Codex/Worker ou MiniMax conforme política.
- Vocabulário proibido em código/docs: `Jarvis`, `Rivals`, `benchmark`, `superiority`, `concurrent`; nunca escrever que Atlas concorre com Claude Code — Atlas substitui como produto e usa providers como motor.
- Ritual de captura ao concluir obra/slice quando aplicável: testes verdes, canon doc com frontmatter válido, `bin/atlas engineering knowledge sync --prune`, `bin/atlas engineering knowledge index-code --prune --workspace "$(pwd)"`, memória Atlas provider-safe, ledger atualizado, checklist DoD relido item por item.

## Model / Role Policy

- PM: Fable 5 / Anthropic. Alta razão, governança, sequenciamento, receipts e estado do board.
- Judge: Fable 5 / Anthropic. Refutação adversarial, decisões difíceis, auditoria de risco e final.
- Worker: OpenAI Codex `gpt-5.5`. Implementação, testes, refactors e correções mecânicas dentro de `allowed_files`.
- Scout: MiniMax M3 quando barato e read-only; Fable só para mapeamento que realmente exija alto julgamento.
- Board maintenance / receipt cleanup: MiniMax M3 ou PM se simples.

## Stop Rule

Stop only when a final audit proves the full original outcome is complete.

Do not stop after planning, discovery, or Judge selection if a safe Worker task can be activated.

Do not stop after a single verified Worker package when the obra alvo still has safe local follow-up work. Advance the board to the next highest-leverage safe Worker package and continue unless a phase, risk, rejected-verification, ambiguity, or final-completion review is due.

Do not create one Worker/Judge pair per repeated file, table, route, or helper. Put repeated same-shape work into one Worker package and review the package as a whole.

## Slice Sizing

Safe means bounded, explicit, verified, and reversible. It does not mean tiny.

A good task is the largest safe useful slice for the obra alvo: e.g. one audit domain, one corrected behavior with frozen regression, one capture package, or one vertical runtime/gate path.

Small tasks are acceptable only when risk/unknowns demand it. After two tiny tasks in a row, PM/Judge must reorient toward a larger useful slice.

## Canonical Board

Machine truth lives at:

`docs/goals/fable-campanha-obra-atual/state.yaml`

If this charter and `state.yaml` disagree, `state.yaml` wins for task status, active task, receipts, verification freshness, and completion truth.

## Run Command

```text
/goal Follow docs/goals/fable-campanha-obra-atual/goal.md.
```

## PM Loop

On every `/goal` continuation:

1. Read this charter.
2. Read `state.yaml`.
3. Run the GoalBuddy update checker when available.
4. Re-read `docs/fable-campanha-execution-prompt.md` and `docs/fable-campanha-11-dias-nxm.md` before code work.
5. Identify the first obra `em andamento` or `pendente`; do not rely blindly on prep-time state.
6. Work only on the active board task.
7. Assign Scout/Judge/Worker/PM according to the task and model policy.
8. Write a compact receipt on task completion/block/escalation.
9. Update the board and immediately select the next safe task unless final audit proves completion.
10. Keep comparing progress to the oracle and obra DoD.
11. Finish only with a Judge/PM audit receipt that maps receipts and verification back to the original outcome and records `full_outcome_complete: true`.
