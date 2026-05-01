<?php

namespace App\Support;

class TerminalMarkdownRenderer
{
    private const CANONICAL_SECTIONS = [
        'plano',
        'executando',
        'execucao',
        'execução',
        'resultado',
        'resultados',
        'risco',
        'riscos',
        'proximo passo',
        'próximo passo',
        'proximos passos',
        'próximos passos',
        'objetivo',
        'verificacao',
        'verificação',
        'contexto',
        'decisao',
        'decisão',
    ];

    public function render(string $markdown, bool $decorated = true, bool $compact = false): string
    {
        $markdown = str_replace("\r\n", "\n", str_replace("\r", "\n", $markdown));
        $lines = explode("\n", $markdown);
        $rendered = [];
        $inCode = false;
        $codeLabel = '';
        $codeBuffer = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $line = $lines[$i];

            if (preg_match('/^\s*```([^`]*)\s*$/', $line, $match) === 1) {
                if (! $inCode) {
                    $inCode = true;
                    $codeLabel = trim((string) ($match[1] ?? ''));
                    $codeBuffer = [];
                } else {
                    foreach ($this->renderCodeBlock($codeBuffer, $codeLabel, $decorated, $compact) as $codeLine) {
                        $rendered[] = $codeLine;
                    }
                    $inCode = false;
                    $codeLabel = '';
                    $codeBuffer = [];
                }
                $i++;
                continue;
            }

            if ($inCode) {
                $codeBuffer[] = $line;
                $i++;
                continue;
            }

            if ($this->looksLikeTableRow($line)
                && $i + 1 < $count
                && $this->looksLikeTableSeparator($lines[$i + 1])
            ) {
                [$consumed, $tableLines] = $this->renderTable($lines, $i, $decorated);
                foreach ($tableLines as $tableLine) {
                    $rendered[] = $tableLine;
                }
                $i += $consumed;
                continue;
            }

            if (preg_match('/^\s{0,3}(#{1,6})\s+(.+)$/', $line, $match) === 1) {
                $level = strlen((string) $match[1]);
                $body = trim((string) $match[2]);
                foreach ($this->renderHeader($level, $body, $decorated) as $headerLine) {
                    $rendered[] = $headerLine;
                }
                $i++;
                continue;
            }

            if (preg_match('/^\s{0,3}>\s?(.*)$/', $line, $match) === 1) {
                $body = $this->renderInline((string) $match[1], $decorated);
                $rendered[] = $decorated ? $this->ansi('90', '| ').$body : '| '.$body;
                $i++;
                continue;
            }

            if (preg_match('/^(\s*)(\d+)\.\s+(.+)$/', $line, $match) === 1) {
                $prefix = (string) $match[1];
                $marker = (string) $match[2].'.';
                $body = $this->renderInline((string) $match[3], $decorated);
                $rendered[] = $prefix.($decorated ? $this->ansi('36', $marker) : $marker).' '.$body;
                $i++;
                continue;
            }

            if (preg_match('/^(\s*)[-*]\s+(.+)$/', $line, $match) === 1) {
                $prefix = (string) $match[1];
                $bullet = $decorated ? $this->ansi('36', '-') : '-';
                $rendered[] = $prefix.$bullet.' '.$this->renderInline((string) $match[2], $decorated);
                $i++;
                continue;
            }

            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $rule = str_repeat('-', 56);
                $rendered[] = $decorated ? $this->ansi('90', $rule) : $rule;
                $i++;
                continue;
            }

