# AP-165 — Cognitive Process Pattern Catalog

Status: implemented-operational-read-model

## Objetivo

Implementar capability Core que armazena **padroes humanos formais** com estrutura analoga a Design Patterns GoF, aplicados a decisao, processo, comunicacao, otimizacao, recuperacao de falha e arquitetura. Catalogo pessoal + canonico, com `personal_evidence_refs` rastreaveis ao Evidence Ledger.

Implementa C22 (Padroes humanos sao reusaveis como Design Patterns GoF) e operacionaliza o **Latticework de Munger** como artefato auditavel.

E base para o **Process Pattern Personal Detector** (AP-COG-EDGE-10, futuro) — capability cardinal Multiplier Edge que varre o ledger e destila padroes emergentes automaticamente.

## Nao Objetivo

Nao implementar:

- Process Pattern Personal Detector (AP-COG-EDGE-10; consome este catalogo)
- Auto-deteccao de pattern por similaridade de embedding sem confirmacao humana
- Promover candidato a pattern publico sem review
- Substituicao do Knowledge Graph (patterns sao nós cross-domain do grafo, nao tabela paralela)
- Pattern matching como roteador de policy (continua sendo Atlas Decide)

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > `cognitive/capabilities-core.md` > este AP > `domains/learning.md`.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Atlas Input (`php artisan atlas:pattern <name>` | `php artisan atlas:pattern apply <name>` | `php artisan atlas:pattern matcher <problem>`)
  -> Surface Adapter canoniza
  -> Operation Envelope (input_kind=cognitive)
  -> Domain / Profile / Flow (learning.pattern_extraction | learning.process_optimization)
  -> Context Builder (Knowledge Graph + ProcessPatternRepository + ledger refs)
  -> Atlas Decide
  -> Decision Receipt v2
  -> Runtime: ProcessPatternMatcher | ProcessPatternEvidenceTracker
  -> Quality Gate (pattern_structure_complete, pattern_personal_evidence_provider_safe)
  -> Output Renderer
  -> Evidence Ledger (PROCESS_PATTERN_*)
```

## Schema

### Migration `2026_05_07_150000_create_process_patterns.php`

```php
Schema::create('process_patterns', function (Blueprint $t) {
    $t->id();
    $t->string('name', 80)->unique();             // slug: cut-then-rebuild, validate-then-scale
    $t->string('category', 32);                   // decision | process | communication | optimization | failure_recovery | architecture
    $t->string('intent', 200);                    // 1 frase
    $t->text('problem_context');
    $t->json('forces');                           // tensoes competitivas: [{name, description}]
    $t->json('solution');                         // {abstract, steps, applicability}
    $t->json('consequences');                     // {pros, cons, trade_offs}
    $t->json('anti_patterns')->nullable();        // refs por nome a outros patterns ou anti-patterns nomeados
    $t->json('related_patterns')->nullable();     // refs por nome
    $t->json('personal_evidence_refs')->nullable(); // [{ledger_event_id, domain, applied_at, outcome}]
    $t->unsignedInteger('applied_count')->default(0);
    $t->unsignedInteger('success_count')->default(0);
    $t->unsignedInteger('failure_count')->default(0);
    $t->timestamp('last_applied_at')->nullable();
    $t->string('created_via', 32);                // operator | personal_detector | curator_proposal
    $t->string('status', 32)->default('draft');   // draft | active | deprecated
    $t->uuid('knowledge_node_id')->nullable();    // se promovido a nó cross-domain do grafo
    $t->timestamps();
    $t->index('category');
    $t->index('status');
    $t->index('last_applied_at');
});

