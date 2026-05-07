# AP-169 — Cognitive Personal Worked Examples Generator

Status: scaffold

## Objetivo

Implementar capability cardinal do **Multiplier Edge** que extrai material proprio do operador (PRs, decisoes, Feynman antigos) do Evidence Ledger e transforma em **worked examples personalizados** que alimentam a engine canonica do AP-164.

Eixo Tim Cook / alta performance operacional levado ao extremo: tutor humano nao tem seu codigo; ChatGPT direto nao tem seu historico. Atlas tem ambos e usa como material didatico.

E **Capability 8** do Multiplier Edge (`cognitive/multiplier-edge.md`).

## Pre-requisitos

- AP-164 (Worked Example Engine) implementado — esta capability popula a tabela `worked_examples` com `source=personal_ledger`
- Evidence Ledger maduro (~30-60 dias de uso) — sem historico, nada a extrair
- Programming domain ativo + opcional Strategic Decision domain ativo

## Nao Objetivo

Nao implementar:

- Worked Example Engine (AP-164; esta AP popula a engine)
- Auto-promover worked example pessoal a canonical_library (sempre fica como `source=personal_ledger`)
- Extrair material privado sem redaction (`provider_safety` e hard gate)
- Substituir AP-167 SRL (overlay metacognitivo) ou AP-168 Productive Failure (flow integrado)

## Authority

Em conflito: Tese central > Kernel > `cognitive/multiplier-edge.md` > este AP > `domains/learning.md`.

Status promove para `implemented-operational-read-model` apos DoD.

## Por que so o Atlas faz

| Limitacao concorrente | Por que Atlas resolve |
|---|---|
| Tutor humano nao tem acesso a seu codigo | Atlas tem Programming domain ledger |
| ChatGPT direto esquece toda sessao | Atlas tem Evidence Ledger longitudinal |
| Plataforma de curso (Coursera, etc.) tem material generico | Atlas tem PRs, decisoes, Feynman do Vitor |
| Anki/SuperMemo so tem o que voce digita | Atlas extrai do trabalho real automatizado |

## Fluxo

```
Scheduler periodico (semanal por default)
  -> PersonalWorkedExampleExtractor varre fontes:
       * Programming domain: PRs com explicacao no commit + tests passando
       * Strategic Decision domain: decisoes com outcome conhecido
       * Feynman scores: explicacoes >= 7/10
  -> PersonalWorkedExampleQualityFilter aplica criterios duros
  -> PersonalWorkedExamplePrivacyRedactor remove PII/secrets
  -> WorkedExampleSerializer transforma em estrutura solution_full + fading_levels
  -> Persiste em `worked_examples` com source=personal_ledger
  -> Quality Gates (personal_worked_example_quality, personal_worked_example_privacy_safe)
  -> Evidence Ledger (PERSONAL_WORKED_EXAMPLE_EXTRACTED)
  -> Aparece automaticamente no AP-164 quando AP-164 selector roda com source_preference=personal
```

## Schema

### Migration `2026_05_07_190000_create_worked_example_extractions.php`

