<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\PersonalWorkedExamplePrivacySafeGate;
use Tests\TestCase;

class PersonalWorkedExamplePrivacySafeGateTest extends TestCase
{
    public function test_blocks_unredacted_pii_or_secret_and_allows_redacted_private_example(): void
    {
        $gate = new PersonalWorkedExamplePrivacySafeGate;

        $this->assertSame('blocked', $gate->evaluate([
            'problem_context' => 'Customer vitorepf@example.com reported a bug.',
            'solution_full' => [],
        ])['status']);

        $this->assertSame('blocked', $gate->evaluate([
            'problem_context' => 'Secret sk_testsecret123456 is visible.',
            'solution_full' => [],
        ])['status']);

        $this->assertSame('passed', $gate->evaluate([
            'problem_context' => 'Customer [redacted_email] reported a bug.',
            'solution_full' => [['action' => 'Rotate [redacted_secret].']],
            'privacy_class' => 3,
            'redaction_applied' => ['pii_removed' => 1, 'secrets_removed' => 1, 'provider_safe' => true],
        ])['status']);
    }
}
