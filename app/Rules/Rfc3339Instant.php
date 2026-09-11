<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

final class Rfc3339Instant implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (
            ! is_string($value)
            || preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',
                $value,
            ) !== 1
        ) {
            $fail('validation.date_format');

            return;
        }

        $timezoneLength = str_ends_with($value, 'Z') ? 1 : 6;
        $instant = substr($value, 0, -$timezoneLength);
        $timezone = substr($value, -$timezoneLength);
        $fractionPosition = strrpos($instant, '.');

        if ($fractionPosition === false) {
            $normalized = $instant . '.000000' . $timezone;
        } else {
            $fractionLength = strlen($instant) - $fractionPosition - 1;
            $normalized = $instant . str_repeat('0', 6 - $fractionLength) . $timezone;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.up', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || $errors !== false) {
            $fail('validation.date_format');
        }
    }
}
