<?php

namespace App\Console\Commands;

use App\Imports\StudentsSexImport;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class ImportStudentSexCommand extends Command
{
    protected $signature = 'students:import-sex
                            {file : Path to CSV/XLSX with Gender column}
                            {--dry-run : Match and report without writing sex}';

    protected $description = 'Update existing students.sex from a roster CSV (does not create students)';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run — no database changes will be saved.');
        }

        $import = new StudentsSexImport($dryRun);
        Excel::import($import, $path);
        $report = $import->report();

        $this->info(($dryRun ? '[dry-run] would update' : 'Updated').': '.$report['updated']);
        $this->line('Already correct: '.$report['unchanged']);
        $this->line('Skipped: '.$report['skipped']);

        foreach (['invalid' => 'Invalid gender', 'not_found' => 'Not found', 'ambiguous' => 'Ambiguous'] as $key => $label) {
            $items = $report[$key] ?? [];
            if ($items === []) {
                continue;
            }
            $this->newLine();
            $this->warn($label.' ('.count($items).'):');
            foreach (array_slice($items, 0, 40) as $line) {
                $this->line('  '.$line);
            }
            if (count($items) > 40) {
                $this->line('  … and '.(count($items) - 40).' more');
            }
        }

        return self::SUCCESS;
    }
}
