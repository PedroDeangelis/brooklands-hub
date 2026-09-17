<?php

namespace App\Models;

use App\DocumentAttachments\AttachmentParentType;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One file attached to a Business Central record.
 *
 * Stored as its own row rather than as a list on the parent, unlike ship-to
 * addresses. Two things make the difference: an attachment's parent may be one
 * of three different models, so there is no single column it could live in, and
 * it carries its own Business Central id that the website stores and matches
 * on, so it is a record in its own right rather than an anonymous entry.
 *
 * The file itself is not here. Only the name and the Business Central id are
 * kept; the bytes are streamed on demand through /bc-doc/{attachmentId}.
 */
#[Fillable([
    'bc_id',
    'parent_bc_id',
    'parent_type',
    'file_name',
    'bc_modified_at',
    'bc_payload',
])]
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory;

    /**
     * The attachments hanging off one parent record, in a stable order.
     *
     * @param  Builder<DocumentAttachment>  $query
     * @return Builder<DocumentAttachment>
     */
    #[Scope]
    protected function forParent(Builder $query, AttachmentParentType $type, string $parentBcId): Builder
    {
        return $query->where('parent_type', $type->value)
            ->where('parent_bc_id', $parentBcId);
    }

    /**
     * Which kind of record this file hangs off.
     *
     * Null only for a row stored before a parent type was withdrawn, which
     * nothing currently does; callers treat it as "cannot be placed".
     */
    public function parentType(): ?AttachmentParentType
    {
        return AttachmentParentType::tryFrom((string) $this->parent_type);
    }

    /**
     * The local record this file hangs off, or null when it has not arrived.
     *
     * Not a relation: the parent is one of three unrelated models chosen at
     * runtime, which Eloquent's morph machinery could express only by storing
     * a class name in the database — coupling the schema to namespaces that
     * are ours to rename.
     */
    public function parent(): ?Model
    {
        $type = $this->parentType();

        if ($type === null) {
            return null;
        }

        return $type->modelClass()::query()
            ->where('bc_id', $this->parent_bc_id)
            ->first();
    }

    /**
     * The file's extension, lowercased, or '' when it has none.
     *
     * Used only to label a row; the website decides how to render a link.
     */
    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bc_modified_at' => 'immutable_datetime',
            'bc_payload' => 'array',
        ];
    }
}
