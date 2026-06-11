<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Publishing\BlogEditorialPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AtlasBlogEditorialController extends Controller
{
    public function state(Request $request, BlogEditorialPlannerService $planner): JsonResponse
    {
        $data = $request->validate([
            'site' => ['nullable', 'string', 'max:1000'],
            'backlog' => ['nullable', 'string', 'max:500'],
            'with_context' => ['nullable', 'boolean'],
            'candidate_limit' => ['nullable', 'integer', 'min:1', 'max:30'],
            'graph_context_limit' => ['nullable', 'integer', 'min:1', 'max:12'],
            'graph_world_model_id' => ['nullable', 'string', 'max:120'],
            'include_writing_packet' => ['nullable', 'boolean'],
            'include_graph_context' => ['nullable', 'boolean'],
            'include_graph_candidates' => ['nullable', 'boolean'],
        ]);

        $siteRoot = is_string($data['site'] ?? null) && trim((string) $data['site']) !== ''
            ? trim((string) $data['site'])
            : dirname(base_path(), 2).'/vitorepf-site';
        $backlog = is_string($data['backlog'] ?? null) && trim((string) $data['backlog']) !== ''
            ? trim((string) $data['backlog'])
            : 'content/backlog/blog-first-month.yaml';

        $payload = $planner->plan($siteRoot, $backlog, [
            'with_context' => (bool) ($data['with_context'] ?? false),
            'source_map' => true,
            'review_queue' => true,
            'coverage_map' => true,
            'operations' => true,
            'operating_state' => true,
            'writing_packet' => (bool) ($data['include_writing_packet'] ?? true),
            'editorial_radar' => true,
            'graph_rag_readiness' => true,
            'suggest_candidates' => true,
            'candidate_limit' => (int) ($data['candidate_limit'] ?? 8),
            'editorial_graph_context' => (bool) ($data['include_graph_context'] ?? false),
            'editorial_graph_candidates' => (bool) ($data['include_graph_candidates'] ?? false),
            'graph_context_limit' => (int) ($data['graph_context_limit'] ?? 8),
            'graph_world_model_id' => $data['graph_world_model_id'] ?? null,
        ]);

        return response()->json([
            'schema_version' => 'atlas.blog_editorial_area_state_api.v1',
            'status' => ($payload['status'] ?? null) === 'failed' ? 'failed' : 'ready',
            'mode' => 'read_only_area_surface_p1',
            'area' => [
                'name' => 'blog_editorial_planning',
                'purpose' => 'Expose the public blog planning area to Atlas UI and agents without granting write, reorder or publish authority.',
                'surfaces' => [
                    'state' => '/blog/editorial/state',
                    'planner_command' => 'atlas:blog:editorial-plan --operating-state --json',
                    'writing_packet_command' => 'atlas:blog:editorial-plan --writing-packet --json',
                ],
                'guardrails' => [
                    'read_only' => true,
                    'writes_backlog' => false,
                    'publishes_content' => false,
                    'reorders_posts' => false,
                    'accepts_candidates' => false,
                    'promotes_candidates' => false,
                    'requires_human_approval_to_publish' => true,
                ],
            ],
            'planner' => $payload,
        ], ($payload['status'] ?? null) === 'failed' ? 422 : 200);
    }
}
