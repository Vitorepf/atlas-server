<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainHandoff;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DomainHandoffService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public function __construct(private readonly DomainManifestRegistryService $registry) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function emit(string $sourceDomainId, string $targetDomainId, string $reason, array $attributes = []): AiDomainHandoff
    {
        $source = $this->registry->findByDomainId($sourceDomainId)
            ?? throw DomainRuntimeException::unknownDomain($sourceDomainId);
        $target = $this->registry->findByDomainId($targetDomainId)
            ?? throw DomainRuntimeException::unknownDomain($targetDomainId);

        $sourceRules = (array) ($source->handoff_rules ?? []);
        $allowed = (array) ($sourceRules['allowed'] ?? []);
        $forbidden = (array) ($sourceRules['forbidden'] ?? []);

        if (in_array($targetDomainId, $forbidden, true)) {
            throw DomainRuntimeException::handoffViolatesRules($sourceDomainId, $targetDomainId);
        }
        if ($allowed !== [] && ! in_array($targetDomainId, $allowed, true)) {
            throw DomainRuntimeException::handoffViolatesRules($sourceDomainId, $targetDomainId);
        }

        $contextPack = (array) ($attributes['context_pack'] ?? []);
        if ($contextPack === []) {
            throw DomainRuntimeException::handoffMissingContext();
        }

        $evidenceRefs = (array) ($attributes['evidence_refs'] ?? []);
        $expectedOutput = (array) ($attributes['expected_output'] ?? []);
        if ($expectedOutput === []) {
            throw DomainRuntimeException::manifestMissingField('handoff', 'expected_output');
        }

        $hashInput = [
            'source_domain_id' => $sourceDomainId,
            'target_domain_id' => $targetDomainId,
            'reason' => $reason,
            'context_pack' => $contextPack,
            'evidence_refs' => $evidenceRefs,
            'expected_output' => $expectedOutput,
            'mission_id' => $attributes['mission_id'] ?? null,
            'work_order_id' => $attributes['work_order_id'] ?? null,
        ];
        $receiptHash = MissionCanonicalHash::sha256($hashInput);

        return AiDomainHandoff::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $attributes['mission_id'] ?? null,
            'work_order_id' => $attributes['work_order_id'] ?? null,
            'source_domain_id' => $sourceDomainId,
            'target_domain_id' => $targetDomainId,
            'reason' => $reason,
            'context_pack' => $contextPack,
            'evidence_refs' => $evidenceRefs,
            'expected_output' => $expectedOutput,
            'blockers' => $attributes['blockers'] ?? null,
            'status' => self::STATUS_PENDING,
            'receipt_hash' => $receiptHash,
        ]);
    }

    public function accept(AiDomainHandoff $handoff): AiDomainHandoff
    {
        $handoff->status = self::STATUS_ACCEPTED;
        $handoff->save();

        return $handoff;
    }

    public function complete(AiDomainHandoff $handoff): AiDomainHandoff
    {
        $handoff->status = self::STATUS_COMPLETED;
        $handoff->save();

        return $handoff;
    }
}
