<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

/**
 * One ordered, declarative redaction rule. Pure value object — never executes redaction; only
 * describes what to redact. Execution lives in the AAEL evidence redactor (packet 02).
 */
final class AtlasAaelEvidenceRedactionRule
{
    public const KIND_REGEX = 'regex';

    public const KIND_JSONPATH = 'jsonpath';

    public const KIND_PATH_PREFIX = 'path_prefix';

    public const KIND_KEY_NAME = 'key_name';

    public const ALLOWED_KINDS = [
        self::KIND_REGEX,
        self::KIND_JSONPATH,
        self::KIND_PATH_PREFIX,
        self::KIND_KEY_NAME,
    ];

    public const SCOPE_FULL = 'full';

    public const SCOPE_MATCH = 'match';

    public function __construct(
        public readonly string $kind,
        public readonly string $pattern,
        public readonly string $replacement,
        public readonly string $scope = self::SCOPE_MATCH,
    ) {
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException('atlas_aael_redaction_rule_unknown_kind:'.$kind);
        }
    }

    /**
     * @return array{kind:string,pattern:string,replacement:string,scope:string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'pattern' => $this->pattern,
            'replacement' => $this->replacement,
            'scope' => $this->scope,
        ];
    }
}
