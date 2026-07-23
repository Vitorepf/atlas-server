<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · G-6 — License / provenance tagging for cross-project context.
 *
 * Before an EXTERNAL project's code is allowed to enter the unified code graph,
 * Atlas must know its license. The hazard this closes is silent contamination:
 * copyleft source (GPL/AGPL/LGPL) being mixed into a proprietary context where a
 * model could echo it back into the operator's closed codebase. This detector is
 * the classification half — it tells the ingest gate WHAT a body of text is
 * licensed under; the gate decides whether that may cross the boundary.
 *
 * Two surfaces:
 *   - {@see detect()}     classifies a raw license-text blob.
 *   - {@see detectPath()} best-effort reads a project's LICENSE/COPYING file then
 *     delegates to detect(), tagging which file it matched.
 *
 * Detection strategy (deterministic, ordered most-specific → least-specific):
 *   1. Normalize the text once (lowercase, collapse all whitespace) so layout,
 *      line-wrapping and casing never change the verdict — the same license
 *      always yields the same SPDX id byte-for-byte.
 *   2. Walk an ordered rule table. Order is load-bearing: AGPL is matched before
 *      GPL (AGPL text contains "GNU ... General Public License"); GPL-3.0 before
 *      GPL-2.0; BSD-3-Clause before BSD-2-Clause (3-clause is a superset that
 *      adds the no-endorsement clause); permissive families before the bare
 *      "all rights reserved" proprietary catch so a real OSS notice is never
 *      mis-tagged proprietary just because it also reserves rights.
 *   3. An explicit SPDX tag in the text ("SPDX-License-Identifier: MIT") is the
 *      highest-confidence signal and short-circuits when recognised.
 *   4. No license signal at all → proprietary if it asserts "all rights
 *      reserved", else 'unknown'. Never guesses.
 *
 * This is [php] by the runtime-language boundary: pure governance/identity, no
 * heavy data or ML, no DB, no clock, no randomness. It NEVER throws on bad input
 * (empty, binary, malformed) — it returns a safe 'unknown'/0.0 default so a
 * single unreadable LICENSE can never break the ingest path.
 */
class CodeGraphLicenseDetector
{
    public const SCHEMA = 'atlas.code_graph.license_detector.v1';

    /** Stable category vocabulary returned in the `category` field. */
    public const CATEGORY_PERMISSIVE = 'permissive';

    public const CATEGORY_COPYLEFT = 'copyleft';

    public const CATEGORY_PROPRIETARY = 'proprietary';

    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * Candidate filenames a project may carry its license in, checked in this
     * order. Case variants are tried per name so LICENSE / license / License all
     * resolve on case-sensitive filesystems.
     *
     * @var array<int,string>
     */
    private const LICENSE_FILENAMES = [
        'LICENSE',
        'LICENSE.md',
        'LICENSE.txt',
        'LICENCE',      // British spelling, occasionally used.
        'LICENCE.md',
        'LICENCE.txt',
        'COPYING',      // GNU convention.
        'COPYING.md',
        'COPYING.txt',
        'COPYING.LESSER', // LGPL convention.
    ];

    /**
     * Cap on how many bytes of a LICENSE file we read. License text is small;
     * this bounds memory if a malformed/huge file is named LICENSE, keeping the
     * detector fail-safe on hostile input. 256 KiB is far above any real license.
     */
    private const MAX_LICENSE_BYTES = 262144;

