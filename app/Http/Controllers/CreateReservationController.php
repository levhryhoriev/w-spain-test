<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Reservations\CreateReservation;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Offer;
use Illuminate\Http\JsonResponse;
use LogicException;

final class CreateReservationController extends Controller
{
    public function __invoke(
        StoreReservationRequest $request,
        Offer $offer,
        CreateReservation $createReservation,
    ): JsonResponse {
        $offerId = $offer->getKey();

        if (! is_int($offerId)) {
            throw new LogicException('The route Offer must be persisted.');
        }

        $result = $createReservation->execute(
            $offerId,
            $request->clientReference(),
            $request->customerName(),
            $request->customerEmail(),
        );

        return (new ReservationResource($result->reservation))
            ->response()
            ->setStatusCode($result->created ? 201 : 200);
    }
}
