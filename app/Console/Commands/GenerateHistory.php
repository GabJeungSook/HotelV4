<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Owner\CreateBranch;
use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\ReceiveStock;
use App\Actions\Reports\CloseDay;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\HandOverShift;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckInGuest;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\BranchStatus;
use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ProductKind;
use App\Enums\Role;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\FolioLine;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\CurrentShift;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Summaries\CheckSummaries;
use App\Summaries\RebuildSummaries;
use App\Support\Clock;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\BranchSeeder;
use Database\Seeders\DemoBranch;
use Database\Seeders\DiscountSeeder;
use Database\Seeders\ExtensionRateSeeder;
use Database\Seeders\ListSeeder;
use Database\Seeders\OwnerSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\RateSeeder;
use Database\Seeders\ReasonSeeder;
use Database\Seeders\RoomSeeder;
use Database\Seeders\ShiftDefinitionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Local-only: wipe the database and replay a realistic history through the real actions (open
 * shift → check-ins → POS → check-outs → expense → close & hand over → close day), day by day on
 * a travelled clock, for both branches, from --from to today. Summaries are rebuilt and checked
 * at the end, so every dashboard, report and stat matches the ledger (not random rows).
 */
final class GenerateHistory extends Command
{
    protected $signature = 'homi:generate-history {--from=2026-10-01} {--force : Skip the confirmation}';

    protected $description = 'Wipe and generate accurate operational history from --from to today (local only).';

    /** @var list<string> guest names, cycled for check-ins. */
    private array $names = [
        'Mateo Rivera', 'Sofia Mendoza', 'Gabriel Santos', 'Isabella Cruz', 'Lucas Garcia', 'Camila Reyes',
        'Andres Flores', 'Valentina Torres', 'Diego Ramos', 'Lucia Morales', 'Emilio Castro', 'Martina Aquino',
        'Rafael Villanueva', 'Elena Pascual', 'Tomas Delgado', 'Bianca Soriano', 'Nicolas Padilla', 'Carmen Ocampo',
        'Javier Navarro', 'Daniela Esguerra', 'Marco Bautista', 'Paloma Gutierrez', 'Enrique Lagman', 'Rosa Carpio',
    ];

