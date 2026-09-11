<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class ReservationResource extends JsonResource
{
    /** @return array<string, int|string> */
    public function toArray(Request $request): array
    {
        $reservation = $this->resource;

        if (! $reservation instanceof Reservation) {
            throw new LogicException('The reservation resource is invalid.');
        }

        $id = $reservation->getKey();
        $offerId = $reservation->getAttribute('offer_id');
        $clientReference = $reservation->getAttribute('client_reference');
        $customerName = $reservation->getAttribute('customer_name');
        $customerEmail = $reservation->getAttribute('customer_email');
        $checkIn = $reservation->getAttribute('check_in');
        $checkOut = $reservation->getAttribute('check_out');
        $price = $reservation->getAttribute('price');
        $currency = $reservation->getAttribute('currency');
        $createdAt = $reservation->getAttribute('created_at');

        if (
            ! is_int($id)
            || ! is_int($offerId)
            || ! is_string($clientReference)
            || ! is_string($customerName)
            || ! is_string($customerEmail)
            || ! $checkIn instanceof CarbonImmutable
            || ! $checkOut instanceof CarbonImmutable
            || ! is_int($price)
            || ! is_string($currency)
            || ! $createdAt instanceof CarbonImmutable
        ) {
            throw new LogicException('The persisted Reservation is invalid.');
        }

        return [
            'id' => $id,
            'offer_id' => $offerId,
            'client_reference' => $clientReference,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'price' => $price,
            'currency' => $currency,
            'created_at' => $createdAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
