---
id: atlas-agent-merge-review-promotion-v1
type: engineering_knowledge
title: Atlas Agent Merge Review + Promotion Dry-Run v1
status: active
category: self_construction
priority: 82
summary: Camada de revisão dry-run de merges de work-products do Atlas. Pacote, escopo, risco, aprovação humana, promotion dry-run, rollback e certificação — tudo read-only. promotion_allowed=false e completion_claim_allowed=false por contrato.
tags:
  - atlas
  - self-construction
  - merge-review
  - dry-run
  - read-only
capabilities:
  - agent_merge_review_packet_builder
  - agent_merge_review_scope_verifier
  - agent_merge_review_risk_scorer
  - agent_merge_review_human_approval_planner
  - agent_merge_review_promotion_dry_run
  - agent_merge_review_rollback_verifier
  - agent_merge_review_certification
decisions:
  - Merge review é dry-run puro; nunca aplica patch, nunca toca arquivos reais, nunca avança completion claim.
  - Aprovação humana é planejada, jamais concedida ou persistida por estes serviços.
  - Certificação read-only mantém promotion_allowed=false e completion_claim_allowed=false sob hard-law.
maintenance:
  - Quando schema_version mudar, refletir os 7 contratos em testes e doc.
  - Não wire em routes/api.php nem em CLI mutating até que o promotion gate runtime esteja certificado.
  - Rodar `php artisan atlas:engineering:knowledge docs-health --json` após qualquer edição neste doc.
related_paths:
  - app/Services/Ai/SelfConstruction/AgentMergeReviewPacketBuilder.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewScopeVerifier.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewRiskScorer.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewHumanApprovalPlanner.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewPromotionDryRun.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewRollbackVerifier.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewCertificationService.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewPacketBuilderTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewScopeVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewRiskScorerTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewHumanApprovalPlannerTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewPromotionDryRunTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewRollbackVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewCertificationServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agent-merge-review-promotion-v1
graph_title: Atlas Agent Merge Review + Promotion Dry-Run v1
graph_world: atlas
graph_layer: module
graph_kind: contract
graph_parent: atlas-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Agent Merge Review + Promotion Dry-Run v1
canonical_name: Atlas Agent Merge Review + Promotion Dry-Run v1
technical_name: atlas-agent-merge-review-promotion-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/agent-merge-review-promotion-v1.md
owner: atlas-self-construction-os
repo_paths:
  - app/Services/Ai/SelfConstruction/AgentMergeReviewPacketBuilder.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewScopeVerifier.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewRiskScorer.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewHumanApprovalPlanner.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewPromotionDryRun.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewRollbackVerifier.php
  - app/Services/Ai/SelfConstruction/AgentMergeReviewCertificationService.php
allowed_changes:
  - Refinar tabela de risk factors quando observar drift em multi-Claude.
  - Adicionar invariant novo ao certification grid quando contrato canônico mudar.
forbidden_changes:
  - Wire em rota HTTP, comando Artisan mutating ou ledger writer.
  - Permitir promotion_allowed=true ou completion_claim_allowed=true.
  - Modificar arquivos reais a partir destes serviços.
depends_on:
  - atlas-self-construction-os
  - agent-control-plane-contract
flows_to:
  - macro-sprint-promotion-gate
  - release-dossier-future
unlocks:
  - dry_run_merge_review_evidence
  - human_approval_planning_protocol
governs:
  - atlas_self_construction_evidence_corridor
evidence:
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewPacketBuilderTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewScopeVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewRiskScorerTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewHumanApprovalPlannerTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewPromotionDryRunTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewRollbackVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AgentMergeReviewCertificationServiceTest.php
evidence_refs:
  - test: AgentMergeReviewPacketBuilderTest
  - symbol: AgentMergeReviewPacketBuilder
required_tests:
  - vendor/bin/phpunit --filter 'AgentMergeReview' --no-coverage
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Conectar à pipeline de promotion gate runtime quando a slice estiver certificada.
  - Adicionar consumer read-only (artisan command com flag --json) sem disparar mutating tick.
visual_tags:
  - merge-review
  - dry-run
---
## Resumo

Camada de revisão dry-run de merges de work-products que o Atlas
Self-Construction OS está prestes a integrar. Recebe um manifesto
sintético de diff + manifesto de artefatos + escopo declarado + risk
score + plano de aprovação + dry-run de promoção + verificação de
rollback, e emite uma certificação read-only com hashes determinísticos.
**Nunca aplica patch, nunca toca arquivos reais, nunca persiste
aprovação, nunca avança completion claim, nunca despacha agente, nunca
escreve no ledger.**

## Papel no Atlas

Camada de evidência antes do promotion gate. Mantém o operador humano
no controle da decisão de merge, sem dar à IA poder de escrever no
codebase. Convive lado a lado com o Agent Control Plane: este último
projeta estado de slices da Self-Construction OS; o Merge Review valida
work-products candidatos a integração contra o mesmo conjunto de
invariantes hard-law.

