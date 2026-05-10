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
            'source_metadata' => [
                'commit_explanation' => 'Explained by vitorepf@example.com with token=metadatasecret123.',
            ],
            'quality_signals' => [
                'commit_explanation' => 'Validated using secret-quality-token123.',
            ],
        ]);

        $this->assertStringContainsString('[redacted_email]', $result['candidate']['title']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['problem_context']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['raw_steps'][0]['action']);
        $this->assertStringContainsString('[redacted_email]', $result['candidate']['source_metadata']['commit_explanation']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['source_metadata']['commit_explanation']);
        $this->assertStringContainsString('[redacted_secret]', $result['candidate']['quality_signals']['commit_explanation']);
        $this->assertGreaterThanOrEqual(1, $result['redaction']['pii_removed']);
        $this->assertGreaterThanOrEqual(4, $result['redaction']['secrets_removed']);
    }
}
