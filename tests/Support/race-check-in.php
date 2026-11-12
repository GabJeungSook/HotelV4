<?php

declare(strict_types=1);

/*
| One side of the two-simultaneous-check-ins race (TRD §10). Run by
| tests/Concurrency/SimultaneousCheckInTest.php as a separate PHP process against the
| hotelv4_test database: it boots the app, waits for the agreed start moment, then checks a
| guest in. Prints "ok:<stay id>" or "refused:<exception>".
|
| Usage: php race-check-in.php <start unix time> <room number> <username> <guest name>
*/

use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Hotel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt, $room, $username, $guest] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $stay = Hotel::checkIn($room, 12, by: Hotel::user($username), guest: $guest);
    echo 'ok:'.$stay->id;
} catch (RoomTaken|RoomNotAvailable $e) {
    echo 'refused:'.class_basename($e);
}
