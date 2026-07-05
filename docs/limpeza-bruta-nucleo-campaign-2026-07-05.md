# GOAL — Limpeza Bruta do Núcleo (AAEOS + ACOS + contexto/memória): eliminar, simplificar, abstrair, solidificar, religar

**Data:** 2026-07-05 · **Status:** spec da campanha (referência congelada do /goal)

## Missão
Executar uma campanha de limpeza profunda no núcleo do atlas-server (~4.100 arquivos / ~1,23M linhas em `app/Services/Ai/*` + `app/Services/Engineering/`), em lotes pequenos commitados na main, com prova de comportamento preservado a cada lote. Não é faxina cosmética: é remover massa morta, consolidar duplicação, religar órgãos bons desconectados e consertar o PRODUTOR de entropia para a limpeza não regredir.

## Baseline medido em 05/07/2026 (re-medir ao final para provar o delta)
- Duplicação exata (jscpd, min-tokens 100, sobre `app/Services/Ai/`): **4.016 clones / 54.926 linhas (4,25%)**. Pior área: `SelfConstruction/` (20.914 linhas clonadas), depois `SoftwareCompanyStewardship/` (6.721), `Aaeos/` (5.202), `AutonomousEvolution/` (4.658), `Programming/` (4.581).
- Órfãos: amostra aleatória de 200 classes do `SelfConstruction/` → **17% com zero referência estática** em app/config/routes/database.
- Série especulativa L8/L9/L10: **62 arquivos / 20.106 linhas**; 10 `*CertificationService` dessa série comprovadamente 0-ref (~4k linhas): L9Q3EngineeringDisciplineEvolution, L8P1FrameEvolution, L10R1GenerativeEngineering, L8P5SelfDeceptionImmunity, L9Q1OperatorJudgmentAmplification, AaeosL10GenerativeEngineering, L8P4LocalEngineDistillation, L8P2MetaCompounding, L10R2LongHorizonStrategy, L10R3BoundedRecursion.
- **81 `*CertificationService`** fragmentados em volta do AcceptanceGate soberano (Obra #1).
- **228 arquivos** mencionam legacy/legado em prosa; só **2** têm `@deprecated` formal.
- Bricks mortos confirmados: `app/Services/Ai/AutonomousEvolution/Merge/AtlasLoopAutoMergeService.php` (WAVE-14, executor NO-OP, ainda bound em `AppServiceProvider.php:80` como `AtlasLoopMergeService`); 8 arquivos WAVE-; par duplicado `Introspection/` vs `Consolidation/` no Loop (`AtlasLoopSelfArchitectureScanner` e `AtlasLoopSelfDependencyGraphReporter` existem nos dois).
- `Aaeos/Generated/`: 309 arq / 133k linhas — projeção regenerável, amostra de 60 classes = 100% consumida fora de Generated/. NÃO é lixo.
- 11 `class_alias` shims no app (back-compat intencional, não contar como duplicata).

## Fases (nesta ordem)
**F0 — Consertar o produtor (primeiro, senão é Sísifo).** A duplicação nasce da esteira autônoma (`SelfConstruction/` = 38% dos clones). Adicionar verificação determinística de dedup/reuso no caminho de aceite das entregas autônomas: entrega que re-implementa símbolo público existente (mesma assinatura/propósito, detectável pelo census de símbolos que o certificador já persiste) é recusada com blocker nomeado, forçando reuso. ADITIVO, fail-closed, sem LLM no julgamento.

**F1 — Eliminações confirmadas baratas (~25k linhas, risco baixo).**
1. Aposentar o brick `Merge/AtlasLoopAutoMergeService` no-op + remover o binding do container + os demais bricks WAVE- sem consumidor.
2. Resolver o par `Introspection/` vs `Consolidation/`: eleger o canônico por evidência (callers reais), migrar callers, deletar o perdedor.
3. Deletar os 10 certifiers L8/L9/L10 0-ref (~4k) e avaliar o resto da série de 20k com preflight — patamar especulativo se constrói quando chega, não antes.

**F2 — Auditoria adversarial com deletion-preflight (a maior massa).** Varredura por área começando pelo `SelfConstruction/`: para cada classe candidata a órfã, rodar o preflight completo (regras abaixo). Saída: lista deletável COM PROVA por item. Deletar em lotes de ≤20 arquivos, suíte verde entre lotes.

**F3 — Abstrair as famílias de clone (uma família por commit).** Alvos já identificados: máquinas de estado do `Finance/PolymarketExec/` (`BasketStateMachine` vs `MintSellStateMachine`, 97 linhas iguais), readiness-gates de agente no `SelfConstruction/` (82), verifiers de completion-receipt (79), auto-clone de 149 linhas no `Voice/AtlasVoiceRuntimeCertificationService` (linhas 121-269 ≡ 425-573). Extrair base/trait, migrar as cópias, provar com a suíte da área.

**F4 — Simplificar os 81 certifiers.** Cada `*CertificationService` legado vira adapter fino do AcceptanceGate soberano (padrão dos adapters existentes em `EngineeringKernel/Adapters/`) ou é deletado se 0-ref (com preflight). Meta: <20 restantes.

**F5 — Religar o que é bom mas está desligado (wire-or-retire).** Para cada organ 0-ref que o preflight indicar como "unwired, não morto": decidir religar (dar caller real + teste de integração) ou aposentar com justificativa no commit. Candidatos conhecidos: leitor de `admission.jsonl`, scorecard, matcher, behavior-ledger. ATENÇÃO: se a Obra #4 S4 (COMPOUND→Brain) já estiver em execução, esses quatro pertencem a ela — verificar antes para não duplicar trabalho.

**F6 — Formalizar deprecação.** Todo arquivo mantido que a prosa chama de legacy ganha `@deprecated` com apontador para o substituto; o que não tem substituto nem uso, volta para F2.

## Regras pétreas (violar qualquer uma = parar e reportar)
1. **Deletion-preflight obrigatório** antes de QUALQUER remoção: (a) `rg` full-sweep do nome da classe em app/config/routes/database/tests; (b) bindings de container e aliases (`class_alias` — existem 11); (c) wiring dinâmico: Finder/glob por convenção, reflection, strings FQCN, schedules, comandos artisan; (d) git log recente do arquivo; (e) testes que o referenciam. Órfão estático ≠ morto — a lição provada é que organs 0-ref podem estar esperando wiring (ex.: `DevOutcomeMemoryService` parecia órfão e não era).
2. **Proibido remover API pública sem varredura completa de callers** (adendo de qualidade Autonomos).
3. **NÃO re-propor unificações Dev/Forge/AWEOS→Kernel** — refutadas com prova (memória `eng-kernel-unification-map`); alvo de abstração são as famílias de clone, nunca a fusão dos executores.
4. **NÃO tocar `EngineeringKernel/`** (o juiz, 2,7k linhas) — mudanças ali só via obra com freeze, nunca via limpeza.
5. **NÃO editar `Aaeos/Generated/` à mão** — projeção regenerável e 100% consumida (verificado); dedup dessa área se faz no GERADOR.
6. **Comportamento preservado = provado**: suíte da área verde ANTES e DEPOIS de cada lote; wiper-safe (SQLite `:memory:`, jamais RefreshDatabase em pgsql — lição do incidente 15/06).
7. **Commits**: lotes pequenos direto na main, SÓ os arquivos do lote, nunca `git add -A`; push só com OK do operador.
8. **Anti-Goodhart pareado**: a métrica nunca é só "linhas removidas" — é linhas removidas PAREADO com (suíte verde + zero caller quebrado + jscpd caindo). Lote que quebra = revert imediato e registro do porquê.
9. Placar em PT-BR a cada lote: fase, feitas/faltam, linhas removidas/abstraídas, próximo lote.

## Metas quantitativas (medir, não declarar)
- Eliminar 60–110k linhas com prova (banda honesta do que a medição sustenta).
- jscpd: 4,25% → **<2%** no núcleo.
- Certifiers: 81 → **<20**.
- Bricks no-op bound no container: **0**.
- 100% do legado mantido com `@deprecated` formal.

## Prova final da campanha
Re-rodar: jscpd no mesmo escopo/params (min-tokens 100) + re-amostragem 0-ref (200 aleatórios SelfConstruction) + contagem das famílias + suíte completa verde. Publicar placar antes/depois. Delta não comprovado = meta não atingida (reportar honesto, nunca ajustar a régua).

## Governança pré-início
`php artisan atlas:ai:session-bootstrap --task="limpeza bruta nucleo AAEOS" --json`; consultar `atlas_memory_recall` sobre vetos de deleção antes da F1; ao final `atlas engineering knowledge sync --prune` + `atlas engineering knowledge index-code --prune`.
