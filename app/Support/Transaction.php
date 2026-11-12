<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * The one database transaction of a business action (TRD §3.2, §5): DB::transaction with
 * three tries on a deadlock. An action called inside another action's run() joins the outer
 * transaction (a savepoint), so both roll back together.
 *
 * afterwards() queues writes that must survive a rollback, such as the pin_attempts row of a
 * wrong PIN (TRD §4.12: every try is logged, and a wrong PIN rolls the action back). They run
 * once the outermost run() has finished, whether it committed or rolled back. Outside run()
 * they run at once.
 */
final class Transaction
{
    private int $depth = 0;

    /** @var list<Closure(): void> */
    private array $deferred = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $this->depth++;

        try {
            return $this->db->connection()->transaction($work, 3);
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $this->flush();
            }
        }
    }

    /** @param  Closure(): void  $write */
    public function afterwards(Closure $write): void
    {
        if ($this->depth === 0) {
            $write();

            return;
        }

        $this->deferred[] = $write;
    }

    private function flush(): void
    {
        $writes = $this->deferred;
        $this->deferred = [];
        $failure = null;

        foreach ($writes as $write) {
            try {
                $write();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
