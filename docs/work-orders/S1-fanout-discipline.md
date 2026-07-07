# S1 (Obra #19) — fan-out sem stall: a disciplina (infra já existe)

**Data:** 2026-07-07 · **Veredicto:** os primitivos já existem; S1 é a DISCIPLINA de usá-los. Sem código novo — o failure mode ("4 agentes travados em stall/dia") é evitado por regra, não por serviço.

## Os primitivos (já presentes, verificado nesta sessão)
- **Tipos de pesquisa sem a ferramenta `Agent`:** `Explore` e `Plan` excluem `Agent` POR CONSTRUÇÃO (não podem spawnar sub-agentes → não travam num fan-out aninhado). `general-purpose` TEM `Agent` → **proibido para pesquisa** (é a fonte do stall).
- **`Workflow`:** orquestração determinística para **≥2 fases dependentes** (pipeline/parallel, cap de concorrência). Já usado em `.claude/workflows/`.
- **Background + notificação:** agente longo roda em background (`run_in_background`) e re-invoca on-complete — nunca bloqueia o loop principal.

## A regra (o template)
1. **Pesquisa read-only larga** (varrer arquivos/convenções) ⇒ `Explore` (ou `Plan` para desenhar). NUNCA `general-purpose` para pesquisa.
2. **≥2 fases dependentes** (achar→verificar→sintetizar) ⇒ `Workflow` (pipeline), não N chamadas `Agent` manuais encadeadas.
3. **Agente longo** ⇒ background + notificação, não espera síncrona.
4. **Todo delegado leva:** tarefa + FACTS pré-verificados (paths/símbolos com `rg`, colados) + schema de saída esperado. Sem FACTS, o agente alucina.

Gate de S1 ("re-invocações manuais por stall: 4/dia → 0"): atingido pela regra 1+2 (pesquisa não-travável + fan-out determinístico). Ver [[workflow-fanout-verify-pattern]], [[claude-code-dynamic-workflows-review]].
