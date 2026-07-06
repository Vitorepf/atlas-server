# LEIA-ME DO IMPLEMENTADOR — linha de obras ACOS (#15→#18)

Data: 2026-07-06 · Público: o modelo implementador (ex.: Codex) e qualquer sessão fria · Autoridade: este arquivo define O QUE implementar e em que ordem. Em conflito entre docs, a precedência é: **ordem de trabalho > este LEIA-ME > #18 > #17 (com adendo) > #16/#15 (visão, NUNCA implementar direto)**.

## Papel de cada documento

| Doc | Papel | Implementar direto? |
|---|---|---|
| `obra15-acos-consciencia-de-engenharia-2026-07-06.md` | VISÃO (memória de engenharia) | **NÃO** — fases corrigidas/cortadas pela #17 |
| `obra16-acos-cognicao-ativa-2026-07-06.md` | VISÃO (pensamento) | **NÃO** — só P3/P6 sobreviveram, re-sequenciados na #17/#18 |
| `obra17-acos-3x-plano-mestre-2026-07-06.md` + **Adendo de Implementabilidade** (no fim do arquivo) | PLANO MESTRE do motor de retrieval + valor por degrau | Sim, **slice a slice, cada um via ordem de trabalho** |
| `obra18-acos-materia-prima-canos-kit-2026-07-06.md` | PLANO MESTRE de dado + costuras + kit de delegação | Sim, idem |
| `docs/work-orders/*.md` | ORDENS DE TRABALHO (uma por slice, formato do Kit) | **SIM — só se implementa o que tem ordem** |

## A regra de ouro

**Nenhum slice é implementado sem ordem de trabalho.** A ordem contém: contrato de arquivos (allowed/forbidden), lista congelada de callers, teste de aceitação PRÉ-ESCRITO (você o faz passar; NUNCA o edita), comandos de prova literais, glossário sigla→path, critérios pare-e-devolva, e gates rotulados. A primeira ordem exemplar já existe: `docs/work-orders/WO-17-T0.1-retrieval-por-query.md`. As demais são geradas no mesmo formato (Frente K da #18 automatiza isso; até lá, o planejador as escreve à mão).

## Ordem de execução da linha

1. `WO-17-T0.1` (retrieval por query) — **pronta**
2. #18 C1 (dereferenciar memória no Dev) e #18 D1/D2 (matéria-prima) — ordens a gerar; paralelos ao T0
3. #18 K1-K4 (kit) — antes de qualquer delegação em lote
4. #17 T0.2-T0.4, T1... conforme os planos mestres (sequência cruzada na #18 §Sequência)

## Regras universais do implementador (valem em TODA ordem)

1. **Aditivo-only em símbolo público.** Mudar/remover assinatura, renomear método, "consolidar" classes = PROIBIDO sem que a ordem liste os callers e o destino de cada um. Se parecer necessário: PARE e devolva.
2. **Você nunca escreve o oráculo.** O teste de aceitação vem pronto na ordem e está em forbidden_files. Testes ADICIONAIS seus são bem-vindos; editar o de aceitação, nunca.
3. **Gates têm dois tipos.** `gate:mecânico` = você fecha com o comando literal da ordem. `gate:evento-operador` = SÓ o operador fecha; você não simula, não declara, não fabrica.
4. **Arquivo fora de allowed_files = PARE**, mesmo que "só uma linha".
5. **Símbolo citado que você não encontra**: verifique no workspace certo (`cd atlas-server`), com `rg --no-ignore` se o path envolve `storage/`. Se continuar não existindo: PARE e devolva com o diagnóstico — não crie um novo com o mesmo nome.
6. **Suite vermelha ampla**: classifique (minha regressão / pré-existente / WIP externo) e PARE. Nunca "force verde". Há trabalho paralelo vivo nesta main (workers + sessões do operador); reds de `AtlasMemoryRegistryTest` sobre verbatim/redacted são WIP externo conhecido — não toque.
7. **Nunca**: `git add -A`; push; `composer dump-autoload` em worktree com vendor symlinkado; testes apontando para o pgsql vivo (o phpunit.xml já força sqlite :memory: — não altere isso); rodar qualquer coisa autônoma do Loop.
8. **Vocabulário proibido em código/docs novos**: Jarvis, Rivals, benchmark, superiority, concurrent.
9. **Commit**: só os arquivos da ordem, mensagem começando com o id da ordem (ex.: `WO-17-T0.1: ...`).
10. **Comando artisan novo**: antes de criar, `php artisan list | grep '<nome>'` — colisão de nome sobrescreve o serving (falha histórica). Se o comando não aparecer após criar: `php artisan optimize:clear`.
