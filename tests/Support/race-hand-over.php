<?php

declare(strict_types=1);

/*
| One side of the concurrent-close race (TRD §10, "Z numbers are sequential per branch with no
| gaps or repeats under concurrent closes"). Run by tests/Concurrency/ConcurrentCloseTest.php as
| a separate PHP process: it boots the app, waits for the agreed start moment, then hands the
| closing shift over to the receiver. Prints "ok:<new shift id>" or "refused:<exception>".
|
| Usage: php race-hand-over.php <start unix time> <closer username> <receiver username>
*/

use App\Actions\Shifts\HandOverShift;
use App\Exceptions\DomainException;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\Hotel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $startAt, $closer, $receiver] = $argv;

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $shift = app(HandOverShift::class)->toReceiver(
        Hotel::user($closer), Hotel::branch()->id, Hotel::user($receiver), Hotel::PIN, Money::ofCentavos((int) getenv('RACE_RECEIVED')), Hotel::device(),
    );
    echo 'ok:'.$shift->id;
} catch (DomainException $e) {
    echo 'refused:'.class_basename($e);
}
