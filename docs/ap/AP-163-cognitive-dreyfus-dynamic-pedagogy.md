# AP-163 — Cognitive Dreyfus Dynamic Pedagogy

Status: implemented-operational-read-model

## Objetivo

Implementar a **primeira capability cardinal** do Cognitive Multiplier Edge: pedagogia dinamica baseada em Dreyfus Stage Theory. Atlas detecta nivel real do operador por nó do Knowledge Graph (1=novato a 5=master) via evidencia auditavel cruzada de `programming`, `finance`, `learning` ledgers — e adapta apresentacao de conteudo, gates e flows do `learning` automaticamente.

Resultado: `atlas study laravel-queues` opera em modo expert; `atlas study agricultura-soja` opera em modo novato. Mesma sessao pode mudar pedagogia conforme nó visitado.

## Nao Objetivo

Nao implementar:

- Knowledge Graph completo (escopo de C2 do roadmap; aqui consumimos schema minimo)
- Atlas-Vitor Cognitivo (escopo de C9)
- Cross-Domain Latticework (escopo de Fase 3)
- Auto-promocao de nivel sem evidence (operador confirma ou contesta)
- Diagnostico clinico (`non_clinical_safety` continua hard gate)

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > `cognitive/multiplier-edge.md` > este AP > `domains/learning.md`.

Este AP nasce em scaffold; promove para `implemented-operational-read-model` somente quando todos os items da Definition Of Done passarem.

## Fluxo

```
Atlas Input (atlas study <topic>)
  -> Surface Adapter canoniza (atlas_cli_study)
  -> Operation Envelope (input_kind=cognitive, dreyfus_stage_target)
  -> Intent / Routing (learning.deep_work | learning.daily_plan)
  -> Domain / Profile / Flow (learning + flow + specialist_profile)
  -> Context Builder (Currículo + Mastery + Knowledge Graph + DREYFUS OVERLAY)
  -> Policy / Profile (gate pedagogy_matches_stage)
  -> Atlas Decide (provider/modelo modulado por dreyfus_stage)
  -> Decision Receipt v2 (carrega dreyfus_stage_target)
  -> Runtime / Executor (DreyfusPedagogyService modula prompt + scaffolding)
  -> Quality Gates (pedagogy_matches_stage executavel)
  -> Output Renderer (formato muda por nivel)
```

## Schema

### Migration `2026_05_07_120000_create_dreyfus_overlays.php`

```php
Schema::create('dreyfus_overlays', function (Blueprint $t) {
    $t->id();
    $t->uuid('knowledge_node_id');
    $t->string('domain', 64);                    // programming, finance, learning, ...
    $t->string('specialist_profile', 64)->nullable();
    $t->unsignedTinyInteger('current_level');    // 1..5
    $t->decimal('confidence', 3, 2);             // 0.00..1.00
    $t->json('evidence_refs')->nullable();       // [PROG_TRACE_..., MASTERY_..., FEYNMAN_...]
    $t->string('last_updated_via', 64);          // mastery_evidence_aggregator | operator_dispute | feynman_score | transfer_proof
    $t->timestamp('last_validated_at')->nullable();
    $t->timestamp('next_validation_at')->nullable();
    $t->timestamps();
    $t->index(['knowledge_node_id', 'domain']);
    $t->index('current_level');
    $t->unique(['knowledge_node_id', 'domain'], 'dreyfus_overlay_node_domain_unique');
});

// Idempotente; nunca INSERT INTO migrations manualmente (regra Atlas).
```

### Memory type `dreyfus_overlay_snapshot`

```yaml
dreyfus_overlay_snapshot:
  knowledge_node_id: uuid
  domain: string
  current_level: 1..5
  confidence: 0.0..1.0
  evidence_refs:
    - PROG_TRACE_2026_03_15
    - MASTERY_2026_04_02
    - FEYNMAN_2026_04_18
  last_updated_via: mastery_evidence_aggregator
  last_validated_at: ISO-8601
  next_validation_at: ISO-8601
```

### Operation Envelope extension

```yaml
input_kind: cognitive
cognitive_payload:
  study_session_id: uuid
  cognitive_load_snapshot: { intrinsic, extrinsic, germane } # Sweller
  dreyfus_stage_target: 1..5 | auto
  pedagogy_mode_requested: novato | competente | proficiente | expert | master | auto
```

