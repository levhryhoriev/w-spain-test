<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\Rfc3339Instant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSupplierImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'supplier' => ['required', 'string', 'max:100', Rule::exists('suppliers', 'slug')],
            'external_import_id' => ['required', 'string', 'max:191'],
            'sent_at' => ['required', new Rfc3339Instant()],
            'offers' => ['present', 'array', 'list', 'max:500'],
            'offers.*' => [
                'required',
                'array:external_id,property,check_in,check_out,max_guests,price,currency,available_units,expires_at',
            ],
            'offers.*.external_id' => ['required', 'string', 'max:191', 'distinct:strict'],
            'offers.*.property' => ['required', 'array:code,name,city'],
            'offers.*.property.code' => ['required', 'string', 'max:100'],
            'offers.*.property.name' => ['required', 'string', 'max:255'],
            'offers.*.property.city' => ['required', 'string', 'max:120'],
            'offers.*.check_in' => ['required', 'date_format:Y-m-d'],
            'offers.*.check_out' => ['required', 'date_format:Y-m-d', 'after:offers.*.check_in'],
            'offers.*.max_guests' => ['required', 'integer:strict', 'between:1,32767'],
            'offers.*.price' => ['required', 'integer:strict', 'between:0,9223372036854775807'],
            'offers.*.currency' => ['required', 'string', Rule::in(['EUR'])],
            'offers.*.available_units' => ['required', 'integer:strict', 'between:0,2147483647'],
            'offers.*.expires_at' => ['required', new Rfc3339Instant()],
        ];
    }
}
