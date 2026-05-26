---
title: Atlas Decide → Gateway Consultation Hook
slug: atlas-decide-gateway-consultation
status: building
risk_level: high
graph_parent: atlas-decide-meta-learning-loop-closure
depends_on:
  - atlas-decide-meta-learning-loop-closure
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: composer
forbidden_changes:
  - bypass_kernel_or_admission
  - silent_provider_override
  - claim_winner_from_consultation
schema:
  - atlas.atlas_decide.gateway_consultation.v1
---

# Atlas Decide → Gateway Consultation Hook

Thin consultation service the `AiGatewayService` (or any consumer doing provider resolution) calls before each provider call to learn whether ADML has an active learned route for the scope.

## Não-objetivos
- Não chama provider.
- Não decide a chamada; só recomenda.
- Não pode fazer claim de winner/rivals/benchmark.

## API
```php
consult(array $context): array  // verdict ∈ {follow_learned_route|free_to_choose|requires_approval|blocked}
listConsultations(): array
```

## Gates
1. Constitutional Kernel sobre `change_kind=gateway_provider_route`.
2. Autonomy Admission sobre o mesmo.
3. ADML.activeRouteFor() pra ler a rota aprendida.

## Storage
`storage/atlas/atlas_decide/gateway_consultations.jsonl`

## CLI
`php artisan atlas:atlas-decide:gateway-consult --task-category=X --role=Y --privacy=public --json`

## Integração futura
- `AiGatewayService` ou `AiProviderManager::get()` chama `consult()` antes de resolver provider.
- Verdict=follow_learned_route ⇒ usa active_route['provider'].
- Verdict=requires_approval ⇒ abre ApprovalRequest via Policy stack.
- Verdict=blocked ⇒ aborta provider call.
