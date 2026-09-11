<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->string('slug', 100)->collation('utf8mb4_0900_as_cs');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique('slug', 'uq_suppliers_slug');
        });

        Schema::create('properties', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->string('code', 100)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 255);
            $table->string('city', 120)->collation('utf8mb4_0900_ai_ci');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique('code', 'uq_properties_code');
        });

        Schema::create('supplier_imports', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('supplier_id');
            $table->string('external_import_id', 191)->collation('utf8mb4_0900_as_cs');
            $table->dateTime('sent_at', 6);
            $table->string('status', 20)->charset('ascii')->collation('ascii_bin')->default('pending');
            $table->json('payload');
            $table->smallInteger('total_offers')->default(0);
            $table->smallInteger('processed_offers')->default(0);
            $table->string('error_type', 255)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(
                ['supplier_id', 'external_import_id'],
                'uq_supplier_imports_supplier_external',
            );
            $table->unique(
                ['supplier_id', 'id'],
                'uq_supplier_imports_supplier_id_id',
            );
            $table->foreign('supplier_id', 'fk_supplier_imports_supplier')
                ->references('id')
                ->on('suppliers')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('supplier_id');
            $table->foreignId('property_id');
            $table->foreignId('supplier_import_id');
            $table->string('external_id', 191)->collation('utf8mb4_0900_as_cs');
            $table->date('check_in');
            $table->date('check_out');
            $table->smallInteger('max_guests');
            $table->bigInteger('price');
            $table->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $table->integer('available_units');
            $table->dateTime('expires_at', 6);
            $table->dateTime('source_sent_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(
                ['supplier_id', 'external_id'],
                'uq_offers_supplier_external',
            );
            $table->foreign('supplier_id', 'fk_offers_supplier')
                ->references('id')
                ->on('suppliers')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign('property_id', 'fk_offers_property')
                ->references('id')
                ->on('properties')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreign(
                ['supplier_id', 'supplier_import_id'],
                'fk_offers_supplier_import',
            )
                ->references(['supplier_id', 'id'])
                ->on('supplier_imports')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_0900_ai_ci';

            $table->id();
            $table->foreignId('offer_id');
            $table->string('client_reference', 191)->collation('utf8mb4_0900_as_cs');
            $table->string('customer_name', 255);
            $table->string('customer_email', 254);
            $table->date('check_in');
            $table->date('check_out');
            $table->bigInteger('price');
            $table->char('currency', 3)->charset('ascii')->collation('ascii_bin');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique('client_reference', 'uq_reservations_client_reference');
            $table->foreign('offer_id', 'fk_reservations_offer')
                ->references('id')
                ->on('offers')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('supplier_imports');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('suppliers');
    }

    private function addCheckConstraints(): void
    {
        DB::statement(
            'ALTER TABLE supplier_imports '
            . 'ADD CONSTRAINT chk_supplier_imports_status '
            . "CHECK (status IN ('pending', 'processing', 'completed', 'failed'))",
        );
        DB::statement(
            'ALTER TABLE supplier_imports '
            . 'ADD CONSTRAINT chk_supplier_imports_total_offers '
            . 'CHECK (total_offers BETWEEN 0 AND 500)',
        );
        DB::statement(
            'ALTER TABLE supplier_imports '
            . 'ADD CONSTRAINT chk_supplier_imports_processed_offers '
            . 'CHECK (processed_offers BETWEEN 0 AND total_offers)',
        );
        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT chk_offers_stay_dates CHECK (check_out > check_in)',
        );
        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT chk_offers_max_guests CHECK (max_guests > 0)',
        );
        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT chk_offers_price CHECK (price >= 0)',
        );
        DB::statement(
            'ALTER TABLE offers ADD CONSTRAINT chk_offers_available_units CHECK (available_units >= 0)',
        );
        DB::statement(
            "ALTER TABLE offers ADD CONSTRAINT chk_offers_currency CHECK (currency = 'EUR')",
        );
        DB::statement(
            'ALTER TABLE reservations ADD CONSTRAINT chk_reservations_stay_dates CHECK (check_out > check_in)',
        );
        DB::statement(
            'ALTER TABLE reservations ADD CONSTRAINT chk_reservations_price CHECK (price >= 0)',
        );
        DB::statement(
            "ALTER TABLE reservations ADD CONSTRAINT chk_reservations_currency CHECK (currency = 'EUR')",
        );
    }
};
