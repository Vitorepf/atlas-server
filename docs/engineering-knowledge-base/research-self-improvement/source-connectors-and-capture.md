---
id: atlas-ai-research-source-connectors-capture
type: engineering_knowledge
title: Atlas AI Research Source Connectors And Capture
status: active
category: research
priority: 99
summary: Source connector map and capture requirements for docs, GitHub, papers, Hugging Face, YouTube, news and social signals.
tags:
  - atlas-ai
  - source-connectors
  - capture
  - research
capabilities:
  - source_registry
  - research_connectors
  - evidence_capture
decisions:
  - API-first capture is preferred over browser scraping when official APIs exist.
  - Social and community sources are discovery leads, not final proof.
  - Every connector must emit evidence objects and source judgments.
maintenance:
  - Update when connectors, allowlists, source registry or capture workers become executable.
related_paths:
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
---

# Atlas AI Research Source Connectors And Capture

## Source Classes

| Class | Examples | Authority |
|---|---|---|
| Official docs/changelogs | Provider docs, API docs, standards | Primary |
| GitHub official | Releases, tags, commits, PRs, issues, advisories | Primary/implementation |
| Papers | arXiv, OpenReview, ACL, PubMed, Crossref, OpenAlex | Primary/academic |
| Benchmarks | Repos, papers, eval suites, datasets | Primary when reproducible |
| Hugging Face | Model cards, dataset cards, commits, Spaces | Artifact source |
| YouTube | Official talks, conference demos, author videos | Secondary unless official |
| News | Reliable outlets with primary links | Secondary |
| Social/community | X, Reddit, HN, Discord, forums | Lead only |
| Internal Atlas | Code, docs, Evidence Ledger, tests, receipts | Tier 0 |

## Named Source Registry Seed

These are the initial source families Atlas should know how to classify before
any crawler or scheduler is enabled.

| Source family | Examples | What to do |
|---|---|---|
| Provider docs and cookbooks | OpenAI docs/cookbook, Anthropic docs/cookbook, Google Gemini docs/cookbooks, MCP spec | Treat as Tier 1. Capture docs, changelog, API shape, examples, safety notes and version/date. |
| Provider research/system cards | OpenAI safety/system cards, Anthropic engineering posts, Google Research/DeepMind posts | Treat as Tier 1 or 3 depending on whether it is official spec or engineering narrative. Extract claims, limitations and benchmark references. |
| GitHub official repos | provider SDKs, MCP, vLLM, SGLang, llama.cpp, Transformers, FlashAttention, Qdrant, Weaviate, Milvus, OpenSearch, LangGraph, LlamaIndex, Haystack | Treat as implementation evidence. Capture releases, tags, commits, PRs, issues, advisories, examples and benchmark scripts. |
| Academic indexes | arXiv, OpenReview, ACL Anthology, PubMed, PubMed Central, Semantic Scholar, OpenAlex, Crossref, bioRxiv, medRxiv | Treat as academic source. Capture IDs, versions, authors, venue, date, method, results, limitations, code/dataset and citation graph. |
| Benchmark repositories | BrowseComp, DeepResearch Bench, GAIA-like benchmarks, HELM, LM Evaluation Harness, RAG eval frameworks, Papers with Code | Treat as eval evidence. Capture task definition, dataset, metric, baseline, license, reproducibility and known limits. |
| Model/dataset hubs | Hugging Face Hub, model cards, dataset cards, Spaces | Treat as artifact evidence. Capture model/dataset card, license, config, files, commits, benchmark claims and safety notes. |
| Video sources | YouTube official channels, conference talks, author demos, webinars | Treat as secondary unless official. Capture transcript, timestamps, links, slides/screenshots and primary source references. |
| Web/news archives | official blogs, reliable news, Wayback/CDX snapshots, GDELT | Treat as secondary or change-detection evidence. Capture canonical URL, timestamp, hash, archive URL and primary links. |
| Social/community | X, Reddit, Hacker News, Discord, forums | Treat as lead only. Capture original post, timestamp, links cited and external confirmation status. |
| Fact-check/reference APIs | Google Fact Check Tools, official advisories, CVE/NVD, regulators, standards bodies | Treat as verification support. Capture claim, claimant, reviewer, date, rating and official reference. |

## Per-Source Action Matrix

| Source | Capture | Promote to |
|---|---|---|
| Official provider documentation | API behavior, parameters, limits, examples, release date | Provider envelope, docs/AP, adapter plan |
| Official changelog/release notes | changed capability, breaking change, date, version | Provider Evolution review and AP candidate |
| GitHub release/tag | tag, commit SHA, changelog, artifacts, breaking changes | Implementation candidate or benchmark task |
| GitHub PR/commit | diff summary, affected files, tests, maintainer context | Evidence only until release or official note |
| GitHub issue/discussion | bug reports, maintainer response, reproduction | Risk signal or research lead |
| Security advisory/CVE/NVD | CVE ID, severity, affected versions, mitigation | Critical alert and guarded AP |
| Paper | method, results, limitations, code, dataset, version | Architecture guidance or benchmark requirement |
| OpenReview/review venue | peer review, comments, decision, limitations | Confidence adjustment, not standalone truth |
| Benchmark repo | tasks, metrics, baseline, reproducibility | Eval harness candidate |
| Hugging Face model card | license, intended use, evals, safety, files | Model/artifact candidate, not performance truth alone |
| YouTube/conference talk | transcript, timestamps, demo claims, links | Secondary evidence or source discovery |
| News article | primary documents cited, date, corrections | Context only unless primary source linked |
| Social post/thread | lead, link, timestamp, author | Research task only |
| Wayback/archive | snapshot, timestamp, original URL | Citation health support |
| GDELT/news index | event spike, article set, time window | Change detection lead |
| Fact Check API | checked claim, rating, publisher, date | Verification support |

## Capture Requirements

GitHub:

- owner/repo;
- commit SHA or release tag;
- changed files;
- changelog/release notes;
- issues/PR/advisories referenced;
- maintainer activity;
- benchmark scripts or examples;
- timestamp and content hash.

Papers:

- title, authors, venue/source;
- DOI/arXiv/OpenReview ID;
- version and date;
- abstract, method, results, limitations;
- code/dataset link;
- baseline and benchmark claims;
- peer-review/retraction/correction status.

Docs/sites:

- canonical URL;
- title and organization;
- publication/modification date;
- raw HTML/text;
- screenshot when layout matters;
- content hash;
- archive URL when volatile;
- version label.

YouTube/video:

- video/channel ID;
- upload date;
- transcript/captions;
- timestamps;
- linked sources;
- speaker identity;
- screenshots of slides when needed;
- related primary source.

Social/community:

- original post/thread;
- author and timestamp;
- links cited;
- replies or community notes;
- external confirmation;
- lead status.

## Connector Rules

- Prefer official APIs over scraping.
- Store raw evidence before summarization.
- Normalize every capture into `atlas.evidence_source.v1`.
- Assign trust tier immediately.
- Enforce domain allowlist and rate limits.
- Never expose secrets to the model.
- Treat prompt injection in web/source content as hostile input.
