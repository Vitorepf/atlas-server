<?php

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\OperatorLearningClassifier;
use Tests\TestCase;

final class OperatorLearningClassifierTest extends TestCase
{
    public function test_classifier_normalizes_operator_learning_without_persisting_raw_text(): void
    {
        $classified = app(OperatorLearningClassifier::class)->classify([
            'operator_id' => 'vitor',
            'raw_excerpt' => 'Prefiro resposta curta quando eu pedir status.',
            'confidence' => 0.92,
        ]);

        $this->assertSame('vitor', $classified['operator_id']);
        $this->assertSame('COL-156', $classified['taxonomy_item_id']);
        $this->assertSame('normal', $classified['privacy_class']);
        $this->assertSame('low', $classified['risk_level']);
        $this->assertSame(64, strlen((string) $classified['raw_excerpt_hash']));
        $this->assertFalse(data_get($classified, 'metadata.raw_text_persisted'));
    }

    public function test_sensitive_or_secret_claims_are_not_auto_confident(): void
    {
        $classified = app(OperatorLearningClassifier::class)->classify([
            'claim' => 'Minha API key precisa ficar secreta.',
            'confidence' => 0.99,
        ]);

        $this->assertSame('secret', $classified['privacy_class']);
        $this->assertSame('high', $classified['risk_level']);
        $this->assertLessThan(0.75, $classified['confidence']);
    }
}