```php
Schema::create('worked_example_extractions', function (Blueprint $t) {
    $t->id();
    $t->string('source_type', 32);                // programming_pr | strategic_decision | feynman_session
    $t->string('source_ref', 200);                // PR id / decision id / feynman session id
    $t->json('source_metadata');                  // {domain, project, knowledge_node_ids[], outcome_signal}
    $t->string('extraction_status', 32);          // pending | extracted | discarded_quality | discarded_privacy | discarded_dup
    $t->text('discard_reason')->nullable();
    $t->foreignId('worked_example_id')->nullable()->constrained();  // se extraido com sucesso
    $t->json('quality_signals')->nullable();      // {pr_no_regression, tests_passed, feynman_score, decision_outcome}
    $t->json('redaction_applied')->nullable();    // {pii_removed, secrets_removed, project_names_obfuscated}
    $t->timestamp('processed_at')->nullable();
    $t->timestamps();
    $t->index(['source_type', 'extraction_status']);
    $t->unique(['source_type', 'source_ref']);
});

Schema::create('personal_extraction_jobs', function (Blueprint $t) {
    $t->id();
    $t->string('cadence', 16);                    // daily | weekly | manual
    $t->json('source_filters');                   // {domains: [], min_quality_score: 7, max_age_days: 90}
    $t->timestamp('last_run_at')->nullable();
    $t->timestamp('next_run_at')->nullable();
    $t->json('last_run_summary')->nullable();     // {total_scanned, extracted, discarded}
    $t->boolean('enabled')->default(true);
    $t->timestamps();
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `PersonalWorkedExampleExtractor` | varre fontes do ledger; identifica candidatos | `app/Services/Ai/Cognitive/PersonalWorkedExample/` |
| `ProgrammingPRSourceProvider` | extrai PRs do Programming domain | `app/Services/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `StrategicDecisionSourceProvider` | extrai decisoes com outcome conhecido | `app/Services/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `FeynmanSessionSourceProvider` | extrai sessoes Feynman score >= 7 | `app/Services/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `PersonalWorkedExampleQualityFilter` | aplica criterios duros (PR sem regression, decisao com outcome conhecido, etc.) | `app/Services/Ai/Cognitive/PersonalWorkedExample/` |
| `PersonalWorkedExamplePrivacyRedactor` | remove PII, secrets, nomes de cliente, redaction de privacy class >= 3 | `app/Services/Ai/Cognitive/PersonalWorkedExample/` |
| `WorkedExampleSerializer` | converte source em `solution_full` + `fading_levels` (5 niveis) | `app/Services/Ai/Cognitive/PersonalWorkedExample/` |
| `PersonalExtractionScheduler` | scheduler que dispara extracao periodica | `app/Services/Ai/Cognitive/PersonalWorkedExample/` |
| `PersonalWorkedExampleQualityGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `PersonalWorkedExamplePrivacySafeGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasWorkedExampleExtractCommand` | CLI manual | `app/Console/Commands/` |

## Gates Executaveis

### `personal_worked_example_quality_gate`

```php
final class PersonalWorkedExampleQualityGate {
    public function evaluate(array $candidate): GateResult {
        $signals = $candidate['quality_signals'];
        switch ($candidate['source_type']) {
            case 'programming_pr':
                if (!$signals['tests_passed']) return GateResult::block('quality_pr_tests_failed');
                if (!$signals['no_regression_30d']) return GateResult::block('quality_pr_regression_detected');
                if (empty($candidate['source_metadata']['commit_explanation'])) return GateResult::block('quality_pr_no_explanation');
                break;
            case 'strategic_decision':
                if (!in_array($signals['decision_outcome'], ['success','partial_success'])) return GateResult::block('quality_decision_no_positive_outcome');
                break;
            case 'feynman_session':
                if ($signals['feynman_score'] < 7) return GateResult::block('quality_feynman_below_threshold');
                break;
        }
        return GateResult::pass();
    }
}
```

### `personal_worked_example_privacy_safe`

```php
final class PersonalWorkedExamplePrivacySafeGate {
    public function evaluate(array $serializedExample): GateResult {
        if ($this->piiDetector->detect($serializedExample['solution_full'])) {
            return GateResult::block('privacy_pii_detected_redaction_required');
        }
        if ($this->secretsDetector->detect($serializedExample['solution_full'])) {
            return GateResult::block('privacy_secrets_detected');
        }
        if ($serializedExample['privacy_class'] >= 3 && empty($serializedExample['redaction_applied'])) {
            return GateResult::block('privacy_class_3_requires_redaction');
        }
        return GateResult::pass();
    }
}
```

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `atlas worked-example extract --source=programming_pr [--domain=...]` | varre + extrai sob demanda |
| `atlas worked-example extract status` | ultimo run summary |
| `atlas worked-example extract schedule on/off` | toggle scheduler |
| `atlas worked-example personal --node=<knowledge_node_id>` | lista exemplos pessoais para nó |
| `atlas worked-example extract review --status=discarded_privacy` | inspeciona descartados |

### Tela / Interacao tipica

```
$ atlas worked-example extract --source=programming_pr --domain=programming

Atlas escaneou 47 PRs nos ultimos 90 dias.

