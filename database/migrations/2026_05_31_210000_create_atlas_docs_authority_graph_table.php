<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1 (atlas:docs:locate) — the owner-doc resolver read-model. One row per
 * resolvable needle (a doc id, a governed capability, a frontmatter capability)
 * pointing at the canonical owner doc, with the basis and confidence of the
 * mapping. Rebuilt from doc frontmatter (and, later, code-intel doc-link
 * density) so any AI can resolve "where does X live / where should X go" in one
 * deterministic query instead of scanning 897 docs.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-documentation-as-law-proposal.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atlas_docs_authority_graph', function (Blueprint $table): void {
            $table->id();
            $table->string('needle_kind', 40)->index();          // doc_id | governs | capability
            $table->string('needle', 300);
            $table->string('needle_normalized', 300)->index();   // lowercased, for matching
            $table->string('owner_doc_path', 500)->index();
            $table->string('owner_doc_id', 200)->nullable()->index();
            $table->string('owner_basis', 40)->index();          // doc_id | governs_frontmatter | capability_frontmatter | keyword_fallback
            $table->unsignedSmallInteger('confidence')->default(0)->index();
            $table->string('owner_implementation_state', 60)->nullable();
            $table->timestamps();

            $table->unique(
                ['needle_normalized', 'owner_doc_path', 'owner_basis'],
                'docs_authority_needle_owner_basis_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_docs_authority_graph');
    }
};
