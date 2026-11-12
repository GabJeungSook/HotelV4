<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DATABASE_SCHEMA §4.14 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_tasks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('stay_id'); // the stay that left the room
            $table->enum('trigger_kind', ['check_out', 'transfer_out']);
            $table->enum('status', ['queued', 'in_progress', 'done', 'reset'])->default('queued');
            $table->timestamp('queued_at');
            $table->timestamp('due_at'); // queued_at + cleaning_deadline_minutes
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('cleaned_by')->nullable();
            $table->boolean('is_late')->default(false); // finished_at > due_at
            $table->enum('result', ['ready', 'problem'])->nullable();
            $table->string('problem_note', 255)->nullable();
            $table->unsignedBigInteger('open_room_id')->nullable()
                ->storedAs("IF(status IN ('queued','in_progress'), room_id, NULL)");
            $table->unsignedBigInteger('open_cleaner_id')->nullable()
                ->storedAs("IF(status = 'in_progress', cleaned_by, NULL)");
            $table->timestamps();

            $table->unique('open_room_id', 'cleaning_tasks_one_open_per_room');
            $table->unique('open_cleaner_id', 'cleaning_tasks_one_per_cleaner');
            $table->index(['branch_id', 'status', 'queued_at'], 'cleaning_tasks_queue_idx');
            $table->index(['branch_id', 'finished_at'], 'cleaning_tasks_report_idx');
            $table->foreign('branch_id', 'cleaning_tasks_branch_fk')->references('id')->on('branches');
            $table->foreign('room_id', 'cleaning_tasks_room_fk')->references('id')->on('rooms');
            $table->foreign('stay_id', 'cleaning_tasks_stay_fk')->references('id')->on('stays');
            $table->foreign('cleaned_by', 'cleaning_tasks_user_fk')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_tasks');
    }
};
