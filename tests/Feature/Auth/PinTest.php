<?php

declare(strict_types=1);

use App\Actions\Auth\ApproveByPin;
use App\Actions\Auth\UnlockPin;
use App\Actions\Auth\VerifyPin;
use App\Actions\Stays\VoidLine;
use App\Enums\ApprovalAction;
use App\Enums\PinContext;
use App\Enums\ReasonKind;
use App\Enums\Role;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\PinInvalid;
use App\Exceptions\PinLocked;
use App\Exceptions\UnlockRefused;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\FolioLine;
use App\Models\PinAttempt;
use App\Models\PinLock;
use App\Models\User;
use App\Services\PinGuard;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| PIN checks and lockouts: TRD §4.12 and §9, APP_FLOW §4.4 and §5.8,
| "ten wrong PINs lock the computer and a supervisor unlocks it" (TRD §10).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $this->device = Hotel::device();
});

function approve(User $approver, string $pin, string $device, ?User $requester = null, ?int $branchId = null): Approval
{
    return app(ApproveByPin::class)->handle(
        $approver, $pin, $device, $requester ?? Hotel::frontdesk(), $branchId ?? Hotel::branch()->id,
        ApprovalAction::VoidLine, 'folio_line', 1, 'Typed wrong amount', 'DGS-26-000001 · Chips · ₱35.00',
    );
}

it('logs a correct PIN as a successful try and returns the member', function (): void {
    $user = app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), Hotel::PIN, $this->device);

    $attempt = PinAttempt::query()->sole();
    expect($user->username)->toBe('rhea.bautista')
        ->and([$attempt->device_key, $attempt->user_id, $attempt->context, $attempt->succeeded])
        ->toBe([$this->device, $user->id, PinContext::SwitchUser, true]);
});

it('counts down the switch-user tries and asks for the full sign-in after the third wrong PIN', function (): void {
    $verify = fn () => app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), '0000', $this->device);
    $tries = [];

    foreach (range(1, 3) as $ignored) {
        try {
            $verify();
        } catch (PinInvalid $e) {
            $tries[] = [$e->triesLeft, $e->mustSignIn];
        }
    }

    expect($tries)->toBe([[2, false], [1, false], [0, true]])
        ->and(PinAttempt::query()->where('succeeded', false)->count())->toBe(3);
});

it('locks the computer after ten wrong PINs in a row and a supervisor unlocks it with their password', function (): void {
    $other = Hotel::device();

    // Ten wrong tries in a row on one computer, across switch user, approvals and the handover.
    foreach (range(1, 10) as $i) {
        try {
            match ($i % 3) {
                0 => app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), '0000', $this->device),
                1 => app(VerifyPin::class)->handle(Hotel::user('mark.sumagaysay'), '9999', $this->device, PinContext::Handover),
                default => approve(Hotel::user('ramil.ortega'), '0000', $this->device),
            };
        } catch (PinInvalid) {
        }
    }

    $lock = PinLock::query()->sole();
    expect($lock->device_key)->toBe($this->device)->and($lock->unlocked_at)->toBeNull()
        ->and(PinAttempt::query()->where('device_key', $this->device)->where('succeeded', false)->count())->toBe(10);

    // While locked every PIN on that computer is refused, even a correct one, and is not logged.
    expect(fn () => app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), Hotel::PIN, $this->device))->toThrow(PinLocked::class)
        ->and(fn () => approve(Hotel::supervisor(), Hotel::PIN, $this->device))->toThrow(PinLocked::class, 'PIN entry is locked on this computer.');
    expect(PinAttempt::query()->where('device_key', $this->device)->count())->toBe(10);

    // Another computer is not affected.
    app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), Hotel::PIN, $other);

    // A front desk user cannot unlock it, nor a supervisor with a wrong password.
    expect(fn () => app(UnlockPin::class)->handle($this->device, 'jessa.delacruz', 'password'))->toThrow(UnlockRefused::class)
        ->and(fn () => app(UnlockPin::class)->handle($this->device, 'carol.villanueva', 'wrong-password'))->toThrow(UnlockRefused::class);

    $this->travelClock(5);
    app(UnlockPin::class)->handle($this->device, 'carol.villanueva', 'password');

    $lock->refresh();
    $audit = AuditLog::query()->where('action', 'pin.unlocked')->sole();
    expect($lock->unlocked_by)->toBe(Hotel::supervisor()->id)
        ->and($lock->unlocked_at->toDateTimeString())->toBe('2026-11-12 01:05:00')
        ->and([$audit->actor_id, $audit->subject_type, $audit->subject_id])->toBe([Hotel::supervisor()->id, 'pin_lock', $lock->id])
        ->and(Approval::query()->count())->toBe(0);

    // PIN entry works again, and the run of wrong tries starts afresh.
    app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), Hotel::PIN, $this->device);
    expect(app(PinGuard::class)->wrongInARow($this->device))->toBe(0);
});

