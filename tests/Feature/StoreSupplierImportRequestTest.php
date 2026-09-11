<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\StoreSupplierImportRequest;
use App\Models\Offer;
use App\Models\Supplier;
use App\Models\SupplierImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Tests\TestCase;

final class StoreSupplierImportRequestTest extends TestCase
{
    use RefreshDatabase;

    public function testValidExamplePayloadPassesWithoutPersistingImportData(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();

        self::assertIsArray($payload);
        self::assertTrue($this->validator($payload)->passes());
        self::assertSame(0, SupplierImport::query()->count());
        self::assertSame(0, Offer::query()->count());
        self::assertTrue((new StoreSupplierImportRequest())->authorize());
    }

    public function testEveryTopLevelFieldIsRequired(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);

        foreach (['supplier', 'external_import_id', 'sent_at', 'offers'] as $field) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            unset($payload[$field]);

            self::assertTrue($this->validator($payload)->errors()->has($field));
        }
    }

    public function testSupplierMustExistWithItsExactCaseAndRespectSchemaLength(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        Supplier::factory()->create(['slug' => str_repeat('s', 100)]);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        $payload['supplier'] = 'Supplier-A';
        self::assertTrue($this->validator($payload)->errors()->has('supplier'));

        $payload['supplier'] = str_repeat('s', 100);
        self::assertTrue($this->validator($payload)->passes());

        $payload['supplier'] = str_repeat('s', 101);
        self::assertTrue($this->validator($payload)->errors()->has('supplier'));
    }

    public function testOfferCollectionAcceptsZeroThroughFiveHundredItemsOnly(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        $payload['offers'] = [];
        self::assertTrue($this->validator($payload)->passes());

        $payload['offers'] = [];

        for ($index = 0; $index < 500; $index++) {
            $payload['offers'][] = $this->validOffer($index);
        }

        self::assertTrue($this->validator($payload)->passes());

        $payload['offers'][] = $this->validOffer(500);
        self::assertTrue($this->validator($payload)->errors()->has('offers'));

        $payload['offers'] = 'not-an-array';
        self::assertTrue($this->validator($payload)->errors()->has('offers'));

        $payload['offers'] = ['first' => $this->validOffer(1)];
        self::assertTrue($this->validator($payload)->errors()->has('offers'));
    }

    public function testOfferAndPropertyObjectsRequireExactNestedShapes(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        data_set($payload, 'offers.0.unexpected', true);
        self::assertTrue($this->validator($payload)->errors()->has('offers.0'));

        data_forget($payload, 'offers.0.unexpected');
        data_set($payload, 'offers.0.property.unexpected', true);
        self::assertTrue($this->validator($payload)->errors()->has('offers.0.property'));

        data_set($payload, 'offers.0', 'not-an-object');
        self::assertTrue($this->validator($payload)->errors()->has('offers.0'));
    }

    public function testEveryNestedFieldIsRequired(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $offerFields = [
            'external_id',
            'property',
            'check_in',
            'check_out',
            'max_guests',
            'price',
            'currency',
            'available_units',
            'expires_at',
        ];

        foreach ($offerFields as $field) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            data_forget($payload, 'offers.0.' . $field);

            self::assertTrue($this->validator($payload)->errors()->has('offers.0.' . $field));
        }

        foreach (['code', 'name', 'city'] as $field) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            data_forget($payload, 'offers.0.property.' . $field);

            self::assertTrue($this->validator($payload)->errors()->has('offers.0.property.' . $field));
        }
    }

    public function testExternalAndPropertyTextFieldsMatchSchemaBoundaries(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $boundaries = [
            ['external_import_id', 191],
            ['offers.0.external_id', 191],
            ['offers.0.property.code', 100],
            ['offers.0.property.name', 255],
            ['offers.0.property.city', 120],
        ];

        foreach ($boundaries as [$field, $maximum]) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            data_set($payload, $field, str_repeat('x', $maximum));
            self::assertTrue($this->validator($payload)->passes(), $field);

            data_set($payload, $field, str_repeat('x', $maximum + 1));
            self::assertTrue($this->validator($payload)->errors()->has($field), $field);
        }

        $payload = $this->validPayload();
        self::assertIsArray($payload);
        data_set($payload, 'offers.1', data_get($payload, 'offers.0'));

        self::assertTrue($this->validator($payload)->errors()->has('offers.1.external_id'));
    }

    public function testStayDatesMustBeRealDatesWithCheckoutAfterCheckin(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        data_set($payload, 'offers.0.check_in', '2026-02-30');
        self::assertTrue($this->validator($payload)->errors()->has('offers.0.check_in'));

        $payload = $this->validPayload();
        self::assertIsArray($payload);
        data_set($payload, 'offers.0.check_out', data_get($payload, 'offers.0.check_in'));
        self::assertTrue($this->validator($payload)->errors()->has('offers.0.check_out'));

        data_set($payload, 'offers.0.check_out', '2026-10-09');
        self::assertTrue($this->validator($payload)->errors()->has('offers.0.check_out'));
    }

    public function testInstantFieldsRequireStrictIso8601Values(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);

        foreach (['sent_at', 'offers.0.expires_at'] as $field) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            data_set($payload, $field, '2026-09-01 10:00:00');
            self::assertTrue($this->validator($payload)->errors()->has($field));

            data_set($payload, $field, '2026-09-01T10:00:00.123456+02:00');
            self::assertTrue($this->validator($payload)->passes());

            data_set($payload, $field, '2026-09-01T10:00:00.123Z');
            self::assertTrue($this->validator($payload)->passes());

            data_set($payload, $field, '2026-09-01T10:00:00.1Z');
            self::assertTrue($this->validator($payload)->passes());

            data_set($payload, $field, '2026-09-01T10:00:00.1234567Z');
            self::assertTrue($this->validator($payload)->errors()->has($field));

            data_set($payload, $field, '2026-09-01T10:00:00');
            self::assertTrue($this->validator($payload)->errors()->has($field));

            data_set($payload, $field, '2026-02-30T10:00:00Z');
            self::assertTrue($this->validator($payload)->errors()->has($field));
        }
    }

    public function testNumericFieldsMatchDatabaseRangesAndRejectNonIntegers(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $cases = [
            ['offers.0.max_guests', 1, 32_767, 0, 32_768],
            ['offers.0.price', 0, PHP_INT_MAX, -1, 1.5],
            ['offers.0.available_units', 0, 2_147_483_647, -1, 2_147_483_648],
        ];

        foreach ($cases as [$field, $minimum, $maximum, $below, $above]) {
            $payload = $this->validPayload();
            self::assertIsArray($payload);
            data_set($payload, $field, $minimum);
            self::assertTrue($this->validator($payload)->passes(), $field);

            data_set($payload, $field, $maximum);
            self::assertTrue($this->validator($payload)->passes(), $field);

            data_set($payload, $field, $below);
            self::assertTrue($this->validator($payload)->errors()->has($field), $field);

            data_set($payload, $field, $above);
            self::assertTrue($this->validator($payload)->errors()->has($field), $field);

            data_set($payload, $field, (string) $minimum);
            self::assertTrue($this->validator($payload)->errors()->has($field), $field);
        }
    }

    public function testCurrencyIsStrictlyEur(): void
    {
        Supplier::factory()->create(['slug' => 'supplier-a']);
        $payload = $this->validPayload();
        self::assertIsArray($payload);

        foreach (['USD', 'eur', 'EURO', 978] as $currency) {
            data_set($payload, 'offers.0.currency', $currency);
            self::assertTrue($this->validator($payload)->errors()->has('offers.0.currency'));
        }

        data_set($payload, 'offers.0.currency', 'EUR');
        self::assertTrue($this->validator($payload)->passes());
    }

    private function validator(mixed $payload): LaravelValidator
    {
        self::assertIsArray($payload);

        return Validator::make($payload, (new StoreSupplierImportRequest())->rules());
    }

    private function validPayload(): mixed
    {
        return [
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [$this->validOffer(1)],
        ];
    }

    private function validOffer(int $sequence): mixed
    {
        return [
            'external_id' => 'offer-a-' . $sequence,
            'property' => [
                'code' => 'BCN-' . $sequence,
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72_500,
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ];
    }
}
