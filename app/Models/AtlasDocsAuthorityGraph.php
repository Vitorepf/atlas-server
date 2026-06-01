<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * R1 owner-doc resolver read-model row. See the migration and
 * AtlasDocsAuthorityGraphService for how rows are built and queried.
 *
 * @property string $needle_kind
 * @property string $needle
 * @property string $needle_normalized
 * @property string $owner_doc_path
 * @property string|null $owner_doc_id
 * @property string $owner_basis
 * @property int $confidence
 * @property string|null $owner_implementation_state
 */
class AtlasDocsAuthorityGraph extends Model
{
    protected $table = 'atlas_docs_authority_graph';

    protected $fillable = [
        'needle_kind',
        'needle',
        'needle_normalized',
        'owner_doc_path',
        'owner_doc_id',
        'owner_basis',
        'confidence',
        'owner_implementation_state',
    ];

    protected $casts = [
        'confidence' => 'integer',
    ];
}