it('resets the run of wrong tries on a correct PIN', function (): void {
    foreach (range(1, 9) as $ignored) {
        try {
            app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), '0000', $this->device, PinContext::Handover);
        } catch (PinInvalid) {
        }
    }

    app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), Hotel::PIN, $this->device, PinContext::Handover);

    try {
        app(VerifyPin::class)->handle(Hotel::user('rhea.bautista'), '0000', $this->device, PinContext::Handover);
    } catch (PinInvalid) {
    }

    expect(PinLock::query()->count())->toBe(0)->and(app(PinGuard::class)->wrongInARow($this->device))->toBe(1);
});

it('refuses the unlock form when the computer is not locked', function (): void {
    app(UnlockPin::class)->handle($this->device, 'carol.villanueva', 'password');
})->throws(UnlockRefused::class, 'not locked');

it('writes an approval by a supervisor of the branch', function (): void {
    $approval = approve(Hotel::supervisor(), Hotel::PIN, $this->device);

    expect([$approval->action, $approval->requested_by, $approval->approved_by, $approval->reason])
        ->toBe([ApprovalAction::VoidLine, Hotel::frontdesk()->id, Hotel::supervisor()->id, 'Typed wrong amount'])
        ->and(PinAttempt::query()->sole()->context)->toBe(PinContext::Approval);
});

it('refuses self-approval and approvers who are not a supervisor or admin of the branch', function (): void {
    expect(fn () => approve(Hotel::supervisor(), Hotel::PIN, $this->device, requester: Hotel::supervisor()))
        ->toThrow(ApprovalRefused::class, 'You cannot approve your own request.')
        ->and(fn () => approve(Hotel::user('rhea.bautista'), Hotel::PIN, $this->device))
        ->toThrow(ApprovalRefused::class, 'cannot approve this')
        ->and(fn () => approve(Hotel::user('grace.tolentino'), Hotel::PIN, $this->device))
        ->toThrow(ApprovalRefused::class);

    $elsewhere = User::factory()->create(['role' => Role::Supervisor, 'pin_hash' => Hotel::PIN]);
    expect(fn () => approve($elsewhere, Hotel::PIN, $this->device))->toThrow(ApprovalRefused::class)
        ->and(Approval::query()->count())->toBe(0);
});

it('locks one approver for 15 minutes after five wrong approval PINs', function (): void {
    $tries = [];
    foreach (range(1, 5) as $ignored) {
        try {
            approve(Hotel::supervisor(), '0000', $this->device);
        } catch (PinInvalid $e) {
            $tries[] = $e->triesLeft;
        }
    }

    expect($tries)->toBe([4, 3, 2, 1, 0])
        ->and(fn () => approve(Hotel::supervisor(), Hotel::PIN, $this->device))->toThrow(PinLocked::class, 'Approvals by Carol Villanueva are locked for 15 minutes')
        ->and(approve(Hotel::user('ramil.ortega'), Hotel::PIN, Hotel::device())->approved_by)->toBe(Hotel::user('ramil.ortega')->id);

    $this->travelClock(15);
    expect(approve(Hotel::supervisor(), Hotel::PIN, $this->device)->approved_by)->toBe(Hotel::supervisor()->id);
});

it('keeps the try of a wrong approval PIN although the action it was for rolls back', function (): void {
    Hotel::openShift();
    $stay = Hotel::checkIn();
    $line = FolioLine::query()->where('stay_id', $stay->id)->firstOrFail();

    expect(fn () => app(VoidLine::class)->handle(
        Hotel::frontdesk(), $line, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), '0000', $this->device,
    ))->toThrow(PinInvalid::class, 'PIN not recognised.');

    expect(PinAttempt::query()->where('succeeded', false)->count())->toBe(1)
        ->and(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(0)
        ->and(Approval::query()->count())->toBe(0);
});
