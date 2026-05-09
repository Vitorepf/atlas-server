<?php

namespace Tests\Unit\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Cognitive\PersonalWorkedExample\PersonalWorkedExamplePrivacyRedactor;
use Tests\TestCase;

class PersonalWorkedExamplePrivacyRedactorTest extends TestCase
{
    public function test_redacts_email_and_secret_from_candidate_and_steps(): void
    {
        $redactor = new PersonalWorkedExamplePrivacyRedactor;

        $result = $redactor->redact([
            'title' => 'Fix for vitorepf@example.com',
            'problem_context' => 'Token sk_testsecret123456 leaked in trace.',
            'raw_steps' => [
                ['action' => 'Remove token=abcd1234567890 from config.'],
            ],
        ]);

        $this->assertStringContainsString('[redacted_email]', $result['candidate']['title']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['problem_context']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['raw_steps'][0]['action']);
        $this->assertGreaterThanOrEqual(1, $result['redaction']['pii_removed']);
        $this->assertGreaterThanOrEqual(2, $result['redaction']['secrets_removed']);
    }
}
