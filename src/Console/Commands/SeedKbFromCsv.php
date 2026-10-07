<?php

namespace Subodh\SmartAiAssistant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Subodh\SmartAiAssistant\Knowledge\KeywordKnowledgeSource;
use Subodh\SmartAiAssistant\Models\ErrorDefinition;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SeedKbFromCsv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'smart-ai:seed-kb
        {file : The path to the CSV or Excel file}
        {--domain= : Knowledge domain (service) for the entries; defaults to config default_service}
        {--keywords : Column A holds keywords ("pan refund"), matched in any order, instead of exact error text}';

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

        $service = $this->option('domain') ?: config('smart-ai-assistant.default_service', 'general');
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

            // Keyword files are often templates with answers still to write:
            // an entry without an answer would reply with nothing
            if ($this->option('keywords') && $ansEng === '') {
                $skippedWithoutAnswer = ($skippedWithoutAnswer ?? 0) + 1;
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
                    // Keyword entries are matched by KeywordKnowledgeSource only
                    'meta'      => $this->option('keywords') ? ['match' => KeywordKnowledgeSource::MATCH_TYPE] : null,
                ]
            );

            $count++;
        }

        $this->info("Successfully seeded {$count} entries into the Knowledge Base ({$service}).");

        if (!empty($skippedWithoutAnswer)) {
            $this->warn("Skipped {$skippedWithoutAnswer} keyword rows without an English answer (column B).");
        }
        return 0;
    }

}
