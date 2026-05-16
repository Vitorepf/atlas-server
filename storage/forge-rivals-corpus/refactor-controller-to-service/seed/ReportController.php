<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * ReportController concentra:
 *   - decodificação do request,
 *   - agregação dos dados (open count, closed count, top status, totais por status),
 *   - serialização da resposta.
 *
 * O arm precisa extrair a agregação para `App\Services\Report\ReportService` e
 * deixar este controller thin (≤ 25 linhas em index()).
 *
 * Contract local do test: index recebe array<string,int> com counts por status
 * e devolve array com keys 'open', 'closed', 'top_status', 'totals'.
 *
 * @phpstan-type Counts array<string,int>
 * @phpstan-type Summary array{open:int, closed:int, top_status:string, totals:Counts}
 */
final class ReportController
{
    /**
     * @param  Counts  $request
     * @return Summary
     */
    public function index(array $request): array
    {
        $open = (int) ($request['open'] ?? 0);
        $closed = (int) ($request['closed'] ?? 0);

        $totals = [
            'open' => $open,
            'closed' => $closed,
        ];

        $topStatus = 'tie';
        if ($open > $closed) {
            $topStatus = 'open';
        } elseif ($closed > $open) {
            $topStatus = 'closed';
        }

        return [
            'open' => $open,
            'closed' => $closed,
            'top_status' => $topStatus,
            'totals' => $totals,
        ];
    }
}
