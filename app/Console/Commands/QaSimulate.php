<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Owner\CreateBranch;
use App\Actions\Shifts\OpenShift;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckInGuest;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\BranchStatus;
use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\ProductKind;
use App\Enums\Role;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Enums\VatMode;
use App\Exceptions\ActionRefused;
use App\Exceptions\DiscountNotAllowed;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\FolioLine;
use App\Models\Product;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\CurrentBranch;
use App\Services\CurrentShift;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\Reports\Totals;
use App\Services\ReportService;
use App\Support\Clock;
use App\Support\Money;
use Database\Seeders\BranchSeeder;
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
 * QA harness (local only): two branches with different settings, ten guests each from check-in to
 * check-out, then every report figure is tied out against the ledger. Not part of the product.
 */
final class QaSimulate extends Command
{
    protected $signature = 'homi:qa-sim';

    protected $description = 'QA: two branches, different settings, 10 guests each to checkout, then verify report accuracy (local only).';

    private int $failures = 0;

    public function handle(CurrentBranch $cb): int
    {
        if (! app()->environment('local')) {
            $this->error('Local environment only.');

            return self::FAILURE;
        }

        // Clean, deterministic baseline: fresh schema + structure only (no demo operational data),
        // so every room is free and no shift is open before the simulation.
        $this->components->task('Fresh schema + structure (no demo)', function (): void {
            Artisan::call('migrate:fresh', ['--force' => true]);
            foreach ([OwnerSeeder::class, BranchSeeder::class, ShiftDefinitionSeeder::class, RoomSeeder::class, RateSeeder::class, ExtensionRateSeeder::class, DiscountSeeder::class, ListSeeder::class, ReasonSeeder::class, ProductSeeder::class, UserSeeder::class] as $class) {
                Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
            }
        });

        $owner = User::query()->withoutGlobalScopes()->where('role', Role::Owner)->firstOrFail();
        $b1 = Branch::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();

        // Branch 1: VAT 12% (percentage), deposit on.
        $b1->update(['vat_enabled' => true, 'vat_mode' => VatMode::Percent, 'vat_rate_percent' => 12, 'vat_amount' => 0, 'deposit_enabled' => true, 'tin' => '111-111-111-000', 'registered_name' => 'Dalisay GenSan Inc.']);

        // Branch 2: fresh, copied from branch 1, then DIFFERENT settings — VAT fixed ₱20/transaction, deposit off.
        $b2 = $this->ensureBranch2($owner, $b1);
        $b2->update(['vat_enabled' => true, 'vat_mode' => VatMode::Amount, 'vat_amount' => 2000, 'vat_rate_percent' => 12, 'deposit_enabled' => false, 'tin' => '222-222-222-000', 'registered_name' => 'Dalisay Koronadal Inc.']);

        foreach ([$b1->fresh(), $b2->fresh()] as $branch) {
            $cb->run($branch->id, fn () => $this->simulateBranch($branch));
        }

        $this->newLine();
        $this->components->info('Global integrity');
        $this->check('data invariants hold', $this->callQuiet('homi:check-invariants') === self::SUCCESS);
        $this->callQuiet('homi:rebuild-summaries');
        $this->check('summaries agree with the ledgers', $this->callQuiet('homi:check-summaries') === self::SUCCESS);

        $this->newLine();
        if ($this->failures > 0) {
            $this->error("QA FAILED — {$this->failures} check(s) did not pass.");

            return self::FAILURE;
        }
        $this->info('QA PASSED — both branches simulated end to end and every report figure ties out to the ledger.');

        return self::SUCCESS;
    }

