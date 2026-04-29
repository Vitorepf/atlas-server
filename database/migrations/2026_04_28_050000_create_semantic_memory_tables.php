<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                CREATE TABLE semantic_notes (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  note_key TEXT NOT NULL UNIQUE,
                  path TEXT NOT NULL UNIQUE,
                  title TEXT NOT NULL,
                  type TEXT NOT NULL CHECK (type IN (
                    'source_note',
                    'mental_model',
                    'principle',
                    'hypothesis',
                    'practice',
                    'synthesis',
                    'decision_identity',
                    'cognitive_game'
                  )),
                  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN (
                    'inbox',
                    'draft',
                    'active',
                    'testing',
                    'validated',
                    'archived',
                    'invalid'
                  )),
                  confidence TEXT NOT NULL DEFAULT 'low' CHECK (confidence IN (
                    'low',
                    'medium',
                    'high',
                    'validated'
                  )),
                  maturity TEXT NOT NULL DEFAULT 'draft' CHECK (maturity IN (
                    'seed',
                    'draft',
                    'useful',
                    'tested',
                    'principle',
                    'archived'
                  )),
                  domains JSONB NOT NULL DEFAULT '[]'::jsonb,
                  summary TEXT,
                  body_excerpt TEXT,
                  frontmatter JSONB NOT NULL DEFAULT '{}'::jsonb,
                  when_to_use JSONB NOT NULL DEFAULT '[]'::jsonb,
                  trigger_signals JSONB NOT NULL DEFAULT '[]'::jsonb,
                  do_not_use_when JSONB NOT NULL DEFAULT '[]'::jsonb,
                  postgres_refs JSONB NOT NULL DEFAULT '{}'::jsonb,
                  content_hash TEXT NOT NULL,
                  embedding VECTOR(1536),
                  indexed_at TIMESTAMPTZ,
                  last_seen_at TIMESTAMPTZ,
                  last_activated_at TIMESTAMPTZ,
                  last_practiced_at TIMESTAMPTZ,
                  activation_count INTEGER NOT NULL DEFAULT 0 CHECK (activation_count >= 0),
                  usefulness_avg NUMERIC(4, 3) CHECK (usefulness_avg IS NULL OR usefulness_avg BETWEEN 0 AND 5),
                  validation_errors JSONB NOT NULL DEFAULT '[]'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  deleted_at TIMESTAMPTZ
                );
            SQL);

            DB::statement('CREATE INDEX idx_semantic_notes_type_status ON semantic_notes(type, status) WHERE deleted_at IS NULL;');
            DB::statement('CREATE INDEX idx_semantic_notes_updated_at ON semantic_notes(updated_at DESC);');
            DB::statement('CREATE INDEX idx_semantic_notes_last_activated ON semantic_notes(last_activated_at DESC NULLS LAST);');
            DB::statement('CREATE INDEX idx_semantic_notes_frontmatter_gin ON semantic_notes USING GIN(frontmatter);');
            DB::statement('CREATE INDEX idx_semantic_notes_domains_gin ON semantic_notes USING GIN(domains);');
            DB::statement('CREATE INDEX idx_semantic_notes_embedding ON semantic_notes USING ivfflat (embedding vector_cosine_ops);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_semantic_notes_updated_at
                BEFORE UPDATE ON semantic_notes
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE semantic_note_links (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  source_note_id UUID NOT NULL REFERENCES semantic_notes(id) ON DELETE CASCADE,
                  target_note_id UUID NOT NULL REFERENCES semantic_notes(id) ON DELETE CASCADE,
                  link_type TEXT NOT NULL CHECK (link_type IN (
                    'supports',
                    'contradicts',
                    'extends',
                    'example_of',
                    'applies_to',
                    'derived_from',
                    'similar_to',
                    'tension'
                  )),
                  explanation TEXT NOT NULL,
                  created_by TEXT NOT NULL DEFAULT 'operator' CHECK (created_by IN (
                    'operator',
                    'atlas_suggestion',
                    'import'
                  )),
                  confidence NUMERIC(4, 3) CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
                  confirmed_by_operator BOOLEAN NOT NULL DEFAULT FALSE,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_semantic_note_links_source ON semantic_note_links(source_note_id);');
            DB::statement('CREATE INDEX idx_semantic_note_links_target ON semantic_note_links(target_note_id);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_semantic_note_links_updated_at
                BEFORE UPDATE ON semantic_note_links
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE semantic_curation_proposals (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  source_type TEXT NOT NULL CHECK (source_type IN (
                    'capture',
                    'transcription',
                    'behavior_pattern',
                    'health_pattern',
                    'rize_pattern',
                    'manual'
                  )),
                  source_refs JSONB NOT NULL DEFAULT '{}'::jsonb,
                  proposed_note_type TEXT NOT NULL,
                  proposed_title TEXT NOT NULL,
                  proposed_summary TEXT NOT NULL,
                  proposed_path TEXT,
                  proposed_frontmatter JSONB NOT NULL DEFAULT '{}'::jsonb,
                  proposed_body TEXT,
                  score NUMERIC(4, 3) CHECK (score IS NULL OR score BETWEEN 0 AND 1),
                  reason TEXT NOT NULL,
                  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN (
                    'pending',
                    'accepted',
                    'edited',
                    'dismissed',
                    'postponed'
                  )),
                  shown_at TIMESTAMPTZ,
                  resolved_at TIMESTAMPTZ,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_semantic_curation_status ON semantic_curation_proposals(status, created_at DESC);');
            DB::statement('CREATE INDEX idx_semantic_curation_source_refs ON semantic_curation_proposals USING GIN(source_refs);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_semantic_curation_proposals_updated_at
                BEFORE UPDATE ON semantic_curation_proposals
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE semantic_note_activations (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  note_id UUID NOT NULL REFERENCES semantic_notes(id) ON DELETE CASCADE,
                  activation_type TEXT NOT NULL CHECK (activation_type IN (
                    'remember',
                    'practice',
                    'connect',
                    'confront',
                    'test',
                    'promote',
                    'archive_review'
                  )),
                  context_type TEXT NOT NULL CHECK (context_type IN (
                    'morning_briefing',
                    'capture_created',
                    'health_state',
                    'rize_context',
                    'weekly_review',
                    'manual_search',
                    'cognitive_game',
                    'notification_candidate'
                  )),
                  context_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  prompt TEXT NOT NULL,
                  shown_at TIMESTAMPTZ,
                  acted_at TIMESTAMPTZ,
                  dismissed_at TIMESTAMPTZ,
                  usefulness_score SMALLINT CHECK (usefulness_score IS NULL OR usefulness_score BETWEEN 1 AND 5),
                  operator_feedback TEXT,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_semantic_activations_note ON semantic_note_activations(note_id, created_at DESC);');
            DB::statement('CREATE INDEX idx_semantic_activations_context ON semantic_note_activations(context_type, created_at DESC);');
            DB::statement('CREATE INDEX idx_semantic_activations_pending ON semantic_note_activations(created_at DESC) WHERE shown_at IS NULL AND dismissed_at IS NULL;');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_semantic_note_activations_updated_at
                BEFORE UPDATE ON semantic_note_activations
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE semantic_hypothesis_tests (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  note_id UUID NOT NULL REFERENCES semantic_notes(id) ON DELETE CASCADE,
                  hypothesis TEXT NOT NULL,
                  metric_targets JSONB NOT NULL DEFAULT '[]'::jsonb,
                  required_sources JSONB NOT NULL DEFAULT '[]'::jsonb,
                  started_at TIMESTAMPTZ,
                  ended_at TIMESTAMPTZ,
                  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN (
                    'draft',
                    'running',
                    'completed',
                    'inconclusive',
                    'cancelled'
                  )),
                  result_summary TEXT,
                  result_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
                  decision TEXT CHECK (decision IS NULL OR decision IN (
                    'promote',
                    'revise',
                    'archive',
                    'keep_testing'
                  )),
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_semantic_hypothesis_tests_note ON semantic_hypothesis_tests(note_id, created_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_semantic_hypothesis_tests_updated_at
                BEFORE UPDATE ON semantic_hypothesis_tests
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE cognitive_game_runs (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  game_key TEXT NOT NULL,
                  title TEXT NOT NULL,
                  input_note_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
                  prompt TEXT NOT NULL,
                  operator_answer TEXT,
                  atlas_feedback TEXT,
                  score NUMERIC(4, 3) CHECK (score IS NULL OR score BETWEEN 0 AND 1),
                  duration_seconds INTEGER CHECK (duration_seconds IS NULL OR duration_seconds >= 0),
                  promoted_note_id UUID REFERENCES semantic_notes(id) ON DELETE SET NULL,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_cognitive_game_runs_created ON cognitive_game_runs(created_at DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_cognitive_game_runs_updated_at
                BEFORE UPDATE ON cognitive_game_runs
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

            DB::statement(<<<'SQL'
                CREATE TABLE vault_health_snapshots (
                  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                  snapshot_date DATE NOT NULL UNIQUE,
                  total_notes INTEGER NOT NULL DEFAULT 0 CHECK (total_notes >= 0),
                  active_notes INTEGER NOT NULL DEFAULT 0 CHECK (active_notes >= 0),
                  inbox_notes INTEGER NOT NULL DEFAULT 0 CHECK (inbox_notes >= 0),
                  invalid_notes INTEGER NOT NULL DEFAULT 0 CHECK (invalid_notes >= 0),
                  stale_notes INTEGER NOT NULL DEFAULT 0 CHECK (stale_notes >= 0),
                  notes_without_triggers INTEGER NOT NULL DEFAULT 0 CHECK (notes_without_triggers >= 0),
                  notes_without_links INTEGER NOT NULL DEFAULT 0 CHECK (notes_without_links >= 0),
                  activations_7d INTEGER NOT NULL DEFAULT 0 CHECK (activations_7d >= 0),
                  useful_activations_7d INTEGER NOT NULL DEFAULT 0 CHECK (useful_activations_7d >= 0),
                  health_state TEXT NOT NULL CHECK (health_state IN (
                    'healthy',
                    'inflated',
                    'cold',
                    'anxious',
                    'mature',
                    'needs_attention'
                  )),
                  recommendations JSONB NOT NULL DEFAULT '[]'::jsonb,
                  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                );
            SQL);

            DB::statement('CREATE INDEX idx_vault_health_snapshots_date ON vault_health_snapshots(snapshot_date DESC);');
            DB::statement(<<<'SQL'
                CREATE TRIGGER trg_vault_health_snapshots_updated_at
                BEFORE UPDATE ON vault_health_snapshots
                FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('DROP TABLE IF EXISTS vault_health_snapshots;');
            DB::statement('DROP TABLE IF EXISTS cognitive_game_runs;');
            DB::statement('DROP TABLE IF EXISTS semantic_hypothesis_tests;');
            DB::statement('DROP TABLE IF EXISTS semantic_note_activations;');
            DB::statement('DROP TABLE IF EXISTS semantic_curation_proposals;');
            DB::statement('DROP TABLE IF EXISTS semantic_note_links;');
            DB::statement('DROP TABLE IF EXISTS semantic_notes;');
        });
    }
};
