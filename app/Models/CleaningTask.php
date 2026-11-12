<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CleaningResult;
use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Models\Concerns\BelongsToBranch;
use Database\Factories\CleaningTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One clean per check-out or transfer-out, tied to the stay that left (DATABASE_SCHEMA §4.14).
 * open_room_id and open_cleaner_id are generated columns and are never written.
 */
class CleaningTask extends Model
{
    use BelongsToBranch;

    /** @use HasFactory<CleaningTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'branch_id', 'room_id', 'stay_id', 'trigger_kind', 'status', 'queued_at', 'due_at',
        'started_at', 'finished_at', 'cleaned_by', 'is_late', 'result', 'problem_note',
    ];

    protected function casts(): array
    {
        return [
            'trigger_kind' => CleaningTrigger::class,
            'status' => CleaningStatus::class,
            'result' => CleaningResult::class,
            'is_late' => 'boolean',
            'queued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cleaner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleaned_by');
    }
}