    private function simulateBranch(Branch $branch): void
    {
        $this->newLine();
        $this->components->info(sprintf('%s — VAT %s, deposit %s', $branch->name, $branch->vat_mode->value, $branch->deposit_enabled ? 'on' : 'off'));

        $fd = User::query()->where('branch_id', $branch->id)->where('role', Role::Frontdesk)->orderBy('id')->firstOrFail();
        if (app(CurrentShift::class)->find($branch->id) === null) {
            app(OpenShift::class)->handle($fd, $branch->id, Money::ofCentavos(500000));
        }
        $shift = app(CurrentShift::class)->for($branch->id);

        $rooms = Room::query()->where('status', RoomStatus::Available)->orderBy('number')->take(10)->get()->values();
        if ($rooms->count() < 10) {
            $this->check("has 10 available rooms (found {$rooms->count()})", false);

            return;
        }

        $senior = Discount::query()->where('is_active', true)->where('is_vat_exempt', true)->first();
        $promo = Discount::query()->where('is_active', true)->where('is_vat_exempt', false)->first();
        $product = Product::query()->where('sellable', true)->where('kind', ProductKind::FoodDrink)->where('price_amount', '>', 0)->first();

        $card = RateCard::forBranch($branch);
        $pricing = app(PricingService::class);
        $checkIn = app(CheckInGuest::class);
        $now = app(Clock::class)->now();
        $discounted = 0;

        $stays = [];
        foreach ($rooms as $i => $room) {
            [$kind, $qty] = match (true) {
                $i === 6 || $i === 7 => [StayKind::Overnight, 1],
                $i % 2 === 0 => [StayKind::Hourly, 6],
                default => [StayKind::Hourly, 12],
            };
            $discount = $i === 2 ? $senior : ($i === 5 ? $promo : null);

            try {
                $terms = $discount !== null ? DiscountTerms::of($discount) : null;
                $quote = $pricing->quoteStay($card, $room->room_type_id, $kind, $qty, $now, $terms);
                $stays[] = $checkIn->handle($fd, $room, 'QA Guest '.($i + 1), $kind, $qty, PaymentMethod::Cash, (string) Str::uuid(), $quote->totalToCollect(), null, false, $discount);
                if ($discount !== null) {
                    $discounted++;
                }
            } catch (DiscountNotAllowed|ActionRefused) {
                // Discount not eligible for this rate — check in without it (still a valid stay).
                $quote = $pricing->quoteStay($card, $room->room_type_id, $kind, $qty, $now);
                $stays[] = $checkIn->handle($fd, $room, 'QA Guest '.($i + 1), $kind, $qty, PaymentMethod::Cash, (string) Str::uuid(), $quote->totalToCollect());
            }
        }

        // Add a POS charge to a few stays (settled at check-out).
        if ($product !== null) {
            foreach ([1, 3, 8] as $idx) {
                app(AddCharges::class)->handle($fd, $stays[$idx], [$product->id => 2], (string) Str::uuid());
            }
        }

        // Check everyone out, settling any balance and returning the deposit.
        $sq = app(StayQuery::class);
        $checkOut = app(CheckOutGuest::class);
        foreach ($stays as $stay) {
            $balance = $sq->balanceDue($stay->id);
            $checkOut->handle($fd, $stay->fresh(), true, true, [], $balance->isPositive() ? PaymentMethod::Cash : null, $balance->isPositive() ? $balance : null, null, true);
        }

        $this->components->twoColumnDetail('  checked in', (string) count($stays).' ('.$discounted.' with a discount)');
        $this->verifyBranch($branch, $shift->fresh(), $stays);
    }

