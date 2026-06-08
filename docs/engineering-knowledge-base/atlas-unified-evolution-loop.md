---
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
status: active
owner: operator
updated: 2026-06-08
---

# Atlas Unified Evolution Loop

## Resumo

O Unified Evolution Loop é o supervisor único, propose-only, que roda os modos de
evolução baseados em verificador-frozen sobre o próprio Atlas e os interliga numa só
fila de propostas com um só relatório visível. Ele cobre, de forma honesta e auditável,
três dos quatro pontos pedidos pelo operador: varredura de código em busca de código
morto (P1/P3), varredura de documentação para completar módulos canônicos (P2) e
varredura de código-versus-documentação para achar implementação falsa (P3). O quarto
ponto — implementações grandes (P4) — entra como backlog roteado para o humano/Forge,
nunca executado às cegas. Nada é mergeado: o loop só propõe; um humano revisa.

A garantia de qualidade espelha o loop de trading: cada vencedor do juiz frozen ainda
precisa passar por um holdout independente (`AtlasEngineeringHonestyGate`) que o
candidato nunca otimizou, antes de virar proposta certificada-para-revisão.

## Papel no Atlas

É o "motor de convergência" que mantém código e documentação honestos, consistentes e
sem código morto, de forma autônoma e contínua, durante 24h+, sempre propondo e nunca
aplicando. Os providers (hermes_cli por padrão, provider-agnóstico) são apenas o músculo
que produz o candidato; o cérebro — descoberta, verificador frozen, gate de honestidade,
governança propose-only — é do Atlas.

## Onde Se Encaixa

Consome o motor de busca existente (`AtlasEvolutionScenarioExplorer`,
`AtlasEvolutionFrozenJudge`, `AtlasEvolutionLoopRunner`) — não o reescreve. Roda ao lado
da campanha de código de teste-gerado (`atlas:loop:campaign`, modo P1/P4) e dobra o
status dela no mesmo painel. O dispatcher `AtlasP3FindingDispatcher` é a ponte que liga
os quatro pontos: uma varredura emite achados tipados que ou fecham aqui (auto-loop) ou
são roteados para o arm certo (humano/Forge para implementação e julgamento).

## Contratos

- Entrada: raiz do repo + lista de modos (`deadcode`, `docs_structure`) + provider.
- Verificadores frozen (aceitação por-arquivo, exit 0 iff limpo):
  - `atlas:code:deadcode-check --path=` imprime `ATLAS_DEADCODE=<n>`.
  - `atlas:docs:lint-file --path=` imprime `ATLAS_DOC_VIOLATIONS=<n>`.
  - `atlas:docs:reality-check-file --path=` imprime `ATLAS_DOC_PHANTOM=<n>` (discovery/flag).
- Saída: `report.json` (utilização/aproveitamento), `proposals.jsonl` (certificadas),
  `rejected.jsonl` (com razões), `backlog.json` (fake-implemented flags). Invariante:
  `merged_to_main: false` sempre.

## Fluxo

1. Scan (dispatcher) → achados auto-loop (deadcode, docs_structure) + flags (phantom).
2. Para cada achado não-visto: monta task métrica → `AtlasEvolutionLoopRunner` (N cenários,
   juiz frozen pega o melhor) → reconstrói o conteúdo proposto pelo diff → holdout no
   `AtlasEngineeringHonestyGate`.
3. Certifica-para-revisão só se o gate aprovar; senão registra rejeição com a razão exata.
4. Persiste, atualiza o relatório, faz heartbeat. Repete por ciclos até o budget de tempo,
   o kill-switch (`storage/atlas/loop/unified/STOP`) ou a varredura drenar.

## Regras para IA

- NUNCA mergear; o loop só propõe. Três camadas abaixo do loop proíbem merge.
- Só fechar autonomamente o que é behavior-free/checável (remoção de código morto,
  seções estruturais). Phantom de doc é julgamento → FLAG, nunca auto-editar.
- Honestidade acima de verde-falso: rejeição do gate é o sistema funcionando, não falha.
- Provider-agnóstico: nunca hardcode um provider; resolver de config/task.

## Escopo de Implementacao

Implementado e provado vivo: modo `deadcode` (analisador AST `AtlasDeadCodeAnalyzer`,
sound para membros private) end-to-end com Hermes + gate. Implementado: modo
`docs_structure` (via `AtlasDocStructureAnalyzer`), backlog `fake_implemented` (via
`AtlasDocClaimAnalyzer`, registro de comandos como ground truth). Fora de escopo do
auto-loop: implementações grandes (P4) e julgamento semântico — roteados para humano/Forge.

## Dependencias

- `AtlasEvolutionScenarioExplorer`, `AtlasEvolutionFrozenJudge`, `AtlasEvolutionLoopRunner`.
- `AtlasP3FindingDispatcher`, `AtlasEngineeringHonestyGate`.
- nikic/php-parser (vendored) para a análise AST.
- Provider via Forge router (default `hermes_cli`).

## Evidencias

- Grind vivo P3-deadcode: 1 proposta certificada, propose-only, diff = exatamente o
  método morto removido (`merged_to_main:false`).
- `AtlasEngineeringHonestyGate`: certifica a proposta real e rejeita no-op, remoção de
  método vivo colateral e injeção de método público backdoor — cada um com razão precisa.
- phpstan nível 5 limpo em todo o código novo.

## Riscos

- Custo de provider em varreduras grandes (mitigado por `max_per_cycle` + propose-only).
- Conteúdo de seções de doc pode ser raso (mitigado por revisão humana propose-only).
- O gate de holdout é determinístico; ataques fora do conjunto de holdouts dependem da
  revisão humana — por isso propose-only é inegociável.

## Exemplos

```
php artisan atlas:loop:unified --once --modes=deadcode,docs_structure
php artisan atlas:loop:unified --max-seconds=86400 --provider=hermes_cli
php artisan atlas:loop:unified:report
touch storage/atlas/loop/unified/STOP   # kill-switch
```

## Proximas Acoes

- Camada adversarial-LLM opcional sobre o holdout determinístico para modos de maior risco.
- Materialização para rodar testes-de-classe como holdout em alvos não-self-contained.
- Folding mais profundo da campanha de código (P1/P4) no mesmo orquestrador.
