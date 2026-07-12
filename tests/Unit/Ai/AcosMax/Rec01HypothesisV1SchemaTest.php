<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Governance\Recursion\HypothesisV1;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REC-01 — canonical `hypothesis.v1` schema tests.
 *
 * Every acceptance clause from frontier plan §3039 is exercised mechanically:
 *   - flip without complete hypothesis object ⇒ refused (validate returns errors)
 *   - missing field ⇒ invalid
 *   - alternatives_compared (ELEV-31) missing ⇒ invalid
 *   - falsifier without a machinable condition ⇒ invalid
 *   - rollback without a real handle ⇒ invalid
 *   - complete payload ⇒ valid + canonicalized with SCHEMA_VERSION stamp
 */
final class Rec01HypothesisV1SchemaTest extends TestCase
{
    #[Test]
    public function schema_version_is_pinned_to_the_canonical_string(): void
    {
        $this->assertSame('atlas.acos.hypothesis.v1', HypothesisV1::SCHEMA_VERSION);
    }

    #[Test]
    public function empty_payload_produces_one_error_per_required_field(): void
    {
        $errors = HypothesisV1::validate([]);
        $fields = array_column($errors, 'field');

        foreach (HypothesisV1::REQUIRED_FIELDS as $required) {
            $this->assertContains($required, $fields, "missing field '{$required}' must produce a validation error");
        }
        $this->assertFalse(HypothesisV1::isValid([]));
    }

    #[Test]
    public function all_three_alternatives_are_required_by_elev31(): void
    {
        $payload = $this->completePayload();
        $payload['alternatives_compared'] = [
            'do_nothing' => 'não fazer nada não move a régua M em nenhuma direção',
        ];

        $errors = HypothesisV1::validate($payload);
        $fields = array_column($errors, 'field');

        $this->assertContains('alternatives_compared.simplify_existing', $fields);
        $this->assertContains('alternatives_compared.remove_a_layer', $fields);
        $this->assertNotContains('alternatives_compared.do_nothing', $fields);
    }

    #[Test]
    public function falsifier_without_machinable_condition_is_refused(): void
    {
        $payload = $this->completePayload();
        $payload['falsifier'] = ['metric' => 'M_lift'];

        $errors = HypothesisV1::validate($payload);
        $codes = array_combine(
            array_column($errors, 'field'),
            array_column($errors, 'code'),
        );

        $this->assertArrayHasKey('falsifier.condition', $codes);
    }

    #[Test]
    public function rollback_without_handle_or_reverse_command_is_refused(): void
    {
        $payload = $this->completePayload();
        $payload['rollback_pre_declared'] = ['notes' => 'reverter na mão'];

        $errors = HypothesisV1::validate($payload);
        $fields = array_column($errors, 'field');

        $this->assertContains('rollback_pre_declared', $fields);
    }

    #[Test]
    public function complete_payload_is_valid_and_canonicalize_pins_schema_version(): void
    {
        $payload = $this->completePayload();
        $this->assertTrue(HypothesisV1::isValid($payload), 'complete payload must be accepted');

        $canonical = HypothesisV1::canonicalize($payload);
        $this->assertSame(HypothesisV1::SCHEMA_VERSION, $canonical['schema_version']);
        $this->assertSame('schema_version', array_key_first($canonical));

        foreach (HypothesisV1::REQUIRED_FIELDS as $field) {
            $this->assertArrayHasKey($field, $canonical);
        }
    }

    #[Test]
    public function empty_string_fields_are_refused_as_empty_not_as_missing(): void
    {
        $payload = $this->completePayload();
        $payload['proposed_change'] = '';

        $errors = HypothesisV1::validate($payload);
        $codes = array_combine(
            array_column($errors, 'field'),
            array_column($errors, 'code'),
        );

        $this->assertSame('empty', $codes['proposed_change'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function completePayload(): array
    {
        return [
            'proposed_change' => 'promover feedback_ranking_enabled de shadow para live',
            'causal_mechanism' => 'ranker consome sinal ARFL verified ⇒ recall@5 sobe em queries com histórico',
            'frozen_metric_ref' => 'med-01:golden-v2-frozen@2026-07-01',
            'expected_result' => '+3pp recall@5 na golden v2 sem regressão em outras janelas',
            'falsifier' => [
                'metric' => 'recall_at_5_golden_v2',
                'condition' => '<baseline - 1pp em 2 janelas consecutivas',
            ],
            'treatment_control' => 'A/B 50/50 por session, 14 dias, atribuição limpa por MULTX-09',
            'budget' => ['tokens' => 0, 'wallclock_hours' => 336],
            'rollback_pre_declared' => [
                'handle' => 'rol-01:feedback-ranking-enabled@2026-07-01',
                'reverse_command' => 'php artisan atlas:flag set feedback_ranking_enabled false',
            ],
            'architectural_cost' => 'zero: só flip de flag existente',
            'alternatives_compared' => [
                'do_nothing' => 'baseline continua sem sinal de qualidade — recall@5 estagna',
                'simplify_existing' => 'reduzir feedback_ranking a positive-only: perde sinal de negativos, ganho menor',
                'remove_a_layer' => 'remover concentration_demotion: dominant topics travam o pool — pior',
            ],
        ];
    }
}
