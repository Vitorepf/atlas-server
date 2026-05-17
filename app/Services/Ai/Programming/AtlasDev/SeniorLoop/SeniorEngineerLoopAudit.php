<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\SeniorLoop;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class SeniorEngineerLoopAudit implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.senior_engineer_loop_audit.v1';

    /**
     * @param  array<string,mixed>  $ambiguityResolution
     * @param  list<array<string,mixed>>  $multiStepPlan
     * @param  array<string,mixed>  $debugLoop
     * @param  array<string,mixed>  $architectureReview
     * @param  array<string,mixed>  $desktopCockpit
     * @param  array<string,mixed>  $learningHandoff
     * @param  array<string,bool>  $capabilities
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly array $ambiguityResolution,
        public readonly array $multiStepPlan,
        public readonly array $debugLoop,
        public readonly array $architectureReview,
        public readonly array $desktopCockpit,
        public readonly array $learningHandoff,
        public readonly array $capabilities,
        public readonly array $blockers,
        public readonly string $auditHash = '',
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('SeniorEngineerLoopAudit.run_id must not be empty.');
        }
        if (! in_array($this->status, ['passed', 'blocked'], true)) {
            throw new InvalidArgumentException('SeniorEngineerLoopAudit.status must be passed or blocked.');
        }
        foreach ($this->capabilities as $name => $value) {
            if (! is_string($name) || $name === '' || ! is_bool($value)) {
                throw new InvalidArgumentException('SeniorEngineerLoopAudit.capabilities must be a string=>bool map.');
            }
        }
        foreach ($this->blockers as $i => $blocker) {
            if (! is_string($blocker) || $blocker === '') {
                throw new InvalidArgumentException("SeniorEngineerLoopAudit.blockers[{$i}] must be a non-empty string.");
            }
        }
    }

    public function withHash(): self
    {
        return new self(
            runId: $this->runId,
            status: $this->status,
            ambiguityResolution: $this->ambiguityResolution,
            multiStepPlan: $this->multiStepPlan,
            debugLoop: $this->debugLoop,
            architectureReview: $this->architectureReview,
            desktopCockpit: $this->desktopCockpit,
            learningHandoff: $this->learningHandoff,
            capabilities: $this->capabilities,
            blockers: $this->blockers,
            auditHash: CanonicalHasher::hashWithout($this->toCanonicalArray(), 'audit_hash'),
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'ambiguity_resolution' => $this->ambiguityResolution,
            'architecture_review' => $this->architectureReview,
            'audit_hash' => $this->auditHash,
            'blockers' => array_values($this->blockers),
            'capabilities' => $this->capabilities,
            'debug_loop' => $this->debugLoop,
            'desktop_cockpit' => $this->desktopCockpit,
            'learning_handoff' => $this->learningHandoff,
            'multi_step_plan' => array_values($this->multiStepPlan),
            'provider_safe' => true,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return true;
    }
}
