<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveListReason;
use App\Actions\Admin\SaveShiftDefinition;
use App\Actions\Admin\UpdateBranchSettings;
use App\Http\Requests\Admin\BranchSettingsRequest;
use App\Http\Requests\Admin\ListReasonRequest;
use App\Http\Requests\Admin\ShiftDefinitionRequest;
use App\Queries\AdminSetupQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Branch settings (46): the setting groups, the shifts and the reason lists (APP_FLOW §8.2). */
final class SettingController extends AdminController
{
    public function index(AdminSetupQuery $query): Response
    {
        return Inertia::render('Admin/Settings', $query->settings($this->branch()));
    }

    public function update(BranchSettingsRequest $request, UpdateBranchSettings $update): RedirectResponse
    {
        $update->handle($this->user($request), $this->branchId(), $request->settings());

        return $this->saved('Settings saved.');
    }

    public function storeShift(ShiftDefinitionRequest $request, SaveShiftDefinition $save): RedirectResponse
    {
        $shift = $save->handle(
            $this->user($request), $this->branchId(), null, $request->string('name')->toString(),
            $request->string('starts_at')->toString(), $request->string('ends_at')->toString(), $request->boolean('is_active'),
        );

        return $this->saved("{$shift->name} shift added.");
    }

    public function updateShift(ShiftDefinitionRequest $request, SaveShiftDefinition $save, int $shift): RedirectResponse
    {
        $saved = $save->handle(
            $this->user($request), $this->branchId(), $shift, $request->string('name')->toString(),
            $request->string('starts_at')->toString(), $request->string('ends_at')->toString(), $request->boolean('is_active'),
        );

        return $this->saved("{$saved->name} shift saved.");
    }

    public function storeReason(ListReasonRequest $request, SaveListReason $save, string $list): RedirectResponse
    {
        abort_unless(in_array($list, SaveListReason::lists(), true), 404);
        $save->handle(
            $this->user($request), $this->branchId(), $list, null, $request->string('name')->toString(),
            $request->boolean('needs_note'), $request->boolean('needs_manager'), $request->boolean('is_active'),
        );

        return $this->saved('Reason added.');
    }

    public function updateReason(ListReasonRequest $request, SaveListReason $save, string $list, int $reason): RedirectResponse
    {
        abort_unless(in_array($list, SaveListReason::lists(), true), 404);
        $save->handle(
            $this->user($request), $this->branchId(), $list, $reason, $request->string('name')->toString(),
            $request->boolean('needs_note'), $request->boolean('needs_manager'), $request->boolean('is_active'),
        );

        return $this->saved('Reason saved.');
    }
}
