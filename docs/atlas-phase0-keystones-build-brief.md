# Atlas — Phase 0 Build Brief: os 6 Keystones (pronto-pra-construir)

> A ponte desenho→implementação. Traduz a fundação consolidada (`atlas-architecture-evolution-loop.md`) em slices concretos que o **Atlas Loop / hermes-MiniMax** (ou um dev) pega e constrói. Grounded no código real verificado. NÃO é mais desenho — é o handoff.
> **Invariante global:** tudo **flag default-OFF, byte-identical-OFF** (flag ausente ⇒ comportamento de hoje), com **teste frozen** provando o default. Construir na ordem K1→K6 (cada um destrava o próximo).

---

## K1 — ReconciledCashEventStore (a verdade que paga) ✅ CONSTRUÍDO + VERDE (2026-06-17)
> SHIPPED: migration `2026_06_17_000500_create_ai_reconciled_cash_events.php` (enum DB-CHECK), model `AiReconciledCashEvent`, service `app/Services/Ai/VentureFoundry/Reward/ReconciledCashEventStore.php`, teste `tests/Feature/.../Reward/ReconciledCashEventStoreTest.php` — **7/7 verde**. Provado: DB rejeita `source='operator'`; unsettled não paga; refund reduz net; idempotente em (source, external_ref). Byte-identical-OFF (tabela/serviço novos, ligados a nada). Próximo: K2.

- **Criar:** migration `ai_reconciled_cash_events` com **DB-CHECK** `source IN ('payment_processor','bank','external_reconciled')`; model `AiReconciledCashEvent`; service `ReconciledCashEventStore` (**single-writer**, append-only, com `external_ref` distinto + `event_kind` ∈ {credit, refund, chargeback, dispute, failed_renewal} + `settlement_horizon`/`settled_at`).
- **Retrofit:** `app/Services/Ai/VentureFoundry/Success/VentureSuccessEvaluator.php` (se reconstruído) e qualquer leitura de MRR p/ veredito → **ler SÓ deste store**, nunca `ai_venture_metric_observations.source='operator'`.
- **DoD + teste frozen:** (1) INSERT com `source` fora do CHECK é **rejeitado no DB**; (2) `succeeded` é impossível sem ≥3 meses de crédito reconciliado **net de refund/chargeback**; (3) um chargeback dentro do settlement-horizon **flipa succeeded→failed**.
- **Flag:** `ATLAS_VENTURE_RECONCILED_CASH_ENABLED` (off ⇒ store vazio, todo veredito = `insufficient_data`).

## K2 — VentureCostAttributionLedger (o denominador) ✅ CONSTRUÍDO + VERDE (2026-06-17)
> SHIPPED: migration `2026_06_17_000600_create_ai_venture_cost_attributions.php`, model `AiVentureCostAttribution`, services `Cost/VentureCostAttributionLedger.php` + `Cost/VentureRunwayReader.php` (compõe K1↔K2), teste `tests/Feature/.../Cost/VentureCostAndRunwayTest.php`. K1+K2 juntos: **13/13 verde**. Provado: burn=UNKNOWN(null) sem rows (nunca zero); runway honesto (unknown/needs_fx/computed, nunca fabricado); custo negativo rejeitado; token_cost rotulado como floor. Byte-identical-OFF. Próximo: K3.

- **Criar:** stamp `venture_id` na telemetria (`AiTelemetryEvent`/`agent_runs` — hoje ausente); `VentureBurnReader` **thin sobre o substrato real** (`AiProviderCostRate` + `AiTraceMetricAggregator`, split input/output), `cost_mode='token-cost'` rotulado como FLOOR; `RunwayCalculator` (enum c/ `unknown`).
- **DoD:** burn por venture só retorna número quando **atribuição provada completa**; senão `UNKNOWN` (nunca verde fabricado). Trace sem venture → bucket `unattributed`, **nunca espalhado**.
- **Flag:** `ATLAS_VENTURE_COST_ATTRIBUTION_ENABLED`.

## K3 — WindowedReservationService (a segurança de gasto) ✅ CONSTRUÍDO + VERDE (2026-06-17)
> SHIPPED: migration `2026_06_17_000700_create_ai_venture_spend_windows.php` (2 tabelas) + service `Safety/WindowedReservationService.php` (atomic conditional increment `reserved+n<=cap` via DB transaction + idempotency) + teste `tests/Feature/.../Safety/WindowedReservationServiceTest.php`. K1+K2+K3: **18/18 verde**. Provado: gastos cumulativos não furam o cap (death-by-a-thousand-cuts bloqueado, fail-closed); idempotency-key reserva 1×. Byte-identical-OFF. Próximo: K4.

- **Criar:** `WindowedReservationService` com `check()+consume()` **atômico** (`SELECT … FOR UPDATE` + `UPDATE … WHERE reserved+n<=cap` numa transação) + idempotency-key + janelas (hora/dia/semana + portfólio). **Substitui** o `BudgetEnvelopeService::consume()` read-modify-write não-atômico (verificado :86) nos call-sites de gasto.
- **DoD + teste:** 2 reservas concorrentes **não podem** exceder o cap (teste de concorrência); idempotency-key fecha o double-charge.
- **Flag:** `ATLAS_VENTURE_SPEND_RESERVATION_ENABLED`.

