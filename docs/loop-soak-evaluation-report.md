# Relatório de Avaliação do Loop — Soak 24h (auto-melhoria)

> Documento vivo. Avaliação **independente** (não os auto-scores do loop). Atualizado a cada merge novo.
> Campanha: `019ed832` · escopo = `app/Services/Ai/AutonomousEvolution` · motor = GLM 5.2 + MiniMax-M3 (Hermes-native).
> Início: 2026-06-18.
>
> **Fatos brutos (todos os merges, auto-registrado a cada 2min):** [`loop-soak-merge-log.md`](loop-soak-merge-log.md) — gerado por `bin/atlas-loop-report-recorder.sh` direto do git history, então nenhum merge escapa entre as minhas revisões. Este documento adiciona a **avaliação qualitativa** por cima desses fatos.

## Critérios de avaliação (honestos)

| Eixo | Pergunta | Como meço |
|---|---|---|
| **Real?** | É mudança de verdade ou churn cosmético? | linhas tocadas + queda de complexidade medida |
| **Útil?** | Melhora manutenção/correção ou só mexe? | reduz cyclomatic / remove dead-code / extrai coesão |
| **Qualidade?** | Extração limpa e comportamento preservado? | classe extraída é coesa + canário verde no teste frozen |
| **Tipo** | Refactor / feature / fix? | natureza do diff |

## Merges do soak (avaliação por item)

| # | Commit | Alvo | Δlinhas | Tipo | Real? | Útil? | Qualidade | Veredito independente |
|---|---|---|---|---|---|---|---|---|
| 1 | `5887a6124` | `Verify/AtlasDeadCodeAnalyzer.php` (cyclo 43) | +111 / −55 (2 arq) | Refactor (extract-class) | ✅ sim | ✅ sim | **Boa** | Extraiu `collectUsage()` + `isMagicCallProxy()` p/ `AtlasDeadCodeAnalyzerSupport` (helpers coesos, não split arbitrário); target delega; canário VERDE no teste frozen → comportamento preservado; reduziu um método cyclo-43. **Melhoria genuína de manutenção.** |

**Yield do soak até agora:** discovered 5 → attempted 2 → certified 1 → **merged 1** · retired-stale **0** · `certified→merged = 100%` (amostra ainda pequena, soak no início).

## Avaliação agregada (contexto histórico — 213 merges lifetime)

Honestidade sobre o corpus inteiro (não dá pra graduar os 213 um-a-um, e a maioria é de runs antigos sobre código hoje stale):

- **Cert-rate é alta** (~99% do que é *proposto* certifica) — o pipeline grind→cert FUNCIONA: quando o loop entrega, passa pelos gates (juiz-frozen + canário).
- **Yield histórico era RUIM:** `certified→merged ~40%` — **309 propostas certificadas viraram lixo stale** (drift: base andava, diff não aplicava). Era o desperdício real. **Mitigado nesta sessão:** (a) fix do commit de arquivo-irmão na auto-merge (`a7a690314` — antes corrompia main em extract-refactor), (b) limpeza de 11 propostas stale que entupiam o drain, (c) auto-merge autônomo de self-improvement.
- **Amostra recente** (`WorkspaceReader` +22/−16, `AtlasEvolutionLoopRunner` +82/−12, `VentureIdeationService` +19/−46): todos refactors/extrações pequenas-médias. Padrão consistente.

## Veredito honesto (o que o loop É e o que NÃO é)

- ✅ **É bom em:** reduzir complexidade com refactor seguro e comportamento-preservado (extract-class de métodos cyclomatic-altos). Entrega a parte **"limpo"** da sua visão de produto.
- ⚠️ **NÃO é (ainda):** construtor de features / corretor de bugs de negócio. O loop faz **polimento de saúde de código**, não "produto novo poderoso". Dizer que 7 dias viram "outro produto funcional" seria over-claim — viram **um código mais limpo e menos complexo**, o que é valioso mas incremental.
- 📊 **Qualidade por-merge: ALTA** quando merge acontece (cert+canário são gates reais). O risco nunca foi a qualidade do que entra — foi o **aproveitamento** (quanto do esforço chega na main), que esta sessão atacou.

## Incidentes de supervisão (anotados)

| Quando | Incidente | Impacto | Ação | Status |
|---|---|---|---|---|
| ~1h no soak | **Campanha DUPLICADA** — o keepalive respawnou um 2º processo (`--campaign-id` resume) do MESMO campaign-id enquanto o original estava vivo (heartbeat parecia stale por grind longo → liveness check racetou). | **GLM dobrado** (8 grinds em vez de 4) + 4 grinds órfãos (ppid=1) | Matei a duplicata + órfãos; **guard no watchdog**: só roda keepalive se NÃO houver `artisan atlas:loop:campaign` vivo. | ✅ corrigido, anti-recorrência |
| early | Grind sem teto de wall-clock no caminho hermes (900s `attempt_hard_seconds` não aplicado) | 1 grind correu 20min+ | Teto de 30min/grind no watchdog | ✅ corrigido |
| early | Fila do drain entupida com 11 propostas stale de campanhas mortas (nunca mergeiam, reaparecem todo ciclo) | bloqueava drain das frescas | Retiradas (reviewed_at) | ✅ limpo |

_Lição honesta: a auto-cura do loop (keepalive) tinha uma corrida que causava desperdício — o tipo de coisa que só aparece supervisionando ao vivo, não em teste._

---
_Atualiza a cada novo merge do soak._
