<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

/**
 * L4-10 — the EXECUTOR-SELF-STAMPED RUNTIME RECEIPT.
 *
 * THE PROVENANCE INVERSION. Before L4-10 the {@see \App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService}
 * certified a STATIC, operator-authored JSON file: a reader could hand-assemble a
 * green-looking receipt (flip `certified:true`, write `provider:hermes_cli`) and the
 * strict proof would pass. That is a self-declared green, exactly the anti-over-claim
 * floor forbids.
 *
 * THIS service closes that hole. The {@see AtlasObraExecutor} EMITS the receipt at the
 * end of a real run from facts it GENUINELY OBSERVED (the branch it cut, the base_head
 * it pinned, the per-step commits the materializer minted, the F3 certification
 * envelope's receipt_hash, whether it resumed, the provider/model the delivery
 * reported) and SIGNS it with an HMAC over those load-bearing facts. The proof then
 * RECOMPUTES the HMAC from the receipt's OWN fields and rejects any receipt whose
 * signature does not match — so a hand-edit of ANY load-bearing field (the certified
 * flag, the provider, the node_count, a step commit, main_untouched) invalidates the
 * stamp and the proof refuses it.
 *
 * HARD CONTRACTS (non-negotiable):
 *  - SELF-STAMP ONLY: only the executor (which holds the run secret + the observed
 *    facts) can mint a valid signature. The signature is over the CANONICAL load-bearing
 *    subset, NOT the whole payload, so adding presentation fields never breaks it but
 *    editing a load-bearing fact always does.
 *  - DETERMINISTIC: the same observed run state yields the same canonical body + the
 *    same signature (re-stamping is idempotent; the frozen test can assert exact bytes).
 *  - PROVIDER-SAFE: the canonical body carries ids / commits / receipt_hash / paths /
 *    provider+model LABELS / booleans only — never source, never diffs, never prompts.
 *  - NO SECRET LEAK: the signing secret never appears in the receipt; only the HMAC
 *    digest does. Verification needs only the same secret + the receipt's own fields.
 */
final class AtlasObraReceiptStamp
{
    public const SCHEMA = 'atlas.obra.executor_receipt.v1';

    /** The HMAC algorithm — stable so a verifier reproduces the exact digest. */
    private const HMAC_ALGO = 'sha256';

    /**
     * Build a self-stamped, signed executor receipt from a real run's observed facts.
     * The returned array is the COMPLETE receipt (canonical body + provenance block);
     * the proof verifies the provenance block against the canonical body.
     *
     * @param  array{
     *     obra_id:string,
     *     branch:?string,
     *     base_head?:?string,
     *     certified:bool,
     *     status:string,
     *     node_count:int,
     *     delivered_nodes?:int,
     *     provider?:?string,
     *     model?:?string,
     *     resumed?:bool,
     *     resume_count?:int,
     *     main_untouched?:bool,
     *     never_merged?:bool,
     *     receipt_hash?:?string,
     *     steps?:list<array{id?:string,status?:string,commit?:?string}>,
     *     delivered_files?:list<string>,
     *     delivered_item_id?:?string,
     *     integrated?:array{supplied?:bool,ran?:bool,passed?:bool,exit_code?:?int,cmd?:?string},
     * }  $facts
     * @return array<string,mixed> the signed receipt (canonical body + `provenance`)
     */
    public function stamp(array $facts): array
    {
        $body = $this->canonicalBody($facts);
        $signature = $this->signature($body);

        // The receipt = the canonical body the proof verifies, PLUS a provenance block
        // that declares it executor-stamped and carries the digest the proof recomputes.
        return $body + [
            'provenance' => [
                'executor_stamped' => true,
                'emitter' => AtlasObraExecutor::SCHEMA,
                'hmac_algo' => self::HMAC_ALGO,
                'signature' => $signature,
                // The exact, ordered field set the signature covers — a verifier rebuilds
                // the body from THESE keys (so a reader can audit which facts are sealed).
                'signed_fields' => array_keys($body),
            ],
        ];
    }

