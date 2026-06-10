<?php

declare(strict_types=1);

namespace App\Services\Ai\Patamar4;

use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Atlas Embodiment Integration Service — Patamar 4 · C3 / P7 closure.
 *
 * Closes canon §2.7 "Embodiment unificado: voz + Mac + StackChan +
 * Cartografia = uma só presença operacional". This service is the **bridge
 * surface** that exposes, to any consumer (mobile, desktop, future
 * StackChan firmware), the unified embodiment envelope:
 *
 *   {
 *     locus: { mac, voice, stackchan, cartography },
 *     readiness: { each locus → readiness state },
 *     active_locus: <currently-foreground locus>,
 *     priority_announcement: <next sentence StackChan/Vox should speak>
 *   }
 *
 * The service does NOT itself drive Vox audio, StackChan motors, or
 * Cartografia rendering. It is the canonical PROBE — each subsystem
 * registers its readiness via setter, and consumers read the unified view.
 *
 * Provider-safe. Local-first. Sensitive embodiment (voice, hardware
 * presence) classes never leak: every readiness field is a string status,
 * not raw biometric data.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-embodiment-integration.md
 *
 * Schemas:
 *   - atlas.embodiment.envelope.v1
 *
 * Invariants:
 *   - Four loci canon: mac, voice, stackchan, cartography.
 *   - Readiness canon: ready | partial | building | offline.
 *   - Active locus is reported by `setActiveLocus` (default: mac).
 *   - claim_policy provider-safe.
 *   - JSONL append-only.
 */
class AtlasEmbodimentIntegrationService
{
    public const SCHEMA = 'atlas.embodiment.envelope.v1';

    public const LOCUS_MAC = 'mac';

    public const LOCUS_VOICE = 'voice';

    public const LOCUS_STACKCHAN = 'stackchan';

    public const LOCUS_CARTOGRAPHY = 'cartography';

    public const LOCI = [
        self::LOCUS_MAC,
        self::LOCUS_VOICE,
        self::LOCUS_STACKCHAN,
        self::LOCUS_CARTOGRAPHY,
    ];

    public const READINESS_READY = 'ready';

    public const READINESS_PARTIAL = 'partial';

    public const READINESS_BUILDING = 'building';

    public const READINESS_OFFLINE = 'offline';

    public const VALID_READINESS = [
        self::READINESS_READY,
        self::READINESS_PARTIAL,
        self::READINESS_BUILDING,
        self::READINESS_OFFLINE,
    ];

    private ?string $logPathOverride = null;

    /** @var array<string,string> */
    private array $loci = [
        self::LOCUS_MAC => self::READINESS_READY,             // Mac is always live host.
        self::LOCUS_VOICE => self::READINESS_BUILDING,        // Vox program in onda 0 doutrina.
        self::LOCUS_STACKCHAN => self::READINESS_BUILDING,    // Sub-project — docs only.
        self::LOCUS_CARTOGRAPHY => self::READINESS_PARTIAL,   // Visual + canvas, mount pending.
    ];

    private string $activeLocus = self::LOCUS_MAC;

    private ?string $priorityAnnouncement = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
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
            ? storage_path('atlas/patamar4')
            : sys_get_temp_dir().'/atlas/patamar4';

        return $base.DIRECTORY_SEPARATOR.'embodiment.jsonl';
    }

    public function setLocusReadiness(string $locus, string $readiness): void
    {
        if (! in_array($locus, self::LOCI, true)) {
            throw new \InvalidArgumentException("Unknown locus '{$locus}'.");
        }
        if (! in_array($readiness, self::VALID_READINESS, true)) {
            throw new \InvalidArgumentException("Unknown readiness '{$readiness}'.");
        }
        $this->loci[$locus] = $readiness;
    }

    public function setActiveLocus(string $locus): void
    {
        if (! in_array($locus, self::LOCI, true)) {
            throw new \InvalidArgumentException("Unknown locus '{$locus}'.");
        }
        $this->activeLocus = $locus;
    }

    public function setPriorityAnnouncement(?string $sentence): void
    {
        // Trim length defensively — never let a single announcement explode.
        $this->priorityAnnouncement = $sentence === null ? null : mb_substr($sentence, 0, 280);
    }

    /**
     * Build a snapshot envelope of the unified embodiment surface.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $envelope = [
            'schema_version' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'loci' => $this->loci,
            'active_locus' => $this->activeLocus,
            'priority_announcement' => $this->priorityAnnouncement,
            'all_loci_ready' => $this->allReady(),
            'kernel_hash' => $this->kernel->kernelHash(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['embodiment_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'loci' => $this->loci,
            'active_locus' => $this->activeLocus,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSnapshots(int $tail = 50): array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    public function allReady(): bool
    {
        foreach ($this->loci as $r) {
            if ($r !== self::READINESS_READY) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'local_first_only' => true,
            'sensitive_data_never_leaves_mac' => true,
        ];
    }

    // ---------- internals ----------
}
