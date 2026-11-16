<?php

declare(strict_types=1);

use App\Actions\Owner\CreateBranch;
use App\Actions\Shifts\OpenShift;
use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Enums\StayState;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentBranch;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Owner branches (55), opening a branch, deactivate / reactivate, and TRD §10 "Branches and
| permissions": a branch admin cannot read another branch; owner can open any branch; every
| other role is refused on the owner routes.
*/

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila, 12 Nov; the PM shift of 11 Nov is open
    $this->owner = Hotel::user('ramon.alvarez');
    $this->gensan = Hotel::branch();

    $created = app(CreateBranch::class)->handle($this->owner, [
        'name' => 'Dalisay Koronadal', 'address' => 'Koronadal City', 'code_prefix' => 'DKR',
        'admin_name' => 'Noel Fabrigas', 'admin_username' => 'noel.fabrigas', 'copy_from_id' => $this->gensan->id,
    ]);
    $this->koronadal = $created['branch'];
    $this->koronadalAdmin = $created['admin'];
    $this->koronadalAdmin->update(['pin_hash' => Hotel::PIN, 'must_change_password' => false]);

    // CurrentBranch is request-scoped; the one the arrange code resolved belongs to no request.
    app()->forgetScopedInstances();
});

it('lists every branch with rooms, staff, the shift now and today\'s sales from the summary table', function (): void {
    $open = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $this->gensan->id)->where('status', 'open')->sole();
    DB::table('daily_summaries')->insert(['branch_id' => $this->gensan->id, 'business_date' => $open->business_date->toDateString(), 'net_sales_amount' => 942000]);
    // A summary of another date is not "today".
    DB::table('daily_summaries')->insert(['branch_id' => $this->gensan->id, 'business_date' => '2026-11-01', 'net_sales_amount' => 111]);

    $this->actingAs($this->owner)->get('/owner/branches')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Owner/Branches')
        ->has('branches', 2)
        ->where('opened_id', null)
        ->where('branches.0.name', 'Hotel Dalisay GenSan')
        ->where('branches.0.status', 'active')
        ->where('branches.0.rooms', $this->gensan->rooms()->where('is_active', true)->count())
        ->where('branches.0.staff', 9)
        ->where('branches.0.shift.period_name', $open->period_name)
        ->where('branches.0.business_date', $open->business_date->toDateString())
        ->where('branches.0.today_net_sales', 942000)
        ->where('branches.1.name', 'Dalisay Koronadal')
        ->where('branches.1.status', 'setting_up')
        ->where('branches.1.rooms', 0)
        ->where('branches.1.staff', 1)
        ->where('branches.1.shift', null)
        ->where('branches.1.today_net_sales', null));
});

it('shows 0 sales and no open shift for an active branch without a summary row or shift', function (): void {
    $this->koronadal->update(['status' => BranchStatus::Active]);

    $this->actingAs($this->owner)->get('/owner/branches')->assertInertia(fn (Assert $page) => $page
        ->where('branches.1.shift', null)
        ->where('branches.1.today_net_sales', 0)
        ->where('branches.1.business_date', '2026-11-12'));
});

it('lets the owner open any branch, see that branch\'s data, and go back to all branches', function (): void {
    $this->actingAs($this->owner)->post("/owner/branches/{$this->gensan->id}/open")->assertRedirect('/dashboard');
    expect(session(CurrentBranch::SESSION_KEY))->toBe($this->gensan->id);

    $this->get('/reports/shifts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('branch.name', 'Hotel Dalisay GenSan') // the top-bar chip
        ->has('list.rows', 3));
    $this->get('/owner/branches')->assertInertia(fn (Assert $page) => $page->where('opened_id', $this->gensan->id));

    $this->post("/owner/branches/{$this->koronadal->id}/open")->assertRedirect('/dashboard');
    $this->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('branch.id', $this->koronadal->id)
        ->where('branch.name', 'Dalisay Koronadal'));
    $this->get('/reports/shifts')->assertInertia(fn (Assert $page) => $page->has('list.rows', 0));

    $this->post('/owner/branches/close')->assertRedirect('/owner');
    expect(session(CurrentBranch::SESSION_KEY))->toBeNull();
    $this->get('/owner/branches')->assertInertia(fn (Assert $page) => $page->where('branch', null)->where('opened_id', null));
});

it('opens an inactive branch so its history stays readable', function (): void {
    $this->gensan->update(['status' => BranchStatus::Inactive]);

    $this->actingAs($this->owner)->post("/owner/branches/{$this->gensan->id}/open")->assertRedirect('/dashboard');
    $this->get('/reports/shifts')->assertOk()->assertInertia(fn (Assert $page) => $page->has('list.rows', 3));
});