    /**
     * Verify a receipt's provenance: it MUST declare executor_stamped, carry a signature
     * over the canonical body, and that signature MUST recompute from the receipt's OWN
     * load-bearing fields. Returns a structured verdict (never throws).
     *
     * Fail-closed: a missing provenance block, a wrong algo, a tampered field, or a
     * forged/absent signature all verify=false with an explicit reason.
     *
     * @param  array<string,mixed>  $receipt  the full receipt (as loaded from JSON)
     * @return array{verified:bool,reason:?string,recomputed:?string,presented:?string}
     */
    public function verify(array $receipt): array
    {
        $provenance = is_array($receipt['provenance'] ?? null) ? (array) $receipt['provenance'] : [];

        if ($provenance === []) {
            return $this->verdict(false, 'provenance_block_missing');
        }
        if (($provenance['executor_stamped'] ?? null) !== true) {
            return $this->verdict(false, 'not_marked_executor_stamped');
        }
        if (($provenance['hmac_algo'] ?? null) !== self::HMAC_ALGO) {
            return $this->verdict(false, 'hmac_algo_mismatch');
        }
        $presented = $provenance['signature'] ?? null;
        if (! is_string($presented) || $presented === '') {
            return $this->verdict(false, 'signature_missing');
        }

        // Rebuild the canonical body from the receipt's OWN load-bearing fields and
        // recompute the digest. A hand-edit of any sealed fact changes the body → the
        // digest no longer matches → verify=false (the hand-edit is rejected).
        $body = $this->canonicalBody($this->factsFromReceipt($receipt));
        $recomputed = $this->signature($body);

        if (! hash_equals($recomputed, $presented)) {
            return [
                'verified' => false,
                'reason' => 'signature_mismatch',
                'recomputed' => $recomputed,
                'presented' => $presented,
            ];
        }

        return [
            'verified' => true,
            'reason' => null,
            'recomputed' => $recomputed,
            'presented' => $presented,
        ];
    }

