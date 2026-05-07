# AP-164 — Cognitive Worked Example Engine + Process Fading Scheduler

Status: implemented-operational-read-model

## Objetivo

Implementar capability Core que apresenta **solucao completa de processo/decisao** e faz **fading** automatico (vai removendo etapas) conforme `dreyfus_stage` do operador avanca no nó-alvo. Implementa C21 (Maestria operacional vem de Worked Examples + Fading, nao de pratica direta cega).

Eixo operacional: alta performance em construir e otimizar processos (referencia Tim Cook). Cardinal para `learning.process_engineering`, `learning.operational_excellence`, programming, finance, strategic_decision.

E base para o **Personal Worked Examples Generator** (AP-COG-EDGE-08, futuro) — capability cardinal Multiplier Edge que consome esta engine usando material proprio do operador.

## Nao Objetivo

Nao implementar:

- Personal Worked Examples Generator (AP-COG-EDGE-08; consome esta engine)
- Auto-geracao de exemplos por LLM sem fonte canonica ou ledger pessoal
- Substituicao do `learning.deep_work` (este flow e novo, nao substituto)
- Promover exemplo gerado a memoria canonica sem review humano
- Curriculum design (escopo de Curriculum Engine)

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > `cognitive/capabilities-core.md` > este AP > `domains/learning.md`.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Atlas Input (atlas worked-example <topic> | learning.deep_work com stage<=3)
  -> Surface Adapter canoniza
  -> Operation Envelope (input_kind=cognitive)
  -> Domain / Profile / Flow (learning.worked_example)
  -> Context Builder (Knowledge Graph + dreyfus_overlay + worked_examples_repo)
  -> Atlas Decide (provider/modelo modulado por specialist_profile)
  -> Decision Receipt v2 (carrega fading_level resolved)
  -> Runtime: WorkedExampleSelector -> ProcessFadingScheduler -> WorkedExampleRenderer
  -> Quality Gate (worked_example_appropriate_for_stage, worked_example_provider_safety)
  -> Output Renderer (formato muda por nivel)
  -> Evidence Ledger (WORKED_EXAMPLE_DELIVERED)
