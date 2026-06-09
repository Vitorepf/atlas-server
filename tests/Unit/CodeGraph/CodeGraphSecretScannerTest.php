<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use Tests\TestCase;

/**
 * AP-815 · G-5 — contract for the ingestion-edge secret/PII scanner.
 *
 * Pure (no DB): the scanner is a deterministic text transform. We prove the four
 * invariants the canon requires before external content may enter the graph:
 *   1. every secret family is DETECTED with a 1-based line + a MASKED preview;
 *   2. the preview NEVER echoes the raw secret (first 4 chars + '***');
 *   3. redact() removes every secret ([REDACTED:<type>] present, raw secret absent);
 *   4. clean text is inert — no findings, redact() returns it byte-identical, and
 *      the scanner never throws on empty/malformed/binary input.
 */
class CodeGraphSecretScannerTest extends TestCase
{
    private function scanner(): CodeGraphSecretScanner
    {
        return new CodeGraphSecretScanner;
    }

    /**
     * Happy path: a blob carrying an AWS access key, a private-key block,
     * `password=hunter2`, and an e-mail. Each is detected with a masked preview, and
     * redact() strips every one of them.
     */
    public function test_detects_each_secret_with_masked_preview_and_redacts_all(): void
    {
        $blob = implode("\n", [
            '# infra config',                                   // 1
            'aws_key = AKIAIOSFODNN7EXAMPLE',                    // 2 — AWS access key id
            'password=hunter2',                                 // 3 — generic assignment
            'owner: alice@example.com',                         // 4 — email (PII)
            '-----BEGIN RSA PRIVATE KEY-----',                  // 5 — private key block
            'MIIEpAIBAAKCAQEAfakebodyline',                     // 6
            '-----END RSA PRIVATE KEY-----',                    // 7
        ]);

        $result = $this->scanner()->scan($blob);

        $this->assertTrue($result['has_secrets']);
        $this->assertSame(4, $result['count'], 'AWS key + assignment + email + private key = 4 findings.');

        // Index findings by type for order-independent assertions.
        $byType = [];
        foreach ($result['findings'] as $finding) {
            $byType[$finding['type']] = $finding;
        }

        // --- AWS access key id ---
        $this->assertArrayHasKey('aws_access_key_id', $byType);
        $this->assertSame('high', $byType['aws_access_key_id']['severity']);
        $this->assertSame(2, $byType['aws_access_key_id']['line']);
        $this->assertSame('AKIA***', $byType['aws_access_key_id']['preview']);

        // --- generic password assignment ---
        $this->assertArrayHasKey('generic_secret_assignment', $byType);
        $this->assertSame('high', $byType['generic_secret_assignment']['severity']);
        $this->assertSame(3, $byType['generic_secret_assignment']['line']);
        $this->assertSame('hunt***', $byType['generic_secret_assignment']['preview']);

        // --- email (PII, low) ---
        $this->assertArrayHasKey('email', $byType);
        $this->assertSame('low', $byType['email']['severity']);
        $this->assertSame(4, $byType['email']['line']);
        $this->assertSame('alic***', $byType['email']['preview']);

        // --- private key block (line points at the BEGIN header) ---
        $this->assertArrayHasKey('private_key', $byType);
        $this->assertSame('high', $byType['private_key']['severity']);
        $this->assertSame(5, $byType['private_key']['line']);

        // Previews never leak the raw secret.
        foreach ($result['findings'] as $finding) {
            $this->assertStringEndsWith('***', $finding['preview'], 'Every preview is masked.');
            $this->assertStringNotContainsString('hunter2', $finding['preview']);
            $this->assertStringNotContainsString('EXAMPLE', $finding['preview']);
            $this->assertStringNotContainsString('@example.com', $finding['preview']);
        }

        // --- redact removes every secret, inserts a typed placeholder for each ---
        $redacted = $this->scanner()->redact($blob);

        foreach (['aws_access_key_id', 'generic_secret_assignment', 'email', 'private_key'] as $type) {
            $this->assertStringContainsString("[REDACTED:{$type}]", $redacted, "Placeholder for {$type} present.");
        }
        // Raw secrets are gone — including the multi-line key BODY, not just the header.
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $redacted);
        $this->assertStringNotContainsString('hunter2', $redacted);
        $this->assertStringNotContainsString('alice@example.com', $redacted);
        $this->assertStringNotContainsString('MIIEpAIBAAKCAQEAfakebodyline', $redacted);
        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $redacted);
        // Non-secret structure is preserved.
        $this->assertStringContainsString('# infra config', $redacted);
        $this->assertStringContainsString('owner:', $redacted);
    }

    /**
     * Edge case: clean text is fully inert. No findings, hasSecrets() is false, and
     * redact() returns the input BYTE-IDENTICAL (no spurious rewriting).
     */
    public function test_clean_text_has_no_secrets_and_redact_is_identity(): void
    {
        $clean = "function add(int \$a, int \$b): int {\n    return \$a + \$b; // sum two ints\n}\n";

        $result = $this->scanner()->scan($clean);

        $this->assertFalse($result['has_secrets']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['findings']);
        $this->assertFalse($this->scanner()->hasSecrets($clean));
        $this->assertSame($clean, $this->scanner()->redact($clean), 'Clean text is returned unchanged.');
    }

    /**
     * Edge case: empty input yields a safe, well-formed zeroed shape and never throws.
     */
    public function test_empty_input_returns_safe_zeroed_shape(): void
    {
        $result = $this->scanner()->scan('');

        $this->assertSame(['findings' => [], 'has_secrets' => false, 'count' => 0], $result);
        $this->assertSame('', $this->scanner()->redact(''));
        $this->assertFalse($this->scanner()->hasSecrets(''));
    }

    /**
     * Edge case: covers the remaining high-severity token families (GitHub, Slack,
     * JWT, AWS secret) in one blob, each masked and redacted, with stable line numbers.
     */
    public function test_detects_token_families_github_slack_jwt_aws_secret(): void
    {
        $blob = implode("\n", [
            'gh   = ghp_0123456789abcdefghijklmnopqrstuvWXYZ',                                 // 1
            'sl   = xoxb-123456789012-abcdefghijklmnop',                                       // 2
            'jwt  = eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSJ9.SflKxwRJSMeKKF2QT4fwpMeJf36POk', // 3
            'awss = aws_secret_access_key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',           // 4
        ]);

        $result = $this->scanner()->scan($blob);

        $types = array_column($result['findings'], 'type');
        $this->assertContains('github_token', $types);
        $this->assertContains('slack_token', $types);
        $this->assertContains('jwt', $types);
        $this->assertContains('aws_secret_access_key', $types);

        $byType = [];
        foreach ($result['findings'] as $finding) {
            $byType[$finding['type']] = $finding;
        }
        $this->assertSame('ghp_***', $byType['github_token']['preview']);
        $this->assertSame('xoxb***', $byType['slack_token']['preview']);
        $this->assertSame('eyJh***', $byType['jwt']['preview']);
        $this->assertSame(1, $byType['github_token']['line']);
        $this->assertSame(3, $byType['jwt']['line']);

        // All four are high severity.
        foreach (['github_token', 'slack_token', 'jwt', 'aws_secret_access_key'] as $type) {
            $this->assertSame('high', $byType[$type]['severity'], "{$type} is high severity.");
        }

        $redacted = $this->scanner()->redact($blob);
        $this->assertStringNotContainsString('ghp_0123456789', $redacted);
        $this->assertStringNotContainsString('xoxb-123456789012', $redacted);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $redacted);
        $this->assertStringNotContainsString('wJalrXUtnFEMI', $redacted);
    }

    /**
     * Edge case: the credit-card detector is Luhn-gated, so a Luhn-VALID 16-digit
     * number is flagged (medium) while a same-shaped Luhn-INVALID number is ignored.
     * This proves the validator gate, not just the regex shape.
     */
    public function test_credit_card_requires_luhn_validity(): void
    {
        // 4111 1111 1111 1111 is Luhn-valid; 1234 5678 9012 3456 is not.
        $blob = "valid: 4111 1111 1111 1111\ninvalid: 1234 5678 9012 3456";

        $result = $this->scanner()->scan($blob);

        $cards = array_values(array_filter(
            $result['findings'],
            static fn (array $f): bool => $f['type'] === 'credit_card'
        ));

        $this->assertCount(1, $cards, 'Only the Luhn-valid number is flagged as a card.');
        $this->assertSame('medium', $cards[0]['severity']);
        $this->assertSame(1, $cards[0]['line']);
        $this->assertSame('4111***', $cards[0]['preview']);

        $redacted = $this->scanner()->redact($blob);
        $this->assertStringContainsString('[REDACTED:credit_card]', $redacted);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $redacted);
        // The non-card number is left intact (no false positive).
        $this->assertStringContainsString('1234 5678 9012 3456', $redacted);
    }

    /**
     * Edge case: template / placeholder assignment values are NOT secrets. `${VAR}`,
     * `{{ secret }}`, `changeme`, and `null` must not produce findings — this is the
     * false-positive guard that keeps the admission gate usable.
     */
    public function test_template_and_placeholder_values_are_not_flagged(): void
    {
        $blob = implode("\n", [
            'password=${DB_PASSWORD}',
            'api_key={{ secret }}',
            'secret=changeme',
            'token=null',
            'client_secret=<your-secret-here>',
        ]);

        $result = $this->scanner()->scan($blob);

        $assignments = array_filter(
            $result['findings'],
            static fn (array $f): bool => $f['type'] === 'generic_secret_assignment'
        );
        $this->assertCount(0, $assignments, 'No real-secret assignment in a file of placeholders.');
        $this->assertSame($blob, $this->scanner()->redact($blob), 'Placeholder-only text is unchanged.');
    }

    /**
     * Edge case: a secret value short enough that showing a 4-char prefix would leak
     * most of it is FULLY masked ('***') — the preview never echoes a small secret.
     */
    public function test_short_secret_is_fully_masked(): void
    {
        // Exactly the 3-char minimum value; <= PREVIEW_PREFIX (4) → fully starred.
        $result = $this->scanner()->scan('password=abc');

        $this->assertSame(1, $result['count']);
        $this->assertSame('***', $result['findings'][0]['preview'], 'A short value is fully masked, not prefixed.');
        $this->assertStringNotContainsString('abc', $result['findings'][0]['preview']);
    }

    /**
     * Edge case: malformed / binary-ish / oversized input must never throw and must
     * still find a clearly-embedded secret (the scanner degrades, never crashes).
     */
    public function test_binary_and_oversized_input_is_failsafe(): void
    {
        $weird = str_repeat('x', 4096)."\x00\x01\x02 AKIAIOSFODNN7EXAMPLE \xff\xfe";

        $result = $this->scanner()->scan($weird);

        $this->assertTrue($result['has_secrets'], 'Embedded AWS key found despite binary noise.');
        $this->assertTrue($this->scanner()->hasSecrets($weird));
        $types = array_column($result['findings'], 'type');
        $this->assertContains('aws_access_key_id', $types);

        // redact still strips it and never throws.
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $this->scanner()->redact($weird));
    }

    /**
     * Determinism: identical input yields byte-identical scan() and redact() output.
     */
    public function test_deterministic_output(): void
    {
        $blob = "AKIAIOSFODNN7EXAMPLE\npassword=hunter2\nbob@example.com\nghp_0123456789abcdefghijklmnopqrstuvWXYZ";

        $this->assertSame(
            $this->scanner()->scan($blob),
            $this->scanner()->scan($blob),
            'scan() is deterministic.'
        );
        $this->assertSame(
            $this->scanner()->redact($blob),
            $this->scanner()->redact($blob),
            'redact() is deterministic.'
        );
    }

    /**
     * The SCHEMA constant is the stable contract identifier.
     */
    public function test_schema_constant_is_versioned(): void
    {
        $this->assertSame('atlas.code_graph.secret_scanner.v1', CodeGraphSecretScanner::SCHEMA);
    }
}
