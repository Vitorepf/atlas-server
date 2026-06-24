<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council;

/**
 * CORTEX COUNCIL — LENS #5 of 5: DOC-INTENT. Observes a {@see CortexSubject} (PHP source) and emits FACTS
 * about declared intent: class-level docblock summary, per-method `@purpose` / `@invariant` tags, references
 * to canonical docs under `docs/loop-*.md`, `docs/cortex-*.md`, `docs/maestro-*.md`, and a distinct
 * `doc_absent` FACT when the class has no docblock at all. NO scoring, NO quality grading — just whether
 * declared intent exists and where it points.
 *
 * DISAGREEMENT SIGNALS:
 *   - `doc_intent_drift` : the docblock references a doc file that does not exist on disk
 *   - `source_unresolved` : neither source_code nor file_path resolves to readable PHP
 *
 * The subject's facts may carry:
 *   - `source_code` : raw PHP source string
 *   - `file_path`   : absolute path the lens will read once (also used as the repo anchor for doc-ref existence)
 *   - `repo_root`   : (optional) absolute path to the repo root for resolving relative doc references
 */
final class AtlasCortexDocIntentLens implements LensContract
{
    public function id(): string
    {
        return 'docintent';
    }

    public function name(): string
    {
        return 'Doc-Intent';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        $source = $this->resolveSource($subject);
        if ($source === null) {
            return new LensObservation($this->id(), $subject->id, ['facts' => []], ['source_unresolved']);
        }

        $repoRoot = $this->resolveRepoRoot($subject);
        $disagreement = [];
        $facts = [];

        $classDoc = $this->extractClassDocBlock($source);
        if ($classDoc === null) {
            $facts[] = ['kind' => 'doc_absent', 'subject' => 'class'];
        } else {
            $summary = $this->firstSentenceOfDocBlock($classDoc);
            if ($summary !== '') {
                $facts[] = ['kind' => 'class_summary', 'summary' => $summary];
            }
            // Doc references in the class-level docblock.
            foreach ($this->extractDocRefs($classDoc) as $ref) {
                $facts[] = ['kind' => 'declared_doc_ref', 'ref' => $ref, 'in' => 'class'];
                if (! $this->docRefExists($repoRoot, $ref)) {
                    $disagreement[] = 'doc_intent_drift:'.$ref;
                }
            }
        }

        // Per-method @purpose / @invariant tags + per-method doc refs.
        foreach ($this->methodDocBlocks($source) as $methodBlock) {
            $method = (string) $methodBlock['method'];
            $doc = (string) $methodBlock['doc'];
            foreach ($this->extractTags($doc, ['purpose', 'invariant']) as $tag => $valueList) {
                foreach ($valueList as $value) {
                    $facts[] = ['kind' => $tag, 'method' => $method, 'value' => $value];
                }
            }
            foreach ($this->extractDocRefs($doc) as $ref) {
                $facts[] = ['kind' => 'declared_doc_ref', 'ref' => $ref, 'in' => 'method:'.$method];
                if (! $this->docRefExists($repoRoot, $ref)) {
                    $disagreement[] = 'doc_intent_drift:'.$ref;
                }
            }
        }

        return new LensObservation($this->id(), $subject->id, ['facts' => $facts], array_values(array_unique($disagreement)));
    }

    private function resolveSource(CortexSubject $subject): ?string
    {
        $facts = $subject->facts;
        if (isset($facts['source_code']) && is_string($facts['source_code']) && $facts['source_code'] !== '') {
            return (string) $facts['source_code'];
        }
        $path = isset($facts['file_path']) ? (string) $facts['file_path'] : '';
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return $contents === false || $contents === '' ? null : $contents;
    }

    private function resolveRepoRoot(CortexSubject $subject): ?string
    {
        $facts = $subject->facts;
        if (isset($facts['repo_root']) && is_string($facts['repo_root']) && $facts['repo_root'] !== '') {
            return rtrim((string) $facts['repo_root'], '/');
        }
        if (function_exists('base_path')) {
            try {
                return rtrim((string) base_path(), '/');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * Return the PHPDoc block that immediately precedes the `class` / `final class` / etc. declaration, or
     * null when no such docblock exists.
     */
    private function extractClassDocBlock(string $source): ?string
    {
        if (preg_match('#(/\*\*[\s\S]*?\*/)\s*(?:final\s+|abstract\s+|readonly\s+|final\s+readonly\s+)?(?:class|interface|trait|enum)\s+#m', $source, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return list<array{method:string, doc:string}>
     */
    private function methodDocBlocks(string $source): array
    {
        $out = [];
        if (preg_match_all('#(/\*\*[\s\S]*?\*/)\s*(?:public|protected|private)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(#', $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $m) {
            $out[] = ['doc' => (string) $m[1], 'method' => (string) $m[2]];
        }

        return $out;
    }

    /**
     * Extract the named tags + their values (one entry per occurrence) from a docblock.
     *
     * @param  list<string>  $tagNames  e.g. ['purpose', 'invariant']
     * @return array<string,list<string>>  tag => values (in source order)
     */
    private function extractTags(string $doc, array $tagNames): array
    {
        $out = [];
        foreach ($tagNames as $tag) {
            $out[$tag] = [];
            if (preg_match_all('/@'.preg_quote($tag, '/').'\s+(.+)/', $doc, $matches) > 0) {
                foreach ($matches[1] as $value) {
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $out[$tag][] = $value;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Pull out `docs/loop-*.md`, `docs/cortex-*.md`, `docs/maestro-*.md` references from a docblock.
     *
     * @return list<string>  unique, source-order preserved
     */
    private function extractDocRefs(string $doc): array
    {
        $found = [];
        if (preg_match_all('#docs/(loop|cortex|maestro)-[A-Za-z0-9_\-]+\.md#', $doc, $matches) > 0) {
            foreach ($matches[0] as $ref) {
                $ref = (string) $ref;
                if (! in_array($ref, $found, true)) {
                    $found[] = $ref;
                }
            }
        }

        return $found;
    }

    private function docRefExists(?string $repoRoot, string $ref): bool
    {
        if ($repoRoot === null) {
            // No repo anchor ⇒ we cannot verify; default to "exists" to avoid false-positive drift.
            return true;
        }

        return is_file($repoRoot.'/'.$ref);
    }

    /**
     * Strip leading "* " markers and return the first sentence (up to the first ". " or end of paragraph).
     */
    private function firstSentenceOfDocBlock(string $doc): string
    {
        $lines = preg_split('/\R/', $doc) ?: [];
        $paragraph = [];
        foreach ($lines as $line) {
            $clean = trim($line);
            if ($clean === '/**' || $clean === '*/' || $clean === '*') {
                if ($paragraph !== []) {
                    break; // end of summary paragraph
                }

                continue;
            }
            $clean = ltrim($clean, '*');
            $clean = trim($clean);
            if ($clean === '' || str_starts_with($clean, '@')) {
                if ($paragraph !== []) {
                    break;
                }

                continue;
            }
            $paragraph[] = $clean;
        }
        $text = trim(implode(' ', $paragraph));
        if ($text === '') {
            return '';
        }
        // First sentence — break on ". " (with trailing space) preserved.
        $pos = strpos($text, '. ');

        return $pos === false ? $text : substr($text, 0, $pos + 1);
    }
}
