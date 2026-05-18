<?php

namespace App\Services\Ai\Evidence;

use App\Models\AiSourceRef;
use Illuminate\Support\Str;

class SourceRefService
{
    public const TYPE_URL = 'url';

    public const TYPE_DOC = 'doc';

    public const TYPE_CODE_PATH = 'code_path';

    public const TYPE_DB_QUERY = 'db_query';

    public const TYPE_API_CALL = 'api_call';

    public const TYPE_DATASET = 'dataset';

    public const TYPE_VAULT_NOTE = 'vault_note';

    public const ALLOWED_TYPES = [
        self::TYPE_URL,
        self::TYPE_DOC,
        self::TYPE_CODE_PATH,
        self::TYPE_DB_QUERY,
        self::TYPE_API_CALL,
        self::TYPE_DATASET,
        self::TYPE_VAULT_NOTE,
    ];

    public function __construct(private readonly AuditEventService $auditEvents) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function register(array $args): AiSourceRef
    {
        $type = (string) ($args['source_type'] ?? '');
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("invalid source_type [{$type}]");
        }

        $reference = (string) ($args['source_ref'] ?? '');
        if ($reference === '') {
            throw new \InvalidArgumentException('source_ref cannot be empty.');
        }

        $hash = $args['source_hash'] ?? EvidenceCanonicalHash::sha256($reference);

        $quality = $args['source_quality'] ?? null;
        if ($quality !== null) {
            $quality = (float) $quality;
            if ($quality < 0.0 || $quality > 1.0) {
                throw new \InvalidArgumentException('source_quality must be between 0.0 and 1.0');
            }
        }

        $source = AiSourceRef::query()->create([
            'uuid' => (string) Str::uuid(),
            'source_type' => $type,
            'source_ref' => $reference,
            'source_hash' => $hash,
            'source_quality' => $quality,
            'metadata' => $args['metadata'] ?? null,
            'mission_id' => $args['mission_id'] ?? null,
        ]);

        $this->auditEvents->record(
            AuditEventService::EVENT_SOURCE_REGISTERED,
            'source_ref',
            (string) $source->id,
            [
                'source_id' => $source->id,
                'source_type' => $source->source_type,
                'source_hash' => $source->source_hash,
                'source_quality' => $source->source_quality,
            ],
            missionId: $source->mission_id,
        );

        return $source;
    }
}
