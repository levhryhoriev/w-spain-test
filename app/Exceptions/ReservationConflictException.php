<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ReservationConflictException extends RuntimeException
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Reservation conflict.'], 409);
    }
}
