<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\BranchStatus;
use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\Rate;
use App\Models\RoomType;
use App\Support\Clock;
use Illuminate\Support\Str;

/**
 * The public welcome page (59, TRD §4.11): the branches with `welcome_enabled` and status
 * active, and for the chosen one its active room types with the lowest hourly and the lowest
 * overnight rate read live from `rates`. Prices only: no availability, no booking.
 */
final class WelcomeQuery
{
    /** The three hero slides (DESIGN_SYSTEM §6.7), each with its 1200 px export. */
    private const HERO = ['lobby', 'signin-afternoon', 'signin-evening'];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function page(?int $branchId, ?int $rememberedId): array
    {
        $branches = Branch::query()->withoutGlobalScopes()
            ->where('status', BranchStatus::Active->value)
            ->where('welcome_enabled', true)
            ->orderBy('name')
            ->get();

        $branch = $branches->firstWhere('id', $branchId)
            ?? $branches->firstWhere('id', $rememberedId)
            ?? $branches->first();

        $types = $branch === null ? [] : $this->types($branch);
        $photos = array_merge(
            array_map(fn (string $p): string => "{$p}.jpg", self::HERO),
            array_values(array_filter(array_map(fn (array $t): ?string => $t['photo'] === null ? null : basename($t['photo']), $types))),
        );

        /** @var array<string, array{by: string, url: string}> $credits */
        $credits = (array) config('hotel.photo_credits', []);

        return [
            'headline' => (string) config('hotel.welcome.headline'),
            'subline' => (string) config('hotel.welcome.subline'),
            'hero' => array_map(fn (string $p): array => [
                'src' => "/images/photos/{$p}.jpg",
                'srcset' => "/images/photos/{$p}-1200.jpg 1200w, /images/photos/{$p}.jpg 2000w",
            ], self::HERO),
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branch' => $branch === null ? null : [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                // "Gensan Drive, General Santos City" → "General Santos City" for the eyebrow.
                'place' => trim((string) Str::afterLast($branch->address, ',')),
                'deposit' => $branch->deposit_enabled && $branch->deposit_amount !== null && $branch->deposit_amount->isPositive()
                    ? ['name' => $branch->deposit_name, 'amount' => $branch->deposit_amount->centavos()]
                    : null,
                // "Cash or GCash" in the facts strip and Find us (59, mockup v6); a card number is never shown.
                'methods' => $branch->payment_methods->map(fn ($m): string => $m->value)->values()->all(),
            ],
            'types' => $types,
            'credits' => collect(array_unique($photos))
                ->filter(fn (string $file): bool => isset($credits[$file]))
                ->map(fn (string $file): array => ['file' => $file, 'by' => $credits[$file]['by'], 'url' => $credits[$file]['url']])
                ->unique('url')
                ->values()
                ->all(),
            'year' => (int) $this->clock->local()->format('Y'),
        ];
    }

    /**
     * One card per active room type that has a price on sale.
     *
     * @return list<array{id: int, name: string, line: string|null, photo: string|null, price: array{amount: int, hours: int}|null, overnight: int|null}>
     */
    private function types(Branch $branch): array
    {
        $rates = Rate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get();
        $types = RoomType::query()->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $cards = [];

        foreach ($types as $type) {
            $mine = $rates->where('room_type_id', $type->id);

            $hourly = $branch->hourly_enabled
                ? $mine->where('stay_kind', StayKind::Hourly)->sortBy(fn (Rate $r): array => [$r->amount?->centavos() ?? 0, $r->hours])->first()
                : null;
            $overnight = $branch->overnight_enabled
                ? $mine->where('stay_kind', StayKind::Overnight)->sortBy(fn (Rate $r): int => $r->amount?->centavos() ?? 0)->first()
                : null;

            if ($hourly === null && $overnight === null) {
                continue;
            }

            $slug = Str::slug($type->name);

            $cards[] = [
                'id' => $type->id,
                'name' => $type->name,
                'line' => $type->description,
                'photo' => is_file(public_path("images/photos/room-{$slug}.jpg")) ? "/images/photos/room-{$slug}.jpg" : null,
                'price' => $hourly === null ? null : ['amount' => $hourly->amount?->centavos() ?? 0, 'hours' => $hourly->hours],
                'overnight' => $overnight?->amount?->centavos(),
            ];
        }

        return $cards;
    }
}