## Onde Se Encaixa

- Acima: contrato canônico `agent-control-plane-contract.md`.
- Ao lado: serviços `AgentControlPlaneCertification*` (que validam a
  chain de slices) e `AgentControlPlaneScopeLockPlanner` (que planeja
  scope locks read-only).
- Abaixo: o promotion gate runtime (futuro) e o release dossier
  (futuro) — nenhum deles existe ainda em modo runtime mutating.

## Contratos

| Service | `SCHEMA_VERSION` |
|---|---|
| `AgentMergeReviewPacketBuilder` | `atlas.self_construction.agent_merge_review_packet.v1` |
| `AgentMergeReviewScopeVerifier` | `atlas.self_construction.agent_merge_review_scope_verification.v1` |
| `AgentMergeReviewRiskScorer` | `atlas.self_construction.agent_merge_review_risk_score.v1` |
| `AgentMergeReviewHumanApprovalPlanner` | `atlas.self_construction.agent_merge_review_human_approval_plan.v1` |
| `AgentMergeReviewPromotionDryRun` | `atlas.self_construction.agent_merge_review_promotion_dry_run.v1` |
| `AgentMergeReviewRollbackVerifier` | `atlas.self_construction.agent_merge_review_rollback_verification.v1` |
| `AgentMergeReviewCertificationService` | `atlas.self_construction.agent_merge_review_certification.v1` |

Todos os envelopes carregam `non_execution_guarantees[]`, hash sha256
do próprio envelope, e flags `apply_patch_allowed`, `real_file_write_allowed`,
`completion_claim_allowed`, `dispatch_allowed`, `ledger_write_allowed`
todas em `false`.

## Fluxo

```
synthetic diff manifest   artifact manifest   declared scope    context
            │                      │                  │              │
            └──────────────┬───────┴──────────────────┴────┬─────────┘
                           ▼                                ▼
              AgentMergeReviewPacketBuilder      (packet envelope, packet_hash)
                           │
                           ▼
              AgentMergeReviewScopeVerifier      (verification envelope, verification_hash)
                           │
                           ▼
              AgentMergeReviewRiskScorer         (risk envelope, risk_hash)
                           │
                           ▼
              AgentMergeReviewHumanApprovalPlanner  (plan envelope, plan_hash)
                           │
                           ▼
              AgentMergeReviewPromotionDryRun    (dry-run envelope, dry_run_hash)
                           │
                           ▼
              AgentMergeReviewRollbackVerifier   (verification envelope, verification_hash)
                           │
                           ▼
              AgentMergeReviewCertificationService
                           │
                           ▼
                  certification envelope (status=available, all flags false, 27 invariants)
```

Cada envelope é puro: pode ser hasheado e auditado independentemente.

## Padrão "tudo verde" por envelope

- **Packet**: `status=agent_merge_review_packet_ready`, todas as flags
  `*_allowed=false`, `packet_hash` sha256 64-char.
- **Scope verification**: `status=agent_merge_review_scope_verified`,
  `verification.all_in_scope=true`, `violation_count=0`.
- **Risk score**: `status=agent_merge_review_risk_scored`,
  `risk.overall_band` ∈ {low, medium, high}, `risk.critical=false`.
- **Approval plan**: `status=agent_merge_review_human_approval_plan_ready`,
  `plan.approval_eligible=true`, `approval_granted=false`,
  `approval_persisted=false`.
- **Promotion dry-run**: `status=agent_merge_review_promotion_dry_run_planned`,
  `promotion_allowed=false`, `plan.critical_blockers_present=false`,
  todos os steps com `has_side_effect=false`.
- **Rollback verification**: `status=agent_merge_review_rollback_verified`,
  `verification.all_steps_reversible=true`, `verification.confidence=high`.
- **Certification**: `status=available`, `invariants_all_true=true`,
  `violation_count=0`, `runtime_safety.runtime_safety_all_false=true`,
  `promotion_allowed=false`, `completion_claim_allowed=false`.

## Risk bands

- `low`: até 2 pontos. Aprovação `single` (operator).
- `medium`: 3–5. Aprovação `double` (operator + reviewer).
- `high`: 6–9. Aprovação `double` (operator + reviewer + security).
- `critical`: 10+. Aprovação `quorum` (operator + reviewer + security +
  release_manager), **e** approval_eligible é forçado a `false` até a
  band cair de critical.

Pesos de fatores: blast radius por arquivo (0–4), blast radius por
linhas (0–4), deletions (0–2), renames (0–1), forbidden paths (×3),
cross-axis paths (×4), unsafe path prefixes (×4), out-of-scope (×2),
failing artifacts (×2), artifacts ausentes (1).

## Regras para IA

- IA implementadora **nunca** pode trocar `promotion_allowed` ou
  `completion_claim_allowed` para `true` nestes serviços.
- IA **nunca** pode adicionar dependência a `routes/api.php`, a
  Postgres writer, a queue dispatcher ou a process supervisor.
