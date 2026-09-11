<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class CanonicalPositiveInteger implements ValidationRule
{
    public function __construct(private int $maximum)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
        } else {
            $fail('The :attribute field must be a canonical positive integer.');

            return;
        }

        if (! is_int($integer) || $integer < 1 || $integer > $this->maximum) {
            $fail('The :attribute field is out of range.');
        }
    }
}
