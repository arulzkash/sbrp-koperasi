<?php

namespace App\Console\Commands;

use App\Services\RouteBenchmarkService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BenchmarkRoutes extends Command
{
    protected $signature = 'route:benchmark
        {--methods=sweep_nn,final : Comma-separated methods: sweep_nn,final}
        {--runs=10 : Number of solver timing runs}
        {--direction=all : morning, afternoon, or all}
        {--output=storage/app/benchmarks : Output directory for JSON, CSV, and Markdown}
        {--no-export : Print terminal summary only}';

    protected $description = 'Benchmark school bus route algorithms without persisting student assignments.';

    public function handle(RouteBenchmarkService $benchmark): int
    {
        try {
            $methods = $this->parseMethods((string) $this->option('methods'));
            $runs = (int) $this->option('runs');
            $direction = (string) $this->option('direction');

            $result = $benchmark->run($methods, $runs, $direction);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Route benchmark summary');
        $this->table([
            'Method',
            'Direction',
            'Session',
            'Students',
            'Used trips',
            'Distance km',
            'Active util %',
            'Load CV',
            'Mean ms',
            'Final distance delta %',
        ], array_map(fn (array $row) => [
            $row['method'],
            $row['direction'],
            $row['session'] ?? '-',
            $row['students_allocated'].'/'.$row['students_processed'],
            $row['used_trips'].'/'.$row['active_trips'],
            number_format($row['total_haversine_distance_km'], 4),
            number_format($row['active_trip_utilization_percent'], 2),
            number_format($row['trip_load_coefficient_of_variation'], 4),
            number_format($row['algorithm_time_ms_mean'], 4),
            $row['distance_improvement_vs_sweep_nn_percent'] === null
                ? '-'
                : number_format($row['distance_improvement_vs_sweep_nn_percent'], 2),
        ], $result['rows']));

        if (! $this->option('no-export')) {
            $paths = $benchmark->export($result, (string) $this->option('output'));

            $this->info('Benchmark files written:');
            $this->line('JSON: '.$paths['json']);
            $this->line('CSV: '.$paths['csv']);
            $this->line('Markdown: '.$paths['markdown']);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function parseMethods(string $methods): array
    {
        $parsed = array_values(array_filter(array_map('trim', explode(',', $methods))));

        if ($parsed === []) {
            throw new InvalidArgumentException('At least one method is required.');
        }

        return $parsed;
    }
}
