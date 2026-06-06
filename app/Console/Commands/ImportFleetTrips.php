<?php

namespace App\Console\Commands;

use App\Models\Fleet;
use App\Models\FleetTrip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportFleetTrips extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'research:import-fleet-trips
        {--trips= : Path to fleet trips CSV}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import fleet trip schedules from CSV without changing students or fleets.';

    public function handle(): int
    {
        $tripPath = $this->option('trips') ?: storage_path('app/imports/fleet_trips_import.csv');

        try {
            $rows = $this->readCsv($tripPath);

            DB::transaction(function () use ($rows) {
                FleetTrip::query()->delete();

                $imported = $this->importTrips($rows);

                $this->info("Imported {$imported} fleet trips.");
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, array<string, string|null>> $rows
     */
    private function importTrips(array $rows): int
    {
        $fleetsByDriver = Fleet::query()
            ->get()
            ->keyBy(fn (Fleet $fleet) => $this->normalizeDriverName($fleet->driver_name));

        $count = 0;

        foreach ($rows as $index => $row) {
            $driverName = $this->requiredString($row, 'driver_name', $index);
            $fleet = $fleetsByDriver->get($this->normalizeDriverName($driverName));

            if (! $fleet) {
                $line = $index + 2;

                throw new RuntimeException("Fleet driver '{$driverName}' was not found at CSV line {$line}.");
            }

            FleetTrip::create([
                'fleet_id' => $fleet->id,
                'direction' => $this->normalizeDirection($this->requiredString($row, 'direction', $index)),
                'departure_time' => $this->normalizeTime($this->requiredString($row, 'departure_time', $index), $index),
                'trip_order' => (int) $this->requiredString($row, 'trip_order', $index),
                'is_active' => $this->boolean($this->nullableString($row, 'is_active') ?? 'true'),
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

        while (($values = fgetcsv($handle)) !== false) {
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

    private function normalizeDriverName(?string $driverName): string
    {
        return Str::of((string) $driverName)->trim()->lower()->squish()->toString();
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

    private function normalizeDirection(string $direction): string
    {
        $direction = strtolower(trim($direction));

        if (! in_array($direction, ['morning', 'afternoon'], true)) {
            throw new RuntimeException("Invalid trip direction: {$direction}");
        }

        return $direction;
    }

    private function normalizeTime(string $time, int $index): string
    {
        $parts = explode(':', trim($time));

        if (count($parts) === 2) {
            $parts[] = '00';
        }

        if (count($parts) !== 3) {
            $line = $index + 2;

            throw new RuntimeException("Invalid departure_time '{$time}' at CSV line {$line}.");
        }

        [$hour, $minute, $second] = array_map('intval', $parts);

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            $line = $index + 2;

            throw new RuntimeException("Invalid departure_time '{$time}' at CSV line {$line}.");
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    private function boolean(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y'], true);
    }
}
