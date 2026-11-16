<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reports\CloseDay;
use App\Actions\Reports\LogPrint;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Exceptions\PreviewNotExportable;
use App\Http\Requests\ReportRequest;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\DayClosing;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\ReportQuery;
use App\Queries\ReportScreenQuery;
use App\Queries\ShiftCalendarQuery;
use App\Services\CurrentBranch;
use App\Services\ReportService;
use App\Services\ZReportExport;
use App\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Reports (36–43, 37b, 37c, 38b and the shifts list), APP_FLOW §2 and §4.21–4.23. Thin: each
 * method reads one ReportService builder array and renders it, exports it (PDF, CSV) or logs a
 * print of it. Who may see what (TRD §4.1, §4.10):
 *
 * - the route group lets back office, supervisor, admin and owner in; front desk never;
 * - staff see their own branch only; the owner any branch (`?branch=`, else the opened one);
 * - a preview of an open shift shows expected cash, so a supervisor sees it only while they
 *   have recorded no money rows on that shift;
 * - closing the day is for back office, admin and owner, not a supervisor.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportScreenQuery $screens,
        private readonly ZReportExport $export,
        private readonly CurrentBranch $currentBranch,
        private readonly Clock $clock,
    ) {}

    /** Report hub (36) with the result of the check of the previous business date. */
    public function index(ReportRequest $request): Response
    {
        $branch = $this->branch($request);
        $latest = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('status', ShiftStatus::Closed)->orderByDesc('z_no')->value('id');

        return Inertia::render('Reports/Hub', [
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'check' => $this->screens->check($branch->id, $this->clock->local()->subDay()->toDateString()),
            'latest_shift_id' => $latest,
            'today' => $this->clock->localDate(),
        ]);
    }

    /** The shifts list, newest business date first (APP_FLOW §8.1); each row opens 37, 37b or 37c. */
    /**
     * Shift reports (37a): the list with filters (shift, front desk, status, dates), search (a Z
     * number or a name) and pages, or with `view=calendar` the same shifts as week blocks.
     */
    public function shifts(ReportRequest $request, ShiftCalendarQuery $calendar): Response
    {
        $branch = $this->branch($request);
        $ymd = fn (string $key): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) === 1 ? (string) $request->query($key) : null;
        $filters = [
            'period' => $request->filled('period') ? (string) $request->query('period') : null,
            'desk' => $request->filled('desk') ? $request->integer('desk') : null,
            'status' => in_array($request->query('status'), ['open', 'closed', 'short', 'force'], true) ? (string) $request->query('status') : null,
            'from' => $ymd('from'),
            'to' => $ymd('to'),
            'q' => $request->filled('q') ? mb_substr((string) $request->query('q'), 0, 60) : null,
        ];
        $view = $request->query('view') === 'calendar' ? 'calendar' : 'list';
        $week = $calendar->week($branch->id, $ymd('week'), $filters['desk'], $filters['period']);

        return Inertia::render('Reports/Shifts', [
            'view' => $view,
            'filters' => [...$filters, 'week' => $ymd('week')],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'options' => ['desks' => $week['desks'], 'periods' => $week['periods']],
            'list' => $view === 'list' ? $this->screens->shifts($branch->id, max(1, $request->integer('page', 1)), $filters) : null,
            'calendar' => $view === 'calendar' ? $week : null,
        ]);
    }

    /** "Z-report" in the menu: the Z paper of the last closed shift, or the list when none. */
    public function zLatest(ReportRequest $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $id = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('status', ShiftStatus::Closed)->orderByDesc('z_no')->value('id');

        return $id === null ? redirect()->route('reports.shifts') : redirect()->route('reports.z', $id);
    }

    /** Shift report (37), or its CSV. */
    public function shift(ReportRequest $request, ReportQuery $queries, int $shift): Response|HttpResponse
    {
        $session = $this->shiftFor($request, $shift);
        $report = $this->reports->shift($session);

        if ($request->query('export') === 'csv') {
            $this->refusePreviewExport($report['preview']);

            return $this->export->table($this->screens->shiftTables($report), $this->user($request), $session->branch_id, $session->id, "shift-report-{$session->business_date->toDateString()}-{$session->period_name}.csv");
        }

        return Inertia::render('Reports/Shift', [
            'report' => $report,
            'ledger' => Inertia::optional(fn (): array => $queries->ledger([$session->id], (string) $request->query('figure', ''))),
        ]);
    }

    /** Z-report (37b) and its tabs: rooms by floor (37c), cleaning chart, housekeeping log, stocks, expenses. */
    public function zReport(ReportRequest $request, ReportQuery $queries, int $shift): Response
    {
        $session = $this->shiftFor($request, $shift);
        $tab = (string) $request->query('tab', 'sales');
        $paper = $this->reports->zReport($session);

        return Inertia::render('Reports/ZReport', [
            'tab' => $tab,
            'paper' => $paper,
            'shift' => [
                'id' => $session->id,
                'period_name' => $session->period_name,
                'business_date' => $session->business_date->toDateString(),
                'status' => $session->status->value,
            ],
            'rooms' => $tab === 'rooms' ? $this->reports->zRooms($session) : null,
            'cleaning' => $tab === 'cleaning' ? $this->reports->zCleaning($session) : null,
            'housekeeping' => $tab === 'housekeeping' ? $this->reports->housekeeping($session->branch_id, $session->business_date->toDateString(), $session) : null,
            'stocks' => $tab === 'stocks' ? $queries->stock($session) : null,
            'expenses' => $tab === 'expenses' ? $this->reports->zExpenses($session) : null,
            'ledger' => Inertia::optional(fn (): array => $queries->ledger([$session->id], (string) $request->query('figure', ''))),
        ]);
    }

    public function zPdf(ReportRequest $request, int $shift): HttpResponse
    {
        $session = $this->shiftFor($request, $shift);

        return $this->export->pdf($this->reports->zReport($session), $this->user($request), $session->branch_id, PrintDocument::ZReport, $session->id, self::zFile($session).'.pdf');
    }

    public function zCsv(ReportRequest $request, int $shift): HttpResponse
    {
        $session = $this->shiftFor($request, $shift);

        return $this->export->csv($this->reports->zReport($session), $this->user($request), $session->branch_id, PrintDocument::ZReport, $session->id, self::zFile($session).'.csv');
    }

    /** Logs a browser print of 37 (report) or 37b/37c (z_report) before it happens. */
    public function shiftPrint(ReportRequest $request, LogPrint $log, int $shift): JsonResponse
    {
        $session = $this->shiftFor($request, $shift);
        $isZ = $request->input('document') === 'z_report';

        if ($isZ && $session->status !== ShiftStatus::Closed) {
            abort(422, 'A preview cannot be printed. Close the shift first.');
        }

        $row = $log->handle($this->user($request), $session->branch_id, $isZ ? PrintDocument::ZReport : PrintDocument::Report, $session->id);

        return response()->json(['reprint' => $row->is_reprint]);
    }

    /** Daily sales (38) for a day, week or month, or its CSV. */
    public function daily(ReportRequest $request, ReportQuery $queries): Response|HttpResponse
    {
        $branch = $this->branch($request);
        $date = $request->businessDate($this->clock->local()->subDay()->toDateString());
        $report = $this->reports->daily($branch->id, $date, (string) $request->query('range', 'day'));

        if ($request->query('export') === 'csv') {
            return $this->export->table($this->screens->dailyTables($report), $this->user($request), $branch->id, null, "daily-sales-{$report['from']}-{$report['to']}.csv");
        }

        $column = (string) $request->query('column', '');
        $ids = collect([...$report['columns'], $report['total']])->firstWhere('key', $column)['shift_ids'] ?? [];

        return Inertia::render('Reports/Daily', [
            'report' => $report,
            'can_close' => $this->canCloseDay($this->user($request)),
            'ledger' => Inertia::optional(fn (): array => $queries->ledger($ids, (string) $request->query('figure', ''))),
        ]);
    }

    /** Close the day (38b): the date's shifts and day number, or the closed day paper. */
    public function closeDay(ReportRequest $request): Response
    {
        $branch = $this->branch($request);
        $date = $request->businessDate($this->clock->local()->subDay()->toDateString());

        return Inertia::render('Reports/CloseDay', [
            'day' => $this->screens->closeDay($branch->id, $date),
            'can_close' => $this->canCloseDay($this->user($request)),
        ]);
    }

    public function closeDayStore(ReportRequest $request, CloseDay $close): RedirectResponse
    {
        $user = $this->user($request);

        if (! $this->canCloseDay($user)) {
            abort(403);
        }

        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $branch = $this->branch($request);
        $date = (string) $request->input('date');
        $closing = $close->handle($user, $branch->id, $date);

        Inertia::flash('toast', ['type' => 'success', 'message' => ReportService::dayNumber($closing->day_z_no).' · the day is closed.']);

        return redirect()->route('reports.daily.close', ['date' => $date]);
    }

    public function dayPdf(ReportRequest $request, string $date): HttpResponse
    {
        [$branch, $closing] = $this->closedDay($request, $date);

        return $this->export->pdf($this->reports->dayZ($branch->id, $date), $this->user($request), $branch->id, PrintDocument::DayZ, $closing->id ?? 0, "day-z-{$date}.pdf");
    }

    public function dayCsv(ReportRequest $request, string $date): HttpResponse
    {
        [$branch, $closing] = $this->closedDay($request, $date);

        return $this->export->csv($this->reports->dayZ($branch->id, $date), $this->user($request), $branch->id, PrintDocument::DayZ, $closing->id ?? 0, "day-z-{$date}.csv");
    }

    public function dayPrint(ReportRequest $request, LogPrint $log, string $date): JsonResponse
    {
        [$branch, $closing] = $this->closedDay($request, $date);

        if ($closing === null) {
            abort(422, 'A day that is not closed cannot be printed.');
        }

        $row = $log->handle($this->user($request), $branch->id, PrintDocument::DayZ, $closing->id);

        return response()->json(['reprint' => $row->is_reprint]);
    }

    /** Occupancy and guests (40), or its CSV. */
    public function occupancy(ReportRequest $request): Response|HttpResponse
    {
        $branch = $this->branch($request);
        $report = $this->reports->occupancy($branch->id, $request->businessDate($this->clock->local()->subDay()->toDateString()));

        if ($request->query('export') === 'csv') {
            return $this->export->table($this->screens->occupancyTables($report), $this->user($request), $branch->id, null, "occupancy-{$report['date']}.csv");
        }

        return Inertia::render('Reports/Occupancy', ['report' => $report]);
    }

    /** Housekeeping (41) for a day or one shift, or its CSV. */
    public function housekeeping(ReportRequest $request): Response|HttpResponse
    {
        $branch = $this->branch($request);
        $shift = $request->filled('shift') ? $this->shiftFor($request, $request->integer('shift')) : null;
        $date = $shift?->business_date->toDateString() ?? $request->businessDate($this->clock->local()->subDay()->toDateString());
        $report = $this->reports->housekeeping($branch->id, $date, $shift);

        if ($request->query('export') === 'csv') {
            return $this->export->table($this->screens->housekeepingTables($report), $this->user($request), $branch->id, null, "housekeeping-{$date}.csv");
        }

        $shifts = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereDate('business_date', $date)->orderBy('opened_at')->get(['id', 'period_name', 'z_no']);

        return Inertia::render('Reports/Housekeeping', [
            'report' => $report,
            'shifts' => $shifts->map(fn (ShiftSession $s): array => ['id' => $s->id, 'label' => "{$s->period_name} shift"])->values()->all(),
        ]);
    }

    /** Inventory (42), or its CSV. */
    public function inventory(ReportRequest $request, ReportQuery $queries): Response|HttpResponse
    {
        $branch = $this->branch($request);
        $report = $this->reports->inventory($branch->id, $request->businessDate($this->clock->local()->subDay()->toDateString()));

        if ($request->query('export') === 'csv') {
            return $this->export->table($this->screens->inventoryTables($report), $this->user($request), $branch->id, null, "inventory-{$report['date']}.csv");
        }

        return Inertia::render('Reports/Inventory', ['report' => $report]);
    }

    /** Audit trail (43), filtered by type and person, or its CSV. */
    public function audit(ReportRequest $request): Response|HttpResponse
    {
        $branch = $this->branch($request);
        $report = $this->reports->audit(
            $branch->id,
            $request->businessDate($this->clock->localDate()),
            (string) $request->query('type', 'all'),
            $request->filled('user') ? $request->integer('user') : null,
        );

        if ($request->query('export') === 'csv') {
            return $this->export->table($this->screens->auditTables($report), $this->user($request), $branch->id, null, "audit-{$report['date']}.csv");
        }

        return Inertia::render('Reports/Audit', ['report' => $report]);
    }

    /** The branch of this request: the user's own, or for an owner `?branch=`, the opened one, or the first. */
    private function branch(Request $request): Branch
    {
        $user = $this->user($request);

        if ($user->role !== Role::Owner) {
            return Branch::query()->withoutGlobalScopes()->findOrFail($user->branch_id);
        }

        $id = $request->filled('branch') ? $request->integer('branch') : $this->currentBranch->id();

        return $id === null
            ? Branch::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail()
            : Branch::query()->withoutGlobalScopes()->findOrFail($id);
    }

    /**
     * A shift of a branch this user may read. An open shift's preview shows expected cash, so
     * a supervisor who recorded money on it is refused (TRD §4.10).
     */
    private function shiftFor(Request $request, int $id): ShiftSession
    {
        $user = $this->user($request);
        $shift = ShiftSession::query()->withoutGlobalScopes()->findOrFail($id);

        if ($user->role !== Role::Owner && $shift->branch_id !== $user->branch_id) {
            abort(404);
        }

        if ($shift->status !== ShiftStatus::Closed && $user->role === Role::Supervisor && self::recordedMoney($shift, $user)) {
            abort(403);
        }

        return $shift;
    }

    private static function recordedMoney(ShiftSession $shift, User $user): bool
    {
        foreach ([FolioLine::class, Payment::class, CashMovement::class] as $model) {
            if ($model::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->where('created_by', $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: Branch, 1: DayClosing|null} */
    private function closedDay(Request $request, string $date): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            abort(404);
        }

        $branch = $this->branch($request);

        return [$branch, DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereDate('business_date', $date)->first()];
    }

    private function refusePreviewExport(bool $preview): void
    {
        if ($preview) {
            throw PreviewNotExportable::make();
        }
    }

    private function canCloseDay(User $user): bool
    {
        return in_array($user->role, [Role::Backoffice, Role::Admin, Role::Owner], true);
    }

    private static function zFile(ShiftSession $shift): string
    {
        return 'z-'.str_pad((string) $shift->z_no, 4, '0', STR_PAD_LEFT)."-{$shift->business_date->toDateString()}-{$shift->period_name}";
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
