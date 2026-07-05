# GOAL — Documentação Canônica Verdadeira: refatorar, limpar e atualizar `docs/engineering-knowledge-base`

**Data:** 2026-07-05 · **Status:** spec da campanha (referência congelada do /goal)
**Antecedentes:** campanha limpeza-bruta (38 commits, −86.048 linhas, 156 classes deletadas), Obras #1–#5 entregues, auditoria de staleness de 05/07 (workflow 13 agentes sobre 12 docs-chave).

## Missão
Fazer os docs canônicos (1.011 arquivos em `docs/engineering-knowledge-base/`) contarem a VERDADE do sistema em 05/07/2026: todo claim de estado de implementação com evidência verificável, toda row de backlog consumida/deletada rebaixada, zero `required_tests`/evidence refs quebrados — **sem apagar história e sem inventar fato novo**. Docs canônicos são a fonte autoral que alimenta o KB read-model e o Loop; doc mentiroso = Loop reconstruindo código deletado ou re-atacando gap fechado.

## Baseline medido (05/07, re-medir ao final)
- Auditoria dos 12 docs-chave: **3 ATUALIZADO, 5 STALE_SEMANTICO, 3 STALE_MECANICO, 1 STALE_INTENCIONAL** (vereditos + claims específicos abaixo, §Auditoria).
- 10 docs citam ≥1 das 156 classes deletadas na limpeza-bruta.
- `atlas-domain-research-runtime.md` tem `required_tests` que FALHA hoje (teste deletado em 26333fec23).
- `runtime-gap-matrix` + `department-maturity-matrix`: snapshot congelado ~26/05–01/06, pré-Obras #1–#5.
- 3 leap-backlogs com rows `status=ready` cujas classes JÁ EXISTEM desde 01/06 (32/33 no cognitive-plane; 5/8 no final-convergence; 10 no deep-cores) — colisão garantida se o Loop consumir.
- `loop-evolution-backlog.md`: S83-S165 `ready` incluindo as 63 classes L8-L10 deletadas hoje (DECISAO-1, commit 8d9ce8c0bd) E as 2 classes vivas mantidas (S129/S130) marcadas como gap.

## Fases (nesta ordem)

**F0 — Censo mecânico determinístico (TODOS os 1.011 docs).** Script (não LLM) varrendo cada doc por: (a) refs às 156 classes deletadas — regenerar a lista com `git log --diff-filter=D --name-only --pretty=format: <primeiro-commit-limpeza>..HEAD -- 'app/**/*.php' | sort -u`; (b) `required_tests:` apontando para arquivo inexistente; (c) `repo_paths`/`evidence`/`related_paths` de frontmatter apontando para arquivo inexistente; (d) FQCNs/paths inline `app/...` inexistentes no disco. Saída: TSV doc→categoria→hits. **Este censo é a work-list: nenhum doc é editado sem aparecer nele ou na auditoria §abaixo.**