    /** @param  list<Stay>  $stays */
    private function verifyBranch(Branch $branch, ShiftSession $shift, array $stays): void
    {
        $sq = app(StayQuery::class);

        $settled = collect($stays)->every(fn ($s): bool => $s->fresh()?->state === StayState::CheckedOut && $sq->balanceDue($s->id)->isZero());
        $this->check('all 10 guests checked out and settled (balance ₱0)', $settled);

        $totals = app(ReportService::class)->computeShiftTotals($shift, new Collection, new Collection);
        $sales = is_array($totals['sales'] ?? null) ? $totals['sales'] : [];

        // Every sales line the report breaks out must equal the ledger's own sum for that kind.
        $kindOk = true;
        $mismatch = [];
        foreach ($sales as $kind => $amount) {
            if ($kind === 'total') {
                continue;
            }
            $ledger = (int) FolioLine::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->where('kind', $kind)->whereNull('reversal_of_id')->sum('amount');
            if ($ledger !== (int) $amount) {
                $kindOk = false;
                $mismatch[] = "{$kind} ledger {$ledger} vs report {$amount}";
            }
        }
        $this->check('every sales line ties out to the ledger'.($kindOk ? '' : ' — '.implode('; ', $mismatch)), $kindOk);

        // Net sales: the report total equals the ledger's own sum of all lines.
        $reportNet = Totals::int($totals, 'sales.total');
        $ledgerNet = (int) FolioLine::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->whereNull('reversal_of_id')->sum('amount');
        $this->check("net sales tie out to the ledger (ledger ₱{$this->peso($ledgerNet)} = report ₱{$this->peso($reportNet)})", $ledgerNet === $reportNet);

        // VAT block always balances to net sales (the Z-report invariant), in either mode.
        $vat = $totals['vat'] ?? null;
        if (is_array($vat)) {
            $sum = (int) $vat['vatable'] + (int) $vat['vat'] + (int) $vat['exempt'];
            $this->check(sprintf('VAT block balances (%s: vatable+vat+exempt ₱%s = net ₱%s)', $branch->vat_mode->value, $this->peso($sum), $this->peso($reportNet)), $sum === $reportNet);
        }

        // Every deposit taken was returned at check-out (nothing left held).
        $held = collect($stays)->sum(fn ($s): int => $sq->depositHeld($s->id, DepositType::Key)->centavos() + $sq->depositHeld($s->id, DepositType::Guest)->centavos());
        $this->check('all deposits returned (nothing left held)', $held === 0);
    }

    private function ensureBranch2(User $owner, Branch $source): Branch
    {
        $existing = Branch::query()->withoutGlobalScopes()->where('code_prefix', 'QAB')->first();
        if ($existing !== null) {
            return $existing;
        }

        ['branch' => $branch] = app(CreateBranch::class)->handle($owner, [
            'name' => 'QA Branch Two',
            'address' => 'QA Street, Koronadal City',
            'code_prefix' => 'QAB',
            'admin_name' => 'QA Admin',
            'admin_username' => 'qa.admin',
            'copy_from_id' => $source->id,
        ]);

        $branch->update(['status' => BranchStatus::Active, 'payment_methods' => ['cash', 'gcash'], 'next_stay_number' => 1, 'next_pos_number' => 1, 'next_z_number' => 1]);
        $branch->refresh();

        $type = RoomType::query()->where('branch_id', $branch->id)->where('name', 'Standard')->first()
            ?? RoomType::query()->where('branch_id', $branch->id)->firstOrFail();
        $floor = Floor::query()->create(['branch_id' => $branch->id, 'number' => 1, 'label' => '1F']);
        foreach (range(1, 12) as $n) {
            Room::query()->create(['branch_id' => $branch->id, 'floor_id' => $floor->id, 'room_type_id' => $type->id, 'number' => (string) (100 + $n), 'status' => RoomStatus::Available]);
        }

        Product::query()->where('branch_id', $branch->id)->update(['stock_on_hand' => 200]);
        User::query()->create(['branch_id' => $branch->id, 'name' => 'QA Front Desk', 'username' => 'qa.front', 'password' => 'password', 'pin_hash' => '1234', 'role' => Role::Frontdesk, 'is_active' => true]);

        return $branch->fresh();
    }

    private function check(string $label, bool $ok): void
    {
        $this->components->twoColumnDetail('  '.$label, $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>');
        if (! $ok) {
            $this->failures++;
        }
    }

    private function callQuiet(string $command): int
    {
        return $this->callSilent($command);
    }

    private function peso(int $centavos): string
    {
        return number_format($centavos / 100, 2);
    }
}
