<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSecretScanner;
use Tests\TestCase;

/**
 * AP-815 · G-5 (D5) — MEASUREMENT test: secret-scanner DETECTION RECALL + false-positive
 * rate against a real-FORMAT corpus.
 *
 * This is a *characterization / measurement* test, not a unit test of one branch. The
 * scanner ({@see CodeGraphSecretScanner}) is the oracle: we feed it a corpus of secrets
 * in their real shapes (SYNTHETIC values — no live credential is ever committed) plus a
 * corpus of hard negatives that look secret-ish but are not, and we assert that:
 *
 *   - RECALL on the positives is >= {@see self::RECALL_FLOOR};
 *   - the false-positive RATE on the negatives is <= {@see self::FP_CEILING}.
 *
 * The floors below are the ACTUALLY MEASURED rates (probed before this test was written),
 * NOT aspirational targets. Anti-over-claim: if the scanner regresses, the numbers move
 * and this test fails loudly rather than quietly passing on a faked bar.
 *
 * MEASURED on 2026-06-09 (PHP 8.5.5) against the corpora below, AFTER the AP-815 G-5 D6
 * hardening that closed the three blind spots this corpus used to steer around:
 *   - recall   = 22/22 = 1.0000   (every sample in {@see positives()} is detected)
 *   - fp rate  =  0/18 = 0.0000   (no sample in {@see negatives()} trips it)
 *
 * The floor/ceiling are set CONSERVATIVELY BELOW the measured perfection (0.80 / 0.10)
 * so trivial future corpus tweaks don't make the bar brittle, while still failing hard
 * on any real recall regression. The exact measured rates are additionally asserted in
 * {@see test_measured_rates_match_documented_values} so a silent drift from 1.0/0.0 is
 * caught even while it stays above the conservative floor.
 *
 * CLOSED GAPS (AP-815 G-5 D6). These were the scanner's three documented blind spots; the
 * D6 hardening closed all three WITHOUT raising the false-positive rate, and the corpus
 * below now exercises each closed case directly (see also
 * {@see test_formerly_known_gaps_are_now_detected}). Kept here as the audit trail:
 *   1. A dedicated Stripe rule now flags a BARE `sk_live_…` / `rk_live_…` / `pk_live_…`
 *      key on its own line — previously only caught inside an `api_key=…` assignment.
 *   2. The generic-assignment keyword now matches COMPOUND keys (`DB_PASSWORD=`,
 *      `STRIPE_SECRET=`, `APP_KEY=`) via a bounded `WORD_` prefix — the old bare `\b`
 *      missed them because the `_` before the keyword left no boundary. The `_key` family
 *      is a CURATED prefix list, so ORM/cache identifiers (`foreign_key=`, `cache_key=`)
 *      stay clean.
 *   3. A dedicated connection-string rule flags an inline `user:password@host` credential
 *      even when the host is a bare IP (`…@127.0.0.1/db`) — the e-mail PII shape could
 *      only catch a host with a dotted alpha TLD.
 */
class CodeGraphSecretScannerRecallTest extends TestCase
{
    /**
     * Conservative recall floor. Set BELOW the measured 1.0 so the bar is not brittle,
     * but high enough to fail on any meaningful detection regression. The canon's own
     * threshold for this gate is 0.8; the measured value clears it with room to spare.
     */
    private const RECALL_FLOOR = 0.80;

    /** Conservative false-positive ceiling. Measured 0.0; we allow at most ~1/15. */
    private const FP_CEILING = 0.10;

    private function scanner(): CodeGraphSecretScanner
    {
        return new CodeGraphSecretScanner;
    }

