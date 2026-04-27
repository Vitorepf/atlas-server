-- ============================================================
--  Schema Fundacional v1.0 - Atlas 
-- Baseado no Documento Mestre v3.0, Lei 6 (Dataset Sagrado)
-- ============================================================

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "vector";

CREATE TABLE operators (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  name TEXT NOT NULL,
  email TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO operators (name, email) VALUES ('Vitor', 'vitordsny@gmail.com');

CREATE TABLE notes (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  type TEXT NOT NULL,
  title TEXT NOT NULL,
  frontmatter JSONB NOT NULL DEFAULT '{}',
  area TEXT,
  status TEXT DEFAULT 'pending',
  file_path TEXT,
  content_preview TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  embedding VECTOR(1024),
  schema_version INT NOT NULL DEFAULT 1
);

CREATE INDEX idx_notes_operator ON notes(operator_id);
CREATE INDEX idx_notes_type ON notes(type);
CREATE INDEX idx_notes_area ON notes(area);
CREATE INDEX idx_notes_status ON notes(status);
CREATE INDEX idx_notes_created ON notes(created_at DESC);

CREATE TABLE note_links (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  source_id UUID REFERENCES notes(id) NOT NULL,
  target_id UUID REFERENCES notes(id) NOT NULL,
  link_type TEXT DEFAULT 'reference',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE(source_id, target_id, link_type)
);

CREATE TABLE timeline_events (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  source TEXT NOT NULL,
  entity_type TEXT,
  entity_id UUID,
  payload JSONB NOT NULL DEFAULT '{}',
  schema_version INT NOT NULL DEFAULT 1
);

CREATE INDEX idx_timeline_operator ON timeline_events(operator_id);
CREATE INDEX idx_timeline_ts ON timeline_events(ts DESC);
CREATE INDEX idx_timeline_source ON timeline_events(source);

CREATE TABLE metrics (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  source TEXT NOT NULL,
  metric_name TEXT NOT NULL,
  value_numeric DOUBLE PRECISION,
  value_text TEXT,
  unit TEXT,
  raw_payload JSONB,
  schema_version INT NOT NULL DEFAULT 1
);

CREATE INDEX idx_metrics_operator ON metrics(operator_id);
CREATE INDEX idx_metrics_ts ON metrics(ts DESC);
CREATE INDEX idx_metrics_name ON metrics(metric_name);
CREATE INDEX idx_metrics_source ON metrics(source);

CREATE TABLE cognitive_state (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  state TEXT NOT NULL,
  energy INT CHECK (energy >= 1 AND energy <= 5),
  source TEXT DEFAULT 'manual_checkin'
);

CREATE INDEX idx_cognitive_operator ON cognitive_state(operator_id);
CREATE INDEX idx_cognitive_ts ON cognitive_state(ts DESC);

CREATE TABLE system_cost (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  operation TEXT NOT NULL,
  duration_seconds INT,
  cognitive_load TEXT,
  outcome TEXT,
  perceived_value INT CHECK (perceived_value >= 1 AND perceived_value <= 5)
);

CREATE INDEX idx_cost_operator ON system_cost(operator_id);
CREATE INDEX idx_cost_ts ON system_cost(ts DESC);

CREATE TABLE insight_triggers (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  name TEXT NOT NULL UNIQUE,
  description TEXT,
  query_template TEXT NOT NULL,
  frequency TEXT DEFAULT 'daily',
  active BOOLEAN DEFAULT true,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE insights (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  trigger_id UUID REFERENCES insight_triggers(id),
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  status TEXT DEFAULT 'pending',
  reviewed_at TIMESTAMPTZ,
  action_taken TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_insights_operator ON insights(operator_id);
CREATE INDEX idx_insights_status ON insights(status);

CREATE TABLE ai_interactions (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  interaction_type TEXT NOT NULL,
  model TEXT,
  input_summary TEXT,
  output_summary TEXT,
  operator_feedback TEXT,
  tokens_used INT
);

CREATE TABLE sop_executions (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  sop_note_id UUID REFERENCES notes(id) NOT NULL,
  ts TIMESTAMPTZ NOT NULL DEFAULT now(),
  outcome TEXT,
  duration_seconds INT,
  notes TEXT
);

CREATE TABLE council_runs (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  council_type TEXT NOT NULL,
  decision_title TEXT NOT NULL,
  round1_output JSONB,
  round2_output JSONB,
  round3_output JSONB,
  final_recommendation TEXT,
  operator_decision TEXT,
  review_90d_date DATE,
  review_90d_outcome TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_council_operator ON council_runs(operator_id);
CREATE INDEX idx_council_review ON council_runs(review_90d_date);

CREATE TABLE experiments (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  operator_id UUID REFERENCES operators(id) NOT NULL,
  source_note_id UUID REFERENCES notes(id),
  hypothesis TEXT NOT NULL,
  method TEXT,
  success_criteria TEXT,
  scheduled_date DATE NOT NULL,
  status TEXT DEFAULT 'scheduled',
  result TEXT,
  review_30d_date DATE,
  review_90d_date DATE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE schema_migrations (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  version INT UNIQUE NOT NULL,
  description TEXT,
  applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO schema_migrations (version, description) VALUES (1, 'Fundacional v1.0 - Atlas V1');
