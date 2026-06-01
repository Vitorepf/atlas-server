<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiArchitectureAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Surfaces the Atlas AI Architecture Audit index decider for one sample.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
 */
class AtlasAiArchitectureAuditCommand extends Command
{
    protected $signature = 'atlas:aaeos:architecture-audit-index {--json : Emit canonical JSON}';

    protected $description = 'Audit one architecture sample against the Atlas AI Architecture Audit index: surface ownership, feature-gap promotion and layer integrity.';

    public function handle(AtlasAiArchitectureAuditService $service): int
    {
        try {
            // Safe default models the canonical violation the audit exists to
            // catch: a surface that owns a business flow which is duplicated
            // across surfaces and not yet promoted to a shared owner.
            $payload = $service->audit();

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                return $payload['status'] === 'clean' ? self::SUCCESS : self::FAILURE;
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Architecture Audit</>', (string) $payload['status']);
            $this->components->twoColumnDetail('Core diagnosis', (string) $payload['core_diagnosis']['problem']);
            $this->components->twoColumnDetail('Surface ownership', (string) $payload['surface_ownership']['verdict']);
            $this->components->twoColumnDetail('Feature-gap verdict', (string) $payload['feature_gap']['verdict']);
            $this->components->twoColumnDetail('Copy forbidden', $payload['feature_gap']['copy_forbidden'] ? 'yes' : 'no');
            $this->components->twoColumnDetail('Promotion target', (string) ($payload['feature_gap']['promotion_target'] ?? 'n/a'));
            $this->components->twoColumnDetail('Layers', $payload['layers']['status'].' ('.count((array) $payload['layers']['present']).'/'.$payload['layers']['expected_count'].')');

            foreach ((array) $payload['surface_ownership']['violations'] as $violation) {
                $this->error('[surface] '.$violation);
            }

            return $payload['status'] === 'clean' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasAiArchitectureAuditService::SCHEMA_VERSION,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('[architecture-audit-index] '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
