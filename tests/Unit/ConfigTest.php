<?php

declare(strict_types=1);

it('has the product constants of TRD §3.9 in config/hotel.php', function (): void {
    expect(config('hotel.name'))->toBe('Homi')
        ->and(config('hotel.display_timezone'))->toBe('Asia/Manila')
        ->and(config('hotel.pin.length'))->toBe(4)
        ->and(config('hotel.login.max_attempts'))->toBe(5)
        ->and(config('hotel.login.lock_minutes'))->toBe(15)
        ->and(config('hotel.polling'))->toBe([
            'board' => 15,
            'kiosk_holds' => 5,
            'housekeeping' => 20,
            'kiosk_availability' => 20,
            'heartbeat' => 5,
            'dashboard' => 30,
        ]);
});

it('has a credit for every photo file it lists', function (): void {
    foreach (array_keys(config('hotel.photo_credits')) as $file) {
        expect(public_path("images/photos/{$file}"))->toBeFile();
    }
});

it('puts queue, cache and session on the database driver', function (): void {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain("QUEUE_CONNECTION=database\n")
        ->toContain("CACHE_STORE=database\n")
        ->toContain("SESSION_DRIVER=database\n")
        ->toContain("DB_CONNECTION=mysql\n")
        ->toContain("DB_DATABASE=hotelv4\n");
});

it('runs the tests on MySQL, never SQLite', function (): void {
    expect(config('database.default'))->toBe('mysql')
        ->and(config('database.connections.mysql.database'))->toBe('hotelv4_test');
});
