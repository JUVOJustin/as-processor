<?php

namespace juvo\AS_Processor;

use Exception;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

abstract class CSV_Sync extends Import
{

    protected int $chunkSize = 5000;
    protected string $delimiter = ',';
    protected bool $hasHeader = true;
    protected string $srcEncoding = "";

    abstract protected function get_source_csv_path(): string;

    /**
     * Takes the source csv and splits it into chunk files that contain the set amount of items
     *
     * @return void
     * @throws \League\Csv\Exception
     * @throws InvalidArgument
     * @throws UnavailableStream
     * @throws \League\Csv\Exception
     * @throws Exception
     */
    public function split_data_into_chunks(): void
    {

        $csvFilePath = $this->get_source_csv_path();
        if (!file_exists($csvFilePath)) {
            throw new Exception("Failed to open the file: $csvFilePath");
        }

        // Read csv from file
        $reader = Reader::createFromPath($this->get_source_csv_path(), 'r');
        $reader->setDelimiter($this->delimiter);

        // If src encoding is set convert table to utf-8
        if (!empty($this->srcEncoding)) {
            $reader->addStreamFilter("convert.iconv.$this->srcEncoding/UTF-8");
        }

        // Maybe add header
        if ($this->hasHeader) {
            $reader->setHeaderOffset(0);
        }

        // Process chunks
        foreach ($reader->chunkBy($this->chunkSize) as $chunk) {
            $this->schedule_chunk($chunk->getRecords());
        }

        // Remove chunk file after sync
        $test_unlink = unlink($csvFilePath);
        if ( $test_unlink === false ) {
            throw new Exception("File '$csvFilePath' could not be deleted!");
        }
    }
}