<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of the report pages (36–43): the business date, the range of daily sales,
 * the Z-report tab, the audit filters, the figure whose ledger rows are opened, the owner's
 * branch and an export format. Validation only; a bad value is a 422 on the field.
 */
final class ReportRequest extends FormRequest
{
    public const TABS = ['sales', 'rooms', 'cleaning', 'housekeeping', 'stocks', 'expenses'];

    public const AUDIT_TYPES = ['all', 'voids', 'cancels', 'transfers', 'shift'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'range' => ['nullable', 'in:day,week,month'],
            'tab' => ['nullable', 'in:'.implode(',', self::TABS)],
            'type' => ['nullable', 'in:'.implode(',', self::AUDIT_TYPES)],
            'user' => ['nullable', 'integer'],
            'shift' => ['nullable', 'integer'],
            'figure' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_]+(:[A-Za-z0-9 _.%₱-]+)?$/u'],
            'column' => ['nullable', 'string', 'max:20'],
            'branch' => ['nullable', 'integer'],
            'export' => ['nullable', 'in:csv'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function businessDate(string $default): string
    {
        $date = $this->query('date');

        return is_string($date) && $date !== '' ? $date : $default;
    }
}
