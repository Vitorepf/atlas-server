<?php

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasOperation;
use App\Models\AtlasRequirement;
use App\Models\AtlasSddDriftReport;
use App\Models\AtlasSddLearningProposal;
use App\Models\AtlasSpec;
use App\Models\AtlasSpecTraceability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only REST surface for the SDD pipeline records.
 *
 * Mirrors the read-only MCP resource list defined in
 * agents-and-mcp-contract.md:150-162. Surface exposes specs, requirements,
 * traceability, decision receipts, drift reports and learning proposals.
 */
class AtlasSddController extends Controller
{
    public function operations(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $items = AtlasOperation::query()->latest('created_at')->limit($limit)->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_operations_index.v1',
            'count' => $items->count(),
            'operations' => $items->map(fn (AtlasOperation $o): array => [
                'id' => $o->id,
                'status' => $o->status,
                'domain' => $o->domain,
                'risk_level' => $o->risk_level,
                'confidence_class' => $o->confidence_class,
                'raw_input' => $o->raw_input,
                'interpreted_intent' => $o->interpreted_intent,
                'created_at' => $o->created_at?->toJSON(),
            ])->all(),
        ]);
    }

    public function specs(Request $request): JsonResponse
    {
        $query = AtlasSpec::query()->latest('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $items = $query->limit((int) min(100, max(1, (int) $request->query('limit', 30))))->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_specs_index.v1',
            'count' => $items->count(),
            'specs' => $items->map(fn (AtlasSpec $s): array => [
                'id' => $s->id, 'title' => $s->title, 'type' => $s->type,
                'status' => $s->status, 'version' => $s->version,
                'risk_level' => $s->risk_level, 'content_hash' => $s->content_hash,
                'operation_id' => $s->operation_id, 'created_at' => $s->created_at?->toJSON(),
            ])->all(),
        ]);
    }

    public function showSpec(string $id): JsonResponse
    {
        $spec = AtlasSpec::query()
            ->with(['requirements.acceptanceCriteria', 'assumptions', 'plans'])
            ->find($id);
        if ($spec === null) {
            return response()->json(['error' => 'spec_not_found'], 404);
        }

        return response()->json([
            'schema_version' => 'atlas.sdd_spec.v1',
            'spec' => [
                'id' => $spec->id, 'title' => $spec->title, 'type' => $spec->type,
                'status' => $spec->status, 'version' => $spec->version,
                'risk_level' => $spec->risk_level, 'content_hash' => $spec->content_hash,
                'content' => $spec->content_json,
            ],
            'requirements' => $spec->requirements->map(fn (AtlasRequirement $r): array => [
                'id' => $r->id, 'code' => $r->code, 'text' => $r->text,
                'priority' => $r->priority, 'status' => $r->status,
                'acceptance_criteria' => $r->acceptanceCriteria->map(fn ($a) => [
                    'id' => $a->id, 'code' => $a->code,
                    'given' => $a->given, 'when' => $a->when, 'then' => $a->then,
                ])->all(),
            ])->all(),
            'assumptions' => $spec->assumptions->map(fn ($a) => [
                'id' => $a->id, 'text' => $a->text,
                'confidence_class' => $a->confidence_class,
                'blocking' => (bool) $a->blocking,
                'resolved_status' => $a->resolved_status,
            ])->all(),
            'plans' => $spec->plans->map(fn ($p) => [
                'id' => $p->id, 'status' => $p->status,
                'target_files' => $p->target_files_json,
                'forbidden_files' => $p->forbidden_files_json,
                'content_hash' => $p->content_hash,
            ])->all(),
        ]);
    }

    public function decisionReceipts(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query('limit', 30)));
        $items = AtlasDecisionReceipt::query()->latest('signed_at')->limit($limit)->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_decision_receipts_index.v1',
            'count' => $items->count(),
            'receipts' => $items->map(fn (AtlasDecisionReceipt $r): array => [
                'receipt_id' => $r->receipt_id,
                'operation_id' => $r->operation_id,
                'spec_id' => $r->spec_id,
                'plan_id' => $r->plan_id,
                'autonomy_level' => $r->autonomy_level,
                'allowed_actions' => $r->allowed_actions_json,
                'forbidden_actions' => $r->forbidden_actions_json,
                'required_gates' => $r->required_gates_json,
                'signed_at' => $r->signed_at?->toJSON(),
                'expires_at' => $r->expires_at?->toJSON(),
                'revoked_at' => $r->revoked_at?->toJSON(),
                'is_active' => $r->isActive(),
            ])->all(),
        ]);
    }

    public function showDecisionReceipt(string $receiptId): JsonResponse
    {
        $r = AtlasDecisionReceipt::query()->where('receipt_id', $receiptId)->first();
        if ($r === null) {
            return response()->json(['error' => 'receipt_not_found'], 404);
        }

        return response()->json([
            'schema_version' => 'atlas.sdd_decision_receipt.v1',
            'receipt_id' => $r->receipt_id,
            'operation_id' => $r->operation_id,
            'spec_id' => $r->spec_id,
            'plan_id' => $r->plan_id,
            'autonomy_level' => $r->autonomy_level,
            'allowed_actions' => $r->allowed_actions_json,
            'forbidden_actions' => $r->forbidden_actions_json,
            'allowed_files' => $r->allowed_files_json,
            'forbidden_files' => $r->forbidden_files_json,
            'required_gates' => $r->required_gates_json,
            'task_ids' => $r->task_ids_json,
            'context_pack_refs' => $r->context_pack_refs_json,
            'signature' => $r->signature,
            'input_hash' => $r->input_hash,
            'output_hash' => $r->output_hash,
            'signed_at' => $r->signed_at?->toJSON(),
            'expires_at' => $r->expires_at?->toJSON(),
            'is_active' => $r->isActive(),
        ]);
    }

    public function traceability(string $specId): JsonResponse
    {
        $links = AtlasSpecTraceability::query()->where('spec_id', $specId)->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_spec_traceability.v1',
            'spec_id' => $specId,
            'count' => $links->count(),
            'links' => $links->map(fn ($l) => [
                'id' => $l->id,
                'requirement_id' => $l->requirement_id,
                'acceptance_criteria_id' => $l->acceptance_criteria_id,
                'task_id' => $l->task_id,
                'file_path' => $l->file_path,
                'test_path' => $l->test_path,
                'evidence_event_id' => $l->evidence_event_id,
                'link_type' => $l->link_type,
                'confidence' => $l->confidence,
            ])->all(),
        ]);
    }

    public function driftReports(Request $request): JsonResponse
    {
        $query = AtlasSddDriftReport::query()->latest('created_at');
        if ($specId = $request->query('spec_id')) {
            $query->where('spec_id', $specId);
        }
        $items = $query->limit((int) min(100, max(1, (int) $request->query('limit', 30))))->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_drift_reports_index.v1',
            'count' => $items->count(),
            'drift_reports' => $items->map(fn ($d) => [
                'id' => $d->id, 'spec_id' => $d->spec_id,
                'status' => $d->status,
                'finding_count' => count((array) $d->drift_findings_json),
                'created_at' => $d->created_at?->toJSON(),
            ])->all(),
        ]);
    }

    public function learningProposals(Request $request): JsonResponse
    {
        $query = AtlasSddLearningProposal::query()->latest('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $items = $query->limit((int) min(100, max(1, (int) $request->query('limit', 30))))->get();

        return response()->json([
            'schema_version' => 'atlas.sdd_learning_proposals_index.v1',
            'count' => $items->count(),
            'learning_proposals' => $items->map(fn ($p) => [
                'id' => $p->id, 'proposal_type' => $p->proposal_type,
                'summary' => $p->summary, 'status' => $p->status,
                'created_at' => $p->created_at?->toJSON(),
            ])->all(),
        'route_decision' => \App\Services\Ai\DualCore\CanonicalRouteDecisionEnvelope::emit(route: 'programming', reason: 'http_atlas_sdd_controller'),
    ]);
    }
}
