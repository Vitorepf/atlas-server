<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeProvenanceService;
use PHPUnit\Framework\TestCase;

/**
 * A folha do commit precisa dizer O QUE mudou. O parser lê o que o Git
 * imprime — e cala quando o Git não mediu, em vez de fabricar zero.
 */
final class AtlasCodeProvenanceServiceTest extends TestCase
{
    public function test_file_changes_carry_status_and_real_counts(): void
    {
        $output = <<<'GIT'
        :100644 100644 7b36f17 e7f2975 M	App/Atlas/AtlasCodeHubModel.swift
        :000000 100644 0000000 dc35e6c A	Sources/AtlasCore/AtlasCodeIssue.swift
        :100644 000000 786bc51 0000000 D	Sources/AtlasCore/AtlasCodeRepos.swift
        7	6	App/Atlas/AtlasCodeHubModel.swift
        56	0	Sources/AtlasCore/AtlasCodeIssue.swift
        0	118	Sources/AtlasCore/AtlasCodeRepos.swift
        GIT;

        $files = (new AtlasCodeProvenanceService())->parseFileChanges($output);

        self::assertSame(['modified', 'added', 'deleted'], array_column($files, 'status'));
        self::assertSame('App/Atlas/AtlasCodeHubModel.swift', $files[0]['path']);
        self::assertSame([7, 6], [$files[0]['additions'], $files[0]['deletions']]);
        self::assertSame([56, 0], [$files[1]['additions'], $files[1]['deletions']]);
        self::assertSame([0, 118], [$files[2]['additions'], $files[2]['deletions']]);
    }

    public function test_rename_keeps_the_new_path_and_remembers_where_it_came_from(): void
    {
        // O numstat escreve o caminho de rename como `src/{a => b}.ts`; por
        // isso os blocos são casados por posição, não por caminho.
        $output = <<<'GIT'
        :100644 100644 aaaaaaa bbbbbbb R096	Sources/Old.swift	Sources/New.swift
        3	1	Sources/{Old.swift => New.swift}
        GIT;

        $files = (new AtlasCodeProvenanceService())->parseFileChanges($output);

        self::assertCount(1, $files);
        self::assertSame('renamed', $files[0]['status']);
        self::assertSame('Sources/New.swift', $files[0]['path']);
        self::assertSame('Sources/Old.swift', $files[0]['renamed_from']);
        self::assertSame([3, 1], [$files[0]['additions'], $files[0]['deletions']]);
    }

    public function test_binary_file_reports_absence_instead_of_a_fabricated_zero(): void
    {
        $output = <<<'GIT'
        :000000 100644 0000000 dc35e6c A	App/Assets/icon.png
        -	-	App/Assets/icon.png
        GIT;

        $files = (new AtlasCodeProvenanceService())->parseFileChanges($output);

        self::assertSame('added', $files[0]['status']);
        // Binário não tem linhas: ausência é dita, nunca preenchida com 0.
        self::assertNull($files[0]['additions']);
        self::assertNull($files[0]['deletions']);
    }

    public function test_empty_diff_is_an_empty_list_not_an_invention(): void
    {
        self::assertSame([], (new AtlasCodeProvenanceService())->parseFileChanges(''));
    }

    public function test_misaligned_blocks_drop_the_numbers_and_keep_the_files(): void
    {
        // Se os blocos discordam, o Atlas prefere não ter número a ter número
        // errado — o arquivo continua verdadeiro.
        $output = <<<'GIT'
        :100644 100644 aaa bbb M	a.swift
        :100644 100644 ccc ddd M	b.swift
        4	2	a.swift
        GIT;

        $files = (new AtlasCodeProvenanceService())->parseFileChanges($output);

        self::assertCount(2, $files);
        self::assertNull($files[0]['additions']);
        self::assertNull($files[1]['additions']);
        self::assertSame(['a.swift', 'b.swift'], array_column($files, 'path'));
    }
}
