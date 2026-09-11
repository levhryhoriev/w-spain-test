<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'client_reference' => ['required', 'string', 'max:191'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'string', 'max:254', 'email:rfc'],
        ];
    }

    public function clientReference(): string
    {
        return $this->validatedString('client_reference');
    }

    public function customerName(): string
    {
        return $this->validatedString('customer_name');
    }

    public function customerEmail(): string
    {
        return $this->validatedString('customer_email');
    }

    protected function prepareForValidation(): void
    {
        $customerEmail = $this->input('customer_email');

        if (is_string($customerEmail)) {
            $this->merge(['customer_email' => mb_strtolower($customerEmail)]);
        }
    }

    private function validatedString(string $key): string
    {
        $value = $this->validated($key);

        if (! is_string($value)) {
            throw new LogicException('The validated reservation data is invalid.');
        }

        return $value;
    }
}