    /**
     * POSITIVES — ~22 secrets in their REAL on-disk shapes, SYNTHETIC values only.
     *
     * Each value is fabricated (no live credential), but the FORMAT is faithful so the
     * regexes are exercised exactly as they would be on a real second-repo ingestion.
     * The last block of each family carries the AP-815 G-5 D6 "naked" cases that used to
     * be blind spots (bare Stripe key, compound assignment key, IP-host connection string).
     *
     * @return array<string,string>
     */
    public static function positives(): array
    {
        return [
            // VCS / cloud provider tokens.
            'github_pat_classic' => 'gh_token: ghp_0123456789abcdefghijklmnopqrstuvWXYZ',
            'github_pat_finegrained' => 'GH_TOKEN=github_pat_11ABCDEFG0aBcDeFgHiJkL_1a2B3c4D5e6F7g8H9i0JkLmNoPqRsTuVwXyZ012345',
            'slack_bot_token' => 'SLACK=xoxb-123456789012-1234567890123-aBcDeFgHiJkLmNoPqRsTuVwX',

            // JWT (header.payload.signature, header is the base64url of '{"' = eyJ).
            'jwt' => 'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NSIsIm5hbWUiOiJBbGljZSJ9.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c',

            // AWS.
            'aws_access_key_id' => 'aws_access_key_id = AKIAIOSFODNN7EXAMPLE',
            'aws_secret_access_key' => 'aws_secret_access_key = wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',

            // Private key blocks (PEM / OpenSSH headers — body is fake & short).
            'gcp_sa_private_key_json' => '{"type":"service_account","private_key":"-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDfakekeybody\n-----END PRIVATE KEY-----\n","client_email":"svc@proj.iam.gserviceaccount.com"}',
            'rsa_private_key' => "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEAfakebodyline1\nfakebodyline2\n-----END RSA PRIVATE KEY-----",
            'openssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAB\n-----END OPENSSH PRIVATE KEY-----",

            // Stripe — AP-815 G-5 D6 added a dedicated rule, so a BARE key on its own line
            // is detected as well as one carried inside a recognized assignment.
            'stripe_sk_live_in_assignment' => 'api_key=sk_live_0123456789abcdefghijABCD',
            'stripe_sk_live_bare' => 'sk_live_51HxXyZAbCdEfGhIjKlMnOpQr',

            // DB connection strings — AP-815 G-5 D6 added a dedicated rule keyed on the
            // inline `user:password@host` shape, so a bare-IP host (no dotted TLD) is now
            // caught directly, not only the hostname form the e-mail PII shape could see.
            'postgres_conn_string' => 'DATABASE_URL=postgres://app_user:S3cr3tDbPass@db.internal:5432/appdb',
            'mysql_conn_string' => 'mysql://root:MyR00tPazz@db.example.com:3306/shop',
            'conn_string_ip_host' => 'mysql://root:MyR00tPazz@127.0.0.1:3306/shop',

            // Generic credential assignments — AP-815 G-5 D6 fixed the keyword boundary so
            // COMPOUND keys (DB_PASSWORD=, STRIPE_SECRET=) fire too, not just standalone.
            'generic_api_key' => 'API_KEY=ak_live_8f3b2c9d1e4a6b7c0d2e5f8a',
            'generic_password' => 'password=hunter2correct',
            'generic_client_secret' => 'client_secret: "0oa1b2c3d4e5F6g7H8i9"',
            'generic_auth_token' => 'auth_token = "abc123def456ghi789"',
            'generic_apikey' => 'apikey="live_a1b2c3d4e5f6g7h8i9j0"',
            'compound_db_password' => 'DB_PASSWORD=S3cr3tDbPass99',
            'compound_stripe_secret' => 'STRIPE_SECRET=sk_live_0123456789abcdefghijABCD',

            // Card number (Luhn-valid → flagged medium).
            'credit_card_visa' => 'card: 4111 1111 1111 1111',
        ];
    }

    /**
     * NEGATIVES — ~18 hard negatives that LOOK secret-ish but must NOT trip the scanner.
     *
     * @return array<string,string>
     */
    public static function negatives(): array
    {
        return [
            'bare_uuid' => 'request_id: 550e8400-e29b-41d4-a716-446655440000',
            'bare_uuid_2' => 'trace = f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'git_sha_40' => 'commit 9f1ffdac8e01ea515fd65978d12830095fad1234',
            'git_sha_short' => 'HEAD is now at b44d5a8f limpeza 01',
            'plain_base64_lorem' => 'data: '.\base64_encode('Lorem ipsum dolor sit amet, consectetur adipiscing elit sed do.'),
            'version_string' => 'version: 1.2.3-beta.4+build.567',
            'semver_constraint' => '"laravel/framework": "^11.0.3"',
            'lockfile_integrity' => '  "integrity": "sha512-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789Qw",',
            'lockfile_file_sha256' => 'resolved e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'iso_timestamp' => 'created_at: 2026-06-09T10:00:05Z',
            'placeholder_env_var' => 'password=${DB_PASSWORD}',
            'placeholder_changeme' => 'secret=changeme',
            'plain_internal_url' => 'homepage: https://internal.docs/getting-started/index',
            'hex_colors' => 'background: #ff00aa; border-color: #112233;',
            'random_long_int_id' => 'order_id = 1234567890123456',

            // Non-secret *_key identifiers — the D6 compound-key fix must NOT flag these.
            // Their prefixes (foreign/cache/primary) are deliberately absent from the
            // curated secret-key list, so the boundary fix gains recall with zero new FP.
            'orm_foreign_key' => 'foreign_key=user_orders',
            'cache_key_identifier' => 'cache_key=home_page_v2',
            'primary_key_identifier' => 'primary_key=uuid_v4_value',
        ];
    }