    /**
     * Classify a raw license-text blob.
     *
     * @return array{license:string, category:string, confidence:float, evidence:string}
     *   license:    SPDX id (e.g. 'MIT', 'GPL-3.0-or-later'), 'proprietary', or 'unknown'.
     *   category:   one of permissive|copyleft|proprietary|unknown.
     *   confidence: 0.0 (no signal) .. 1.0 (explicit SPDX tag / unmistakable text).
     *   evidence:   short matched snippet that justified the verdict ('' when none).
     */
    public function detect(string $text): array
    {
        // Guard: empty / whitespace-only / non-printable-only input is 'unknown'.
        $raw = trim($text);
        if ($raw === '') {
            return $this->result(self::CATEGORY_UNKNOWN, self::CATEGORY_UNKNOWN, 0.0, '');
        }

        $normalized = $this->normalize($text);
        if ($normalized === '') {
            // Text was only punctuation/whitespace/control bytes after normalize.
            return $this->result(self::CATEGORY_UNKNOWN, self::CATEGORY_UNKNOWN, 0.0, '');
        }

        // 1. Highest confidence: an explicit SPDX-License-Identifier tag.
        $spdx = $this->matchExplicitSpdx($normalized);
        if ($spdx !== null) {
            return $spdx;
        }

        // 2. Ordered text-signature rules (most specific first).
        foreach ($this->rules() as $rule) {
            if ($this->allPresent($normalized, $rule['needles'])) {
                return $this->result(
                    $rule['license'],
                    $rule['category'],
                    $rule['confidence'],
                    $this->snippetFor($raw, $rule['evidence']),
                );
            }
        }

        // 3. Proprietary catch: an explicit "all rights reserved" with no OSS
        //    signal above means proprietary. Lower confidence than a real grant.
        if ($this->containsAny($normalized, ['all rights reserved'])) {
            return $this->result(
                self::CATEGORY_PROPRIETARY,
                self::CATEGORY_PROPRIETARY,
                0.6,
                $this->snippetFor($raw, 'all rights reserved'),
            );
        }

        // 4. Nothing recognised.
        return $this->result(self::CATEGORY_UNKNOWN, self::CATEGORY_UNKNOWN, 0.0, '');
    }

    /**
     * Best-effort: locate a license file under $dir, read it, and classify it.
     * If no readable license file exists the directory is treated as having no
     * license text → proprietary-by-absence is NOT assumed here (that is the
     * gate's policy call); we return 'unknown' with source=null so the caller
     * can decide. Never throws — any IO failure degrades to the empty result.
     *
     * @return array{license:string, category:string, confidence:float, evidence:string, source:string|null}
     *   same shape as {@see detect()} plus `source` = the matched filename
     *   (basename, not full path) or null when no license file was found/read.
     */
    public function detectPath(string $dir): array
    {
        $base = $this->safeDir($dir);
        if ($base === null) {
            return $this->withSource($this->detect(''), null);
        }

        foreach (self::LICENSE_FILENAMES as $filename) {
            $contents = $this->readLicenseFile($base, $filename);
            if ($contents === null) {
                continue;
            }

            $detected = $this->detect($contents);

            // A readable license file that we still can't classify is reported
            // with the source so the caller knows a file existed but was opaque.
            return $this->withSource($detected, $filename);
        }

        return $this->withSource($this->detect(''), null);
    }

