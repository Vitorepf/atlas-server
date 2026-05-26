<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;

/**
 * Atlas Decide Gateway Consultation Service — Patamar 4 wiring.
 *
 * Thin consultation hook the AiGatewayService calls before each provider
 * resolution to learn whether ADML has an active learned route for the
 * (task_category, role, framework) scope. The gateway remains the
 * authority that actually selects the provider; this service only emits
 * a learned recommendation + Kernel/Admission gates.
 *
 * Doc: scaffold; canonical doc to land alongside gateway integration PR.
 *
 * Invariants:
 *   - never calls a provider;
 *   - returns null when ADML has no actionable signal;
 *   - persists every consultation as append-only ticket;
 *   - claim_policy provider-safe enforced via the Kernel gate.
 *
 * Schemas:
 *   - atlas.atlas_decide.gateway_consultation.v1
 */
final class AtlasDecideGatewayConsultationService
{
    public const ENVELOPE_SCHEMA = 'atlas.atlas_decide.gateway_consultation.v1';

    public const VERDICT_FOLLOW_LEARNED = 'follow_learned_route';

    public const VERDICT_FREE_TO_CHOOSE = 'free_to_choose';

    public const VERDICT_REQUIRES_APPROVAL = 'requires_approval';

    public const VERDICT_BLOCKED = 'blocked';

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasDecideMetaLearningService $adml,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'gateway_consultations.jsonl';
    }

    /**
     * Consult ADML + Kernel + Admission before a provider call.
     *
     * @param  array{task_category:string, role:string, framework?:?string, privacy_class?:string, actor?:string}  $context
     * @return array<string,mixed>
     */
    public function consult(array $context): array
    {
        $taskCategory = (string) ($context['task_category'] ?? '');
        $role = (string) ($context['role'] ?? '');
        $framework = $context['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }
        $privacy = (string) ($context['privacy_class'] ?? 'normal');
        $actor = (string) ($context['actor'] ?? 'ai_gateway');

        $activeRoute = ($taskCategory !== '' && $role !== '')
            ? $this->adml->activeRouteFor($taskCategory, $role, $framework)
            : null;

        // Pétreo gate: any provider routing that touches sensitive/secret/cyber must be reviewed.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'gateway_provider_route',
            'proposed_effect' => sprintf('route provider call for task=%s role=%s framework=%s', $taskCategory, $role, $framework ?? 'null'),
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
        ]);

        // Admission decides if the gateway can autonomously follow the learned route.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'gateway_provider_route',
            'proposed_effect' => 'follow ADML active route',
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
            'requested_autonomy' => 'autonomous',
        ]);

        $verdict = $this->deriveVerdict($activeRoute, $kernelEnv['decision'], $admissionEnv['decision']);

        $envelope = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'consulted_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scope' => [
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework,
                'privacy_class' => $privacy,
            ],
            'verdict' => $verdict,
            'active_route' => $activeRoute,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'kernel_hash' => $kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash(),
        ];
        $envelope['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'verdict' => $verdict,
            'active_route_provider' => $activeRoute['provider'] ?? null,
            'kernel_decision' => $envelope['kernel_decision'],
        ], JSON_THROW_ON_ERROR));

        $this->appendJsonl($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listConsultations(): array
    {
        return $this->readJsonl($this->logPath());
    }

    // ---------- internals ----------

    private function deriveVerdict(?array $activeRoute, string $kernelDecision, string $admissionDecision): string
    {
        if ($kernelDecision === AtlasConstitutionalKernelService::DECISION_BLOCK
            || $admissionDecision === AtlasAutonomyAdmissionService::DECISION_DENY) {
            return self::VERDICT_BLOCKED;
        }
        if ($activeRoute === null || empty($activeRoute['provider'])) {
            return self::VERDICT_FREE_TO_CHOOSE;
        }
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS) {
            return self::VERDICT_FOLLOW_LEARNED;
        }

        return self::VERDICT_REQUIRES_APPROVAL;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
