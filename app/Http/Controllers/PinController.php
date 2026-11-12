<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\SetPin;
use App\Actions\Auth\UnlockPin;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Middleware\EnsurePinSet;
use App\Http\Requests\SetPinRequest;
use App\Http\Requests\UnlockPinRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Set your PIN at first sign-in, and unlock PIN entry on a locked computer (APP_FLOW §8.1, §8.2; TRD §4.12). */
final class PinController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! EnsurePinSet::needsPin($user)) {
            return redirect()->route('home');
        }

        return Inertia::render('Auth/SetPin', ['length' => config('hotel.pin.length', 4)]);
    }

    public function update(SetPinRequest $request, SetPin $setPin): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! EnsurePinSet::needsPin($user)) {
            return redirect()->route('home');
        }

        $setPin->handle($user, $request->string('pin')->toString(), $request->string('pin_confirmation')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your PIN is set.']);

        return redirect()->route('home');
    }

    public function unlock(UnlockPinRequest $request, UnlockPin $unlock): RedirectResponse
    {
        $unlock->handle(EnsureDeviceKey::of($request), $request->string('username')->toString(), $request->string('password')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'PIN entry is unlocked on this computer.']);

        return redirect()->back(fallback: route('home'));
    }
}
