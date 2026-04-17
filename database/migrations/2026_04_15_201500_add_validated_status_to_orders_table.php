<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::transaction(function () {
                DB::statement('
                    CREATE TABLE orders_temp (
                        id integer primary key autoincrement not null,
                        order_number varchar not null,
                        amount numeric,
                        merchant_id integer,
                        status varchar check (status in (\'INIT\', \'VALIDATED\', \'PAID\', \'PARTIALY_PAID\', \'CANCELLED\', \'REJECTED\', \'DELIVERED\')) not null default \'INIT\',
                        created_at datetime,
                        updated_at datetime,
                        deleted_at datetime,
                        foreign key(merchant_id) references merchants(id) on delete set null
                    )
                ');

                DB::statement('
                    INSERT INTO orders_temp (id, order_number, amount, merchant_id, status, created_at, updated_at, deleted_at)
                    SELECT id, order_number, amount, merchant_id, status, created_at, updated_at, deleted_at
                    FROM orders
                ');

                DB::statement('DROP TABLE orders');
                DB::statement('ALTER TABLE orders_temp RENAME TO orders');
                DB::statement('CREATE UNIQUE INDEX orders_order_number_unique ON orders (order_number)');
            });

            return;
        }

        DB::statement("
            ALTER TABLE orders
            MODIFY status ENUM('INIT', 'VALIDATED', 'PAID', 'PARTIALY_PAID', 'CANCELLED', 'REJECTED', 'DELIVERED')
            NOT NULL DEFAULT 'INIT'
        ");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::transaction(function () {
                DB::statement('
                    CREATE TABLE orders_temp (
                        id integer primary key autoincrement not null,
                        order_number varchar not null,
                        amount numeric,
                        merchant_id integer,
                        status varchar check (status in (\'INIT\', \'PAID\', \'PARTIALY_PAID\', \'CANCELLED\', \'REJECTED\', \'DELIVERED\')) not null default \'INIT\',
                        created_at datetime,
                        updated_at datetime,
                        deleted_at datetime,
                        foreign key(merchant_id) references merchants(id) on delete set null
                    )
                ');

                DB::statement("
                    INSERT INTO orders_temp (id, order_number, amount, merchant_id, status, created_at, updated_at, deleted_at)
                    SELECT
                        id,
                        order_number,
                        amount,
                        merchant_id,
                        CASE WHEN status = 'VALIDATED' THEN 'INIT' ELSE status END,
                        created_at,
                        updated_at,
                        deleted_at
                    FROM orders
                ");

                DB::statement('DROP TABLE orders');
                DB::statement('ALTER TABLE orders_temp RENAME TO orders');
                DB::statement('CREATE UNIQUE INDEX orders_order_number_unique ON orders (order_number)');
            });

            return;
        }

        DB::statement("
            ALTER TABLE orders
            MODIFY status ENUM('INIT', 'PAID', 'PARTIALY_PAID', 'CANCELLED', 'REJECTED', 'DELIVERED')
            NOT NULL DEFAULT 'INIT'
        ");
    }
};
