<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * For the summary tables, whose primary key spans several columns.
 * Eloquent has no composite keys, so saves are keyed on every key column.
 *
 * @mixin Model
 */
trait HasCompositeKey
{
    /** @return list<string> */
    abstract public function compositeKey(): array;

    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        foreach ($this->compositeKey() as $column) {
            $query->where($column, '=', $this->getOriginal($column) ?? $this->getAttribute($column));
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->setKeysForSaveQuery($query);
    }
}