## K4 — VentureHealthGate + HealthSignal enum (o gate multi-sinal) ✅ CONSTRUÍDO + VERDE (2026-06-17)
> SHIPPED (lógica pura, sem migration): enum `Health/HealthSignalState.php` (green/red/unknown + severity + permitsSuccess) + `Health/VentureHealthGate.php` (AND-gate; healthy só all-green; red→blocked_red; unknown→blocked_unknown; missing=unknown fail-closed; `downgradeOnly` monotonic) + teste `tests/Feature/.../Health/VentureHealthGateTest.php`. K1-K4: **24/24 verde**. Byte-identical-OFF. Próximo: K5.

- **Criar:** `HealthSignal` enum {solvency, churn, concentration, legality, deliverability}; `VentureHealthGate` = **AND** de todos; `unknown` é estado de 1ª classe **distinto de red** (ambos fail-closed, não marcam succeeded); composite **puro/sem-I/O/thresholds-frozen**; **monotonic-downgrade-only**.
- **DoD:** `succeeded` só com TODOS os sinais green; estados `failed_by_{insolvency,churn,concentration,legality}`; cada organ (K-fase-5) fornece exatamente 1 sinal.
- **Flag:** `ATLAS_VENTURE_HEALTH_GATE_ENABLED` (off ⇒ usa só o evaluator MRR de hoje).

## K5 — VentureActionDispatchController + VentureActionClass + ReversibilityClassifier (o boundary único)
- **Criar:** `VentureActionClass` (enum fechado code-const: marketing_post, email, price_change, ad_spend, charge, contract, outreach, refund + metadata frozen reversibilidade/blast/custo); `VentureActionReversibilityClassifier` (**fail-close em payload incompleto/conflitante**; lê magnitudes da AUTORIDADE, nunca do payload); `VentureActionDispatchController` = **único checkpoint** na frente de TODA ação externa, ordem fixa fail-closed, **double-kill re-check dentro do lock de emissão**. Reusa o SHAPE do `AtlasChangeClassTrustLadder` (Governance) p/ a graduação suggest→approve→auto sobre **evidência de caixa reconciliado**, + `KillAuthorityService` (heartbeat **do operador**).
- **DoD + teste de arquitetura:** **nenhuma** ação externa pode ser emitida sem passar pelo controller (teste que falha se algum call-site contorna); irreversível sem mandato vivo → BLOCK.
- **Flag:** `ATLAS_VENTURE_DISPATCH_ENABLED` (off ⇒ suggest-only de hoje).

## K6 — Connector Layer (as mãos) ✅ CORE CONSTRUÍDO + VERDE (2026-06-17)
> SHIPPED (core seguro/testável): `Connectors/FeedLivenessGate.php` (live/stale/never → freeze consumers; outage ≠ zero) + `Connectors/WebhookSignatureVerifier.php` (HMAC fail-closed; source só após assinatura verificada — webhook POSTável não cunha source) + teste `tests/Feature/.../Connectors/ConnectorCoreTest.php`. **K1-K6: 38/38 verde.** Adapters reais de provider (Stripe/email/ads SDK) = passo de WIRING com chaves do operador (não buildável sem credenciais). Byte-identical-OFF.

- **Criar:** contrato de adapter tipado por serviço (payment/email/ads/hosting/social/github) com **idempotency-key + verificação de webhook-signature + nonce + I/O tipado**; `FeedLivenessGate` (feed stale/down → **congela consumidores**, nunca lê zero-real); o `source` setado **server-side só após verificar a assinatura**. Pagamento 1º → alimenta o K1.
- **DoD:** connector que não prova assinatura/idempotência **falha fechado**; webhook POSTável externamente **não cunha** evento reconciliado; KYC/account-holding ficam operator-mandated (soberania).
- **Flag:** `ATLAS_VENTURE_CONNECTORS_ENABLED` + por-connector.

---

## Sequência de execução (para o Atlas Loop / dev)
**K1 → K2 → K3 → K4 → K5 → K6** (a Fase 0 inteira), cada um com teste frozen verde + byte-identical-OFF provado, **antes** de subir pra Fase 1 (medição) → 2 (já é K6) → 3 (operação) → … Construído isto, o plant está LIGADO e a nota de poder começa a subir de 6,5 — porque agora há caixa reconciliado real entrando, e todas as camadas de cima (aprendizado, portfólio, velocidade, órgãos) **param de ser dormentes**.

**Regra de ouro (de todo o loop de desenho):** caixa reconciliado é a única verdade que paga; tudo shipa o que dá valor já + fica dormente/reversível no que depende de dado real; nada finge o que não fecha. Construir nessa ordem mantém o sistema honesto a cada passo.