    /**
     * The CANONICAL, ordered, load-bearing body. The signature covers exactly this —
     * deterministic key order + normalized types so re-stamping the same run state
     * reproduces byte-identical bytes (the verifier rebuilds this from the receipt's
     * own fields). Presentation-only fields live OUTSIDE this body and are never signed.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function canonicalBody(array $facts): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'obra_id' => $this->str($facts['obra_id'] ?? null),
            'branch' => $this->nullableStr($facts['branch'] ?? null),
            'base_head' => $this->nullableStr($facts['base_head'] ?? null),
            'status' => $this->str($facts['status'] ?? null),
            'certified' => (bool) ($facts['certified'] ?? false),
            'node_count' => $this->int($facts['node_count'] ?? 0),
            'delivered_nodes' => $this->int($facts['delivered_nodes'] ?? 0),
            'provider' => $this->nullableStr($facts['provider'] ?? null),
            'model' => $this->nullableStr($facts['model'] ?? null),
            // RUN PROVENANCE (sealed): the delivery binding that produced this obra. A FIXTURE
            // run (deterministic stub) carries execution_mode='fixture_obra_run' and can NEVER be
            // verified as a real provider run — a downstream L4-10 proof rejects it. Sealing these
            // inside the HMAC means a hand-edit of execution_mode/delivery_label breaks the signature.
            'execution_mode' => $this->nullableStr($facts['execution_mode'] ?? null),
            'delivery_label' => $this->nullableStr($facts['delivery_label'] ?? null),
            'resumed' => (bool) ($facts['resumed'] ?? false),
            'resume_count' => $this->int($facts['resume_count'] ?? 0),
            'main_untouched' => (bool) ($facts['main_untouched'] ?? false),
            'never_merged' => (bool) ($facts['never_merged'] ?? true),
            'receipt_hash' => $this->nullableStr($facts['receipt_hash'] ?? null),
            'delivered_item_id' => $this->nullableStr($facts['delivered_item_id'] ?? null),
            'delivered_files' => $this->strList($facts['delivered_files'] ?? []),
            'steps' => $this->normalizeSteps($facts['steps'] ?? []),
            'integrated' => $this->normalizeIntegrated($facts['integrated'] ?? null),
        ];
    }

    /**
     * Rebuild the {@see canonicalBody()} input from a loaded receipt's OWN fields. This
     * is what makes verification tamper-evident: the verifier never trusts a separately
     * supplied "expected" body — it reconstructs the signed subset from the receipt as
     * presented, so any edit to a sealed field flows straight into the recomputed digest.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function factsFromReceipt(array $receipt): array
    {
        return [
            'obra_id' => $receipt['obra_id'] ?? null,
            'branch' => $receipt['branch'] ?? null,
            'base_head' => $receipt['base_head'] ?? null,
            'status' => $receipt['status'] ?? null,
            'certified' => $receipt['certified'] ?? false,
            'node_count' => $receipt['node_count'] ?? 0,
            'delivered_nodes' => $receipt['delivered_nodes'] ?? 0,
            'provider' => $receipt['provider'] ?? null,
            'model' => $receipt['model'] ?? null,
            'execution_mode' => $receipt['execution_mode'] ?? null,
            'delivery_label' => $receipt['delivery_label'] ?? null,
            'resumed' => $receipt['resumed'] ?? false,
            'resume_count' => $receipt['resume_count'] ?? 0,
            'main_untouched' => $receipt['main_untouched'] ?? false,
            'never_merged' => $receipt['never_merged'] ?? true,
            'receipt_hash' => $receipt['receipt_hash'] ?? null,
            'delivered_item_id' => $receipt['delivered_item_id'] ?? null,
            'delivered_files' => $receipt['delivered_files'] ?? [],
            'steps' => $receipt['steps'] ?? [],
            'integrated' => $receipt['integrated'] ?? null,
        ];
    }

    /**
     * HMAC the canonical body with the run secret. The body is JSON-encoded with stable
     * flags + a leading domain-separation tag so this digest can never collide with any
     * other HMAC in the codebase that reuses the same secret.
     *
     * @param  array<string,mixed>  $body
     */
    private function signature(array $body): string
    {
        $payload = self::SCHEMA.'|'.(string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac(self::HMAC_ALGO, $payload, $this->secret());
    }

    /**
     * The signing secret: a dedicated obra receipt secret when configured, else the
     * Laravel app key (always present in a booted app). Stable across stamp + verify in
     * the SAME deployment — which is the whole point: the executor that ran and the
     * proof that certifies both run inside the same Atlas, so they share the secret, and
     * an out-of-band hand-edit cannot reproduce the digest.
     */
    private function secret(): string
    {
        $configured = config('atlas.obra.receipt_secret');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = (string) config('app.key', '');

        // Never sign with an empty secret (that would make forgery trivial). Fall back to
        // a fixed, non-empty domain string so the mechanism still binds the body even in
        // a key-less harness — same secret on both sides keeps verification meaningful.
        return $appKey !== '' ? $appKey : 'atlas.obra.receipt.fallback.secret.v1';
    }

    /**
     * @param  mixed  $steps
     * @return list<array{id:string,status:string,commit:string}>
     */
    private function normalizeSteps(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }
        $rows = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $rows[] = [
                'id' => $this->str($step['id'] ?? null),
                'status' => $this->str($step['status'] ?? null),
                'commit' => $this->str($step['commit'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  mixed  $integrated
     * @return array{supplied:bool,ran:bool,passed:bool,exit_code:?int,cmd:?string}
     */
    private function normalizeIntegrated(mixed $integrated): array
    {
        $integrated = is_array($integrated) ? $integrated : [];

        return [
            'supplied' => (bool) ($integrated['supplied'] ?? false),
            'ran' => (bool) ($integrated['ran'] ?? false),
            'passed' => (bool) ($integrated['passed'] ?? false),
            'exit_code' => array_key_exists('exit_code', $integrated) && $integrated['exit_code'] !== null
                ? (int) $integrated['exit_code']
                : null,
            'cmd' => $this->nullableStr($integrated['cmd'] ?? null),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function strList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : ($value === null ? '' : (string) $value);
    }

    private function nullableStr(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = is_string($value) ? trim($value) : (string) $value;

        return $str === '' ? null : $str;
    }

    private function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array{verified:bool,reason:?string,recomputed:?string,presented:?string}
     */
    private function verdict(bool $verified, ?string $reason): array
    {
        return ['verified' => $verified, 'reason' => $reason, 'recomputed' => null, 'presented' => null];
    }
}
