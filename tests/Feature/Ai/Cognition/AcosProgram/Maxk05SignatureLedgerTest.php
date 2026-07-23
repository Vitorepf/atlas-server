<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasAemorMemoryCandidate;
use App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Autonomy\AtlasAutonomousLearningApplier;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderSignatureLedger;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

/**
 * MAXK-05 adversarial suite. The plan says: "assinaturas all-true forjadas
 * bloqueiam uma promoção de fato". This test exercises the REAL mutation
 * point ({@see AtlasAutonomousLearningApplier::decideCandidate}) with a gate
 * carrying booleans as signatures and asserts auto_apply is FALSE with a
 * named reason. A matching receipt in the append-only ledger flips the
 * verdict; a reused nonce is refused.
 */
final class Maxk05SignatureLedgerTest extends TestCase
{
    use CreatesAemorTables;

    /** @var list<string> */
    private array $tmpFiles = [];

    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerPath = sys_get_temp_dir().'/atlas-maxk05-ledger-'.bin2hex(random_bytes(4)).'.ndjson';
        $this->tmpFiles[] = $this->ledgerPath;
        config([
            'atlas.ai.autonomy_ladder.signature_verification_enabled' => true,
            'atlas.ai.autonomy_ladder.signature_ledger_path' => $this->ledgerPath,
        ]);

        $this->createAemorTables();
    }

    protected function tearDown(): void
    {
        $this->dropAemorTables();
        foreach ($this->tmpFiles as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    public function test_forged_all_true_boolean_signatures_block_the_promotion_mutation_with_named_reason(): void
    {
        $candidate = $this->seedCandidateWithForgedBooleans();

        $verdict = $this->applier(new AtlasAutonomyLadderSignatureLedger($this->ledgerPath))
            ->decideCandidate($candidate);

        $this->assertFalse($verdict['auto_apply'], 'Boolean signatures MUST NOT unlock the mutation.');
        $this->assertStringStartsWith('signature_forged_boolean:', $verdict['reason']);
    }

    public function test_receipt_backed_signature_passes_property_gate_but_other_gates_still_apply(): void
    {
        $ledger = new AtlasAutonomyLadderSignatureLedger($this->ledgerPath);
        $candidateId = (string) Str::uuid();

        $ledger->record(
            signature: 'operator',
            actor: 'operator@atlas',
            nonce: 'nonce-alpha',
            policyHash: 'policy-hash-alpha',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: $candidateId,
        );

        $candidate = $this->seedCandidateWithReceipt($candidateId, [
            'operator' => [
                'actor' => 'operator@atlas',
                'nonce' => 'nonce-alpha',
                'policy_hash' => 'policy-hash-alpha',
            ],
        ]);

        $verdict = $this->applier($ledger)->decideCandidate($candidate);

        // The signature gate passes; the reason must NOT be a
        // `signature_*` block anymore. Downstream gates (delta) can still
        // decline — that's fine and named separately.
        $this->assertStringNotContainsString('signature_', $verdict['reason']);
    }

    public function test_nonce_reused_on_a_second_call_is_denied_with_named_reason(): void
    {
        $ledger = new AtlasAutonomyLadderSignatureLedger($this->ledgerPath);
        $candidateId = (string) Str::uuid();

        $ledger->record(
            signature: 'operator',
            actor: 'operator@atlas',
            nonce: 'nonce-single-use',
            policyHash: 'policy-hash-x',
            targetKind: 'atlas_aemor_memory_candidate',
            targetId: $candidateId,
        );

        $candidate = $this->seedCandidateWithReceipt($candidateId, [
            'operator' => [
                'actor' => 'operator@atlas',
                'nonce' => 'nonce-single-use',
                'policy_hash' => 'policy-hash-x',
            ],
        ]);

        $applier = $this->applier($ledger);

        $first = $applier->decideCandidate($candidate);
        $this->assertStringNotContainsString('signature_', $first['reason']);

        // Second decide with the SAME nonce must be refused.
        $second = $applier->decideCandidate($candidate);
        $this->assertFalse($second['auto_apply']);
        $this->assertStringStartsWith('signature_nonce_reused:', $second['reason']);
    }

    public function test_legacy_promotion_gate_without_signatures_key_is_still_accepted(): void
    {
        $candidate = $this->seedCandidateWithReceipt((string) Str::uuid(), null);

        $verdict = $this->applier(new AtlasAutonomyLadderSignatureLedger($this->ledgerPath))
            ->decideCandidate($candidate);

        // No `signatures` key -> gate does not fire, other verdicts pass through.
        $this->assertStringNotContainsString('signature_', $verdict['reason']);
    }

    private function applier(AtlasAutonomyLadderSignatureLedger $ledger): AtlasAutonomousLearningApplier
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernelLog = sys_get_temp_dir().'/atlas-maxk05-kernel-'.bin2hex(random_bytes(4)).'.jsonl';
        $ticketsLog = sys_get_temp_dir().'/atlas-maxk05-tickets-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->tmpFiles[] = $kernelLog;
        $this->tmpFiles[] = $ticketsLog;
        $kernel->setViolationsLogPathForTesting($kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($ticketsLog);

        return new AtlasAutonomousLearningApplier(
            new AtlasLearningProposalsService,
            $admission,
            new AtlasLearningProposalService,
            new AtlasLearningProposalApplier(new AtlasConductorRoutingMemory),
            new AtlasMemoryDeltaPromotionService(new AtlasMemoryRegistryService, new MemoryQueryInput),
            signatureLedger: $ledger,
        );
    }

    private function seedCandidateWithForgedBooleans(): AtlasAemorMemoryCandidate
    {
        return $this->newCandidate([
            'status' => 'pass',
            'blockers' => [],
            'signatures' => [
                'operator' => true,
                'architect' => true,
                'architect_human_review' => true,
            ],
        ]);
    }

    /**
     * @param  array<string,array<string,string>>|null  $signatures
     */
    private function seedCandidateWithReceipt(string $id, ?array $signatures): AtlasAemorMemoryCandidate
    {
        $gate = [
            'status' => 'pass',
            'blockers' => [],
        ];
        if ($signatures !== null) {
            $gate['signatures'] = $signatures;
        }

        return $this->newCandidate($gate, $id);
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function newCandidate(array $gate, ?string $id = null): AtlasAemorMemoryCandidate
    {
        if (! Schema::hasTable('atlas_aemor_memory_candidates')) {
            $this->markTestSkipped('atlas_aemor_memory_candidates table not available');
        }

        $candidate = new AtlasAemorMemoryCandidate;
        $candidate->forceFill([
            'id' => $id ?? (string) Str::uuid(),
            'episode_id' => (string) Str::uuid(),
            'schema_version' => 'atlas.aemor.memory_candidate.v1',
            'status' => 'candidate',
            'memory_type' => 'decision',
            'claim' => 'MAXK-05 adversarial fixture.',
            'confidence' => 0.9,
            'scope_type' => 'global',
            'promotion_gate' => $gate,
            'evidence_refs' => ['evidence://test/1'],
            'metadata' => [],
            'candidate_hash' => hash('sha256', 'maxk05'),
            'memory_delta_id' => null,
        ]);

        return $candidate;
    }
}
