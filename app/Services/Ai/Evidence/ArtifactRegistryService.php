<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiArtifact;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ArtifactRegistryService
{
    public const TYPE_FILE = 'file';

    public const TYPE_DIFF = 'diff';

    public const TYPE_DATASET = 'dataset';

    public const TYPE_REPORT = 'report';

    public const TYPE_SCREENSHOT = 'screenshot';

    public const TYPE_MODEL_RUN = 'model_run';

    public const TYPE_DASHBOARD = 'dashboard';

    public const TYPE_BRIEFING = 'briefing';

    public const TYPE_CREATIVE = 'creative';

    public const TYPE_CONFIG = 'config';

    public const ALLOWED_TYPES = [
        self::TYPE_FILE,
        self::TYPE_DIFF,
        self::TYPE_DATASET,
        self::TYPE_REPORT,
        self::TYPE_SCREENSHOT,
        self::TYPE_MODEL_RUN,
        self::TYPE_DASHBOARD,
        self::TYPE_BRIEFING,
        self::TYPE_CREATIVE,
        self::TYPE_CONFIG,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function register(array $args): AiArtifact
    {
        $type = (string) ($args['artifact_type'] ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("invalid artifact_type [{$type}]");
        }

        $name = (string) ($args['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('artifact name cannot be empty.');
        }

        $pathOrRef = $args['path_or_ref'] ?? null;
        $contentHash = $args['content_hash'] ?? null;
        if ($contentHash === null && is_string($pathOrRef) && $pathOrRef !== '') {
            $contentHash = $this->hashFor($pathOrRef);
        }

        $artifact = AiArtifact::query()->create([
            'uuid' => (string) Str::uuid(),
            'artifact_type' => $type,
            'name' => $name,
            'path_or_ref' => $pathOrRef,
            'content_hash' => $contentHash,
            'metadata' => $args['metadata'] ?? null,
            'status' => (string) ($args['status'] ?? 'registered'),
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_ARTIFACT_REGISTERED,
            'artifact',
            (string) $artifact->id,
            [
                'artifact_id' => $artifact->id,
                'artifact_type' => $artifact->artifact_type,
                'content_hash' => $artifact->content_hash,
                'name' => $artifact->name,
            ],
            missionId: $artifact->mission_id,
        );

        return $artifact;
    }

    private function hashFor(string $pathOrRef): string
    {
        if (str_starts_with($pathOrRef, '/') && File::isFile($pathOrRef)) {
            return (string) hash_file('sha256', $pathOrRef);
        }

        return EvidenceCanonicalHash::sha256($pathOrRef);
    }
}