    /**
     * Ordered rule table. Each rule fires only when EVERY needle is present in
     * the normalized text (AND semantics), which keeps short, ambiguous strings
     * from matching a heavy license. Order matters — see the class docblock.
     *
     * @return array<int,array{license:string, category:string, confidence:float, needles:array<int,string>, evidence:string}>
     */
    private function rules(): array
    {
        return [
            // ---- Copyleft: AGPL before GPL (its text says "General Public License") ----
            [
                'license' => 'AGPL-3.0',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.95,
                'needles' => ['gnu affero general public license', 'version 3'],
                'evidence' => 'gnu affero general public license',
            ],
            [
                'license' => 'AGPL-3.0',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.9,
                'needles' => ['affero general public license'],
                'evidence' => 'affero general public license',
            ],

            // ---- LGPL before GPL (its text also says "General Public License") ----
            [
                'license' => 'LGPL-3.0',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.95,
                'needles' => ['gnu lesser general public license', 'version 3'],
                'evidence' => 'gnu lesser general public license',
            ],
            [
                'license' => 'LGPL-3.0',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.9,
                'needles' => ['lesser general public license'],
                'evidence' => 'lesser general public license',
            ],

            // ---- GPL-3.0: -or-later vs -only by the "any later version" clause ----
            [
                'license' => 'GPL-3.0-or-later',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.95,
                'needles' => ['gnu general public license', 'version 3', 'any later version'],
                'evidence' => 'gnu general public license version 3 ... any later version',
            ],
            [
                'license' => 'GPL-3.0-only',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.9,
                'needles' => ['gnu general public license', 'version 3'],
                'evidence' => 'gnu general public license version 3',
            ],

            // ---- GPL-2.0: -or-later vs -only ----
            [
                'license' => 'GPL-2.0-or-later',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.95,
                'needles' => ['gnu general public license', 'version 2', 'any later version'],
                'evidence' => 'gnu general public license version 2 ... any later version',
            ],
            [
                'license' => 'GPL-2.0-only',
                'category' => self::CATEGORY_COPYLEFT,
                'confidence' => 0.9,
                'needles' => ['gnu general public license', 'version 2'],
                'evidence' => 'gnu general public license version 2',
            ],

            // ---- Permissive: Apache, BSD (3 before 2), ISC, MIT ----
            [
                'license' => 'Apache-2.0',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.95,
                'needles' => ['apache license', 'version 2.0'],
                'evidence' => 'apache license, version 2.0',
            ],
            [
                // 3-clause BSD adds the no-endorsement clause that 2-clause lacks.
                'license' => 'BSD-3-Clause',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.95,
                'needles' => [
                    'redistribution and use in source and binary forms',
                    'neither the name',
                ],
                'evidence' => 'neither the name ... may be used to endorse',
            ],
            [
                'license' => 'BSD-2-Clause',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.9,
                'needles' => [
                    'redistribution and use in source and binary forms',
                    'this list of conditions and the following disclaimer',
                ],
                'evidence' => 'redistribution and use in source and binary forms',
            ],
            [
                'license' => 'ISC',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.9,
                'needles' => [
                    'permission to use, copy, modify, and/or distribute this software',
                ],
                'evidence' => 'permission to use, copy, modify, and/or distribute',
            ],
            [
                'license' => 'MIT',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.95,
                'needles' => [
                    'permission is hereby granted, free of charge',
                    'the software is provided "as is"',
                ],
                'evidence' => 'permission is hereby granted, free of charge',
            ],
            [
                // MIT title line ("The MIT License") on its own — slightly lower.
                'license' => 'MIT',
                'category' => self::CATEGORY_PERMISSIVE,
                'confidence' => 0.75,
                'needles' => ['mit license', 'permission is hereby granted'],
                'evidence' => 'mit license',
            ],
        ];
    }

    /**
     * Recognise an explicit `SPDX-License-Identifier: <id>` tag and map it to a
     * known license + category. Unknown SPDX ids still return a verdict (the id
     * verbatim) but at lower confidence and category 'unknown', because the tag
     * is authoritative about the id even if we don't know its category.
     *
     * @return array{license:string, category:string, confidence:float, evidence:string}|null
     */
    private function matchExplicitSpdx(string $normalized): ?array
    {
        if (preg_match('/spdx-license-identifier:\s*([a-z0-9.+-]+)/i', $normalized, $m) !== 1) {
            return null;
        }

        $id = trim($m[1], " \t\n\r\0\x0B.");
        if ($id === '') {
            return null;
        }

        $canonical = $this->canonicalSpdxId($id);
        $category = $this->categoryForSpdx($canonical);

        return $this->result(
            $canonical,
            $category,
            $category === self::CATEGORY_UNKNOWN ? 0.7 : 1.0,
            'SPDX-License-Identifier: '.$canonical,
        );
    }

    /**
     * Map a (lowercased) SPDX id from a tag to our canonical spelling. Recognised
     * ids get their official casing; anything else is passed through uppercased so
     * the operator still sees exactly what the tag claimed.
     */
    private function canonicalSpdxId(string $lowerId): string
    {
        static $map = [
            'mit' => 'MIT',
            'apache-2.0' => 'Apache-2.0',
            'bsd-2-clause' => 'BSD-2-Clause',
            'bsd-3-clause' => 'BSD-3-Clause',
            'isc' => 'ISC',
            'gpl-2.0-only' => 'GPL-2.0-only',
            'gpl-2.0-or-later' => 'GPL-2.0-or-later',
            'gpl-3.0-only' => 'GPL-3.0-only',
            'gpl-3.0-or-later' => 'GPL-3.0-or-later',
            'agpl-3.0' => 'AGPL-3.0',
            'agpl-3.0-only' => 'AGPL-3.0',
            'agpl-3.0-or-later' => 'AGPL-3.0',
            'lgpl-3.0' => 'LGPL-3.0',
            'lgpl-3.0-only' => 'LGPL-3.0',
            'lgpl-3.0-or-later' => 'LGPL-3.0',
            // Deprecated short forms still seen in the wild.
            'gpl-2.0' => 'GPL-2.0-only',
            'gpl-3.0' => 'GPL-3.0-only',
        ];

        $key = strtolower($lowerId);

        return $map[$key] ?? strtoupper($lowerId);
    }

