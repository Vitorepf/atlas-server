<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphLicenseDetector;
use Tests\TestCase;

/**
 * AP-815 · G-6 — CodeGraphLicenseDetector.
 *
 * Pure unit test (NO database, NO config mutation): proves the detector
 * classifies canonical license headers to the right SPDX id + category, fails
 * safe on empty/malformed input, and reads a LICENSE file from disk via a temp
 * directory (filesystem only, no DB).
 */
class CodeGraphLicenseDetectorTest extends TestCase
{
    private CodeGraphLicenseDetector $detector;

    /** @var array<int,string> temp paths to clean up */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CodeGraphLicenseDetector();
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of any temp dirs/files created by detectPath tests.
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->tempPaths = [];

        parent::tearDown();
    }

    // ----------------------------------------------------------------- happy path

    public function test_detects_mit_header(): void
    {
        $text = <<<'TXT'
        MIT License

        Copyright (c) 2026 Atlas

        Permission is hereby granted, free of charge, to any person obtaining a copy
        of this software and associated documentation files (the "Software"), to deal
        in the Software without restriction.

        THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.
        TXT;

        $r = $this->detector->detect($text);

        $this->assertSame('MIT', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
        $this->assertGreaterThan(0.8, $r['confidence']);
        $this->assertNotSame('', $r['evidence']);
    }

    public function test_detects_apache_2_0_header(): void
    {
        $text = "Apache License\nVersion 2.0, January 2004\nhttp://www.apache.org/licenses/";

        $r = $this->detector->detect($text);

        $this->assertSame('Apache-2.0', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
    }

    public function test_detects_bsd_3_clause_and_not_2_clause(): void
    {
        // 3-clause has the "neither the name ... endorse" clause that 2-clause lacks.
        $text = <<<'TXT'
        Redistribution and use in source and binary forms, with or without
        modification, are permitted provided that the following conditions are met:

        Redistributions of source code must retain the above copyright notice, this
        list of conditions and the following disclaimer.

        Neither the name of the copyright holder nor the names of its contributors
        may be used to endorse or promote products derived from this software.
        TXT;

        $r = $this->detector->detect($text);

        $this->assertSame('BSD-3-Clause', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
    }

    public function test_detects_bsd_2_clause(): void
    {
        $text = <<<'TXT'
        Redistribution and use in source and binary forms, with or without
        modification, are permitted provided that the following conditions are met:

        1. Redistributions of source code must retain the above copyright notice,
           this list of conditions and the following disclaimer.
        2. Redistributions in binary form must reproduce the above copyright notice.
        TXT;

        $r = $this->detector->detect($text);

        $this->assertSame('BSD-2-Clause', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
    }

    public function test_detects_isc(): void
    {
        $text = 'Permission to use, copy, modify, and/or distribute this software for any '
            .'purpose with or without fee is hereby granted.';

        $r = $this->detector->detect($text);

        $this->assertSame('ISC', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
    }

    // ----------------------------------------------------------------- copyleft

    public function test_detects_gpl_3_only_vs_or_later(): void
    {
        $only = "GNU GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007";
        $rOnly = $this->detector->detect($only);
        $this->assertSame('GPL-3.0-only', $rOnly['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $rOnly['category']);

        $orLater = $only."\nyou can redistribute it under version 3 or (at your option) any later version.";
        $rLater = $this->detector->detect($orLater);
        $this->assertSame('GPL-3.0-or-later', $rLater['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $rLater['category']);
    }

    public function test_detects_gpl_2_only(): void
    {
        $text = "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991";

        $r = $this->detector->detect($text);

        $this->assertSame('GPL-2.0-only', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $r['category']);
    }

    public function test_agpl_is_not_misdetected_as_gpl(): void
    {
        // AGPL text contains "General Public License" — ordering must catch AGPL first.
        $text = "GNU AFFERO GENERAL PUBLIC LICENSE\nVersion 3, 19 November 2007";

        $r = $this->detector->detect($text);

        $this->assertSame('AGPL-3.0', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $r['category']);
    }

    public function test_lgpl_is_not_misdetected_as_gpl(): void
    {
        $text = "GNU LESSER GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007";

        $r = $this->detector->detect($text);

        $this->assertSame('LGPL-3.0', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $r['category']);
    }

    // ----------------------------------------------------------------- SPDX tag

    public function test_explicit_spdx_tag_wins_with_full_confidence(): void
    {
        $r = $this->detector->detect('// SPDX-License-Identifier: Apache-2.0');

        $this->assertSame('Apache-2.0', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PERMISSIVE, $r['category']);
        $this->assertSame(1.0, $r['confidence']);
        $this->assertStringContainsString('SPDX', $r['evidence']);
    }

    public function test_unknown_spdx_id_is_reported_verbatim_with_unknown_category(): void
    {
        $r = $this->detector->detect('SPDX-License-Identifier: Beerware');

        // The tag is authoritative about the id even if we don't know its category.
        $this->assertSame('BEERWARE', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertGreaterThan(0.0, $r['confidence']);
    }

    // ----------------------------------------------------------------- edge cases

    public function test_empty_string_is_unknown(): void
    {
        $r = $this->detector->detect('');

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertSame(0.0, $r['confidence']);
        $this->assertSame('', $r['evidence']);
    }

    public function test_whitespace_only_is_unknown(): void
    {
        $r = $this->detector->detect("   \n\t  \r\n ");

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertSame(0.0, $r['confidence']);
    }

    public function test_all_rights_reserved_is_proprietary(): void
    {
        $r = $this->detector->detect("Copyright (c) 2026 Vitor. All Rights Reserved.\nProprietary and confidential.");

        $this->assertSame('proprietary', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_PROPRIETARY, $r['category']);
        $this->assertGreaterThan(0.0, $r['confidence']);
        $this->assertNotSame('', $r['evidence']);
    }

    public function test_unrecognized_prose_is_unknown(): void
    {
        $r = $this->detector->detect('This file documents the architecture of the billing module. See diagram below.');

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertSame(0.0, $r['confidence']);
    }

    public function test_binary_garbage_does_not_throw_and_is_unknown(): void
    {
        // Control bytes + NULs + high bytes (binary-ish): must fail safe, never throw.
        $garbage = "\x00\x01\x02\xff\xfe\x7f\x08\x0b\x0c\x1f";

        $r = $this->detector->detect($garbage);

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertSame(0.0, $r['confidence']);
    }

    public function test_detection_is_deterministic_and_layout_independent(): void
    {
        $a = "MIT License\n\nPermission is hereby granted, free of charge, to any person.\n"
            .'THE SOFTWARE IS PROVIDED "AS IS".';
        // Same content, different whitespace/casing/wrapping.
        $b = "  mit   LICENSE\t permission is hereby   granted, free of charge, to any PERSON.   "
            .'the software is provided   "as is".  ';

        $ra = $this->detector->detect($a);
        $rb = $this->detector->detect($b);

        $this->assertSame('MIT', $ra['license']);
        $this->assertSame('MIT', $rb['license']);
        $this->assertSame($ra['license'], $rb['license']);
        $this->assertSame($ra['category'], $rb['category']);

        // Repeated calls on identical input are byte-identical.
        $this->assertSame($ra, $this->detector->detect($a));
    }

    public function test_confidence_is_always_within_unit_interval(): void
    {
        foreach ([
            'MIT License Permission is hereby granted, free of charge. The software is provided "as is".',
            'GNU AFFERO GENERAL PUBLIC LICENSE Version 3',
            'All Rights Reserved',
            '',
            'random text',
        ] as $sample) {
            $c = $this->detector->detect($sample)['confidence'];
            $this->assertIsFloat($c);
            $this->assertGreaterThanOrEqual(0.0, $c);
            $this->assertLessThanOrEqual(1.0, $c);
        }
    }

    // ----------------------------------------------------------------- detectPath

    public function test_detect_path_reads_license_file_and_adds_source(): void
    {
        $dir = $this->makeTempDir();
        $file = $dir.DIRECTORY_SEPARATOR.'LICENSE';
        file_put_contents($file, "GNU GENERAL PUBLIC LICENSE\nVersion 3, 29 June 2007\nany later version");
        $this->tempPaths[] = $file;

        $r = $this->detector->detectPath($dir);

        $this->assertSame('GPL-3.0-or-later', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_COPYLEFT, $r['category']);
        $this->assertSame('LICENSE', $r['source']);
    }

    public function test_detect_path_with_no_license_file_returns_unknown_null_source(): void
    {
        $dir = $this->makeTempDir();

        $r = $this->detector->detectPath($dir);

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertNull($r['source']);
    }

    public function test_detect_path_with_nonexistent_dir_is_safe(): void
    {
        $r = $this->detector->detectPath('/this/path/does/not/exist/atlas-xyz-'.bin2hex('g6'));

        $this->assertSame('unknown', $r['license']);
        $this->assertSame(CodeGraphLicenseDetector::CATEGORY_UNKNOWN, $r['category']);
        $this->assertNull($r['source']);
    }

    public function test_detect_path_with_empty_string_dir_is_safe(): void
    {
        $r = $this->detector->detectPath('');

        $this->assertSame('unknown', $r['license']);
        $this->assertNull($r['source']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas-g6-'.uniqid('', true);
        @mkdir($dir, 0700, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }
}
