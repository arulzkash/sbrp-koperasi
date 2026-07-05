<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('distance_matrices');

        if (DB::getDriverName() === 'sqlite') {
            $this->dropSqliteStudentLegacyRoutingColumns();

            return;
        }

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'fleet_id')) {
                $table->dropForeign(['fleet_id']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('students', 'fleet_id') ? 'fleet_id' : null,
                Schema::hasColumn('students', 'route_order') ? 'route_order' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('distance_matrices')) {
            Schema::create('distance_matrices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('origin_id');
                $table->string('origin_type');
                $table->unsignedBigInteger('destination_id');
                $table->string('destination_type');
                $table->integer('distance_meters');
                $table->integer('duration_seconds');
                $table->timestamps();

                $table->index(['origin_id', 'origin_type']);
                $table->index(['destination_id', 'destination_type']);
            });
        }

        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'fleet_id')) {
                $table->foreignId('fleet_id')->nullable()->constrained('fleets')->nullOnDelete();
            }

            if (! Schema::hasColumn('students', 'route_order')) {
                $table->integer('route_order')->nullable();
            }
        });
    }

    private function dropSqliteStudentLegacyRoutingColumns(): void
    {
        if (! Schema::hasColumn('students', 'fleet_id') && ! Schema::hasColumn('students', 'route_order')) {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::beginTransaction();
            DB::statement('DROP TABLE IF EXISTS "students_without_unused_routing_columns"');
            DB::statement(<<<'SQL'
CREATE TABLE "students_without_unused_routing_columns" (
    "id" integer primary key autoincrement not null,
    "user_id" integer not null,
    "name" varchar not null,
    "school_level" varchar check ("school_level" in ('TK', 'SD', 'SMP')) not null,
    "service_type" varchar check ("service_type" in ('full', 'pickup_only', 'dropoff_only')) not null default 'full',
    "session_in" time,
    "session_out" time,
    "address_text" text not null,
    "latitude" numeric not null,
    "longitude" numeric not null,
    "distance_to_school_meters" integer,
    "price_per_month" numeric,
    "status" varchar check ("status" in ('draft', 'registered', 'active', 'inactive')) not null default 'draft',
    "payment_status" varchar check ("payment_status" in ('unpaid', 'paid')) not null default 'unpaid',
    "created_at" datetime,
    "updated_at" datetime,
    "morning_fleet_id" integer,
    "morning_route_order" integer,
    "afternoon_fleet_id" integer,
    "afternoon_route_order" integer,
    "class_room" varchar,
    "class_room_note" varchar,
    "morning_fleet_trip_id" integer,
    "afternoon_fleet_trip_id" integer,
    foreign key("user_id") references "users"("id") on delete cascade,
    foreign key("morning_fleet_id") references "fleets"("id") on delete set null,
    foreign key("afternoon_fleet_id") references "fleets"("id") on delete set null,
    foreign key("morning_fleet_trip_id") references "fleet_trips"("id") on delete set null,
    foreign key("afternoon_fleet_trip_id") references "fleet_trips"("id") on delete set null
)
SQL);
            DB::statement(<<<'SQL'
INSERT INTO "students_without_unused_routing_columns" (
    "id", "user_id", "name", "school_level", "service_type", "session_in", "session_out",
    "address_text", "latitude", "longitude", "distance_to_school_meters", "price_per_month",
    "status", "payment_status", "created_at", "updated_at", "morning_fleet_id",
    "morning_route_order", "afternoon_fleet_id", "afternoon_route_order", "class_room",
    "class_room_note", "morning_fleet_trip_id", "afternoon_fleet_trip_id"
)
SELECT
    "id", "user_id", "name", "school_level", "service_type", "session_in", "session_out",
    "address_text", "latitude", "longitude", "distance_to_school_meters", "price_per_month",
    "status", "payment_status", "created_at", "updated_at", "morning_fleet_id",
    "morning_route_order", "afternoon_fleet_id", "afternoon_route_order", "class_room",
    "class_room_note", "morning_fleet_trip_id", "afternoon_fleet_trip_id"
FROM "students"
SQL);
            DB::statement('DROP TABLE "students"');
            DB::statement('ALTER TABLE "students_without_unused_routing_columns" RENAME TO "students"');
            DB::statement('CREATE INDEX "students_user_id_index" ON "students" ("user_id")');
            DB::statement('CREATE INDEX "students_morning_fleet_id_index" ON "students" ("morning_fleet_id")');
            DB::statement('CREATE INDEX "students_afternoon_fleet_id_index" ON "students" ("afternoon_fleet_id")');
            DB::statement('CREATE INDEX "students_morning_fleet_trip_id_index" ON "students" ("morning_fleet_trip_id")');
            DB::statement('CREATE INDEX "students_afternoon_fleet_trip_id_index" ON "students" ("afternoon_fleet_trip_id")');
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};