            $rendered[] = $this->renderInline($line, $decorated);
            $i++;
        }

        if ($inCode && $codeBuffer !== []) {
            foreach ($this->renderCodeBlock($codeBuffer, $codeLabel, $decorated, $compact) as $codeLine) {
                $rendered[] = $codeLine;
            }
        }

        return implode("\n", $rendered);
    }

    /**
     * @return list<string>
     */
    private function renderHeader(int $level, string $body, bool $decorated): array
    {
        $lookup = $this->normalizeSectionLabel($body);

        if (in_array($lookup, self::CANONICAL_SECTIONS, true)) {
            $label = $lookup;
            $rule = str_repeat('-', max(2, mb_strlen($label, 'UTF-8') + 4));
            if ($decorated) {
                return [
                    '',
                    $this->ansi('1;36', $label),
                    $this->ansi('90', $rule),
                ];
            }

            return ['', $label, $rule];
        }

        $inline = $this->renderInline($body, $decorated);
        if ($decorated) {
            $style = $level <= 2 ? '1;36' : '1';

            return [$this->ansi($style, $inline)];
        }

        return [$inline];
    }

    private function normalizeSectionLabel(string $body): string
    {
        $stripped = preg_replace('/[\*_`]/u', '', $body) ?? $body;
        $stripped = trim((string) $stripped);
        $stripped = rtrim($stripped, ':');
        $stripped = trim($stripped);

        return mb_strtolower($stripped, 'UTF-8');
    }

    /**
     * @param  list<string>  $codeLines
     * @return list<string>
     */
    private function renderCodeBlock(array $codeLines, string $label, bool $decorated, bool $compact): array
    {
        if ($compact) {
            $count = count($codeLines);
            $name = $label !== '' ? $label : 'codigo';
            $word = $count === 1 ? 'linha oculta' : 'linhas ocultas';
            $summary = '['.$name.' - '.$count.' '.$word.']';

            return [$decorated ? $this->ansi('2;3', $summary) : $summary];
        }

        $output = [];
        $labelText = $label !== '' ? ' '.$label.' ' : ' code ';
        $output[] = $decorated
            ? $this->ansi('90', str_repeat('-', 3).$labelText.str_repeat('-', 42))
            : str_repeat('-', 3).$labelText.str_repeat('-', 42);

        foreach ($codeLines as $codeLine) {
            $output[] = $decorated ? $this->ansi('90', '  '.$codeLine) : '  '.$codeLine;
        }

        return $output;
    }

    private function looksLikeTableRow(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] !== '|') {
            return false;
        }

        return substr_count($trimmed, '|') >= 2;
    }

    private function looksLikeTableSeparator(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] !== '|') {
            return false;
        }

        return preg_match('/^\|\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $trimmed) === 1;
    }

    /**
     * @param  list<string>  $lines
     * @return array{0:int,1:list<string>}
     */
    private function renderTable(array $lines, int $start, bool $decorated): array
    {
        $headers = $this->splitTableRow($lines[$start]);
        $alignments = $this->parseTableAlignments($lines[$start + 1]);
        $colCount = count($headers);

        $rows = [];
        $i = $start + 2;
        while ($i < count($lines) && $this->looksLikeTableRow($lines[$i])) {
            $rows[] = $this->splitTableRow($lines[$i]);
            $i++;
        }

        $widths = [];
        for ($c = 0; $c < $colCount; $c++) {
            $widths[$c] = mb_strlen((string) ($headers[$c] ?? ''), 'UTF-8');
        }
        foreach ($rows as $row) {
            for ($c = 0; $c < $colCount; $c++) {
                $cell = (string) ($row[$c] ?? '');
                $width = mb_strlen($cell, 'UTF-8');
                if ($width > ($widths[$c] ?? 0)) {
                    $widths[$c] = $width;
                }
            }
        }

        $output = [];
        $output[] = $this->renderTableRow($headers, $widths, $alignments, $decorated, true);
        $separator = '  '.implode('  ', array_map(fn (int $w): string => str_repeat('-', max(1, $w)), $widths));
        $output[] = $decorated ? $this->ansi('90', $separator) : $separator;
        foreach ($rows as $row) {
            $output[] = $this->renderTableRow($row, $widths, $alignments, $decorated, false);
        }

        return [$i - $start, $output];
    }

    /**
     * @return list<string>
     */
    private function splitTableRow(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = (string) preg_replace('/^\|/', '', $trimmed);
        $trimmed = (string) preg_replace('/\|$/', '', $trimmed);
        $cells = explode('|', $trimmed);

        return array_map(fn (string $cell): string => trim($cell), $cells);
    }

    /**
     * @return list<string>
     */
    private function parseTableAlignments(string $separatorLine): array
    {
        $cells = $this->splitTableRow($separatorLine);

        return array_map(function (string $cell): string {
            $left = str_starts_with($cell, ':');
            $right = str_ends_with($cell, ':');
            if ($left && $right) {
                return 'center';
            }
            if ($right) {
                return 'right';
            }

            return 'left';
        }, $cells);
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int,int>  $widths
     * @param  list<string>  $alignments
     */
    private function renderTableRow(array $cells, array $widths, array $alignments, bool $decorated, bool $isHeader): string
    {
        $padded = [];
        foreach ($widths as $c => $w) {
            $cell = (string) ($cells[$c] ?? '');
            $align = $alignments[$c] ?? 'left';
            $padded[] = $this->padCell($cell, $w, $align);
        }
        $row = '  '.implode('  ', $padded);
        if ($isHeader && $decorated) {
            return $this->ansi('1', $row);
        }

        return $row;
    }

    private function padCell(string $value, int $width, string $align): string
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length >= $width) {
            return $value;
        }
        $pad = $width - $length;
        if ($align === 'right') {
            return str_repeat(' ', $pad).$value;
        }
        if ($align === 'center') {
            $left = intdiv($pad, 2);
            $right = $pad - $left;

            return str_repeat(' ', $left).$value.str_repeat(' ', $right);
        }

        return $value.str_repeat(' ', $pad);
    }

    private function renderInline(string $line, bool $decorated): string
    {
        $codePlaceholders = [];
        $line = preg_replace_callback('/`([^`\n]+)`/', function (array $match) use (&$codePlaceholders, $decorated): string {
            $key = "\0CODE".count($codePlaceholders)."\0";
            $codePlaceholders[$key] = $decorated
                ? $this->ansi('36', (string) $match[1])
                : (string) $match[1];

            return $key;
        }, $line) ?? $line;

        $line = preg_replace_callback('/\*\*([^*\n]+)\*\*/', function (array $match) use ($decorated): string {
            return $decorated ? $this->ansi('1', (string) $match[1]) : (string) $match[1];
        }, $line) ?? $line;

        $line = preg_replace_callback('/__([^_\n]+)__/', function (array $match) use ($decorated): string {
            return $decorated ? $this->ansi('1', (string) $match[1]) : (string) $match[1];
        }, $line) ?? $line;

        $line = preg_replace_callback('/(?<![\*\w])\*([^\s\*][^\*\n]*?[^\s\*]|[^\s\*])\*(?!\*)/', function (array $match) use ($decorated): string {
            return $decorated ? $this->ansi('3', (string) $match[1]) : (string) $match[1];
        }, $line) ?? $line;

        foreach ($codePlaceholders as $key => $value) {
            $line = str_replace($key, $value, $line);
        }

        return $line;
    }

    private function ansi(string $code, string $text): string
    {
        return "\033[".$code.'m'.$text."\033[0m";
    }
}
