<?php

namespace juvo\AS_Processor;
use Iterator;

use PhpOffice\PhpSpreadsheet\IOFactory as IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as Writer;

abstract class Excel_Sync extends Sync
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;
    protected string $srcEncoding = "";

    public function set_hooks(): void
    {
        parent::set_hooks();
        add_action($this->get_sync_name(), [$this, 'split_excel_into_chunks']);
    }

    public function schedule(): void
    {
    }

    /**
     * Schedules an async action to process a chunk of data
     *
     * @param array $data
     * @return void
     */
    protected function schedule_chunk(array|Iterator $data): void
    {
        $groupName = $this->get_sync_name() . '_' . time();
        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [$data],
            $groupName
        );
    }

    public function split_excel_into_chunks( $data )
    {
        $filepath = $data['file'];

        $reader = IOFactory::createReader("Xlsx");
        $spreadsheet = $reader->load($filepath);
        $worksheet = $spreadsheet->getActiveSheet();

        $originalFileName = pathinfo($filepath, PATHINFO_FILENAME);
        $outputDir = get_temp_dir();

        $highestRow = $worksheet->getHighestRow();
        $filesNeeded = ceil($highestRow / $this->chunkSize);

        for ($fileIndex = 0; $fileIndex < $filesNeeded; $fileIndex++) {
            if ($fileIndex == 0) {
                $skip_header = 1;
            } else {
                $skip_header = 0;
            }

            $newSpreadsheet = new Spreadsheet();
            $newSheet = $newSpreadsheet->getActiveSheet();

            $rowOffset = $fileIndex * $this->chunkSize;
            $rowLimit = min($this->chunkSize, $highestRow - $rowOffset);

            for ($row = 0; $row < $rowLimit; $row++) {
                $rowData = $worksheet->rangeToArray('A' . ($row + 1 + $rowOffset) . ':' . $worksheet->getHighestColumn() . ($row + 1 + $rowOffset), NULL, TRUE, FALSE)[0];
                $newSheet->fromArray($rowData, NULL, 'A' . ($row + 1));
            }

            $writer = new Writer($newSpreadsheet);
            $newFileName = $outputDir . '/' . $originalFileName . '_part_' . ($fileIndex + 1) . '.xlsx';
            $writer->save($newFileName);
            $chunk_data = [
                'file' => $newFileName
            ];
            $this->schedule_chunk($chunk_data);
        }
    }

    /**
     * Callback for the Chunk jobs. The child implementation either dispatches to an import or an export
     *
     * @param string $chunk_file_path
     * @return void
     */
    public function process_chunk(string|array $data): void
    {
        $file = $data['file'];

        $reader = IOFactory::createReader("Xlsx");
        $spreadsheet = $reader->load($file);
        $worksheet = $spreadsheet->getActiveSheet();

        $dataArray = $worksheet->toArray(formatData: true);

        $generator = (function() use ($dataArray) {
            foreach ($dataArray as $row) {
                yield $row;
            }
        })();
        $this->process_chunk_data($generator);

        unlink($file);
    }

}
