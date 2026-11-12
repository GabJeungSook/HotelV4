<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * List for screen 21 (DATABASE_SCHEMA §4.6).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property bool $needs_note
 * @property bool $needs_manager
 * @property bool $is_active
 */
class ExpenseCategory extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<ExpenseCategoryFactory> */
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
