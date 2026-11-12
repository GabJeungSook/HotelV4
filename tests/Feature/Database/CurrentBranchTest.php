<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Room;
use App\Models\User;
use App\Services\CurrentBranch;

beforeEach(function (): void {
    $this->a = Branch::factory()->create();
    $this->b = Branch::factory()->create();
    Room::factory()->count(2)->create(['branch_id' => $this->a->id]);
    Room::factory()->count(3)->create(['branch_id' => $this->b->id]);
});

it('limits queries to the signed-in user\'s branch', function (): void {
    $this->actingAs(User::factory()->admin()->create(['branch_id' => $this->a->id]));

    expect(app(CurrentBranch::class)->id())->toBe($this->a->id)
        ->and(Room::query()->count())->toBe(2)
        ->and(Room::query()->pluck('branch_id')->unique()->all())->toBe([$this->a->id]);
});

it('does not let a branch admin read another branch', function (): void {
    $this->actingAs(User::factory()->admin()->create(['branch_id' => $this->a->id]));
    $other = Room::query()->withoutGlobalScopes()->where('branch_id', $this->b->id)->first();

    expect(Room::query()->find($other?->id))->toBeNull();
});

it('shows an owner all branches until they open one, then that branch only', function (): void {
    $this->actingAs(User::factory()->owner()->create());
    $this->withSession([]);
    $this->get('/'); // starts a session for the request

    expect(Room::query()->count())->toBe(5);

    $current = app(CurrentBranch::class);
    $current->open($this->b->id);
    expect($current->id())->toBe($this->b->id)->and(Room::query()->count())->toBe(3);

    $current->close();
    expect($current->id())->toBeNull()->and(Room::query()->count())->toBe(5);
});

it('lets console code set the branch and restores it after run()', function (): void {
    $current = app(CurrentBranch::class);

    expect(Room::query()->count())->toBe(5);
    expect($current->run($this->a->id, fn () => Room::query()->count()))->toBe(2);
    expect($current->id())->toBeNull();

    $current->set($this->b->id);
    expect(Room::query()->count())->toBe(3);
});

it('stamps the current branch on a new row that has none', function (): void {
    $room = Room::query()->withoutGlobalScopes()->where('branch_id', $this->a->id)->firstOrFail();

    $created = app(CurrentBranch::class)->run($this->a->id, fn () => Room::query()->create([
        'floor_id' => $room->floor_id, 'room_type_id' => $room->room_type_id, 'number' => 'X1',
    ]));

    expect($created->branch_id)->toBe($this->a->id);
});