`auto` deixa Atlas Decide compilar pedagogy_mode a partir do `dreyfus_overlay` do nó-raiz do flow.

### Decision Receipt v2 extension

```yaml
cognitive_decision:
  dreyfus_stage_resolved: 1..5
  pedagogy_mode_resolved: string
  selection_explanation:
    primary_signals: [dreyfus_overlay, specialist_profile, cognitive_load]
    fallback_used: boolean
    confidence_band: high | medium | low
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `DreyfusOverlayRepository` | CRUD do overlay; query por nó+dominio | `app/Services/Ai/Cognitive/Dreyfus/` |
| `DreyfusEvidenceAggregator` | varre ledger; computa nivel a partir de PROG_TRACE/MASTERY/FEYNMAN | `app/Services/Ai/Cognitive/Dreyfus/` |
| `DreyfusPedagogyResolver` | recebe nó-raiz + flow; retorna `pedagogy_mode_resolved` + scaffolding policy | `app/Services/Ai/Cognitive/Dreyfus/` |
| `DreyfusPedagogyPromptBuilder` | constroi contrato de prompt modulado por nivel (regras explicitas vs caso ambiguo) | `app/Services/Ai/Cognitive/Dreyfus/` |
| `PedagogyMatchesStageGate` | gate que valida policy carrega `dreyfus_stage_resolved` antes de invocar provider | `app/Services/Ai/Kernel/Gates/` |
| `DreyfusReceiptExtensionContract` | injeta `cognitive_decision` no Decision Receipt v2 | `app/Services/Ai/Kernel/Decision/` |
| `AtlasDreyfusCommand` | CLI `atlas dreyfus <node>` para inspecao | `app/Console/Commands/` |
| `AtlasDreyfusCommand --dispute/--resolve-dispute` | abre e resolve contestacao auditavel sem salvar motivo cru | `app/Console/Commands/` |

## Gates Executaveis

### `pedagogy_matches_stage`

```php
final class PedagogyMatchesStageGate {
    public function evaluate(Envelope $envelope): GateResult {
        $cog = $envelope->cognitive_decision();
        if ($cog->dreyfus_stage_resolved === null) {
            return GateResult::block('pedagogy_matches_stage_missing_dreyfus_stage');
        }
        if (!in_range($cog->dreyfus_stage_resolved, 1, 5)) {
            return GateResult::block('pedagogy_matches_stage_invalid_level');
        }
        if ($cog->pedagogy_mode_resolved === 'auto') {
            return GateResult::block('pedagogy_matches_stage_unresolved_auto');
        }
        return GateResult::pass();
    }
}
```

### `non_clinical_safety` (heranca, reforcado)

Se prompt do provider contiver palavras-gatilho clinicas (lista canonica em `cognitive/principles.md`), bloqueia com `non_clinical_safety_blocked`.

### `provider_safety_redaction` (heranca, reforcado)

Knowledge Graph privacy class >=3 (sensitive) nunca enviado cru para provider externo; redaction obrigatoria.

## Surfaces e Comandos

| Comando | Flow | Output |
|---|---|---|
| `atlas study <topic>` | `learning.deep_work` | conteudo modulado por nivel detectado |
| `atlas dreyfus <node>` | (inspecao) | overlay completo: nivel, confianca, evidencia, proxima validacao |
| `atlas dreyfus <node> --dispute="..."` | (contestacao) | abre proposta para Curator com hash/tamanho do motivo |
| `atlas dreyfus <node> --resolve-dispute="..."` | (contestacao) | registra resolucao auditavel da disputa |
| `atlas dreyfus all --json` | (inspecao agregada) | tabela: dominio, nó, nivel, confianca, ultima validacao |

### Tela / Interacao tipica (CLI modo expert)

```
$ atlas study laravel-queues

Atlas detected:
  domain        = programming
  specialist    = programming.backend_api
  dreyfus_stage = 4 (expert)
  confidence    = 0.87
  evidence      = 23 PRs em 60d, 1 falha, 0 regressao

Mode: EXPERT
  - Sem prefacio nem tutorial
  - Caso mal-estruturado direto
  - Feedback adversarial pos-resposta

Cenario:
  Sua queue de jobs Laravel esta com latency p95 de 8s,
  mas memory usage do worker e estavel. O backlog cresce
  durante picos. Qual a primeira hipotese e qual a primeira
  metrica que voce checa para confirmar?

