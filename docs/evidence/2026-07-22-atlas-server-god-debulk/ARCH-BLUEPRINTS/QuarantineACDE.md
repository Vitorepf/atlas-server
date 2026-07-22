# ARCH BLUEPRINT — QuarantineACDE (órfãos do AutonomousEvolution)

> status: draft-v2 (SOBREVIVEU ao verify adversarial; emendas aplicadas + CSV corrigido pelo comandante)
> EMENDAS: (1) 7 classes Aael/ estavam contaminando o lote high — CORRIGIDAS no CSV para review-aael (minha adjudicação §2 vale: AAEL fora); (2) o piso por arquivo ganha cláusula PATH-string: além de rg -w <Classe>, buscar 'AutonomousEvolution/.*<Classe>.php' e require em tests/ (achado real: tests/atlas_generated_0.php:72 faz require por path de AtlasLoopProviderEditApplier); (3) lote declarado como "≤218, filtrado pelo piso por onda" — é teto, não lote executável (ex.: AtlasLoopSoakReportService tem teste não-espelho que não move); (4) [CORRIGIDO NA IMPLEMENTAÇÃO 2026-07-22] AtlasLoopSimulableTwinOrchestrator NÃO é órfão e NÃO se move: é o `executor_organ` VIVO do path 'simulation-twin' do portfólio do cérebro, resolvido em runtime por `AtlasBrainPathCatalog` via `config('atlas.brain.paths')` L25/L59 (AtlasBrainPathCatalog é VIVO — usado por AtlasBrain{State,HealthDoctor,Catalog}Command + LeverageBrief + AtlasLoopHarnessGuard; o HealthDoctor valida 'simulation-twin' entre os 7 paths canônicos). O CSV o marcou 'high' porque o snapshot perdeu o `::class` no config (trap de class-string). NÃO remover as 2 linhas de config/atlas.php — quebraria um path vivo. O floor-check vivo (exec_wave.py) pegou o config-ref e pulou o componente. Companheiro AtlasLoopCounterfactualEditEvaluator é órfão real (só self+teste; o Twin não o referencia) — separável, adiado.; (5) correção de honestidade: o claim "todo cadáver tem entrada em config+AppServiceProvider" era exagero do analista — só 1/225 tem config entry.
> data: 2026-07-22
> obra: GOD Debulk / cluster AUTONOMOS
> lista provada: `acde-orphans.csv` (neste diretório — 541 linhas, uma por classe non-Brain, com status/refs/LOC/confiança)

## 1. Números finais (provados por script com fecho transitivo a ponto-fixo)

| Status | Classes | % | Critério |
|---|---|---|---|
| VIVO-EXTERNO | 124 | 22,9% | ref de zona viva nomeada (SelfConstruction/Stewardship/Programming/Foundry/EngineeringKernel/Models/AppServiceProvider/Brain/cmds atlas:brain·task) |
| VIVO-TRANSITIVO | 173 | 32,0% | puxado por VIVO (fecho até ponto-fixo) |
| **ÓRFÃO** | **244** | **45,1%** | **0 refs VIVAS** (consumido só por teste-espelho/config/irmão morto) |

⚠ CORREÇÃO DO COMANDANTE sobre a análise anterior do cluster: eram "~388 órfãos (61%)" — número inflado; o real é **244 (45%) / 33.903 LOC**. CONSOLIDATION-MAP corrigido.

**Insight de método (pétreo para execução):** NÃO existe órfão com 0 refs absolutas — todo cadáver ACDE tem teste de caracterização próprio + entrada em config/atlas*.php + registro em bloco no AppServiceProvider. A prova de orfandade é **0 refs vivas**; "referência de teste/config" não é vida. (Spot-check do comandante: AtlasLoopThrashLossRate e AtlasLoopSoakReportService — só self+testes. CONFIRMADO.)

**Keep-list:** as 27 AtlasLoop* vivas estão 100% em VIVO-EXTERNO. Zero na coluna órfão.

## 2. Adjudicação da zona cinza AAEL (decisão do comandante)

19 classes (3.206 LOC) cujo único consumidor não-teste é a família `atlas:aael:*` (8 comandos existentes). Regra pétrea da casa: **"AAEL = outro produto — não renomear/mexer nesta obra"**. Decisão: as 19 **FICAM FORA da quarentena** — pertencem ao produto AAEL, não ao cadáver ACDE. Marcadas `review-aael` no CSV; qualquer decisão futura sobre elas é obra do produto AAEL, não do Debulk.

## 3. Lote de execução

**Lote seguro: ≤218 classes / ~30.3k LOC (teto — filtrado pelo piso por onda; 7 Aael removidas)** (`quarantine_confidence=high` no CSV).

## 4. Mecânica (para o EXECUTE, em ondas)

1. **Destino**: `git mv` para `archive/AutonomousEvolution/<subpath>` preservando a subestrutura (reversão trivial, histórico preservado). `archive/` FORA do autoload PSR-4 de `App\` (exclusão no composer.json OU diretório fora de app/).
2. **NÃO re-namespacear no mesmo commit** — mover primeiro (autoload já não o vê), suíte verde, namespace só se necessário em commit separado.
3. **Teste-espelho move JUNTO** no mesmo commit escopado (senão a suíte quebra com classe ausente). Entradas de config/atlas*.php e registros em bloco do AppServiceProvider que apontem só para órfãos movidos: remover na mesma onda, com diff mostrado.
4. **Piso por arquivo antes de cada mv**: `rg --no-ignore -w <Classe>` retorna só self + teste-espelho + config/irmão-morto. Divergência = arquivo sai da onda.
5. **Ondas de ~25 classes** com `php artisan test --parallel` (subset tocado) + `bash scripts/god-debulk-guard.sh` verdes entre ondas. Commit `refactor(core): GOD-DEBULK QUARANTINE ACDE onda N` com `git add -- <escopado>`.
6. **Re-varredura pós-onda** (efeito cascata): arquivar um VIVO-TRANSITIVO folha pode orfanizar o filho — re-rodar o classificador (script `orphan_scan2.py` preservado na evidência do método) após cada onda.
7. **KILL definitivo** (deletar do archive) = obra futura com OK explícito do operador; a quarentena é o passo reversível.

## 5. Teste do patamar
Ganho: **30.697 LOC saem do caminho vivo** do maior bloco do repo (AutonomousEvolution cai de 109k para ~78k vivo) sem tocar 1 linha de capacidade viva — reversível por `git mv` de volta. O organismo não perde nada que alguma raiz viva alcance (fecho transitivo provado).

## 6. Gate do operador
Esta quarentena é movimento físico de 225 arquivos + testes — **precisa de OK explícito do operador** antes de entrar na fila do EXECUTE (needs_operator registrado no ARCH-LEDGER).
