<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Queries\ActivityQuery;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Activity log (admin + superadmin): every action of a business day in one read-only feed —
| sign-ins, check-ins/outs, payments, POS sales, cash moves, housekeeping and every audit_logs
| action. It reads what is already recorded; no action writes twice.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->branch = Hotel::branch();
    $this->jessa = Hotel::frontdesk();
    Hotel::openShift($this->jessa);
    $this->today = app(Clock::class)->localDate();
});

it('gathers check-ins, payments and audit logs into one day feed', function (): void {
    Hotel::checkIn('205', 12); // a check-in + its payment + key deposit
    AuditLog::query()->create([
        'branch_id' => $this->branch->id, 'actor_id' => $this->jessa->id, 'action' => 'auth.login',
        'subject_type' => 'user', 'subject_id' => $this->jessa->id, 'after_json' => null, 'created_at' => app(Clock::class)->now(),
    ]);

    $feed = app(ActivityQuery::class)->feed([$this->branch->id], $this->today);
    $actions = array_column($feed, 'action');

    expect($actions)->toContain('Checked in')
        ->toContain('Payment taken')
        ->toContain('Signed in')
        ->and($feed[0]['by'])->not->toBe(''); // the actor's name is resolved
});

it('filters the feed by person', function (): void {
    Hotel::checkIn('205', 12); // done by Jessa

    $mine = app(ActivityQuery::class)->feed([$this->branch->id], $this->today, $this->jessa->id);
    $others = app(ActivityQuery::class)->feed([$this->branch->id], $this->today, Hotel::user('rhea.bautista')->id);

    expect(collect($mine)->pluck('action'))->toContain('Checked in')
        ->and(collect($others)->pluck('action'))->not->toContain('Checked in');
});

it('opens the activity log for admin, and refuses the front desk', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega')) // admin
        ->get(route('admin.activity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Activity/Index')->has('rows')->has('people')->where('cross_branch', false));

    $this->actingAs($this->jessa)->get(route('admin.activity'))->assertForbidden();
});

it('gives the owner a cross-branch activity log', function (): void {
    $this->actingAs(Hotel::user('ramon.alvarez')) // owner
        ->get(route('owner.activity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Activity/Index')->has('rows')->has('people'));
});

it('logs a sign-in to the audit trail', function (): void {
    $this->post(route('login.store'), ['username' => 'jessa.delacruz', 'password' => 'password']);

    expect(AuditLog::query()->where('action', 'auth.login')->where('actor_id', $this->jessa->id)->count())->toBe(1);
});
