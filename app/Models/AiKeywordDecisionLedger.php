<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AiKeywordDecisionLedger (L12 — governança durável) — a persistência append-style do Decision-Receipt por
 * keyword. Em escala industrial (milhões de keywords) o ledger em memória não basta: a proveniência por
 * decisão precisa ser CONSULTÁVEL e AUDITÁVEL no tempo. Chave de idempotência = receipt_hash (mesma decisão
 * determinística → 1 linha, nunca duplica).
 */
class AiKeywordDecisionLedger extends Model
{
    use HasUuids;

    protected $table = 'ai_keyword_decision_ledger';

    protected $fillable = [
        'receipt_hash', 'run_hash', 'keyword', 'score', 'band', 'family',
        'intent_tier', 'investment_verdict', 'investment_basis', 'account_risk',
        'core_version', 'receipt',
    ];

    protected $casts = [
        'receipt' => 'array',
        'score' => 'integer',
    ];
}
