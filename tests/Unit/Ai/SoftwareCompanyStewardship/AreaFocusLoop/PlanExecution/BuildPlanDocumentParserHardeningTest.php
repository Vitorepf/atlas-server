<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDocumentParser;
use Tests\TestCase;

/**
 * P1-PARSER-EXTRACTION robustness hardening tests.
 *
 * Proves the four parser defect fixes (duplicate-label detection, ` - `
 * acceptance branch, escaped-pipe cell preservation, in-fence section
 * extraction) while asserting the preserved invariants are not weakened:
 * dedup (I6) strengthened, AFEF I1 evidence-bound (split never satisfies an
 * empty Aceite), no-scaffold/honest-stop (never invents structure),
 * determinism (stable skip, sorted offending-label list).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS.
 */
class BuildPlanDocumentParserHardeningTest extends TestCase
{
    private function parser(): BuildPlanDocumentParser
    {
        return new BuildPlanDocumentParser();
    }

    private function frontmatter(): string
    {
        return "---\nid: build-plan-test\ntitle: Test Plan\n---\n";
    }

    /** Defect 2: duplicate slice label → first wins + offending label exposed. */
    public function test_duplicate_slice_label_keeps_first_and_reports_offender(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | first delivery | verde | dev |\n"
            ."| S1 | second delivery | vermelho | forge |\n"
            ."| S2 | other | verde | dev |\n";

        $result = $this->parser()->parse($md);

        $labels = array_column($result['slices'], 'label');
        $this->assertSame(['S1', 'S2'], $labels);

        $s1 = $result['slices'][0];
        $this->assertSame('first delivery', $s1['delivery'], 'first occurrence wins (stable)');

        $this->assertSame(['S1'], $result['duplicate_slice_labels']);
    }

    /** Determinism: offending-label list is deduped and sorted. */
    public function test_duplicate_labels_are_deduped_and_sorted(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S3 | a | verde | dev |\n"
            ."| S1 | b | verde | dev |\n"
            ."| S3 | c | verde | dev |\n"
            ."| S1 | d | verde | dev |\n"
            ."| S1 | e | verde | dev |\n";

        $result = $this->parser()->parse($md);

        $this->assertSame(['S1', 'S3'], $result['duplicate_slice_labels']);
        $this->assertSame(['S3', 'S1'], array_column($result['slices'], 'label'));
    }

    /** Defect 4a: documented ` - ` bullet fans acceptance into multiple criteria. */
    public function test_acceptance_splits_on_dash_bullet(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | x | verde - cobertura - diff exit 0 | dev |\n";

        $result = $this->parser()->parse($md);

        $this->assertSame(
            ['verde', 'cobertura', 'diff exit 0'],
            $result['slices'][0]['acceptance_criteria'],
        );
    }

    /** AFEF I1: split can only increase criteria, never satisfy an empty Aceite. */
    public function test_empty_acceptance_stays_empty_after_split(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | x |  | dev |\n";

        $result = $this->parser()->parse($md);

        $this->assertSame([], $result['slices'][0]['acceptance_criteria']);
    }

    /** Defect 4b: escaped pipe keeps 4 columns and lands acceptance correctly. */
    public function test_escaped_pipe_preserves_columns(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | run a \\| b | verde cobertura | dev |\n";

        $result = $this->parser()->parse($md);

        $this->assertCount(1, $result['slices']);
        $s1 = $result['slices'][0];
        $this->assertSame('run a | b', $s1['delivery'], 'literal pipe unescaped, stays in delivery column');
        $this->assertSame(['verde cobertura'], $s1['acceptance_criteria']);
        $this->assertSame('dev', $s1['authority_guard']);
    }

    /** Defect 5: a fenced code block with a `## ` line does not truncate section 6. */
    public function test_section_not_truncated_by_fenced_hash_heading(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."```bash\n"
            ."## this is a shell comment, not a heading\n"
            ."echo hi\n"
            ."```\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | a | verde | dev |\n"
            ."| S2 | b | verde | dev |\n";

        $result = $this->parser()->parse($md);

        $this->assertSame(['S1', 'S2'], array_column($result['slices'], 'label'));
        $this->assertTrue($result['section_6_found']);
    }

    /** Regression: semicolon-only single-occurrence doc parses identically. */
    public function test_semicolon_only_doc_unchanged(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | a | verde; cobertura; diff exit 0 | dev |\n"
            ."| S2 | b | verde | forge |\n";

        $result = $this->parser()->parse($md);

        $this->assertSame(['S1', 'S2'], array_column($result['slices'], 'label'));
        $this->assertSame(
            ['verde', 'cobertura', 'diff exit 0'],
            $result['slices'][0]['acceptance_criteria'],
        );
        $this->assertSame([], $result['duplicate_slice_labels'], 'no false positive duplicates');
    }

    /** Determinism: parsing the same doc twice yields the identical shape. */
    public function test_parse_is_deterministic(): void
    {
        $md = $this->frontmatter()
            ."## 6. Decomposicao em slices ordenados\n\n"
            ."| Slice | Entrega | Aceite | Guarda |\n"
            ."| --- | --- | --- | --- |\n"
            ."| S1 | a | verde - cobertura | dev |\n"
            ."| S1 | dup | x | forge |\n";

        $p = $this->parser();
        $this->assertSame($p->parse($md), $p->parse($md));
    }
}