    private function categoryForSpdx(string $canonical): string
    {
        static $permissive = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC'];
        static $copyleft = [
            'GPL-2.0-only', 'GPL-2.0-or-later', 'GPL-3.0-only', 'GPL-3.0-or-later',
            'AGPL-3.0', 'LGPL-3.0',
        ];

        if (in_array($canonical, $permissive, true)) {
            return self::CATEGORY_PERMISSIVE;
        }
        if (in_array($canonical, $copyleft, true)) {
            return self::CATEGORY_COPYLEFT;
        }

        return self::CATEGORY_UNKNOWN;
    }

    /**
     * Normalize license text for matching: lowercase, replace every run of
     * whitespace (incl. newlines/tabs) with a single space, and strip control
     * bytes. This makes matching layout-independent and deterministic.
     */
    private function normalize(string $text): string
    {
        // Drop NULs / control chars (binary-ish input) except whitespace.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
        $clean = is_string($clean) ? $clean : $text;

        $lower = mb_strtolower($clean);

        $collapsed = preg_replace('/\s+/u', ' ', $lower);
        $collapsed = is_string($collapsed) ? $collapsed : $lower;

        return trim($collapsed);
    }

    /**
     * True when every needle (already lowercase) is present in the normalized
     * haystack. Empty needle list never matches (guards a malformed rule).
     *
     * @param  array<int,string>  $needles
     */
    private function allPresent(string $haystack, array $needles): bool
    {
        if ($needles === []) {
            return false;
        }
        foreach ($needles as $needle) {
            if ($needle === '' || ! str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a short human-readable evidence snippet. Prefers the actual matched
     * region from the ORIGINAL text (so casing/wording is real); falls back to
     * the rule's descriptive label when the marker isn't a single literal
     * substring of the raw text (e.g. "A ... B" composite evidence).
     */
    private function snippetFor(string $rawText, string $marker): string
    {
        $marker = trim($marker);
        if ($marker === '') {
            return '';
        }

        // If the marker is a literal substring (case-insensitive) of the raw
        // text, return the real surrounding slice; else return the label itself.
        $pos = stripos($rawText, $marker);
        if ($pos === false) {
            return $this->clamp($marker, 120);
        }

        $slice = substr($rawText, $pos, strlen($marker) + 32);
        $slice = preg_replace('/\s+/u', ' ', $slice);
        $slice = is_string($slice) ? trim($slice) : $marker;

        return $this->clamp($slice, 120);
    }

    private function clamp(string $value, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $max - 1)).'…';
    }

    /**
     * @return array{license:string, category:string, confidence:float, evidence:string}
     */
    private function result(string $license, string $category, float $confidence, string $evidence): array
    {
        return [
            'license' => $license,
            'category' => $category,
            'confidence' => $this->clampConfidence($confidence),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array{license:string, category:string, confidence:float, evidence:string}  $detected
     * @return array{license:string, category:string, confidence:float, evidence:string, source:string|null}
     */
    private function withSource(array $detected, ?string $source): array
    {
        $detected['source'] = $source;

        return $detected;
    }

    private function clampConfidence(float $confidence): float
    {
        if (is_nan($confidence) || $confidence < 0.0) {
            return 0.0;
        }

        return $confidence > 1.0 ? 1.0 : $confidence;
    }

    /**
     * Resolve $dir to a readable directory path, or null. Never throws.
     */
    private function safeDir(string $dir): ?string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return null;
        }

        $real = @realpath($dir);
        if ($real !== false && @is_dir($real)) {
            return rtrim($real, DIRECTORY_SEPARATOR);
        }

        // Fall back to the given path if it is directly a directory (realpath can
        // fail under open_basedir while is_dir still answers).
        return @is_dir($dir) ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
    }

    /**
     * Read a single candidate license file (best-effort, bounded). Returns the
     * file contents, or null if it does not exist / is unreadable / is empty.
     */
    private function readLicenseFile(string $baseDir, string $filename): ?string
    {
        $path = $baseDir.DIRECTORY_SEPARATOR.$filename;

        if (! @is_file($path) || ! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path, false, null, 0, self::MAX_LICENSE_BYTES);
        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        return $contents;
    }
}
