<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Data\OfferSnapshotData;
use App\Enums\ImportStatus;
use App\Models\Offer;
use App\Models\Property;
use App\Models\SupplierImport;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class ProcessSupplierImport
{
    public function execute(int $supplierImportId): void
    {
        if (! $this->claim($supplierImportId)) {
            return;
        }

        DB::transaction(function () use ($supplierImportId): void {
            $supplierImport = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImportId);
            $status = $supplierImport->getAttribute('status');

            if ($status === ImportStatus::Completed || $status === ImportStatus::Failed) {
                return;
            }

            if ($status === ImportStatus::Pending) {
                $supplierImport->forceFill([
                    'status' => ImportStatus::Processing,
                    'started_at' => now()->toImmutable(),
                ])->save();
            }

            if ($status !== ImportStatus::Pending && $status !== ImportStatus::Processing) {
                throw new LogicException('The supplier import has an invalid processing status.');
            }

            $supplierId = $supplierImport->getAttribute('supplier_id');
            $sourceSentAt = $supplierImport->getAttribute('sent_at');
            $payload = $supplierImport->getAttribute('payload');
            $supplierImportKey = $supplierImport->getKey();

            if (
                ! is_int($supplierId)
                || ! $sourceSentAt instanceof CarbonImmutable
                || ! is_array($payload)
                || ! is_int($supplierImportKey)
            ) {
                throw new LogicException('The persisted supplier import is invalid.');
            }

            $offerPayloads = $payload['offers'] ?? null;

            if (! is_array($offerPayloads) || ! array_is_list($offerPayloads)) {
                throw new InvalidArgumentException('The persisted supplier import offers are invalid.');
            }

            usort($offerPayloads, self::comparePayloadExternalIds(...));

            foreach ($offerPayloads as $offerPayload) {
                $this->applySnapshot(
                    $supplierId,
                    $supplierImportKey,
                    OfferSnapshotData::fromPayload($offerPayload, $sourceSentAt),
                );
            }

            $offerCount = count($offerPayloads);

            $supplierImport->forceFill([
                'status' => ImportStatus::Completed,
                'total_offers' => $offerCount,
                'processed_offers' => $offerCount,
                'completed_at' => now()->toImmutable(),
            ])->save();
        }, 3);
    }

    private function claim(int $supplierImportId): bool
    {
        return DB::transaction(function () use ($supplierImportId): bool {
            $supplierImport = SupplierImport::query()->lockForUpdate()->findOrFail($supplierImportId);
            $status = $supplierImport->getAttribute('status');

            if ($status === ImportStatus::Completed || $status === ImportStatus::Failed) {
                return false;
            }

            if ($status === ImportStatus::Pending) {
                $supplierImport->forceFill([
                    'status' => ImportStatus::Processing,
                    'processed_offers' => 0,
                    'started_at' => now()->toImmutable(),
                ])->save();
            }

            if ($status !== ImportStatus::Pending && $status !== ImportStatus::Processing) {
                throw new LogicException('The supplier import has an invalid claim status.');
            }

            return true;
        });
    }

    private function applySnapshot(
        int $supplierId,
        int $supplierImportId,
        OfferSnapshotData $snapshot,
    ): void {
        $offer = Offer::query()
            ->where('supplier_id', $supplierId)
            ->where('external_id', $snapshot->externalId)
            ->lockForUpdate()
            ->first();

        if ($offer instanceof Offer && ! $this->incomingSnapshotWins($offer, $supplierImportId, $snapshot)) {
            return;
        }

        $property = Property::query()->where('code', $snapshot->propertyCode)->first();

        if (! $property instanceof Property) {
            try {
                $property = Property::query()->create([
                    'code' => $snapshot->propertyCode,
                    'name' => $snapshot->propertyName,
                    'city' => $snapshot->propertyCity,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                $property = Property::query()
                    ->useWritePdo()
                    ->where('code', $snapshot->propertyCode)
                    ->lockForUpdate()
                    ->first() ?? throw $exception;
            }
        }

        $propertyId = $property->getKey();

        if (! is_int($propertyId)) {
            throw new LogicException('The imported Property must have an integer identity.');
        }

        $values = [
            'supplier_id' => $supplierId,
            'property_id' => $propertyId,
            'supplier_import_id' => $supplierImportId,
            'external_id' => $snapshot->externalId,
            'check_in' => $snapshot->checkIn,
            'check_out' => $snapshot->checkOut,
            'max_guests' => $snapshot->maxGuests,
            'price' => $snapshot->price,
            'currency' => $snapshot->currency,
            'available_units' => $snapshot->availableUnits,
            'expires_at' => $snapshot->expiresAt,
            'source_sent_at' => $snapshot->sourceSentAt,
        ];
        if ($offer instanceof Offer) {
            $offer->forceFill($values)->save();

            return;
        }

        try {
            Offer::query()->create($values);
        } catch (UniqueConstraintViolationException $exception) {
            $offer = Offer::query()
                ->useWritePdo()
                ->where('supplier_id', $supplierId)
                ->where('external_id', $snapshot->externalId)
                ->lockForUpdate()
                ->first() ?? throw $exception;

            if (! $this->incomingSnapshotWins($offer, $supplierImportId, $snapshot)) {
                return;
            }

            $offer->forceFill($values)->save();
        }
    }

    private function incomingSnapshotWins(
        Offer $offer,
        int $supplierImportId,
        OfferSnapshotData $snapshot,
    ): bool {
        $currentSentAt = $offer->getAttribute('source_sent_at');
        $currentImportId = $offer->getAttribute('supplier_import_id');

        if (! $currentSentAt instanceof CarbonImmutable || ! is_int($currentImportId)) {
            throw new LogicException('The persisted Offer source tuple is invalid.');
        }

        if ($snapshot->sourceSentAt->greaterThan($currentSentAt)) {
            return true;
        }

        return $snapshot->sourceSentAt->equalTo($currentSentAt)
            && $supplierImportId < $currentImportId;
    }

    private static function comparePayloadExternalIds(mixed $left, mixed $right): int
    {
        return self::payloadExternalId($left) <=> self::payloadExternalId($right);
    }

    private static function payloadExternalId(mixed $payload): string
    {
        if (! is_array($payload) || ! isset($payload['external_id']) || ! is_string($payload['external_id'])) {
            throw new InvalidArgumentException('The persisted offer external identity is invalid.');
        }

        return $payload['external_id'];
    }
}