Resultados:
  [extracted]            12  (testes ok + sem regressao + commit explicado)
  [discarded_quality]    18  (sem explicacao no commit ou regressao em 30d)
  [discarded_privacy]     5  (PII detectado, requer redaction manual)
  [discarded_duplicate]   8  (similar a exemplo ja existente)
  [pending]               4  (pre-extracao em fila)

Top 3 extraidos:
  1. "Refactor queue worker com lock pessimista" -> nó queue-batching
     fading_levels: [1:5, 2:4, 3:3, 4:2, 5:0]
  2. "Migration zero-downtime para coluna NOT NULL" -> nó migrations.zero-downtime
  3. "Rate limiter por tenant via Redis" -> nó rate-limiting

PERSONAL_WORKED_EXAMPLE_EXTRACTED x12. Inspecione com:
  atlas worked-example show <id>
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `personal_extraction_full_pass_latency_min` | <= 15 (varredura completa) |
| `personal_extraction_per_source_latency_s` | <= 30 |
| `personal_extraction_quality_filter_rate` | >= 0.2 (pelo menos 20% dos candidatos passam quality) |
| `personal_extraction_privacy_violation_rate` | <= 0.001 (<0.1% chega ao consumo com PII) |

Namespace `cognitive.personal_worked_example.*`.

## Evidence Ledger Events

`PERSONAL_WORKED_EXAMPLE_EXTRACTION_STARTED`, `PERSONAL_WORKED_EXAMPLE_EXTRACTED`, `PERSONAL_WORKED_EXAMPLE_DISCARDED_QUALITY`, `PERSONAL_WORKED_EXAMPLE_DISCARDED_PRIVACY`, `PERSONAL_WORKED_EXAMPLE_DISCARDED_DUPLICATE`, `PERSONAL_EXTRACTION_BATCH_COMPLETED`. Taxonomia fechada.

## Tests

| Test | Local |
|---|---|
| `PersonalWorkedExampleExtractorTest` | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/` |
| `ProgrammingPRSourceProviderTest` | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `StrategicDecisionSourceProviderTest` | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `FeynmanSessionSourceProviderTest` | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/Sources/` |
| `PersonalWorkedExampleQualityFilterTest` | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/` |
| `PersonalWorkedExamplePrivacyRedactorTest` (PII + secrets) | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/` |
| `WorkedExampleSerializerTest` (5 niveis fading_levels gerados) | `tests/Unit/Ai/Cognitive/PersonalWorkedExample/` |
| `PersonalWorkedExampleQualityGateTest` (3 source_types) | `tests/Unit/Ai/Kernel/Gates/` |
| `PersonalWorkedExamplePrivacySafeGateTest` | `tests/Unit/Ai/Kernel/Gates/` |
| `PersonalExtractionIntegrationTest` (end-to-end) | `tests/Feature/Ai/Cognitive/` |
| `CognitiveDomainComplianceTest::test_personal_worked_examples_never_leak_pii` | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/PersonalWorkedExample tests/Feature/Ai/Cognitive
php artisan atlas:worked-example extract --source=programming_pr --json
php artisan atlas:worked-example extract status --json
php artisan atlas:ai:architecture-validate --json
```

## Definition Of Done

1. Migrations `worked_example_extractions` + `personal_extraction_jobs` aplicadas e idempotentes
2. Todos os 3 source providers (Programming PR, Strategic Decision, Feynman) implementados e testados
3. Quality Filter + Privacy Redactor + Serializer implementados e testados
4. Ambos os gates executaveis e ligados ao policy compiler
5. Scheduler semanal default registrado no `bootstrap/app.php`
6. CLI `atlas worked-example extract` (run/status/schedule/review/personal) operacional
7. Integracao com AP-164 funciona: WorkedExampleSelector com `source_preference=personal` retorna exemplos extraidos
8. Architecture test garante zero leak de PII (`test_personal_worked_examples_never_leak_pii`) verde
9. Ledger emite todos os 6 events em fluxo end-to-end
10. SLOs registrados em `KernelSloTargets`
11. `atlas:ai:architecture-validate --json` continua verde
12. `cognitive/multiplier-edge.md` marca Capability 8 (Personal Worked Examples Generator) como `implemented`
