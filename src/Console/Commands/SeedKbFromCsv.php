<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
 use PhpOffice\PhpSpreadsheet\IOFactory;

class SeedKbFromCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'smart-ai:seed-kb {file : The path to the CSV file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the Knowledge Base (error_definitions) from a CSV file (Que, Ans Eng, Ans Hin)';

    /**
     * Execute the console command.
     */
   

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Reading Excel from: {$filePath}");

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

        } catch (\Exception $e) {
            $this->error("Failed to read Excel file: " . $e->getMessage());
            return 1;
        }

        $service = config('smart-ai-assistant.default_service', 'AEPS');
        $count = 0;

        $isHeader = true;

        foreach ($rows as $row) {

            // Skip the first row (header)
            if ($isHeader) {
                $isHeader = false;
                continue;
            }

            // Read & clean values
            $errorText = trim($row['A'] ?? '');
            $ansEng    = trim($row['B'] ?? '');
            $ansHin    = trim($row['C'] ?? '');

            // Skip completely empty / null rows
            if (
                empty($errorText) &&
                empty($ansEng) &&
                empty($ansHin)
            ) {
                continue;
            }

            // Skip row if key_text is missing (A column is required)
            if (empty($errorText)) {
                continue;
            }

            // Insert or Update
            ErrorDefinition::updateOrCreate(
                [
                    'service'   => $service,
                    'key_text'  => $errorText,
                ],
                [
                    'answer_en' => $ansEng,
                    'answer_hi' => $ansHin,
                    'match_type'=> 'fuzzy',
                ]
            );

            $count++;
        }

        $this->info("Successfully seeded {$count} entries into the Knowledge Base.");
        return 0;
    }

}
