# Atlas Evolution Loop — IMPLEMENTADO (estado de runtime)

> O loop final, construído sobre `[espinha do Ladder] + [métrica do autoresearch] + [execução/governança do Atlas]`. Companheiro do design em `ATLAS-SELF-IMPROVEMENT-LOOP-DESIGN.md`. Tudo abaixo é código real, testado (13 unit + 1 end-to-end com provider real), **provider-agnóstico por construção**.

---

## 0. A regra de ouro respeitada: zero dependência de provider

O loop **nunca nomeia um provider no código.** A execução passa por uma **interface** (`LoopExecutionDriver`); o provider concreto é resolvido do **config** (`atlas.loop.default_provider`, herdando o default global, trocável numa linha/env) via o `AiProviderManager`/`providerLock`. **Remover o Hermes não quebra o loop** — ele cai no próximo provider / Atlas Decide. Hermes é o default forte, não um pilar.

---

## 1. Os componentes (todos novos, em `app/Services/Ai/AutonomousEvolution/`)

| Componente | Arquivo | Papel | Provado |
|---|---|---|---|
| **Juiz Congelado** | `AtlasEvolutionFrozenJudge.php` | O `val_bpb` do Atlas. Re-roda a aceitação frozen no candidato (re-prova independente) + rejeita adulteração de path frozen (Goodhart-guard) + fora-de-escopo. | 5 testes |
| **Abstração de execução** | `LoopExecutionDriver.php` (interface) + `SeniorLoopExecutionDriver.php` (default) | O motor é trocável; o default roteia pelo senior-loop provado (provider via config). | binding + e2e |
| **Explorador de Cenários** | `AtlasEvolutionScenarioExplorer.php` | O sênior-vs-júnior: explora N candidatos, juiz escolhe o melhor, desempata por menor diff. **Deep search**: explora enquanto melhora, até convergir/teto/budget. | 5 testes |
| **Runner do Loop** | `AtlasEvolutionLoopRunner.php` | Fila → explorador por tarefa → acumula propostas **propose-only** (`merged_to_main: false` SEMPRE) + caps de tempo/tarefas. | 3 testes |
| **Comando runtime** | `app/Console/Commands/AtlasEvolutionLoopCommand.php` | `atlas:loop:evolve` — entry operacional. | e2e real |
| **Config** | `config/atlas.php` → `atlas.loop` | default_provider (trocável), scenarios_per_task, max_scenarios_per_task, search_patience, propose_only. | — |
| **/workflows rigoroso** | `.claude/workflows/loop-proposal-adversarial-verify.js` | Re-prova adversarial INDEPENDENTE das propostas (Goodhart-guard #4, fora do processo). | — |

---

## 2. Como rodar

```bash
# prova end-to-end da cadeia inteira (driver real):
php artisan atlas:loop:evolve --fixture --scenarios=2 --json

# loop real sobre uma fila de tarefas metric-shaped (a espinha do Ladder):
php artisan atlas:loop:evolve --task-file=queue.json --max-seconds=86400 --max-tasks=500 --json

# depois, a re-prova adversarial das propostas (rigorosa, fora do processo):
#   Workflow({ name: 'loop-proposal-adversarial-verify', args: { repo, proposals } })
```

Uma tarefa metric-shaped (= uma Hypothesis do Ladder: objetivo + métrica/aceitação):

```json
{
  "objective": "...o que mudar...",
  "base_workspace": "/abs/path",
  "allowed_files": ["src/Foo.php"],
  "validation_commands": ["composer test -- --filter=FooTest"],
  "acceptance": {
    "commands": ["composer test -- --filter=FooTest"],
    "allowed_globs": ["src/**"],
    "frozen_globs": ["tests/**", "harness/**"],
    "metric_kind": "gate"
  },
  "min_scenarios": 3, "max_scenarios": 12, "search_patience": 3
}
```

---

## 3. A prova (real, não auto-relato)

- **13 testes unit verdes** cobrindo: juiz aceita bom / **rejeita adulteração do teste frozen** / rejeita fora-de-escopo / rejeita falha; explorador rejeita trapaça + escolhe o honesto + desempata por menor diff; deep search converge / roda até o teto; runner **nunca faz merge** + acumula propostas + respeita caps.
- **End-to-end com provider REAL**: `atlas:loop:evolve --fixture --scenarios=2` → `merged_to_main: False`, 2 cenários explorados/aceitos, **proposta certified-for-review com diff real** (`helo atlas`→`hello atlas`), 95s (provider real via a abstração).

---

## 4. As travas anti-Goodhart (todas implementadas)

1. **Juiz congelado** — o candidato é bloqueado se tocar `frozen_globs` (testes/harness/métrica). `AtlasEvolutionFrozenJudge::score` → `frozen_path_tampered`.
2. **Escopo** — só `allowed_globs` podem mudar → `out_of_scope_change`.
3. **Re-prova independente in-process** — o juiz re-roda a aceitação ele mesmo; nunca confia no auto-relato do loop.
4. **Re-prova adversarial out-of-process** — o `/workflow` re-aplica num checkout limpo + tenta refutar gaming.
5. **Propose-only** — `merged_to_main: false` é invariante; o loop só acumula certified-for-review.
6. **Caps** — orçamento de tempo/cenários/tarefas (a disciplina de orçamento fixo do autoresearch).

---

## 5. A busca profunda (o que você enfatizou)

O loop **não para em N fixo**. Explora cenários enquanto **continua achando candidato estritamente melhor**; só para quando (a) converge — um vencedor existe e os últimos `search_patience` cenários não o bateram — ou (b) bate `max_scenarios_per_task` / o time budget. É o júnior que explora 20 opções até achar a certa, executável e provado (`test_deep_search_keeps_exploring_until_it_converges`).

---

## 6. Estado honesto — o que está 100% e o que é a próxima camada

**100% implementado + provado (o ENGINE do loop):** juiz · abstração trocável · explorador com deep search · runner propose-only · comando runtime · config · /workflow de verificação · todas as travas anti-Goodhart. **Funciona do começo ao fim** (provado e2e com provider real).

**A próxima camada (governança + alimentação da fila), honestamente NÃO é o engine:**
- **Wire no AAEL**: o `AtlasAutonomousEvolutionLoopService` (scan-only, `provider_invoked_directly: false`) é a governança que SELECIONA oportunidades; o seam de execução é o `createExperiment` hollow. Plugar o runner ali transforma o scan em execução. (Bridge ainda não escrito.)
- **Popular a fila = decompor objetivos em tarefas metric-shaped.** Esse é o gap honesto de sempre: o loop mói o mensurável; gerar BOAS tarefas (Sources→Ideas→Hypotheses com métrica) precisa de semente humana/agente. **Sem a métrica por-tarefa, o loop não tem o que moer** — e é por isso que a versão antiga era inútil. O engine agora existe; a fila é o trabalho de curadoria.
- **24h**: `--max-seconds=86400` + uma fila grande já roda 24h. O que falta é a fila cheia + o loop-back do Ladder (Results→Sources) automatizado.

**Resposta direta às tuas perguntas:**
- *O loop está 100% implementado?* O **engine**, sim, provado. A **governança/alimentação**, não — e isso é curadoria, não código de engine.
- *Funciona do começo ao fim?* Sim — `atlas:loop:evolve --fixture` provou a cadeia inteira com provider real.
- *Está completo no estado de produto?* Como **motor de compounding propose-only sobre tarefas metric-shaped**: sim. Como **auto-evolução aberta sem semente**: não — isso é física (precisa da métrica), não esforço.

---

*Arquivos: `app/Services/Ai/AutonomousEvolution/*.php`, `app/Console/Commands/AtlasEvolutionLoopCommand.php`, `config/atlas.php` (`atlas.loop`), `app/Providers/AppServiceProvider.php` (binding), `.claude/workflows/loop-proposal-adversarial-verify.js`, `tests/Unit/Ai/AutonomousEvolution/*`.*