> _
```

### Tela / Interacao tipica (CLI modo novato)

```
$ atlas study agricultura-soja

Atlas detected:
  domain        = learning
  specialist    = learning.business
  dreyfus_stage = 1 (novato)
  confidence    = 0.95
  evidence      = nenhuma aplicacao previa registrada

Mode: NOVATO
  - Regras explicitas + glossario
  - Scaffolding por etapa
  - Checkpoint por gesto

Etapa 1/12: Vocabulario base
  Antes de qualquer estrategia, voce precisa fixar 5 termos:
    1. Cultivar — variedade da planta com caracteristicas distintas
    2. ...
  Quando entender os 5, digite continuar.

> _
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `dreyfus_overlay_lookup_latency_ms` | <= 50 |
| `dreyfus_pedagogy_resolution_latency_ms` | <= 200 |
| `dreyfus_evidence_aggregation_freshness_h` | <= 24 |
| `pedagogy_matches_stage_gate_latency_ms` | <= 30 |

Carregados em `KernelSloTargets` com namespace `cognitive.dreyfus.*`.

## Evidence Ledger Events

`DREYFUS_LEVEL_DELTA_RECORDED`, `DREYFUS_PEDAGOGY_MODE_RESOLVED`, `DREYFUS_DISPUTE_OPENED`, `DREYFUS_DISPUTE_RESOLVED`. Taxonomia fechada, aditiva.

## Tests

| Test | Local |
|---|---|
| `DreyfusOverlayRepositoryTest` | `tests/Unit/Ai/Cognitive/Dreyfus/` |
| `DreyfusEvidenceAggregatorTest` (cruzando ledger fake) | `tests/Unit/Ai/Cognitive/Dreyfus/DreyfusPedagogyResolverTest.php` |
| `DreyfusPedagogyResolverTest` (5 niveis x 3 flows) | `tests/Unit/Ai/Cognitive/Dreyfus/` |
| `DreyfusPedagogyPromptBuilderTest` (contraste novato/expert) | `tests/Unit/Ai/Cognitive/Dreyfus/` |
| `DreyfusReceiptExtensionContractTest` (Decision Receipt metadata + hints) | `tests/Unit/Ai/Kernel/` |
| `PedagogyMatchesStageGateTest` (block + pass cases) | `tests/Unit/Ai/Kernel/Gates/` |
| `AtlasDreyfusCommandTest` (CLI snapshot, upsert/show/all/dispute/resolve) | `tests/Feature/Ai/Cognitive/` |
| `AtlasStudyCommandTest` (end-to-end study session novato vs expert) | `tests/Feature/Ai/Cognitive/` |
| `CognitiveDomainComplianceTest::test_dreyfus_overlay_present_per_active_node` | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/Dreyfus tests/Feature/Ai/Cognitive
php artisan atlas:dreyfus all --json
php artisan atlas:dreyfus laravel-queues
php artisan atlas:study laravel-queues --objective="Treinar filas Laravel" --target-level=can_debug_latency --dreyfus-stage=4 --json
php artisan atlas:ai:slo --domain=learning --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Definition Of Done

1. Migration `dreyfus_overlays` aplicada e idempotente
2. `DreyfusOverlayRepository`, `DreyfusEvidenceAggregator`, `DreyfusPedagogyResolver` implementados e testados
3. `PedagogyMatchesStageGate` executavel e ligado ao policy compiler
4. Decision Receipt v2 carrega `cognitive_decision.dreyfus_stage_resolved` quando `input_kind=cognitive`
5. CLI `atlas dreyfus <node>`, `atlas dreyfus all --json`, `atlas dreyfus <node> --dispute`, `atlas dreyfus <node> --resolve-dispute` operacionais
6. `atlas study <topic>` modula output em pelo menos 2 modos (novato e expert) com testes verificando contraste
7. Ledger emite os 4 events declarados em pelo menos 1 fluxo end-to-end
8. SLOs registrados em `KernelSloTargets`; valores observados em `atlas:ai:slo --domain=cognitive --json`
9. `atlas:ai:architecture-validate --json` continua verde
10. `cognitive/multiplier-edge.md` atualizado com `status: implemented` para Capability 1; AP marcado `implemented-operational-read-model`
11. Tests cobrem: 5 niveis x 3 flows, dispute open/resolve, gate block/pass, evidence aggregation com ledger fake
12. Documentacao da capability em `cognitive/multiplier-edge.md` aponta para este AP
