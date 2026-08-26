<?php

namespace App\Domain\Audit\Models;

use App\Domain\Organization\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'attachments';

    protected $fillable = [
        'organization_id',
        'attachable_type',
        'attachable_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'disk',
        'path',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