    private int $nameCursor = 0;

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This command only runs in the local environment.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This WIPES the database and regenerates history. Continue?')) {
            return self::SUCCESS;
        }

        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $dates = $this->datesBetween((string) $this->option('from'), $today);
        $this->info(sprintf('Generating %d days (%s → %s) for both branches.', count($dates), $dates[0], $today));

        mt_srand(20261119); // deterministic

        $this->components->task('Fresh database + base structure', fn () => $this->freshStructure());
        $this->components->task('Second branch structure (Koronadal)', fn () => $this->ensureKoronadal());

        $branches = Branch::query()->withoutGlobalScopes()->orderBy('id')->get();

        try {
            foreach ($branches as $branch) {
                $this->newLine();
                $this->components->info("Branch: {$branch->name}");
                $this->generateShifts($branch, $dates);
            }

            // Summaries must match the ledger before CloseDay will accept a day.
            $this->components->task('Rebuild summaries (pre-close)', fn () => app(RebuildSummaries::class)->run());

            foreach ($branches as $branch) {
                $this->closeDays($branch, $dates);
            }
        } finally {
            app(Clock::class)->unfreeze();
            Carbon::setTestNow();
            Branch::query()->withoutGlobalScopes()->update(['housekeeping_enabled' => true]); // restore normal operation
        }

        $this->newLine();
        $this->components->task('Rebuild summaries', fn () => app(RebuildSummaries::class)->run());

        $mismatches = app(CheckSummaries::class)->run();
        if ($mismatches === []) {
            $this->components->info('Summaries reconcile with the ledger: no mismatches.');
        } else {
            $this->components->error('Summary mismatches found: '.json_encode($mismatches));

            return self::FAILURE;
        }

        $this->reportTotals();

        return self::SUCCESS;
    }

    // ---- setup -------------------------------------------------------------

    private function freshStructure(): bool
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        foreach ([
            OwnerSeeder::class,
            BranchSeeder::class,
            ShiftDefinitionSeeder::class,
            RoomSeeder::class,
            RateSeeder::class,
            ExtensionRateSeeder::class,
            DiscountSeeder::class,
            ListSeeder::class,
            ReasonSeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
        ] as $class) {
            Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
        }

        return true;
    }

    /** Create the Koronadal branch structure (branch, rooms, staff) — no operational demo. */
    private function ensureKoronadal(): bool
    {
        if (Branch::query()->withoutGlobalScopes()->where('code_prefix', 'KOR')->exists()) {
            return true;
        }

        $owner = User::query()->where('role', Role::Owner)->where('username', 'ramon.alvarez')->firstOrFail();
        $source = DemoBranch::get();

        ['branch' => $branch] = app(CreateBranch::class)->handle($owner, [
            'name' => 'Hotel Dalisay Koronadal',
            'address' => 'National Highway, Koronadal City',
            'code_prefix' => 'KOR',
            'admin_name' => 'Marisol Fuentes',
            'admin_username' => 'marisol.fuentes',
            'copy_from_id' => $source->id,
        ]);

        $branch->update(['status' => BranchStatus::Active, 'payment_methods' => ['cash', 'gcash'], 'next_stay_number' => 1, 'next_pos_number' => 1, 'next_z_number' => 1]);
        $branch->refresh();

        $types = RoomType::query()->where('branch_id', $branch->id)->get()->keyBy('name');
        $floors = [
            ['number' => 1, 'label' => '1F', 'type' => 'Standard', 'numbers' => ['101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '112', '113', '114', '115', '116', '117', '118']],
            ['number' => 2, 'label' => '2F', 'type' => 'Deluxe', 'numbers' => ['201', '202', '203', '204', '205', '206', '207', '208', '209', '210']],
            ['number' => 3, 'label' => '3F', 'type' => 'Suite', 'numbers' => ['301', '302', '3A', '3B']],
        ];
        foreach ($floors as $floor) {
            $floorRow = Floor::query()->create(['branch_id' => $branch->id, 'number' => $floor['number'], 'label' => $floor['label']]);
            $typeId = $types[$floor['type']]->id;
            foreach ($floor['numbers'] as $number) {
                Room::query()->create(['branch_id' => $branch->id, 'floor_id' => $floorRow->id, 'room_type_id' => $typeId, 'number' => $number]);
            }
        }

        Product::query()->where('branch_id', $branch->id)->update(['stock_on_hand' => 200]);

        foreach ([
            ['Cora Diaz', 'cora.diaz', Role::Frontdesk, '1234'],
            ['Elias Tan', 'elias.tan', Role::Frontdesk, '1234'],
            ['Noel Rama', 'noel.rama', Role::Housekeeper, null],
        ] as [$name, $username, $role, $pin]) {
            User::query()->create(['branch_id' => $branch->id, 'name' => $name, 'username' => $username, 'password' => 'password', 'pin_hash' => $pin, 'role' => $role, 'is_active' => true]);
        }

        return true;
    }

    // ---- generation --------------------------------------------------------

    /** @param list<string> $dates */
    private function generateShifts(Branch $branch, array $dates): void
    {
        $bid = $branch->id;
        $branch->update(['housekeeping_enabled' => false]); // rooms return to sale at once; restored after
        $people = User::query()->where('branch_id', $bid)->where('role', Role::Frontdesk)->whereNotNull('pin_hash')->orderBy('id')->take(2)->get()->all();
        $sellable = Product::query()->where('branch_id', $bid)->where('kind', ProductKind::FoodDrink)->where('is_active', true)->where('tracks_stock', true)->get();
        $expenseCat = ExpenseCategory::query()->where('branch_id', $bid)->first();
        [$amUser, $pmUser] = [$people[0], $people[1]];

        $this->setClock($this->utcAt($dates[0], '08:00'));
        app(OpenShift::class)->handle($amUser, $bid, Money::ofCentavos(500000));

        $lastDate = $dates[array_key_last($dates)];
        $bar = $this->output->createProgressBar(count($dates));
        $bar->start();

        foreach ($dates as $date) {
            $isLast = $date === $lastDate;
            foreach ($isLast ? ['AM'] : ['AM', 'PM'] as $period) {
                $isAm = $period === 'AM';
                $by = $isAm ? $amUser : $pmUser;

                $this->runShift($branch, $by, $sellable, $expenseCat);

                $isFinalOpenShift = $isLast && $isAm; // today's shift stays open
                if (! $isFinalOpenShift) {
                    $this->setClock($isAm ? $this->utcAt($date, '20:00') : $this->utcAt($this->nextDate($date), '08:00'));
                    $this->handOver($branch, $by, $isAm ? $pmUser : $amUser);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /**
     * Close every completed day in order; today's open shift keeps its day open.
     *
     * @param  list<string>  $dates
     */
    private function closeDays(Branch $branch, array $dates): void
    {
        $admin = User::query()->where('branch_id', $branch->id)->where('role', Role::Admin)->firstOrFail();
        foreach (array_slice($dates, 0, count($dates) - 1) as $date) {
            app(CloseDay::class)->handle($admin, $branch->id, $date);
        }
    }

    /** @param  Collection<int, Product>  $sellable */
    private function runShift(Branch $branch, User $by, Collection $sellable, ?ExpenseCategory $expenseCat): void
    {
        $bid = $branch->id;

        // Check in a few walk-ins into vacant rooms, paid in full.
        $vacant = $this->vacantRooms($bid)->shuffle()->take(mt_rand(2, 4));
        foreach ($vacant as $room) {
            [$kind, $qty] = [StayKind::Hourly, [6, 12, 24][mt_rand(0, 2)]];
            $quote = app(PricingService::class)->quoteStay(RateCard::forBranch($branch), $room->room_type_id, $kind, $qty, app(Clock::class)->now(), null);
            app(CheckInGuest::class)->handle($by, $room, $this->nextName(), $kind, $qty, PaymentMethod::Cash, (string) Str::uuid(), $quote->totalToCollect());
        }

        // A couple of room charges, left on the folio (forwarded if not paid off at checkout).
        $inHouse = Stay::query()->where('branch_id', $bid)->where('state', 'checked_in')->get();
        foreach ($inHouse->shuffle()->take(min(2, $inHouse->count())) as $stay) {
            $product = $sellable->random();
            $this->ensureStock($branch, $by, $product);
            app(AddCharges::class)->handle($by, $stay, [$product->id => mt_rand(1, 2)]);
        }

        // A walk-in POS sale.
        if ($sellable->isNotEmpty()) {
            $product = $sellable->random();
            $this->ensureStock($branch, $by, $product);
            app(CompleteSale::class)->handle($by, $bid, PosMode::WalkIn, [$product->id => 1], (string) Str::uuid(), Money::ofPesos(1000));
        }

        // Mid-shift: about 45% of in-house guests check out and settle their balance.
        foreach ($inHouse->shuffle() as $stay) {
            if (mt_rand(1, 100) > 45) {
                continue;
            }
            $fresh = $stay->fresh();
            if ($fresh === null || $fresh->state !== StayState::CheckedIn) {
                continue;
            }
            $due = app(StayQuery::class)->balanceDue($fresh->id);
            app(CheckOutGuest::class)->handle($by, $fresh, payWith: PaymentMethod::Cash, amountGiven: $due->isZero() ? null : $due);
        }

        // An occasional expense.
        if ($expenseCat !== null && mt_rand(1, 3) === 1) {
            app(RecordCashMovement::class)->handle($by, $bid, CashMovementKind::Expense, Money::ofPesos(mt_rand(50, 400)), 'Supplies', (string) Str::uuid(), $expenseCat);
        }
    }

    private function handOver(Branch $branch, User $by, User $receiver): void
    {
        $bid = $branch->id;
        $close = app(CloseShift::class);
        $shift = app(CurrentShift::class)->find($bid);
        if ($shift === null) {
            return;
        }
        $close->begin($by, $bid);
        $expected = $close->expected($shift);
        $close->count($by, $bid, ['coins' => $expected->centavos()]);
        $close->result($by, $bid);
        app(HandOverShift::class)->toReceiver($by, $bid, $receiver, '1234', $expected, (string) Str::uuid(), 'Shift handover');
    }

    private function ensureStock(Branch $branch, User $by, Product $product): void
    {
        if ($product->tracks_stock && $product->fresh()->stock_on_hand < 6) {
            app(ReceiveStock::class)->handle($by, $branch->id, $product->id, 60, 'Restock');
        }
    }

    /** @return Collection<int, Room> */
    private function vacantRooms(int $branchId): Collection
    {
        $busy = Stay::query()->where('branch_id', $branchId)->whereIn('state', ['checked_in', 'held'])->pluck('room_id');

        return Room::query()->where('branch_id', $branchId)->whereNotIn('id', $busy)->get();
    }

    // ---- helpers -----------------------------------------------------------

    /** @return list<string> */
    private function datesBetween(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    private function nextDate(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    private function utcAt(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Asia/Manila')->setTimezone('UTC');
    }

    private function setClock(CarbonImmutable $utc): void
    {
        app(Clock::class)->freeze($utc);
        Carbon::setTestNow($utc);
    }

    private function nextName(): string
    {
        $name = $this->names[$this->nameCursor % count($this->names)];
        $this->nameCursor++;

        return $name;
    }

    private function reportTotals(): void
    {
        $this->newLine();
        foreach (Branch::query()->withoutGlobalScopes()->orderBy('id')->get() as $branch) {
            $shifts = ShiftSession::query()->where('branch_id', $branch->id)->count();
            $stays = Stay::query()->where('branch_id', $branch->id)->count();
            $sales = (int) FolioLine::query()->whereIn('shift_session_id', ShiftSession::query()->where('branch_id', $branch->id)->pluck('id'))->sum('amount');
            $this->line(sprintf('  <info>%s</info>: %d shifts, %d stays, ₱%s in ledger lines', $branch->name, $shifts, $stays, number_format($sales / 100, 2)));
        }
    }
}
