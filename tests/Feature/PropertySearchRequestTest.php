<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\PropertySearchCriteria;
use App\Http\Requests\PropertySearchRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class PropertySearchRequestTest extends TestCase
{
    public function testValidBoundariesProduceImmutableTypedCriteriaWithOneStableClock(): void
    {
        $validator = $this->validator([
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 32_767,
            'city' => str_repeat('x', 120),
            'page' => 2_147_483_647,
        ]);
        $comparisonInstant = CarbonImmutable::parse('2026-09-01T10:00:00.123456Z');
        $request = new PropertySearchRequest();
        $request->setValidator($validator);

        $criteria = $request->criteria($comparisonInstant);

        self::assertSame('2026-10-10', $criteria->checkIn->format('Y-m-d'));
        self::assertSame('UTC', $criteria->checkIn->getTimezone()->getName());
        self::assertSame('2026-10-15', $criteria->checkOut->format('Y-m-d'));
        self::assertSame(32_767, $criteria->guests);
        self::assertSame(str_repeat('x', 120), $criteria->city);
        self::assertSame(2_147_483_647, $criteria->page);
        self::assertSame($comparisonInstant, $criteria->comparisonInstant);
        self::assertSame('2026-09-01T10:00:00.123456Z', $criteria->comparisonInstant->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame($criteria->comparisonInstant, $criteria->comparisonInstant);
    }

    public function testRequiredFieldsAndStrictDateOrderAreEnforced(): void
    {
        foreach (['check_in', 'check_out', 'guests'] as $field) {
            $input = $this->validInput();
            unset($input[$field]);
            self::assertTrue($this->validator($input)->errors()->has($field));
        }

        foreach (['2026-02-30', '10-10-2026', '2026-10-10T00:00:00Z'] as $invalidDate) {
            $input = $this->validInput();
            $input['check_in'] = $invalidDate;
            self::assertTrue($this->validator($input)->errors()->has('check_in'));
        }

        foreach (['2026-10-10', '2026-10-09'] as $invalidCheckOut) {
            $input = $this->validInput();
            $input['check_out'] = $invalidCheckOut;
            self::assertTrue($this->validator($input)->errors()->has('check_out'));
        }
    }

    public function testGuestsMustBeAStrictSchemaBoundedInteger(): void
    {
        foreach ([1, 32_767] as $validGuests) {
            $input = $this->validInput();
            $input['guests'] = $validGuests;
            self::assertTrue($this->validator($input)->passes());
        }

        foreach ([0, 32_768, 1.0, null] as $invalidGuests) {
            $input = $this->validInput();
            $input['guests'] = $invalidGuests;
            self::assertTrue($this->validator($input)->errors()->has('guests'));
        }

        foreach (['0', '32768', '1.0', '1e2', ' 1', '1 ', '+1', '-1', '01'] as $invalidGuests) {
            $input = $this->validInput();
            $input['guests'] = $invalidGuests;
            self::assertTrue($this->validator($input)->errors()->has('guests'));
        }

        $input = $this->validInput();
        $input['guests'] = '32767';
        $criteria = PropertySearchCriteria::fromValidated(
            $this->validator($input)->validated(),
            CarbonImmutable::parse('2026-09-01T10:00:00Z'),
        );
        self::assertSame(32_767, $criteria->guests);
    }

    public function testCityIsOptionalButWhenPresentMustBeNonemptyAndBounded(): void
    {
        $input = $this->validInput();
        self::assertTrue($this->validator($input)->passes());
        $criteria = PropertySearchCriteria::fromValidated(
            $this->validator($input)->validated(),
            CarbonImmutable::parse('2026-09-01T10:00:00Z'),
        );
        self::assertNull($criteria->city);
        self::assertSame(1, $criteria->page);

        foreach (['', null, str_repeat('x', 121), 123] as $invalidCity) {
            $input['city'] = $invalidCity;
            self::assertTrue($this->validator($input)->errors()->has('city'));
        }
    }

    public function testPageMustBeAStrictPositiveIntegerWhenPresent(): void
    {
        foreach ([1, 2_147_483_647] as $validPage) {
            $input = $this->validInput();
            $input['page'] = $validPage;
            self::assertTrue($this->validator($input)->passes());
        }

        foreach ([0, -1, 1.0, null] as $invalidPage) {
            $input = $this->validInput();
            $input['page'] = $invalidPage;
            self::assertTrue($this->validator($input)->errors()->has('page'));
        }

        foreach (['0', '2147483648', '1.0', '1e2', ' 1', '1 ', '+1', '-1', '01'] as $invalidPage) {
            $input = $this->validInput();
            $input['page'] = $invalidPage;
            self::assertTrue($this->validator($input)->errors()->has('page'));
        }

        $input = $this->validInput();
        $input['page'] = '2147483647';
        $criteria = PropertySearchCriteria::fromValidated(
            $this->validator($input)->validated(),
            CarbonImmutable::parse('2026-09-01T10:00:00Z'),
        );
        self::assertSame(2_147_483_647, $criteria->page);
    }

    public function testCriteriaRejectsANonUtcComparisonInstant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PropertySearchCriteria::fromValidated(
            $this->validator($this->validInput())->validated(),
            CarbonImmutable::parse('2026-09-01T10:00:00+02:00'),
        );
    }

    private function validator(mixed $input): LaravelValidator
    {
        self::assertIsArray($input);

        return Validator::make($input, (new PropertySearchRequest())->rules());
    }

    /** @return array<string, int|string> */
    private function validInput(): array
    {
        return [
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'guests' => 2,
        ];
    }
}
