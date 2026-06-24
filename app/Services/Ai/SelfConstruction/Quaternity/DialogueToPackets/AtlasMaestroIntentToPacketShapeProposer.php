<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * QUATERNITY · INTENT-TO-PACKET-SHAPE PROPOSER — consumes verbatim operator-intent FACTS plus Cortex grounding
 * (real symbols/files in scope) and emits a deterministic candidate packet SKELETON. NEVER auto-enqueues —
 * emission target is a pending shape file under `storage/atlas/maestro/dialogue/proposed/<ulid>.json` that the
 * operator reviews before any enqueue happens.
 *
 * REFUSAL CONDITIONS (fail-closed, never silently soften):
 *   - {@see ProposalRefusal::CODE_EMPTY_INTENT}           : the IntentFactBundle has zero phrases
 *   - {@see ProposalRefusal::CODE_NO_SCOPE_GROUNDING}     : zero overlap between scope tags and Cortex symbols
 *   - {@see ProposalRefusal::CODE_MULTI_SCOPE_AMBIGUOUS}  : intent mixes Loop+Maestro+Cortex (or any two)
 *
 * DETERMINISM: identical IntentFactBundle + identical CortexGroundingSnapshot + identical wave hint MUST
 * produce a byte-identical {@see ProposedPacketShape}. The task_packet_id is a deterministic ULID-shaped
 * slug derived from sha256(canonical inputs) — same input, same id, same JSON.
 *
 * GROUNDING ANCHOR: the emitted objective ALWAYS contains a symbol literal that exists in the supplied Cortex
 * snapshot. No hallucination — if no symbol overlaps the scope tag, the proposer refuses (CODE_NO_SCOPE_GROUNDING).
 */
final class AtlasMaestroIntentToPacketShapeProposer
{
    /** The primitive scope tags the proposer recognises; an intent carrying more than one is multi-scope. */
    public const PRIMITIVE_SCOPES = ['loop', 'cortex', 'maestro'];

    private const PROPOSED_DIR_REL = 'atlas/maestro/dialogue/proposed';

    /**
     * Optional writer callback for tests — production uses an internal file writer that persists the shape
     * under storage/atlas/maestro/dialogue/proposed/<ulid>.json. The proposer NEVER enqueues to the task
     * queue itself; the operator does that explicitly after review.
     *
     * @var null|callable(string $path, string $json):void
     */
    private $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    /**
     * @return ProposedPacketShape|ProposalRefusal
     */
    public function propose(IntentFactBundle $intent, CortexGroundingSnapshot $cortex, string $waveHint): ProposedPacketShape|ProposalRefusal
    {
        if ($intent->phrases === []) {
            return new ProposalRefusal(ProposalRefusal::CODE_EMPTY_INTENT, 'IntentFactBundle.phrases is empty');
        }

        $primitiveScopes = $this->primitiveScopesPresent($intent->scopeTags);
        if (count($primitiveScopes) >= 2) {
            return new ProposalRefusal(
                ProposalRefusal::CODE_MULTI_SCOPE_AMBIGUOUS,
                'Intent tags mix multiple primitive scopes: '.implode(',', $primitiveScopes),
            );
        }

        $anchor = $this->chooseAnchor($intent, $cortex);
        if ($anchor === null) {
            return new ProposalRefusal(
                ProposalRefusal::CODE_NO_SCOPE_GROUNDING,
                'No Cortex symbol overlaps any scope tag in the intent',
            );
        }

        $shape = $this->buildShape($intent, $cortex, $waveHint, $anchor);
        $this->persist($shape);

        return $shape;
    }

    /**
     * @param  list<string>  $scopeTags
     * @return list<string>  the subset of PRIMITIVE_SCOPES actually present in $scopeTags (lowercased, deduped, sorted)
     */
    private function primitiveScopesPresent(array $scopeTags): array
    {
        $normalised = array_values(array_unique(array_map('strtolower', array_map('trim', $scopeTags))));
        $hits = [];
        foreach (self::PRIMITIVE_SCOPES as $primitive) {
            if (in_array($primitive, $normalised, true)) {
                $hits[] = $primitive;
            }
        }
        sort($hits, SORT_STRING);

        return $hits;
    }

