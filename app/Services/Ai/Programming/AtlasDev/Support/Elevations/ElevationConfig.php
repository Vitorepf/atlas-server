<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Support\Elevations;

use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;

/**
 * Shared helper that reads a given Atlas Dev verification elevation's
 * (E1-E6) tri-state mode from the `atlas_dev.elevations.*` config block and
 * routes the verdict to the correct sanctioned channel.
 *
 * The two sanctioned verdict channels (no third way, no silent green):
 *
 *   - HARD block  => VerificationGateResult::STATUS_FAILED (gate) OR
 *                    ReviewReceipt::STATUS_ESCALATE (critic).
 *   - ADVISORY    => an honesty flag, which the CompletionStateGate
 *                    auto-downgrades PASSED -> needs_review. Advisory never
 *                    produces STATUS_FAILED / STATUS_ESCALATE for the flag
 *                    alone.
 *   - OFF         => no surfacing at all (byte-identical to pre-mission).
 *
 * The CompletionDecision ctor invariant (status=passed forbids honesty_flags)
 * is the mechanical guarantee that an advisory flag can never coexist with a
 * green completion; this helper does not re-implement that invariant, it
 * relies on it.
 *
 * Construction is split so the helper is testable in two ways:
 *   - {@see self::fromConfig()} reads the live Laravel config kernel (feature
 *     tests / production). Missing/invalid values degrade to the safe default.
 *   - {@see self::for()} accepts the raw config sub-array directly (unit
 *     tests, no Laravel kernel needed).
 *
 * This M0 helper introduces NO new runtime behavior beyond reading flags: no
 * elevation is wired into the pipeline yet (E1-E6 land in their milestones).
 * The helper exists so each future elevation has one canonical, audited entry
 * point for its mode and channel routing.
 */
final class ElevationConfig
{
    private function __construct(
        private readonly string $elevation,
        private readonly ElevationMode $mode,
    ) {}

    /**
     * Read a given elevation's mode from the live config kernel. Missing,
     * null, or invalid blocks/values resolve to the safe default (advisory)
     * without throwing.
     */
    public static function fromConfig(string $elevation): self
    {
        /** @var mixed $block */
        $block = config('atlas_dev.elevations.'.$elevation);

        return self::for($elevation, is_array($block) ? $block : null);
    }

    /**
     * Build the helper from a raw config sub-array (no Laravel kernel needed).
     *
     * @param  ?array{mode?: mixed}  $rawBlock  null = the whole block is missing
     */
    public static function for(string $elevation, ?array $rawBlock): self
    {
        $raw = $rawBlock['mode'] ?? null;

        return new self($elevation, ElevationMode::resolve($raw));
    }

    public function elevation(): string
    {
        return $this->elevation;
    }

    public function mode(): ElevationMode
    {
        return $this->mode;
    }

    public function isOff(): bool
    {
        return $this->mode === ElevationMode::OFF;
    }

    public function isAdvisory(): bool
    {
        return $this->mode === ElevationMode::ADVISORY;
    }

    public function isHard(): bool
    {
        return $this->mode === ElevationMode::HARD;
    }

    // -- Routing helpers ----------------------------------------------------

    /**
     * Whether the elevation should append its honesty flag to the
     * VerificationGateResult.honestyFlags channel. True ONLY in advisory mode:
     * off surfaces nothing; hard blocks (it does not settle for a flag).
     */
    public function shouldAppendHonestyFlag(): bool
    {
        return $this->isAdvisory();
    }

    /**
     * Whether the elevation should block via a sanctioned channel
     * (STATUS_FAILED gate or STATUS_ESCALATE critic). True ONLY in hard mode.
     */
    public function shouldBlock(): bool
    {
        return $this->isHard();
    }

    /**
     * The honesty-flag name this elevation appends in advisory mode.
     * Returns null when the elevation is off or hard (those modes do not use
     * the flag channel). The name is stable and references the elevation so
     * receipts/CompletionDecisions are auditable.
     */
    public function honestyFlagName(): ?string
    {
        if (! $this->isAdvisory()) {
            return null;
        }

        return 'elevation_'.$this->elevation.'_advisory';
    }

    // -- Sanctioned channel targets ----------------------------------------

    /**
     * The aggregate gate status an ADVISORY elevation leaves behind. Advisory
     * never forces STATUS_FAILED for the flag alone: the gate stays at
     * passed/needs_review and the honesty flag drives the
     * CompletionStateGate downgrade (PASSED -> needs_review).
     *
     * This is exposed so future elevations and tests can assert the advisory
     * channel never collapses to a hard block.
     */
    public function advisoryGateStatus(): string
    {
        // Advisory is honesty-flag only; it does not alter the gate status.
        // Callers that already have a gate result keep it; the flag does the
        // downgrading. Returning PASSED here represents the "flag on a green
        // gate" case (the canonical advisory scenario); the
        // CompletionStateGate turns it into needs_review.
        return VerificationGateResult::STATUS_PASSED;
    }

    /**
     * The gate status a HARD elevation targets when tripping via the gate
     * channel. Always STATUS_FAILED.
     */
    public function hardGateStatus(): string
    {
        return VerificationGateResult::STATUS_FAILED;
    }

    /**
     * The critic status a HARD elevation targets when tripping via the critic
     * channel. Always STATUS_ESCALATE.
     */
    public function hardCriticStatus(): string
    {
        return ReviewReceipt::STATUS_ESCALATE;
    }
}
