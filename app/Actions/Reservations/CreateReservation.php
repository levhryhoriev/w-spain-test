<?php

declare(strict_types=1);

namespace App\Actions\Reservations;

use App\Data\ReservationResult;
use App\Exceptions\ReservationConflictException;
use App\Models\Offer;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;

final class CreateReservation
{
    public function __construct(private readonly Connection $database)
    {
    }

    public function execute(
        int $offerId,
        string $clientReference,
        string $customerName,
        string $customerEmail,
    ): ReservationResult {
        try {
            return $this->database->transaction(
                fn (): ReservationResult => $this->create(
                    $offerId,
                    $clientReference,
                    $customerName,
                    $customerEmail,
                ),
                3,
            );
        } catch (UniqueConstraintViolationException $exception) {
            return $this->recoverUniqueReference(
                $exception,
                $offerId,
                $clientReference,
                $customerName,
                $customerEmail,
            );
        }
    }

    private function create(
        int $offerId,
        string $clientReference,
        string $customerName,
        string $customerEmail,
    ): ReservationResult {
        $offer = Offer::query()->useWritePdo()->lockForUpdate()->findOrFail($offerId);
        $existingReservation = Reservation::query()
            ->useWritePdo()
            ->where('client_reference', $clientReference)
            ->first();

        if ($existingReservation instanceof Reservation) {
            return new ReservationResult(
                $this->matchingReservation(
                    $existingReservation,
                    $offerId,
                    $customerName,
                    $customerEmail,
                ),
                false,
            );
        }

        $comparisonInstant = CarbonImmutable::now('UTC');
        $expiresAt = $offer->getAttribute('expires_at');
        $availableUnits = $offer->getAttribute('available_units');

        if (
            ! $expiresAt instanceof CarbonImmutable
            || ! is_int($availableUnits)
            || ! $expiresAt->greaterThan($comparisonInstant)
            || $availableUnits < 1
        ) {
            throw new ReservationConflictException();
        }

        $reservation = Reservation::query()->create(
            $this->snapshot($offer, $clientReference, $customerName, $customerEmail),
        );
        $offer->forceFill(['available_units' => $availableUnits - 1])->save();

        return new ReservationResult($reservation, true);
    }

    private function recoverUniqueReference(
        UniqueConstraintViolationException $exception,
        int $offerId,
        string $clientReference,
        string $customerName,
        string $customerEmail,
    ): ReservationResult {
        $reservation = Reservation::query()
            ->useWritePdo()
            ->where('client_reference', $clientReference)
            ->first();

        if (! $reservation instanceof Reservation) {
            throw $exception;
        }

        return new ReservationResult(
            $this->matchingReservation($reservation, $offerId, $customerName, $customerEmail),
            false,
        );
    }

    private function matchingReservation(
        Reservation $reservation,
        int $offerId,
        string $customerName,
        string $customerEmail,
    ): Reservation {
        if (
            $reservation->getAttribute('offer_id') !== $offerId
            || $reservation->getAttribute('customer_name') !== $customerName
            || $reservation->getAttribute('customer_email') !== $customerEmail
        ) {
            throw new ReservationConflictException();
        }

        return $reservation;
    }

    /** @return array<string, CarbonImmutable|int|string> */
    private function snapshot(
        Offer $offer,
        string $clientReference,
        string $customerName,
        string $customerEmail,
    ): array {
        $checkIn = $offer->getAttribute('check_in');
        $checkOut = $offer->getAttribute('check_out');
        $price = $offer->getAttribute('price');
        $currency = $offer->getAttribute('currency');
        $offerId = $offer->getKey();

        if (
            ! $checkIn instanceof CarbonImmutable
            || ! $checkOut instanceof CarbonImmutable
            || ! is_int($price)
            || ! is_string($currency)
            || ! is_int($offerId)
        ) {
            throw new LogicException('The persisted Offer is invalid.');
        }

        return [
            'offer_id' => $offerId,
            'client_reference' => $clientReference,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'price' => $price,
            'currency' => $currency,
        ];
    }
}
