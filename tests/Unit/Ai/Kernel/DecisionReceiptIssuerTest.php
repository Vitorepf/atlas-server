<?php

namespace Tests\Unit\Ai\Kernel;

use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DecisionReceiptIssuerTest extends TestCase
{
    public function test_issuer_creates_receipt_with_model_selection_policy_and_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'corrija esse bug']);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'receipt_id' => 'receipt-1',
            'issued_at' => '2026-05-05T10:00:00Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'risk' => 'medium',
            'provider_selection' => [
                'primary' => 'codex',
                'model' => 'selected-by-decide',
                'fallbacks' => ['claude'],
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'best code-editing fit inside current policy',
            ],
            'required_evidence' => ['diff_summary', 'tests_or_reason'],
            'repair_policy' => ['enabled' => true, 'max_attempts' => 2],
        ]);

        $this->assertSame(DecisionReceipt::SCHEMA_VERSION, $receipt->schemaVersion);
        $this->assertSame($envelope->envelopeId, $receipt->envelopeId);
        $this->assertSame('programming', $receipt->domain);
        $this->assertSame('programming.dev', $receipt->flow);
        $this->assertSame('auto_best_allowed', $receipt->providerSelection['selection_mode']);
        $this->assertSame(['claude'], $receipt->providerSelection['fallbacks']);
        $this->assertFalse($receipt->dryRun);
        $this->assertSame('atlas.decide.v2', $receipt->signedBy);
        $this->assertFalse($receipt->isExpired(CarbonImmutable::parse('2026-05-05T10:00:10Z')));
        $this->assertTrue($receipt->isExpired(CarbonImmutable::parse('2026-05-05T10:00:31Z')));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->inputsHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->receiptHash);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt->chainHash);
    }

    public function test_manual_model_override_is_preserved_as_audited_exception(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'use claude aqui']);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, [
            'provider_selection' => [
                'primary' => 'claude',
                'model' => 'opus',
                'selection_mode' => 'manual_override',
                'manual_override' => [
                    'requested_provider' => 'claude',
                    'requested_model' => 'opus',
                    'accepted' => true,
                    'reason' => 'operator requested a specific engine for this run',
                ],
            ],
        ]);

        $this->assertSame('manual_override', $receipt->providerSelection['selection_mode']);
        $this->assertSame('claude', $receipt->providerSelection['manual_override']['requested_provider']);
        $this->assertSame('opus', $receipt->providerSelection['manual_override']['requested_model']);
    }

    public function test_same_inputs_and_fixed_receipt_metadata_produce_same_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'mesma decisao']);
        $issuer = app(DecisionReceiptIssuer::class);
        $decision = [
            'receipt_id' => 'receipt-fixed',
            'issued_at' => '2026-05-05T10:00:00Z',
            'expires_at' => '2026-05-05T10:00:30Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['selection_mode' => 'auto_best_allowed'],
        ];

        $first = $issuer->issue($envelope, $decision);
        $second = $issuer->issue($envelope, $decision);

        $this->assertSame($first->inputsHash, $second->inputsHash);
        $this->assertSame($first->receiptHash, $second->receiptHash);
        $this->assertSame($first->chainHash, $second->chainHash);
    }

    public function test_nested_decision_payload_order_does_not_change_hashes(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'mesma decisao']);
        $issuer = app(DecisionReceiptIssuer::class);
        $base = [
            'receipt_id' => 'receipt-fixed',
            'issued_at' => '2026-05-05T10:00:00Z',
            'expires_at' => '2026-05-05T10:00:30Z',
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'budgets' => [
                'outer' => ['z' => 1, 'a' => 2],
            ],
        ];

        $first = $issuer->issue($envelope, $base);
        $second = $issuer->issue($envelope, array_merge($base, [
            'budgets' => [
                'outer' => ['a' => 2, 'z' => 1],
            ],
        ]));

        $this->assertSame($first->inputsHash, $second->inputsHash);
        $this->assertSame($first->receiptHash, $second->receiptHash);
    }

    public function test_intermediate_receipt_links_to_parent_with_step_index_and_chained_hash(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'missao com passos']);
        $issuer = app(DecisionReceiptIssuer::class);
        $parent = $issuer->issue($envelope, ['receipt_id' => 'mission-root']);

        $child = $issuer->issueIntermediate($envelope, $parent, 3, [
            'receipt_id' => 'mission-step-3',
            // Adversarial: a caller-supplied linkage must NEVER win over the real parent.
            'parent_receipt_id' => 'spoofed-parent',
            'parent_chain_hash' => 'spoofed-chain',
            'metadata' => ['parent_chain_hash' => 'spoofed-metadata-chain'],
        ]);

        $this->assertSame($parent->receiptId, $child->parentReceiptId);
        $this->assertSame($parent->chainHash, $child->metadata['parent_chain_hash']);
        $this->assertSame(3, $child->metadata['mission_step_index']);
        $this->assertTrue($child->metadata['intermediate']);
        $this->assertNotSame($parent->chainHash, $child->chainHash);
        // Verifiable link, computed by issue()'s existing path (never reimplemented):
        // child.chainHash = hash(parent.chainHash + child.receiptHash).
        $this->assertSame(DecisionReceiptHash::hash([
            'parent_chain_hash' => $parent->chainHash,
            'receipt_hash' => $child->receiptHash,
        ]), $child->chainHash);
    }

    public function test_two_intermediate_steps_chain_sequentially(): void
    {
        // Design choice (matches the DTO): a receipt carries ONE parentReceiptId, so the
        // mission chain is SEQUENTIAL — step1's parent is the mission root and step2's
        // parent is step1 — giving one verifiable hash chain root→step1→step2 instead of
        // a flat star around the root.
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'missao com dois passos']);
        $issuer = app(DecisionReceiptIssuer::class);
        $mission = $issuer->issue($envelope, ['receipt_id' => 'mission-root']);

        $step1 = $issuer->issueIntermediate($envelope, $mission, 0, ['receipt_id' => 'step-0']);
        $step2 = $issuer->issueIntermediate($envelope, $step1, 1, ['receipt_id' => 'step-1']);

        $this->assertSame($mission->receiptId, $step1->parentReceiptId);
        $this->assertSame($step1->receiptId, $step2->parentReceiptId);
        $this->assertSame(0, $step1->metadata['mission_step_index']);
        $this->assertSame(1, $step2->metadata['mission_step_index']);
        // Each link is independently verifiable from its predecessor.
        $this->assertSame(DecisionReceiptHash::hash([
            'parent_chain_hash' => $mission->chainHash,
            'receipt_hash' => $step1->receiptHash,
        ]), $step1->chainHash);
        $this->assertSame(DecisionReceiptHash::hash([
            'parent_chain_hash' => $step1->chainHash,
            'receipt_hash' => $step2->receiptHash,
        ]), $step2->chainHash);
        $this->assertCount(3, array_unique([$mission->chainHash, $step1->chainHash, $step2->chainHash]));
    }

    public function test_issue_without_parent_is_unchanged_by_intermediate_support(): void
    {
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'decisao sem pai']);
        $receipt = app(DecisionReceiptIssuer::class)->issue($envelope, ['receipt_id' => 'standalone']);

        $this->assertNull($receipt->parentReceiptId);
        $this->assertArrayNotHasKey('parent_chain_hash', $receipt->metadata);
        $this->assertArrayNotHasKey('mission_step_index', $receipt->metadata);
        $this->assertArrayNotHasKey('intermediate', $receipt->metadata);
        // The root chain hash is still the unparented link hash(null + receiptHash).
        $this->assertSame(DecisionReceiptHash::hash([
            'parent_chain_hash' => null,
            'receipt_hash' => $receipt->receiptHash,
        ]), $receipt->chainHash);
    }
}