    /**
     * Pick the first Cortex symbol whose lowercased short-name contains any scope tag (also lowercased).
     * Deterministic by listing order: same inputs ⇒ same anchor.
     *
     * @return array{symbol:string, file:string}|null
     */
    private function chooseAnchor(IntentFactBundle $intent, CortexGroundingSnapshot $cortex): ?array
    {
        $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $intent->scopeTags))));
        $tags = array_values(array_filter($tags, static fn (string $t): bool => $t !== ''));
        if ($tags === [] || $cortex->symbols === []) {
            return null;
        }

        foreach ($cortex->symbols as $i => $symbol) {
            $short = strtolower($this->shortName((string) $symbol));
            foreach ($tags as $tag) {
                if ($short !== '' && str_contains($short, $tag)) {
                    return [
                        'symbol' => (string) $symbol,
                        'file' => (string) ($cortex->files[$i] ?? ($cortex->files[0] ?? '')),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param  array{symbol:string, file:string}  $anchor
     */
    private function buildShape(IntentFactBundle $intent, CortexGroundingSnapshot $cortex, string $waveHint, array $anchor): ProposedPacketShape
    {
        $canonicalInputs = [
            'intent_phrases' => $intent->phrases,
            'intent_scope_tags' => $intent->scopeTags,
            'intent_timestamps' => $intent->timestamps,
            'intent_verbs' => $intent->verbs,
            'cortex_id' => $cortex->cortexId,
            'cortex_symbols' => $cortex->symbols,
            'cortex_files' => $cortex->files,
            'wave' => $waveHint,
            'anchor_symbol' => $anchor['symbol'],
            'anchor_file' => $anchor['file'],
        ];
        $seed = hash('sha256', (string) json_encode($canonicalInputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $taskPacketId = $this->slugFromAnchor($anchor['symbol']).'-'.substr($seed, 0, 12);

        $verbsList = $intent->verbs === [] ? ['evolve'] : $intent->verbs;
        $verbPhrase = $verbsList[0];

        $objective = sprintf(
            '%s %s (grounded at %s in %s)',
            ucfirst($verbPhrase),
            $this->shortName($anchor['symbol']),
            $anchor['symbol'],
            $anchor['file'] !== '' ? $anchor['file'] : '(no file)',
        );

        $acceptance = [];
        foreach ($verbsList as $v) {
            $acceptance[] = 'verb:'.strtolower(trim($v));
        }
        $acceptance = array_values(array_unique($acceptance));
        sort($acceptance, SORT_STRING);

        $allowedFiles = $anchor['file'] !== '' ? [$anchor['file']] : [];

        return new ProposedPacketShape(
            taskPacketId: $taskPacketId,
            objective: $objective,
            allowedFiles: $allowedFiles,
            scopeIn: $allowedFiles,
            acceptanceCriteria: $acceptance,
            requiredEvidence: 'cortex_id:'.$cortex->cortexId,
            dependsOn: [],
            wave: $waveHint,
            anchorSymbol: $anchor['symbol'],
            anchorFile: $anchor['file'],
        );
    }

    private function persist(ProposedPacketShape $shape): void
    {
        $writer = $this->writer;
        $path = $this->proposedFilePath($shape->taskPacketId);
        if (is_callable($writer)) {
            $writer($path, $shape->canonicalJson());

            return;
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($path, $shape->canonicalJson(), LOCK_EX);
    }

    private function proposedFilePath(string $taskPacketId): string
    {
        $base = sys_get_temp_dir().'/'.self::PROPOSED_DIR_REL;
        if (function_exists('storage_path')) {
            try {
                $base = storage_path(self::PROPOSED_DIR_REL);
            } catch (\Throwable) {
                // container may not be booted in unit tests — fall back to the temp default above
            }
        }

        return rtrim($base, '/').'/'.$taskPacketId.'.json';
    }

    private function slugFromAnchor(string $symbol): string
    {
        $short = strtolower($this->shortName($symbol));
        $short = preg_replace('/[^a-z0-9]+/i', '-', $short) ?? 'anchor';
        $short = trim($short, '-');

        return $short === '' ? 'anchor' : $short;
    }

    private function shortName(string $symbol): string
    {
        $pos = strrpos($symbol, '\\');

        return $pos === false ? $symbol : substr($symbol, $pos + 1);
    }
}