    /**
     * RECALL: at least {@see self::RECALL_FLOOR} of the positive corpus is detected by
     * hasSecrets(). Any miss is named in the failure message so a regression points at
     * the exact format that broke.
     */
    public function test_detection_recall_meets_floor(): void
    {
        $scanner = $this->scanner();
        $positives = self::positives();

        $missed = [];
        foreach ($positives as $name => $sample) {
            if (! $scanner->hasSecrets($sample)) {
                $missed[] = $name;
            }
        }

        $total = count($positives);
        $detected = $total - count($missed);
        $recall = $detected / $total;

        $this->assertGreaterThanOrEqual(
            self::RECALL_FLOOR,
            $recall,
            sprintf(
                'Recall %.4f (%d/%d) fell below floor %.2f. Missed formats: %s. '
                .'If this is an intentional scope change, lower RECALL_FLOOR to the new measured '
                .'value AND name the newly-missed format in the class docblock (anti-over-claim).',
                $recall,
                $detected,
                $total,
                self::RECALL_FLOOR,
                $missed === [] ? '(none)' : implode(', ', $missed)
            )
        );
    }

    /**
     * FALSE-POSITIVE RATE: at most {@see self::FP_CEILING} of the negative corpus trips
     * the scanner. Any tripped negative is named with the offending finding type(s).
     */
    public function test_false_positive_rate_under_ceiling(): void
    {
        $scanner = $this->scanner();
        $negatives = self::negatives();

        $falsePositives = [];
        foreach ($negatives as $name => $sample) {
            $result = $scanner->scan($sample);
            if ($result['has_secrets']) {
                $falsePositives[$name] = implode(',', array_column($result['findings'], 'type'));
            }
        }

        $total = count($negatives);
        $fpRate = count($falsePositives) / $total;

        $detail = [];
        foreach ($falsePositives as $name => $types) {
            $detail[] = "{$name} -> {$types}";
        }

        $this->assertLessThanOrEqual(
            self::FP_CEILING,
            $fpRate,
            sprintf(
                'False-positive rate %.4f (%d/%d) exceeded ceiling %.2f. Tripped negatives: %s.',
                $fpRate,
                count($falsePositives),
                $total,
                self::FP_CEILING,
                $detail === [] ? '(none)' : implode('; ', $detail)
            )
        );
    }

    /**
     * Anti-over-claim lock: the EXACT measured rates (recall 1.0, fp 0.0 on 2026-06-09).
     * This is stricter than the conservative floor/ceiling above — it fails if the
     * scanner silently drifts off perfection even while staying above the floor, forcing
     * the docblock numbers to be re-measured and updated rather than left stale.
     */
    public function test_measured_rates_match_documented_values(): void
    {
        $scanner = $this->scanner();

        $detected = 0;
        foreach (self::positives() as $sample) {
            if ($scanner->hasSecrets($sample)) {
                $detected++;
            }
        }
        $this->assertSame(
            count(self::positives()),
            $detected,
            'Documented recall is 22/22 = 1.0; a change here means the docblock measurement is stale.'
        );

        $tripped = 0;
        foreach (self::negatives() as $sample) {
            if ($scanner->scan($sample)['has_secrets']) {
                $tripped++;
            }
        }
        $this->assertSame(
            0,
            $tripped,
            'Documented false-positive count is 0/18; a change here means the docblock measurement is stale.'
        );
    }

