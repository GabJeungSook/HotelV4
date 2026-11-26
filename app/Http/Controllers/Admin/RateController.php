<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveDiscount;
use App\Actions\Admin\SaveExtensionRate;
use App\Actions\Admin\SaveRate;
use App\Enums\DiscountKind;
use App\Enums\StayKind;
use App\Http\Requests\Admin\DiscountRequest;
use App\Http\Requests\Admin\ExtensionRateRequest;
use App\Http\Requests\Admin\RateRequest;
use App\Queries\AdminSetupQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Rates (45): the rate table, extension rates and discounts (DATABASE_SCHEMA §4.5). */
final class RateController extends AdminController
{
    public function index(AdminSetupQuery $query): Response
    {
        return Inertia::render('Admin/Rates', $query->rates($this->branch()));
    }

    /** One cell of the rate table (type, floor or room); a blank amount removes the rate ("not set"). */
    public function update(RateRequest $request, SaveRate $save): RedirectResponse
    {
        $rate = $save->handle(
            $this->user($request), $this->branchId(),
            $request->filled('room_type_id') ? $request->integer('room_type_id') : null,
            $request->filled('floor_id') ? $request->integer('floor_id') : null,
            $request->filled('room_id') ? $request->integer('room_id') : null,
            StayKind::from($request->string('stay_kind')->toString()), $request->integer('hours'), $this->amount($request),
        );

        return $this->saved($rate === null ? 'Rate removed. That option is no longer sold.' : 'Rate saved. New check-ins use it; open stays keep their price.');
    }

    public function extension(ExtensionRateRequest $request, SaveExtensionRate $save): RedirectResponse
    {
        $rate = $save->handle($this->user($request), $this->branchId(), $request->integer('hours'), $this->amount($request));

        return $this->saved($rate === null ? 'Extension block removed.' : 'Extension rate saved.');
    }

    public function storeDiscount(DiscountRequest $request, SaveDiscount $save): RedirectResponse
    {
        $discount = $save->handle($this->user($request), $this->branchId(), null, $this->discountData($request), $this->rateIds($request));

        return $this->saved("{$discount->name} added.");
    }

    public function updateDiscount(DiscountRequest $request, SaveDiscount $save, int $discount): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $discount, $this->discountData($request), $this->rateIds($request));

        return $this->saved("{$saved->name} saved.");
    }

    /** Blank (or 0 typed) means "not set": the action removes the row; ₱0 is never stored. */
    private function amount(Request $request): ?Money
    {
        return $request->filled('amount') && $request->integer('amount') > 0 ? Money::ofCentavos($request->integer('amount')) : null;
    }

    /** @return array{name: string, kind: DiscountKind, value: int, all_rates: bool, kiosk_enabled: bool, needs_id: bool, is_vat_exempt: bool, is_active: bool} */
    private function discountData(Request $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'kind' => DiscountKind::from($request->string('kind')->toString()),
            'value' => $request->integer('value'),
            'all_rates' => $request->boolean('all_rates'),
            'kiosk_enabled' => $request->boolean('kiosk_enabled'),
            'needs_id' => $request->boolean('needs_id'),
            'is_vat_exempt' => $request->boolean('is_vat_exempt'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return list<int> */
    private function rateIds(Request $request): array
    {
        return array_values(array_map('intval', (array) $request->input('rate_ids', [])));
    }
}
