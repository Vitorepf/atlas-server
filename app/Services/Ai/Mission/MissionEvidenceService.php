<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionEvidenceRef;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MissionEvidenceService
{
    public const TYPE_DOC = 'doc';

    public const TYPE_COMMAND = 'command';

    public const TYPE_TEST = 'test';

    public const TYPE_ARTIFACT = 'artifact';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_SOURCE = 'source';

    public const TYPE_SCREENSHOT = 'screenshot';

    public const TYPE_DIFF = 'diff';

    public const TYPE_BLOCKER = 'blocker';

    public const TYPE_CERTIFICATION = 'certification';

    public const ALLOWED_TYPES = [
        self::TYPE_DOC,
        self::TYPE_COMMAND,
        self::TYPE_TEST,
        self::TYPE_ARTIFACT,
        self::TYPE_RECEIPT,
        self::TYPE_SOURCE,
        self::TYPE_SCREENSHOT,
        self::TYPE_DIFF,
        self::TYPE_BLOCKER,
        self::TYPE_CERTIFICATION,
    ];

    public function __construct(private readonly MissionLifecycleService $lifecycle) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function attach(AiMission $mission, array $args): AiMissionEvidenceRef
    {
        $type = (string) ($args['evidence_type'] ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw MissionLifecycleException::invalidEvidenceType($type);
        }

        $reference = (string) ($args['evidence_ref'] ?? '');
        if ($reference === '') {
            throw new \InvalidArgumentException('evidence_ref cannot be empty.');
        }

        $hash = $this->hashForReference($reference);

        $evidence = AiMissionEvidenceRef::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $mission->id,
            'work_order_id' => $args['work_order_id'] ?? null,
            'evidence_type' => $type,
            'evidence_ref' => $reference,
            'evidence_hash' => $hash,
            'metadata' => $args['metadata'] ?? null,
        ]);

        $this->lifecycle->recordEvent(
            $mission,
            'evidence.attached',
            (string) ($args['actor_type'] ?? 'system'),
            [
                'evidence_ref_id' => $evidence->id,
                'evidence_type' => $type,
                'evidence_hash' => $hash,
                'work_order_id' => $evidence->work_order_id,
            ],
            receiptHash: $hash,
        );

        return $evidence;
    }

    /**
     * @return Collection<int,AiMissionEvidenceRef>
     */
    public function listFor(AiMission $mission, ?string $type = null, ?string $workOrderId = null): Collection
    {
        $query = $mission->evidenceRefs()->orderBy('created_at');
        if ($type !== null) {
            $query->where('evidence_type', $type);
        }
        if ($workOrderId !== null) {
            $query->where('work_order_id', $workOrderId);
        }

        return $query->get();
    }

    private function hashForReference(string $reference): string
    {
        if (str_starts_with($reference, '/') && File::isFile($reference)) {
            return (string) hash_file('sha256', $reference);
        }

        return MissionCanonicalHash::sha256($reference);
    }
}