    /**
     * Spot-check that the corpus exercises the breadth of detection FAMILIES (not just
     * one easy rule firing 18 times). Proves the recall number is earned across token
     * type, private-key block, assignment, and PII-card families.
     */
    public function test_corpus_spans_detection_families(): void
    {
        $scanner = $this->scanner();

        $byCase = static function (string $sample) use ($scanner): array {
            return array_unique(array_column($scanner->scan($sample)['findings'], 'type'));
        };

        $this->assertContains('github_token', $byCase(self::positives()['github_pat_classic']));
        $this->assertContains('slack_token', $byCase(self::positives()['slack_bot_token']));
        $this->assertContains('jwt', $byCase(self::positives()['jwt']));
        $this->assertContains('aws_access_key_id', $byCase(self::positives()['aws_access_key_id']));
        $this->assertContains('aws_secret_access_key', $byCase(self::positives()['aws_secret_access_key']));
        $this->assertContains('private_key', $byCase(self::positives()['rsa_private_key']));
        $this->assertContains('generic_secret_assignment', $byCase(self::positives()['generic_password']));
        $this->assertContains('credit_card', $byCase(self::positives()['credit_card_visa']));
    }

    /**
     * FORMERLY-KNOWN GAPS, now CLOSED (AP-815 G-5 D6). These four cases were asserted as
     * `assertFalse` blind spots in D5 (the scanner was the oracle for its own limits). The
     * D6 hardening closed them; this test now pins the DETECTION as the contract, and also
     * checks each fires the RIGHT dedicated rule so a future regression that silently stops
     * detecting one (or misroutes it) fails loudly. The companion
     * {@see test_false_positive_rate_under_ceiling} proves these gains cost no false
     * positives — and the explicit ORM `_key` negatives below pin that the curated `_key`
     * list does not over-reach.
     */
    public function test_formerly_known_gaps_are_now_detected(): void
    {
        $scanner = $this->scanner();

        $typesOf = static function (string $sample) use ($scanner): array {
            return array_column($scanner->scan($sample)['findings'], 'type');
        };

        // Gap #1 CLOSED: a BARE Stripe key (no surrounding assignment) is detected by the
        // dedicated `stripe_key` rule — across secret/restricted/publishable prefixes.
        $this->assertTrue(
            $scanner->hasSecrets('sk_live_51HxXyZAbCdEfGhIjKlMnOpQr'),
            'CLOSED GAP #1: bare Stripe sk_live_ is now detected.'
        );
        $this->assertContains('stripe_key', $typesOf('sk_live_51HxXyZAbCdEfGhIjKlMnOpQr'));
        $this->assertContains('stripe_key', $typesOf('rk_live_51HxXyZAbCdEfGhIjKlMnOpQr'));
        $this->assertContains('stripe_key', $typesOf('pk_live_51HxXyZAbCdEfGhIjKlMnOpQr'));

        // Gap #2 CLOSED: a COMPOUND assignment key (DB_PASSWORD / STRIPE_SECRET) is detected
        // — the bounded WORD_ prefix restores the boundary the bare \b had lost.
        $this->assertTrue(
            $scanner->hasSecrets('DB_PASSWORD=S3cr3tDbPass99'),
            'CLOSED GAP #2: compound key DB_PASSWORD= is now detected.'
        );
        $this->assertTrue(
            $scanner->hasSecrets('STRIPE_SECRET=sk_live_0123456789abcdefghijABCD'),
            'CLOSED GAP #2: compound key STRIPE_SECRET= is now detected.'
        );
        $this->assertContains('generic_secret_assignment', $typesOf('DB_PASSWORD=S3cr3tDbPass99'));

        // The curated `_key` family: real secret keys fire …
        $this->assertTrue($scanner->hasSecrets('APP_KEY=base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MA=='));
        $this->assertTrue($scanner->hasSecrets('SECRET_KEY=abcdef1234567890ghijkl'));
        // … while non-secret ORM/cache `_key` identifiers do NOT (the false-positive guard
        // that lets G-5 fix the boundary without raising its false-negative-rate twin).
        $this->assertFalse($scanner->hasSecrets('foreign_key=user_orders'));
        $this->assertFalse($scanner->hasSecrets('cache_key=home_page_v2'));

        // Gap #3 CLOSED: a connection string with a bare-IP host is detected by the
        // dedicated `connection_string` rule (no dotted alpha TLD required).
        $this->assertTrue(
            $scanner->hasSecrets('mysql://root:MyR00tPazz@127.0.0.1:3306/shop'),
            'CLOSED GAP #3: IP-host connection string is now detected.'
        );
        $this->assertContains('connection_string', $typesOf('mysql://root:MyR00tPazz@127.0.0.1:3306/shop'));
    }
}
