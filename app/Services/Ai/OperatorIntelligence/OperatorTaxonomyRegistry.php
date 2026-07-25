<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\OperatorTaxonomyParseSupport;
use Illuminate\Support\Facades\File;

/**
 * Canonical registry of the 170 operator-profile taxonomy items, parsed from the
 * authoring source of truth (docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md)
 * so it can NEVER silently drift from canon — the contract test asserts count==170.
 *
 *   Layer 1  SYS-001..070  — system telemetry, owned by OTHER runtimes (routing, memory,
 *                            telemetry, the capture-quality-gate itself). The chat
 *                            extractor SKIPS these — claiming to "learn" them here would
 *                            be an over-claim; they are already captured upstream.
 *   Layer 2  OP-071..150   — "learns YOU": preferences, taste, risk, decision style.
 *   Layer 3  COL-151..170  — "how to work with you": collaboration patterns.
 *
 * Two override sets harden the dangerous items (the design's adversarial finding):
 *   HIGH_STAKES   — an LLM-inferred signal landing here is force-routed to review +
 *                   refute, never recorded as a settled candidate.
 *   SENSITIVE_DEFAULT — privacy floor raised to 'sensitive' so the claim is redacted at
 *                   rest and never reaches a provider-safe projection.
 */
final class OperatorTaxonomyRegistry
{
    public const DOC_RELATIVE = 'docs/engineering-knowledge-base/atlas-learning-taxonomy-170.md';

    public const LAYER_SYSTEM = 1;
    public const LAYER_OPERATOR = 2;
    public const LAYER_COLLABORATION = 3;

    /** Items where a wrong/inferred mapping is costly — never auto-settle from the LLM. */
    private const HIGH_STAKES = [
        'OP-080', 'OP-084', 'OP-102', 'OP-109', 'OP-112', 'OP-141', 'OP-143', 'OP-144', 'OP-145',
        'OP-085', 'OP-076', 'OP-120',
        'COL-155', 'COL-164', 'COL-169',
    ];

    /** Items whose content is intrinsically sensitive — privacy floor = sensitive. */
    private const SENSITIVE_DEFAULT = [
        'OP-131', 'OP-132', 'OP-135', 'OP-141',
        'COL-167',
    ];

    /** Layer-2/3 items that should populate ONLY from an explicit operator statement. */
    private const EXPLICIT_ONLY = [
        'OP-102', 'OP-109', 'OP-131', 'OP-132', 'OP-141', 'OP-143', 'OP-144', 'OP-145',
        'OP-099', 'OP-106', 'OP-107', 'OP-108',
    ];

    /** Session-volatile items — captured with decaying validity, they churn not accrete. */
    private const MOMENTARY = [
        'COL-151', 'COL-153', 'COL-154', 'OP-081',
    ];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    private ?string $docOverride = null;

    public function setDocPathForTesting(?string $path): void
    {
        $this->docOverride = $path;
        self::$cache = null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        if (self::$cache !== null && $this->docOverride === null) {
            return self::$cache;
        }

        $items = $this->parse();
        if ($this->docOverride === null) {
            self::$cache = $items;
        }

        return $items;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        return $this->all()[strtoupper(trim($id))] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->all()[strtoupper(trim($id))]);
    }

    public function isHighStakes(string $id): bool
    {
        return in_array(strtoupper(trim($id)), self::HIGH_STAKES, true);
    }

    /**
     * Is the canonical taxonomy actually loaded? If the doc is missing/unreadable, parse()
     * yields [] and every id resolves to null — callers that enforce registry-based safety
     * (high-stakes / sensitive) MUST fail closed rather than treat every id as benign.
     */
    public function isAvailable(): bool
    {
        return $this->all() !== [];
    }

    public function isInferable(string $id): bool
    {
        $item = $this->get($id);

        return $item !== null && $item['inferability'] === 'inferable';
    }

    public function privacyFor(string $id): string
    {
        return (string) ($this->get($id)['privacy_default'] ?? 'normal');
    }

    /** @return list<string> */
    public function ids(?int $layer = null): array
    {
        $ids = [];
        foreach ($this->all() as $id => $item) {
            if ($layer === null || $item['layer'] === $layer) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Compact `id — description` menu for the extractor prompt, layers 2+3 by default
     * (the chat extractor never targets layer-1 system telemetry).
     *
     * @param  list<int>  $layers
     */
    public function promptMenu(array $layers = [self::LAYER_OPERATOR, self::LAYER_COLLABORATION]): string
    {
        $lines = [];
        foreach ($this->all() as $id => $item) {
            if (in_array($item['layer'], $layers, true)) {
                $tag = $item['high_stakes'] ? ' [HIGH-STAKES: explicit only]' : '';
                $lines[] = $id.' — '.$item['description'].$tag;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function parse(): array
    {
        $path = $this->docOverride ?? base_path(self::DOC_RELATIVE);
        if (! File::exists($path)) {
            return [];
        }

        return OperatorTaxonomyParseSupport::parseMarkdownTable(
            (string) File::get($path),
            self::HIGH_STAKES,
            self::SENSITIVE_DEFAULT,
            self::EXPLICIT_ONLY,
            self::MOMENTARY,
        );
    }
}
