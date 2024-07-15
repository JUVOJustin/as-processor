<?php

namespace juvo\AS_Processor;

use Exception;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

abstract class CSV_Sync extends Import
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;
    protected bool $skipEmptyRows = true;
    protected string $srcEncoding = "";

    public function set_hooks(): void
    {
        parent::set_hooks();
        add_action($this->get_sync_name(), [$this, 'split_csv_into_chunks']);
    }

    abstract protected function get_source_csv_path(): string;

    /**
     * Takes the source csv and splits it into chunk files that contain the set amount of items
     *
     * @return void
     * @throws Exception
     */
    public function split_csv_into_chunks(): void
    {

        $csvFilePath = $this->get_source_csv_path();
        if (!file_exists($csvFilePath)) {
            throw new Exception("Failed to open the file: $csvFilePath");
        }

        $reader = new Csv();
        $reader->setDelimiter($this->delimiter);
        $reader->setSheetIndex(0);

        // Read csv from file
        $reader->setDelimiter($this->delimiter);

        // If src encoding is set convert table
        if (!empty($this->srcEncoding)) {
            $reader->setInputEncoding($this->srcEncoding);
        } else {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
        }

        $spreadsheet = $reader->load($this->get_source_csv_path());
        $worksheet = $spreadsheet->getActiveSheet();

        // get header of file
        $starting_row = 1;
        if ( $this->hasHeader ) {
            $starting_row = 2;
            $header = $worksheet->rangeToArray('A1:'.$worksheet->getHighestColumn().'1')[0];
        }

        $chunkData = [];
        $rowIterator = $worksheet->getRowIterator( $starting_row );
        foreach ($rowIterator as $row) {
            if ($this->skipEmptyRows && $row->isEmpty()) { // Ignore empty rows
                continue;
            }

            $i = 0;
            $columnIterator = $row->getCellIterator();
            $rowData = [];
            foreach ($columnIterator as $cell) {

                $cellValue = $cell->getValue();

                // Use header as key if set, else just append
                if ( ! empty( $header ) ) {
                    $rowData[$header[$i]] = $cellValue;
                } else {
                    $rowData[] = $cellValue;
                }
                $i++;
            }

            // Add row to chunk
            $chunkData[] = $rowData;

            // schedule chunk and empty chunk data
            if ( count($chunkData) >= $this->chunkSize ) {
                $this->schedule_chunk($chunkData);
                $chunkData = [];
            }
        }

        // Add remaining elements into a last chunk
        if ( ! empty( $chunkData ) ) {
            $this->schedule_chunk($chunkData);
        }

        unlink($csvFilePath);
    }
}
