<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveHall;
use App\Actions\Admin\SaveHallPackage;
use App\Enums\HallPackageKind;
use App\Enums\HallPricing;
use App\Http\Requests\Admin\HallPackageRequest;
use App\Http\Requests\Admin\HallRequest;
use App\Queries\AdminSetupQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Event halls and their packages (DATABASE_SCHEMA §4.14): the Setup → Halls screen. */
final class HallController extends AdminController
{
    private const TABS = ['halls', 'packages'];

    public function index(Request $request, AdminSetupQuery $query): Response
    {
        $tab = $request->string('tab')->toString();

        return Inertia::render('Admin/Halls', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'halls',
            ...$query->halls($this->branch()),
        ]);
    }

    public function store(HallRequest $request, SaveHall $save): RedirectResponse
    {
        $hall = $save->handle($this->user($request), $this->branchId(), null, $this->hallData($request));

        return $this->saved("{$hall->name} added.");
    }

    public function update(HallRequest $request, SaveHall $save, int $hall): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $hall, $this->hallData($request));

        return $this->saved("{$saved->name} saved.");
    }

    public function storePackage(HallPackageRequest $request, SaveHallPackage $save): RedirectResponse
    {
        $package = $save->handle($this->user($request), $this->branchId(), null, $this->packageData($request));

        return $this->saved("{$package->name} added.");
    }

    public function updatePackage(HallPackageRequest $request, SaveHallPackage $save, int $package): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $package, $this->packageData($request));

        return $this->saved("{$saved->name} saved.");
    }

    /** @return array{name: string, capacity: int, base_rate_amount: Money|null, is_active: bool} */
    private function hallData(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'capacity' => $request->integer('capacity'),
            'base_rate_amount' => $request->filled('base_rate_amount') && $request->integer('base_rate_amount') > 0 ? Money::ofCentavos($request->integer('base_rate_amount')) : null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return array{name: string, kind: HallPackageKind, pricing: HallPricing, unit_amount: Money, is_active: bool} */
    private function packageData(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'kind' => HallPackageKind::from($request->string('kind')->toString()),
            'pricing' => HallPricing::from($request->string('pricing')->toString()),
            'unit_amount' => Money::ofCentavos($request->integer('unit_amount')),
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
