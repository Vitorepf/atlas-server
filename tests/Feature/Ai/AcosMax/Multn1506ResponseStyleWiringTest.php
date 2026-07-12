<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AcosMax;

use App\Models\OperatorProfileFeedbackEvent;
use App\Models\OperatorProfileItem;
use App\Models\OperatorProfilePolicyRule;
use App\Services\Ai\OperatorIntelligence\OperatorContextComposer;
use App\Services\Ai\OperatorIntelligence\OperatorProfilePolicyCompiler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * MULTN15-06 — Estilo operacional persistente como policy rule compilada.
 *
 * O plano exige provar o WIRING existente (produtor: taxonomy COL-* →
 * OperatorProfilePolicyCompiler → effect=response_style; consumidor:
 * OperatorContextComposer → contexto composto real carrega a rule
 * com provider_safe=true) — e a exclusão para itens `private` em
 * `provider_external=true`.
 */
final class Multn1506ResponseStyleWiringTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        config([
            'atlas_operator_intelligence.default_operator_id' => 'vitor',
            'atlas_operator_intelligence.auto_apply_enabled' => false,
            'atlas_operator_intelligence.shadow_mode' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    #[Test]
    public function col_taxonomy_item_compiles_to_response_style_rule_and_composer_serves_it(): void
    {
        $item = OperatorProfileItem::query()->create([
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'COL-156',
            'profile_key' => 'communication.pt_br_reports',
            'value' => ['summary' => 'Responder sempre em PT-BR.'],
            'summary' => 'Responder sempre em PT-BR.',
            'privacy_class' => 'normal',
            'confidence' => 0.95,
            'automation_level' => 'observe',
            'status' => 'active',
        ]);

        app(OperatorProfilePolicyCompiler::class)->compileItem($item);

        $rule = OperatorProfilePolicyRule::query()->where('operator_profile_item_id', $item->id)->firstOrFail();
        $this->assertSame('response_style', $rule->effect);
        $this->assertTrue((bool) ($rule->rule['provider_safe'] ?? false));

        $context = app(OperatorContextComposer::class)->compose([
            'operator_id' => 'vitor',
            'provider_external' => true,
        ]);

        $this->assertSame('atlas.operator_context.v1', $context['schema_version']);
        $this->assertCount(1, $context['items']);
        $composedRule = $context['items'][0]['rule'] ?? [];
        $this->assertSame('response_style', $composedRule['effect'] ?? null);
        $this->assertTrue((bool) ($composedRule['provider_safe'] ?? false));

        $rules = $context['rules'] ?? [];
        $this->assertNotEmpty($rules, 'composed context must expose rules[] carrying response_style');
        $this->assertSame('response_style', $rules[0]['effect'] ?? null);

        $injections = OperatorProfileFeedbackEvent::query()
            ->where('operator_profile_item_id', $item->id)
            ->where('feedback_action', 'context_injected')
            ->count();
        $this->assertSame(1, $injections, 'consumer proof: context_injected must be recorded for the item.');
    }

    #[Test]
    public function private_item_never_appears_in_provider_external_composition(): void
    {
        $private = OperatorProfileItem::query()->create([
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'COL-157',
            'profile_key' => 'communication.internal_shorthand',
            'value' => ['summary' => 'Momento efêmero — nao vazar.'],
            'summary' => 'Momento efêmero — nao vazar.',
            'privacy_class' => 'private',
            'confidence' => 0.9,
            'automation_level' => 'observe',
            'status' => 'active',
        ]);

        app(OperatorProfilePolicyCompiler::class)->compileItem($private);

        $context = app(OperatorContextComposer::class)->compose([
            'operator_id' => 'vitor',
            'provider_external' => true,
            'record_usage' => false,
        ]);

        $this->assertCount(0, $context['items'], 'private items must not be injected under provider_external.');
        $omitted = collect($context['omitted'])->firstWhere('id', $private->id);
        $this->assertNotNull($omitted);
        $this->assertSame('privacy_class_not_allowed', $omitted['reason']);
    }

    #[Test]
    public function response_style_rule_priority_beats_context_hint(): void
    {
        $item = OperatorProfileItem::query()->create([
            'operator_id' => 'vitor',
            'taxonomy_item_id' => 'COL-158',
            'profile_key' => 'communication.terse_status',
            'value' => ['summary' => 'Placar por task no fim.'],
            'summary' => 'Placar por task no fim.',
            'privacy_class' => 'normal',
            'confidence' => 1.0,
            'automation_level' => 'observe',
            'status' => 'active',
        ]);

        $rule = app(OperatorProfilePolicyCompiler::class)->compileItem($item);

        $this->assertGreaterThan(60, $rule->priority);
        $this->assertLessThanOrEqual(100, $rule->priority);
    }
}