Schema::create('process_pattern_applications', function (Blueprint $t) {
    $t->id();
    $t->foreignId('process_pattern_id')->constrained();
    $t->uuid('envelope_id');                      // contexto da aplicacao
    $t->string('domain', 64);
    $t->string('outcome', 32);                    // success | partial | failure | abandoned
    $t->json('outcome_evidence')->nullable();     // refs a ledger events que provam outcome
    $t->text('reflection')->nullable();           // operador anota
    $t->timestamp('applied_at');
    $t->index(['process_pattern_id', 'applied_at']);
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

### Memory type `process_pattern`

Ja documentado em `cognitive/pipeline-overlay.md`. Schema canonico:

```yaml
process_pattern:
  name: snake_case_slug
  category: decision | process | communication | optimization | failure_recovery | architecture
  intent: 1 frase
  problem_context: text
  forces: [{name, description}, ...]
  solution: {abstract, steps, applicability}
  consequences: {pros, cons, trade_offs}
  anti_patterns: [pattern_name, ...]
  related_patterns: [pattern_name, ...]
  personal_evidence_refs: [{ledger_event_id, domain, applied_at, outcome}]
  metrics: {applied_count, success_count, failure_count, success_rate}
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `ProcessPatternRepository` | CRUD + query por categoria/status/uso | `app/Services/Ai/Cognitive/Pattern/` |
| `ProcessPatternCatalogService` | leitura agregada com metricas (applied_count, success_rate) | `app/Services/Ai/Cognitive/Pattern/` |
| `ProcessPatternMatcher` | dado problema_description, retorna patterns aplicaveis ranqueados | `app/Services/Ai/Cognitive/Pattern/` |
| `ProcessPatternEvidenceTracker` | registra cada `ProcessPatternApplication`; atualiza counters; emite `PROCESS_PATTERN_APPLIED` | `app/Services/Ai/Cognitive/Pattern/` |
| `ProcessPatternStructureValidator` | valida 10 campos obrigatorios (name, category, intent, problem_context, forces, solution, consequences, anti_patterns, related_patterns, personal_evidence_refs) | `app/Services/Ai/Cognitive/Pattern/` |
| `LearningPatternExtractionFlow` | flow `learning.pattern_extraction` semanal | `app/Services/Ai/Domain/` |
| `LearningProcessOptimizationFlow` | flow `learning.process_optimization` sob demanda | `app/Services/Ai/Domain/` |
| `PatternStructureCompleteGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `PatternPersonalEvidenceProviderSafeGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasPatternCommand` | CLI base `atlas:pattern` | `app/Console/Commands/` |

## Gates Executaveis

### `pattern_structure_complete`

```php
final class PatternStructureCompleteGate {
    private const REQUIRED = ['name','category','intent','problem_context','forces','solution','consequences'];
    public function evaluate(ProcessPattern $p): GateResult {
        foreach (self::REQUIRED as $field) {
            if (empty($p->{$field})) {
                return GateResult::block("pattern_structure_missing_{$field}");
            }
        }
        if (!in_array($p->category, ['decision','process','communication','optimization','failure_recovery','architecture'])) {
            return GateResult::block('pattern_structure_invalid_category');
        }
        return GateResult::pass();
    }
}
```

### `pattern_personal_evidence_provider_safe`

Bloqueia envio de pattern com `personal_evidence_refs` para provider externo se algum ref tem privacy class >=3 sem redaction. Forca redaction antes de pattern entrar em context pack.

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `atlas:pattern catalog [--category=...]` | tabela: name, category, applied_count, success_rate, last_applied_at |
| `atlas:pattern <name>` | spec completa do pattern |
| `atlas:pattern matcher <problem-description>` | retorna top-5 patterns aplicaveis ranqueados |
| `atlas:pattern apply <name>` | registra aplicacao manual com outcome |
| `atlas:pattern author <name>` | autoria manual operator-authored |
| `atlas:pattern propose/reflect` | ficam para AP-COG-EDGE-10 + review UI |

### Tela / Interacao tipica (Pattern Catalog Latticework)

```
$ php artisan atlas:pattern catalog --category=decision

| name                  | category | applied | success_rate | last_applied |
| cut-then-rebuild      | decision | 4       | 0.75         | 2026-04-22   |
| validate-then-scale   | process  | 7       | 0.86         | 2026-05-05   |
| unblock-then-validate | process  | 3       | 1.00         | 2026-05-06   |
| reverse-the-burden    | decision | 2       | 0.50         | 2026-03-11   |
```

```
$ php artisan atlas:pattern matcher "team de 4 ficou bloqueado em decisao de stack ha 2 semanas"

Top-3 patterns aplicaveis:

1. unblock-then-validate (success_rate 1.00, n=3)
   intent: "remover bloqueio com solucao temporaria; validar antes de oficializar"
   por que casa: time bloqueado + decisao reversivel + custo de inacao alto
   evidencia pessoal: aplicado em 2026-04 (PROG_TRACE_...) e 2026-05 (DECISION_...)

2. reverse-the-burden (success_rate 0.50, n=2)
   intent: "transferir onus da decisao a quem questiona"
   por que casa: discussao circular + falta de owner
   evidencia pessoal: aplicado em 2026-03 (DECISION_...)

3. cut-then-rebuild (success_rate 0.75, n=4)
   intent: "abandonar tentativa atual; reiniciar com escopo reduzido"
   por que casa: 2 semanas e sinal de complexidade nao resolvida
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `pattern_catalog_query_latency_ms` | <= 100 |
| `pattern_matcher_latency_ms` | <= 600 |
| `pattern_structure_validation_latency_ms` | <= 50 |

Namespace `cognitive.process_pattern.*`.

## Evidence Ledger Events

`PROCESS_PATTERN_CANDIDATE_DETECTED`, `PROCESS_PATTERN_CATALOGED`, `PROCESS_PATTERN_APPLIED`, `PROCESS_PATTERN_REFLECTION_RECORDED`, `PROCESS_PATTERN_DEPRECATED`. Taxonomia fechada.

## Tests

| Test | Local |
|---|---|
| `ProcessPatternRepositoryTest` | `tests/Unit/Ai/Cognitive/Pattern/` |
| `ProcessPatternStructureValidatorTest` (10 campos validacao) | `tests/Unit/Ai/Cognitive/Pattern/` |
| `ProcessPatternMatcherTest` (ranqueamento por aplicabilidade) | `tests/Unit/Ai/Cognitive/Pattern/` |
| `ProcessPatternEvidenceTrackerTest` (counters atualizam corretamente) | `tests/Unit/Ai/Cognitive/Pattern/` |
| `PatternStructureCompleteGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `LearningPatternExtractionFlowIntegrationTest` | `tests/Feature/Ai/Cognitive/` |
| `AtlasPatternCommandTest` | `tests/Feature/Console/` |
| `CognitiveDomainComplianceTest::test_pattern_catalog_governance_active` | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/Pattern tests/Feature/Ai/Cognitive
php artisan atlas:pattern catalog --json
php artisan atlas:pattern matcher "team bloqueado em decisao de stack" --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Definition Of Done

1. Migrations `process_patterns` + `process_pattern_applications` aplicadas e idempotentes
2. `ProcessPatternRepository`, `CatalogService`, `Matcher`, `EvidenceTracker`, `StructureValidator` implementados e testados
3. `PatternStructureCompleteGate` e `PatternPersonalEvidenceProviderSafeGate` executaveis
4. Flow `learning.pattern_extraction` registrado; agenda semanal fica para AP-COG-EDGE-10 detector
5. Flow `learning.process_optimization` registrado no Learning domain
6. CLI `atlas:pattern catalog`, `atlas:pattern <name>`, `atlas:pattern matcher`, `atlas:pattern apply`, `atlas:pattern author` operacionais
7. Ledger emite `PROCESS_PATTERN_CATALOGED` e `PROCESS_PATTERN_APPLIED` end-to-end
8. SLOs registrados em `KernelSloTargets`
9. `atlas:ai:architecture-validate --json` continua verde
10. Pelo menos 5 patterns canonical_library seedados em migration (cut-then-rebuild, validate-then-scale, unblock-then-validate, reverse-the-burden, fail-fast-cheap)
11. `cognitive/capabilities-core.md` marca Process Pattern Catalog como `implemented-operational-read-model`
12. AP-COG-EDGE-10 (Personal Detector) pode consumir este catalogo sem refactor