- IA **nunca** pode mover esses serviços para outra pasta — eles
  pertencem a `app/Services/Ai/SelfConstruction/` por contrato
  arquitetural.
- IA que adicionar invariant novo ao certification grid precisa
  atualizar o teste de invariants nomeados em
  `AgentMergeReviewCertificationServiceTest::test_invariants_*`.
- IA que adicionar campo novo a um envelope precisa atualizar a tabela
  "Padrão tudo verde" deste doc e adicionar assertion no teste do
  service correspondente.

## Escopo de Implementacao

- Pertence a `app/Services/Ai/SelfConstruction/`.
- Testes em `tests/Feature/Ai/SelfConstruction/`.
- Documento em `docs/engineering-knowledge-base/self-construction/`.
- **Não** wire em rota HTTP, **não** wire em comando Artisan
  mutating, **não** escreve banco, **não** dispara provider.
- Edits válidos: refinar pesos de risk factors, adicionar invariant
  novo, refinar approval mode policy.
- Edits proibidos: mudar flags de read-only para true, persistir
  envelope, aplicar patch real, conectar a writer.

## Dependencias

- Hard: `Carbon\CarbonImmutable` (Laravel default).
- Soft: `agent-control-plane-contract.md` para alinhamento conceitual.
- Nenhuma dependência runtime para provider, Postgres ou redis.

## Evidencias

Execuções em 2026-05-14:

- `vendor/bin/phpunit --filter 'AgentMergeReview' --no-coverage`:
  **135 testes / 432 assertions verdes**.
- `php -l` em todos os 7 services: zero erros de sintaxe.
- Smoke test inline: `certify(...)` retorna
  `status=available`, `invariants_all_true=true`, `violation_count=0`,
  `promotion_allowed=false`, `completion_claim_allowed=false`,
  `runtime_safety_all_false=true`, 27 invariants no grid, hash sha256
  64-char.

## Riscos

- IA refactor futura tentar wire o certification em route HTTP →
  mitigação: invariant `runtime_safety:no_dispatch_real` falharia se
  qualquer flag `*_allowed` virasse `true`.
- Operador interpretar `status=available` como autorização de merge →
  mitigação: human_summary explicita `promotion_allowed=false,
  completion_claim_allowed=false`.
- Risk scorer drift contra novos tipos de violação → mitigação:
  adicionar fator novo + assertion correspondente.

## Exemplos

### Cenário limpo

```php
$svc = new App\Services\Ai\SelfConstruction\AgentMergeReviewCertificationService;
$diff = [
    'source' => 'synthetic_diff_manifest',
    'base_revision' => 'baseline-x',
    'head_revision' => 'head-y',
    'files' => [
        ['path' => 'app/Services/Ai/SelfConstruction/Sample.php',
         'change_kind' => 'modified',
         'lines_added' => 12,
         'lines_deleted' => 4,
         'hunk_count' => 3,
         'content_hash' => str_repeat('a', 64)],
    ],
];
$artifacts = ['artifacts' => [
    ['kind' => 'test', 'name' => 'phpunit', 'status' => 'passed',
     'evidence_hash' => str_repeat('b', 64)],
]];
$scope = ['allowed_files' => ['app/Services/Ai/SelfConstruction/']];
$result = $svc->certify($diff, $artifacts, $scope,
    ['packet_id' => 'p', 'claim_id' => 'c', 'task_packet_id' => 't']);

// $result['status'] === 'available'
// $result['promotion_allowed'] === false
// $result['completion_claim_allowed'] === false
// $result['runtime_safety']['runtime_safety_all_false'] === true
```

### Cenário crítico

```php
$diff = ['files' => [
    ['path' => 'config/secret.php', 'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('a', 64)],
    ['path' => 'routes/api.php',    'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('b', 64)],
    ['path' => '../escape.php',     'change_kind' => 'modified', 'lines_added' => 1, 'content_hash' => str_repeat('c', 64)],
]];
$scope = ['allowed_files' => ['app/'], 'forbidden_files' => ['config/secret.php']];
$result = $svc->certify($diff, [], $scope, ['packet_id' => 'p-critical']);

// $result['inputs']['risk_score']['risk']['overall_band'] === 'critical'
// $result['inputs']['approval_plan']['plan']['approval_eligible'] === false
// $result['inputs']['promotion_dry_run']['status'] === 'agent_merge_review_promotion_dry_run_blocked'
// $result['next_action'] === 'clear_blocking_conditions_then_seek_human_approval'
// $result['promotion_allowed'] === false
// $result['completion_claim_allowed'] === false
```

## Proximas Acoes

- Documentar como o release dossier futuro vai consumir o
  `certification_hash` para selar um merge real.
- Quando o promotion gate runtime existir, adicionar comando Artisan
  read-only (`--json`) que projete a certificação sem disparar nada.
- Estudar se o catalogo de risk factors deve crescer com sinais de
  benchmark/security-scan quando esses pipelines forem integrados.
