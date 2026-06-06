<?php

namespace App\Console\Commands;

use App\Models\Fleet;
use App\Models\Student;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ImportResearchData extends Command
{
    private const SCHOOL_LAT = -6.826864390637824;
    private const SCHOOL_LNG = 107.63886429303408;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'research:import-data
        {--fresh : Delete existing research students, imported parents, and fleets before importing}
        {--fleets= : Path to fleets CSV}
        {--students= : Path to students CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import cleaned research fleets and students from CSV files.';

    public function handle(PricingService $pricingService): int
    {
        $fleetPath = $this->option('fleets') ?: storage_path('app/imports/fleets_import.csv');
        $studentPath = $this->option('students') ?: storage_path('app/imports/students_import.csv');

        try {
            $fleetRows = $this->readCsv($fleetPath);
            $studentRows = $this->readCsv($studentPath);

            DB::transaction(function () use ($fleetRows, $studentRows, $pricingService) {
                if ($this->option('fresh')) {
                    $this->freshReset();
                }

                $fleets = $this->importFleets($fleetRows);
                $studentsImported = $this->importStudents($studentRows, $pricingService);

                $this->info("Imported {$fleets->count()} fleets.");
                $this->info("Imported {$studentsImported} students.");
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function freshReset(): void
    {
        Student::query()->delete();

        User::query()
            ->where('role', 'parent')
            ->where('email', 'like', 'parent-import-%@example.test')
            ->delete();

        Fleet::query()->delete();
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     */
    private function importFleets(array $rows)
    {
        $fleets = collect();

        foreach ($rows as $index => $row) {
            $name = $this->requiredString($row, 'name', $index);
            $driverName = $this->requiredString($row, 'driver_name', $index);

            $fleet = Fleet::create([
                'name' => $name,
                'driver_name' => $driverName,
                'license_plate' => $this->nullableString($row, 'license_plate'),
                'vehicle_type' => $this->nullableString($row, 'vehicle_type'),
                'capacity' => (int) $this->requiredString($row, 'capacity', $index),
                'base_latitude' => $this->decimal($this->requiredString($row, 'base_latitude', $index)),
                'base_longitude' => $this->decimal($this->requiredString($row, 'base_longitude', $index)),
                'base_address' => $this->nullableString($row, 'base_address'),
                'is_active' => $this->boolean($this->nullableString($row, 'is_active') ?? 'true'),
            ]);

            $fleets->push($fleet);
        }

        return $fleets;
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     */
    private function importStudents(array $rows, PricingService $pricingService): int
    {
        $count = 0;

        foreach ($rows as $index => $row) {
            $studentName = $this->requiredString($row, 'nama', $index);
            $schoolLevel = strtoupper($this->requiredString($row, 'jenjang', $index));
            $rawClass = $this->requiredString($row, 'kelas', $index);
            [$classRoom, $classRoomNote] = $this->parseClassRoom($rawClass, $schoolLevel);

            $latitude = $this->decimal($this->requiredString($row, 'lat_final', $index));
            $longitude = $this->decimal($this->requiredString($row, 'lng_final', $index));
            $distanceMeters = $this->haversine($latitude, $longitude, self::SCHOOL_LAT, self::SCHOOL_LNG) * 1000;
            $serviceType = $this->normalizeServiceType($this->requiredString($row, 'layanan', $index));
            $pricing = $pricingService->calculatePricing(
                $distanceMeters,
                $this->estimateDurationMin($distanceMeters),
                0,
            );

            $parent = User::create([
                'name' => 'Parent ' . $studentName,
                'email' => 'parent-import-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT) . '@example.test',
                'password' => Hash::make('password'),
                'role' => 'parent',
            ]);

            Student::create([
                'user_id' => $parent->id,
                'name' => $studentName,
                'school_level' => $schoolLevel,
                'class_room' => $classRoom,
                'class_room_note' => $classRoomNote,
                'service_type' => $serviceType,
                'session_in' => '07:00:00',
                'session_out' => $this->resolveSessionOut($schoolLevel, $classRoom),
                'address_text' => $this->requiredString($row, 'alamat lengkap', $index),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'distance_to_school_meters' => (int) round($distanceMeters),
                'price_per_month' => $pricingService->calculateServicePrice($pricing['monthly_pp'], $serviceType),
                'payment_status' => 'paid',
                'status' => 'registered',
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("CSV file not found: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$path}");
        }

        $rawHeaders = fgetcsv($handle);

        if ($rawHeaders === false) {
            fclose($handle);

            throw new RuntimeException("CSV file has no header row: {$path}");
        }

        $headers = array_map(fn ($header) => $this->normalizeHeader((string) $header), $rawHeaders);
        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            $row = [];

            foreach ($headers as $position => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = $values[$position] ?? null;
            }

            if ($this->hasAnyValue($row)) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        return trim(Str::of($header)->replace("\xEF\xBB\xBF", '')->lower()->toString());
    }

    /**
     * @param array<string, string|null> $row
     */
    private function hasAnyValue(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function requiredString(array $row, string $key, int $index): string
    {
        $value = trim((string) ($row[$key] ?? ''));

        if ($value === '') {
            $line = $index + 2;

            throw new RuntimeException("Missing required '{$key}' at CSV line {$line}.");
        }

        return $value;
    }

    /**
     * @param array<string, string|null> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = trim((string) ($row[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function decimal(string $value): float
    {
        return (float) str_replace(',', '.', trim($value, " \t\n\r\0\x0B\""));
    }

    private function boolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function parseClassRoom(string $rawClass, string $schoolLevel): array
    {
        $rawClass = strtoupper(trim($rawClass));

        if ($schoolLevel === 'TK') {
            return [$rawClass, null];
        }

        if (preg_match('/^(\d+)([A-Z]+)$/', $rawClass, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$rawClass, null];
    }

    private function normalizeServiceType(string $serviceType): string
    {
        $serviceType = strtolower(trim($serviceType));

        if (! in_array($serviceType, ['full', 'pickup_only', 'dropoff_only'], true)) {
            throw new RuntimeException("Invalid service type: {$serviceType}");
        }

        return $serviceType;
    }

    private function resolveSessionOut(string $schoolLevel, string $classRoom): ?string
    {
        $classes = config("student_schedule.levels.{$schoolLevel}", []);
        $selectedClass = collect($classes)->firstWhere('value', $classRoom);

        return isset($selectedClass['session_out'])
            ? $selectedClass['session_out'] . ':00'
            : null;
    }

    private function estimateDurationMin(float $distanceMeters): float
    {
        $speedKmh = config('pricing.fallback_speed_kmh', 18);

        return (($distanceMeters / 1000) / $speedKmh) * 60;
    }

    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