```

## Schema

### Migration `2026_05_07_140000_create_worked_examples.php`

```php
Schema::create('worked_examples', function (Blueprint $t) {
    $t->id();
    $t->uuid('knowledge_node_id');
    $t->string('domain', 64);
    $t->string('specialist_profile', 64)->nullable();
    $t->string('title', 200);
    $t->text('problem_context');
    $t->json('solution_full');           // [{step:1, action:..., reasoning:..., why_works:...}, ...]
    $t->json('fading_levels');           // {1:[1,2,3,4,5], 2:[1,3,5], 3:[1,5], 4:[], 5:[]} keys=stage
    $t->string('source', 32);            // canonical_library | personal_ledger | operator_authored
    $t->json('author_evidence_refs')->nullable(); // se source=personal: [PROG_TRACE_..., DECISION_..., FEYNMAN_...]
    $t->unsignedInteger('delivered_count')->default(0);
    $t->timestamp('last_delivered_at')->nullable();
    $t->string('status', 32)->default('active'); // draft | active | deprecated
    $t->timestamps();
    $t->index(['knowledge_node_id', 'domain']);
    $t->index('source');
    $t->index('status');
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

### Memory type `worked_example_personal`

Ja documentado em `cognitive/pipeline-overlay.md` secao "Memory types". Schema:

```yaml
worked_example_personal:
  worked_example_id: uuid
  knowledge_node_id: uuid
  fading_level: 1..5
  delivered_at: ISO-8601
  source: canonical_library | personal_ledger | operator_authored
  author_evidence_refs: [...]
```

### Operation Envelope extension

```yaml
input_kind: cognitive
cognitive_payload:
  worked_example_request:
    knowledge_node_id: uuid
    fading_level_target: 1..5 | auto    # auto resolve por dreyfus_overlay
    source_preference: canonical_library | personal_ledger | any
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `WorkedExampleRepository` | CRUD + query por nó+dominio+source | `app/Services/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleSelector` | escolhe melhor exemplo por nó+stage+source_preference | `app/Services/Ai/Cognitive/WorkedExample/` |
| `ProcessFadingScheduler` | calcula `fading_level` a partir de dreyfus_overlay; aplica `fading_levels` map para esconder/mostrar etapas | `app/Services/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleRenderer` | formata output por nivel (novato: completo + raciocinio; competente: 2-3 etapas faltando; proficiente: so problema+solucao final; expert: so problema) | `app/Services/Ai/Cognitive/WorkedExample/` |
| `LearningWorkedExampleFlow` | orchestrator do flow `learning.worked_example` | `app/Services/Ai/Domain/` |
| `WorkedExampleAuthorService` | operador autora exemplo manual (CLI `atlas worked-example author`) | `app/Services/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleAppropriateForStageGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasWorkedExampleCommand` | CLI `atlas:worked-example` para deliver/list/show/author | `app/Console/Commands/` |

## Gates Executaveis

### `worked_example_appropriate_for_stage`

```php
final class WorkedExampleAppropriateForStageGate {
    public function evaluate(Envelope $envelope): GateResult {
        $req = $envelope->cognitive_payload->worked_example_request;
        $overlay = $this->dreyfusRepo->find($req->knowledge_node_id, $envelope->domain);
        $resolvedLevel = $req->fading_level_target === 'auto'
            ? $this->fadingScheduler->resolveFromStage($overlay->current_level)
            : $req->fading_level_target;
        if (!in_range($resolvedLevel, 1, 5)) {
            return GateResult::block('worked_example_invalid_fading_level');
        }
        if ($overlay->current_level === 5 && $resolvedLevel !== 5) {
            return GateResult::block('worked_example_unnecessary_for_master_stage');
        }
        return GateResult::pass(['fading_level_resolved' => $resolvedLevel]);
    }
}
```

### `worked_example_provider_safety`

Bloqueia envio para provider externo se `source=personal_ledger` e `author_evidence_refs` contem refs com privacy class >=3 sem redaction.

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `atlas:worked-example <topic>` | exemplo modulado por dreyfus_stage do nó-raiz |
| `atlas:worked-example list --domain=programming` | tabela: id, title, source, delivered_count |
| `atlas:worked-example author <topic>` | autoria manual operator-authored sem LLM escondido |
| `atlas:worked-example show <id>` | inspeciona exemplo + fading_levels |

### Tela / Interacao tipica (modo competente — fading parcial)

```
$ atlas worked-example pull-request-review

Atlas detected:
  domain        = programming + operations
  specialist    = learning.operational_excellence
  dreyfus_stage = 2 (competente)
  fading_level  = 2 (etapas 2 e 4 ocultas para voce preencher)

Worked Example: "PR review estrutural de migration"
Source: canonical_library

Etapa 1 (visivel):
  Lê o diff completo antes de qualquer comentario.
  Por que: contexto evita feedback fragmentado.

Etapa 2 (OCULTA - voce preenche):
  > _

Etapa 3 (visivel):
  Roda migrations em branch local com dataset de staging.
  Por que: verifica que rollback funciona antes de PR ir longe.

Etapa 4 (OCULTA - voce preenche):
  > _

Etapa 5 (visivel):
  Posta findings agrupados por categoria (security/perf/style),
  nao em ordem de leitura.
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `worked_example_selection_latency_ms` | <= 150 |
| `worked_example_render_latency_ms` | <= 300 |
| `worked_example_appropriate_for_stage_gate_latency_ms` | <= 50 |

Carregados em `KernelSloTargets` namespace `cognitive.worked_example.*`.

## Evidence Ledger Events

`WORKED_EXAMPLE_DELIVERED`, `WORKED_EXAMPLE_FADING_PROGRESSED`, `WORKED_EXAMPLE_AUTHORED`, `WORKED_EXAMPLE_DEPRECATED`. Taxonomia fechada, aditiva.

## Tests

| Test | Local |
|---|---|
| `WorkedExampleRepositoryTest` | `tests/Unit/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleSelectorTest` (escolhe canonical antes de personal por default + override) | `tests/Unit/Ai/Cognitive/WorkedExample/` |
| `ProcessFadingSchedulerTest` (5 stages × fading_levels esperados) | `tests/Unit/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleRendererTest` (formato muda por nivel) | `tests/Unit/Ai/Cognitive/WorkedExample/` |
| `WorkedExampleAppropriateForStageGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `LearningWorkedExampleFlowIntegrationTest` (end-to-end) | `tests/Feature/Ai/Cognitive/` |
| `AtlasWorkedExampleCommandTest` | `tests/Feature/Console/` |
| `CognitiveDomainComplianceTest::test_worked_examples_present_per_active_node_in_technical_domains` | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/WorkedExample tests/Feature/Ai/Cognitive
php artisan atlas:worked-example list --domain=programming --json
php artisan atlas:worked-example pull-request-review --domain=programming --dreyfus-stage=2 --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Definition Of Done

1. Migration `worked_examples` aplicada e idempotente
2. `WorkedExampleRepository`, `WorkedExampleSelector`, `ProcessFadingScheduler`, `WorkedExampleRenderer` implementados e testados
3. `WorkedExampleAppropriateForStageGate` executavel e ligado ao policy compiler
4. Decision Receipt v2 carrega `cognitive_payload.worked_example_request` quando flow=`learning.worked_example`
5. CLI `atlas:worked-example`, `atlas:worked-example list`, `atlas:worked-example author`, `atlas:worked-example show` operacionais
6. `learning.worked_example` flow integrado ao Learning orchestrator; auto-disparo em `learning.deep_work` fica para Curriculum Engine
7. Output muda por fading schedule com testes de contraste de visibilidade por stage
8. Ledger emite `WORKED_EXAMPLE_DELIVERED` em pelo menos 1 fluxo end-to-end
9. SLOs registrados em `KernelSloTargets`
10. `atlas:ai:architecture-validate --json` continua verde
11. `cognitive/capabilities-core.md` atualizado com `status: implemented` para Worked Example Engine
12. AP-COG-EDGE-08 (Personal Worked Examples Generator) pode consumir esta engine sem refactor
