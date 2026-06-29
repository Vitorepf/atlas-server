<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactionPolicyRegistry;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactionRule;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\RedactedEvidence;
use Tests\TestCase;

class AtlasAaelEvidenceRedactorTest extends TestCase
{
    private function redactor(): AtlasAaelEvidenceRedactor
    {
        return new AtlasAaelEvidenceRedactor(new AtlasAaelEvidenceRedactionPolicyRegistry());
    }

    public function test_secrets_are_redacted_and_rule_hits_recorded(): void
    {
        $payload = "leaked key sk-ant-api03-XXXXXXXXXXXXXXXXXX in log; ".
            "path /Users/vitorepf/secret/path; ".
            "header BEARER eyJhbGciOi-XXXXXXXXX";

        $result = $this->redactor()->redact('stdout', $payload);

        self::assertStringNotContainsString('sk-ant-api03-XXXXXXXXXXXXXXXXXX', $result->payload);
        self::assertStringNotContainsString('/Users/vitorepf/secret/path', $result->payload);
        self::assertStringNotContainsString('BEARER eyJhbGciOi-XXXXXXXXX', $result->payload);

        $totalHits = array_sum($result->ruleHits);
        self::assertGreaterThanOrEqual(3, $totalHits, 'at least three rules must have fired');
    }

    public function test_redact_is_deterministic_byte_identical_across_two_calls(): void
    {
        $payload = "leaked sk-ant-api03-AAAAAAAAAAAAAAAAAAA in stdout";
        $a = $this->redactor()->redact('stdout', $payload);
        $b = $this->redactor()->redact('stdout', $payload);

        self::assertSame(serialize($a), serialize($b));
        self::assertSame($a->contentHash, $b->contentHash);
    }

    public function test_redact_recursively_walks_nested_arrays(): void
    {
        $secret = 'sk-ant-api03-NESTED_LEAF_TOKEN_XYZ';
        $payload = [
            'meta' => [
                'session' => [
                    'auth_token' => $secret,
                ],
            ],
            'other' => 'no secret here',
        ];
        $result = $this->redactor()->redact('stdout', $payload);

        self::assertIsArray($result->payload);
        $walker = static function (array $value) use (&$walker, $secret): void {
            foreach ($value as $v) {
                if (is_array($v)) {
                    $walker($v);
                } else {
                    \PHPUnit\Framework\Assert::assertStringNotContainsString($secret, (string) $v);
                }
            }
        };
        $walker($result->payload);
    }

    public function test_non_utf8_binary_payload_is_replaced_with_sentinel(): void
    {
        $binary = "\xFF\xFE\x00\x01\x02"; // invalid UTF-8 prefix
        $result = $this->redactor()->redact('stdout', $binary);

        self::assertSame(AtlasAaelEvidenceRedactor::BINARY_SENTINEL, $result->payload);
        self::assertArrayHasKey('__binary__', $result->ruleHits);
    }

    public function test_content_hash_does_not_leak_the_raw_value(): void
    {
        $payload = 'sk-ant-api03-RAWLEAKVALUE_DO_NOT_INCLUDE';
        $result = $this->redactor()->redact('stdout', $payload);
        self::assertStringNotContainsString($payload, $result->contentHash);
        self::assertSame(64, strlen($result->contentHash));
    }

    public function test_redacted_evidence_value_object_round_trips_via_to_array(): void
    {
        $result = $this->redactor()->redact('stdout', 'plain log line');
        $array = $result->toArray();
        foreach (['content_hash', 'evidence_kind', 'payload', 'rule_hits'] as $field) {
            self::assertArrayHasKey($field, $array);
        }
    }

    public function test_returns_redacted_evidence_instance(): void
    {
        $result = $this->redactor()->redact('stdout', 'noop');
        self::assertInstanceOf(RedactedEvidence::class, $result);
        self::assertSame('stdout', $result->evidenceKind);
    }

    public function test_key_name_rule_redacts_array_valued_subtree(): void
    {
        // A specific (non-*) KIND_KEY_NAME rule matching a key whose value is an array must
        // redact the whole subtree — the secret inside the array must NOT survive unredacted.
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry([
            'custom' => [[
                'kind' => AtlasAaelEvidenceRedactionRule::KIND_KEY_NAME,
                'pattern' => 'secrets',
                'replacement' => '[REDACTED:secrets]',
                'scope' => AtlasAaelEvidenceRedactionRule::SCOPE_MATCH,
            ]],
        ]);
        $redactor = new AtlasAaelEvidenceRedactor($registry);

        $result = $redactor->redact('custom', ['secrets' => ['plain_token_value']]);

        self::assertIsArray($result->payload);
        self::assertSame('[REDACTED:secrets]', $result->payload['secrets'] ?? null);
        $json = (string) json_encode($result->payload);
        self::assertStringNotContainsString('plain_token_value', $json);
    }
}