**F1 — Os 3 críticos, na ordem do risco (1 commit por doc).**
1. `atlas-aaeos-loop-evolution-backlog.md`: nota DECISAO-1 na Seção 7; S83-S165 `ready`→`spec-only/gated (DECISAO-1 8d9ce8c0bd)`; S129/S130 → `done/verify-only` (classes vivas em `AtlasLoopFormalInvariantGateService`); WX5-WX7 e DAGs L8-L10 anotados como trilha de reconstrução governada, não fila viva.
2. `atlas-agentic-engineering-os-runtime-gap-matrix.md`: reescrever Snapshot Runtime (12 rows) + Evidências + Próximas Ações com evidência POR ROW (path/commit): SovereignHonestyFloor + CertifierClassificationLedger 80/80 (Obra #5, observe-first — dizer isso), replay-proof + evidence-pack hash (Obra #4, dd7c186f31), remover AP-790 (farm aposentada −158k) e a row do sistema de medição morto.
3. `atlas-domain-research-runtime.md`: remover/reescrever seção do compliance gate (deletado em 26333fec23, NUNCA teve wiring — o enforcement descrito nunca foi real), fluxo, mitigação de risco e frontmatter (`decisions`, `evidence`, `required_tests`, `schema`); registrar a deleção com data+hash; snapshot dos 10 services fica (verificado vivo).

**F2 — Leap-backlogs (supply do Loop).** `cognitive-plane`, `final-convergence`, `deep-cores`: row consumida → `delivered/done` com o PATH da classe existente como prova (seguir a convenção interna de cada doc — ex.: estilo "voz entregue" de S242/S243/S246); frontmatter `implementation_state` corrigido; seções "prontas para execução imediata" reescritas. `deep-cores` é nota curta (backlog por design); os outros dois são seção.

**F3 — Notas curtas mecânicas.** `loop-acde-v3-ownership-map.md` (remover row + Retirement Candidates de FixtureRefactorObraNodeDelivery — regra interna do doc EXIGE); `atlas-dev-forge-escalation-consolidation-plan.md` (nota 2-3 linhas: EscalationChannelGate aposentado em 26333fec23, marco de fase rebaixado); `atlas-aaeos-loop-failure-diagnosis-and-remediation.md` (seção "Status pós-Obras #1–#5 e limpeza-bruta (05/07/2026)" mapeando cada P0/P1/P2 → entregue-onde/superseded/ainda-aberto; nota nos claims que citam classes deletadas; diagnóstico D1-D5 fica como história); verificar `l7-l10-governed-ladder-backlog` (nota já feita hoje — conferir suficiência).

**F4 — Varredura do resto do censo F0.** Cada doc com hits fora dos 12 auditados: fix mecânico mínimo (nota datada; nunca reescrever história) OU marcar STALE_INTENCIONAL com justificativa de 1 linha. `department-maturity-matrix` entra aqui com tratamento F1-2 (mesmo padrão da gap-matrix). Lotes de ≤5 docs por commit.

**F5 — Prova final + sync.** Re-rodar censo F0 → publicar placar antes/depois; re-leitura adversarial de 3 docs corrigidos (amostra); `atlas engineering knowledge sync --prune` + `atlas engineering knowledge index-code --prune` (read-models derivam dos docs — sem sync, o KB continua servindo o conteúdo velho).

## Regras pétreas (violar = parar e reportar)
1. **Doc nunca passa a alegar MAIS do que a realidade.** Toda mudança de claim exige evidência verificada NA HORA (arquivo existe no disco, hash de commit, saída de `rg`). Corrigir staleness inventando fato = pior que o stale. Na dúvida, escrever "não verificado" — nunca afirmar.
2. **História se preserva.** Diagnósticos, decisões e snapshots passados não se apagam: recebem nota datada ("Status em 05/07/2026: …"). Reescrita integral só em seções cuja FUNÇÃO é descrever o presente (matrizes de estado, rows de backlog, "Próximas Ações").
3. **Regras internas de manutenção de cada doc prevalecem** (muitos têm seção "Regras para IA"/maintenance — ex.: ownership-map proíbe remover row enquanto o arquivo existir, e exige remover quando deixa de existir).
4. **Backlogs são supply do Loop**: NUNCA deixar `status=ready` apontando para classe que já existe (colisão) ou que foi deletada (reconstrução de código morto). Consumida→done com path-prova; deletada→spec-only/gated com hash da decisão.
5. **Campanha é SÓ docs.** Zero edição de código, config ou testes. Doc que revelar bug/gap de código → linha na seção Follow-ups desta spec, não fix. NÃO regenerar CLAUDE.md/projections (lean-nested, memória própria). NÃO tocar `Aaeos/Generated/`.
6. **Frontmatter é contrato**: `required_tests`/`evidence`/`schema` só apontam para coisas vivas; datas sempre absolutas; não inventar campos novos.
7. **Vocabulário**: ao editar, não introduzir os termos proibidos do CLAUDE.md; rows/menções ao sistema de medição morto são REMOVIDAS (não renomeadas). Docs da esteira de Medição não se tocam (Criação ≠ Medição).
8. **Commits**: 1 doc crítico por commit (F1) ou lote coeso ≤5 (F4), pathspec explícito, mensagem citando a evidência-chave; NUNCA `git add -A`; push só com OK do operador.
9. **Anti-Goodhart pareado**: métrica nunca é "docs editados" — é claims falsos eliminados PAREADO com (zero claim novo sem evidência + censo F0 zerado + sync verde).
10. **Placar PT-BR a cada lote**: fase, feitas/faltam, claims corrigidos, próximo lote.

## Metas quantitativas (medir, não declarar)
- 9 docs stale da auditoria → **0** (re-leitura adversarial confirma).
- `required_tests` quebrados em todo o censo → **0**.
- Refs a classes deletadas fora de notas históricas datadas → **0**.
- Rows `ready` falsas (classe existe ou foi deletada) → **0**.
- Sync dos read-models executado e verde ao final.

## Auditoria de 05/07 (vereditos congelados — a work-list qualitativa)
| Doc | Veredito | Fix |
|---|---|---|
| atlas-agentic-engineering-os.md | ATUALIZADO | nada |
| runtime-gap-matrix.md | STALE_SEMANTICO | F1-2 |
| loop-evolution-backlog.md | STALE_SEMANTICO | F1-1 |
| loop-failure-diagnosis.md | STALE_SEMANTICO | F3 |
| self-construction/loop-acde-v3-ownership-map.md | STALE_MECANICO | F3 |
| operator-intelligence/implementation-file-map.md | ATUALIZADO | nada |
| atlas-domain-research-runtime.md | STALE_SEMANTICO | F1-3 |
| dev-forge-escalation-consolidation-plan.md | STALE_MECANICO | F3 |
| final-convergence-leap-backlog.md | STALE_MECANICO | F2 |
| deep-cores-leap-backlog.md | STALE_INTENCIONAL | F2 (nota) |
| cognitive-plane-leap-backlog.md | STALE_SEMANTICO | F2 |
| atlas-ai-knowledge-governance-system.md | ATUALIZADO | nada |

Claims específicos por doc (linha a linha, com evidência) estão no output da auditoria; se a sessão da campanha não os tiver, re-verificar cada claim contra o disco antes de editar (Regra 1).

## Governança pré-início
`php artisan atlas:ai:session-bootstrap --task="documentacao canonica verdadeira" --json`; `atlas_memory_recall` sobre governança de docs e vetos antes da F1; ler `atlas-ai-knowledge-governance-system.md` (o contrato que esta campanha executa).

## Follow-ups (preenchido durante a campanha, 05/07 ~17h-18h)

1. **Código (fora da campanha docs-only):** `L7L10QueueConsumer::consume()` IGNORA `status=` das rows e contaria rows gated como ready_slices — chip de task aberto (filtro de status + teste wiper-safe). Até lá, a fila l7-l10 não deve ser apontada para o consumer.
2. **Correções a números MEUS, feitas pelos verificadores:** ledger de certifiers = **66** (2/35/10/19), não 80 (número pré-deleção que esta spec cita na tabela congelada da auditoria — manter lá como registro histórico da auditoria); farm AP aposentada = **~156k deleções provadas** (7c07b1bc82), não 158k.
3. **Correção honesta de commit:** a mensagem de `ae66078304` alega "auditoria errou 'ready'" — FALSO; o rebaixamento foi feito por um editor concorrente ENTRE a auditoria (16:39) e o meu grep (~17:10). Corrigido em `07b475a57b`.
4. **Editor concorrente (batch "atualizar docs canonicas stale apos limpeza bruta nucleo AAEOS", 17:01-17:14):** protocolo verify-then-absorb aplicado — 7 edições absorvidas c/ complementos, 2 REJEITADAS por claims falsos (ownership-map: data/campanha erradas; spec-adversary-obra: rebaixava Obra #2 ENTREGUE para planned — corrigida para runtime_available). `atlas-rivals2-rebuild-map-v1.md` ficou NÃO-COMMITADO por esta equipe (Criação≠Medição) — decisão do operador.
5. **S141 re-aberta:** `WorkspaceReadinessScoreCalculator` não existe em app/ apesar da row constar consumida — candidata a seed da esteira.
6. **Meta-4 ampliada:** o censo F0 só via paths QUEBRADOS; a prova global achou 108 colisões (rows ready com classe JÁ existente) em 6 backlogs fora do censo — todas rebaixadas com prova; re-scan global = 0.

## Ledger F4/F5 (atribuição de todo hit remanescente do re-censo)
- Re-censo final: 85 docs com hits brutos → **0 resíduos não justificados no escopo Criação**. Atribuição: 15 editados+anotados (anotação datada preserva o identificador — hit permanece POR DESIGN), 10 auditados-corrigidos (l7-l10 e loop-evolution mantêm 63 refs cada como rows spec gated por DECISAO-1), 8 intencionais (handoffs congelados, contratos de arquivos futuros, fixtures fora do repo — justificativa 1-linha no output do workflow F4), 21 prospectivos (paths/namespaces planejados por design do doc — censo era falso-positivo), 5 archive (histórico por definição), **25 docs da esteira de Medição** (`atlas-forge-rivals-*` — VETADOS para a equipe Criação; staleness pertence à outra esteira), 1 não-commitado (rivals2-rebuild-map, idem).
- `required_tests` quebrados fora de Medição: 1 doc (`atlas-code-provider-arena-ui-v1.md` — 2 refs de teste deletados em bf449d6fd3, ANOTADOS no corpo com data+hash; frontmatter mantém como registro do plano original da UI de arena, doc inteiro é da família de Medição-adjacente).
- Metas: rows ready falsas = **0 global (provado)**; 9 docs stale da auditoria corrigidos e commitados; re-leitura adversarial de amostra (3 docs) executada; sync dos read-models ao final.
