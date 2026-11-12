<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\TransferReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** List for screen 13 (DATABASE_SCHEMA §4.6). */
class TransferReason extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<TransferReasonFactory> */
    use HasFactory;

    protected $fillable = ['branch_id', 'name', 'needs_note', 'needs_manager', 'is_active'];

    protected function casts(): array
    {
        return [
            'needs_note' => 'boolean',
            'needs_manager' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
