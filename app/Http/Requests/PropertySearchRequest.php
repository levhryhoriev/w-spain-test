<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\PropertySearchCriteria;
use App\Rules\CanonicalPositiveInteger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class PropertySearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['required', new CanonicalPositiveInteger(32_767)],
            'city' => ['sometimes', 'required', 'string', 'max:120'],
            'page' => ['sometimes', 'required', new CanonicalPositiveInteger(2_147_483_647)],
        ];
    }

    public function criteria(CarbonImmutable $comparisonInstant): PropertySearchCriteria
    {
        return PropertySearchCriteria::fromValidated($this->validated(), $comparisonInstant);
    }
}