it('deactivates an active branch and sets it back to active, with audit logs', function (): void {
    $this->actingAs($this->owner)->patch("/owner/branches/{$this->gensan->id}", ['status' => 'inactive'])->assertRedirect('/owner/branches');
    expect($this->gensan->refresh()->status)->toBe(BranchStatus::Inactive)
        ->and(Branch::query()->count())->toBe(2);

    $this->actingAs($this->owner)->patch("/owner/branches/{$this->gensan->id}", ['status' => 'active'])->assertRedirect('/owner/branches');
    expect($this->gensan->refresh()->status)->toBe(BranchStatus::Active);

    expect(AuditLog::query()->where('subject_type', 'branch')->where('subject_id', $this->gensan->id)->orderBy('id')->pluck('action')->all())
        ->toBe(['branch.deactivated', 'branch.reactivated']);
});

it('refuses to deactivate a branch that is still setting up and never deletes one', function (): void {
    $this->actingAs($this->owner)->from('/owner/branches')->patch("/owner/branches/{$this->koronadal->id}", ['status' => 'inactive'])->assertRedirect('/owner/branches');

    expect(session('inertia.flash_data.error.message'))->toContain('still being set up')
        ->and($this->koronadal->refresh()->status)->toBe(BranchStatus::SettingUp);

    $this->actingAs($this->owner)->patch("/owner/branches/{$this->gensan->id}", ['status' => 'setting_up'])->assertSessionHasErrors('status');
    $this->actingAs($this->owner)->delete("/owner/branches/{$this->gensan->id}")->assertStatus(405);
});

it('does not let a branch admin read another branch', function (): void {
    $gensanShift = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $this->gensan->id)->where('z_no', 629)->sole();
    $admin = $this->koronadalAdmin;

    // Shift report, Z-report and its exports of the other branch: not found.
    $this->actingAs($admin)->get("/reports/shifts/{$gensanShift->id}")->assertNotFound();
    $this->actingAs($admin)->get("/reports/shifts/{$gensanShift->id}/z")->assertNotFound();
    $this->actingAs($admin)->get("/reports/shifts/{$gensanShift->id}/z.csv")->assertNotFound();

    // ?branch= is ignored for staff: the hub, the shifts list and the daily report stay on their own branch.
    $this->actingAs($admin)->get("/reports?branch={$this->gensan->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('branch.id', $this->koronadal->id)
        ->where('latest_shift_id', null));
    $this->actingAs($admin)->get("/reports/shifts?branch={$this->gensan->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('list.rows', 0));
    $this->actingAs($admin)->get("/reports/daily?date=2026-11-11&branch={$this->gensan->id}")->assertOk()
        ->assertDontSee('Hotel Dalisay GenSan');

    // Owner routes are not theirs at all.
    $this->actingAs($admin)->get('/owner/branches')->assertForbidden();
    $this->actingAs($admin)->post("/owner/branches/{$this->gensan->id}/open")->assertForbidden();
});

it('does not let a supervisor of one branch open a stay of another branch', function (): void {
    $supervisor = User::factory()->supervisor()->create(['branch_id' => $this->koronadal->id]);
    $frontdesk = User::factory()->frontdesk()->create(['branch_id' => $this->koronadal->id]);
    app(OpenShift::class)->handle($frontdesk, $this->koronadal->id, Money::ofCentavos(0));

    $gensanStay = Stay::query()->withoutGlobalScopes()->where('branch_id', $this->gensan->id)->where('state', StayState::CheckedIn)->firstOrFail();

    $this->actingAs($supervisor)->get('/board')->assertOk()->assertDontSee($gensanStay->code);
    $this->actingAs($supervisor)->get("/stays/{$gensanStay->id}")->assertNotFound();
});

it('refuses every other role on the owner routes', function (string $role): void {
    $user = User::factory()->role(Role::from($role))->create(['branch_id' => $this->gensan->id]);
    $other = User::factory()->owner()->create();

    $this->actingAs($user)->get('/owner/branches')->assertForbidden();
    $this->actingAs($user)->get('/owner/branches/create')->assertForbidden();
    $this->actingAs($user)->post('/owner/branches', ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->post("/owner/branches/{$this->gensan->id}/open")->assertForbidden();
    $this->actingAs($user)->post('/owner/branches/close')->assertForbidden();
    $this->actingAs($user)->patch("/owner/branches/{$this->gensan->id}", ['status' => 'inactive'])->assertForbidden();
    $this->actingAs($user)->get('/owner/accounts')->assertForbidden();
    $this->actingAs($user)->post('/owner/accounts', ['name' => 'X', 'username' => 'xx'])->assertForbidden();
    $this->actingAs($user)->patch("/owner/accounts/{$other->id}", ['name' => 'X', 'username' => 'xx', 'is_active' => false])->assertForbidden();

    expect($this->gensan->refresh()->status)->toBe(BranchStatus::Active)
        ->and($other->refresh()->is_active)->toBeTrue()
        ->and(session(CurrentBranch::SESSION_KEY))->toBeNull();
})->with(['admin', 'supervisor', 'frontdesk', 'backoffice', 'housekeeper', 'kiosk']);

it('sends a guest to the sign-in page', function (): void {
    $this->get('/owner/branches')->assertRedirect('/login');
    $this->get('/owner/accounts')->assertRedirect('/login');
});
