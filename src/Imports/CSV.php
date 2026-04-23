<?php

namespace juvo\AS_Processor\Imports;

use juvo\AS_Processor\Helper;
use juvo\AS_Processor\Import;
use Exception;
use League\Csv\InvalidArgument;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

abstract class CSV extends Import
{

    protected int $chunk_size = 5000;
    protected string $delimiter = ',';
    protected bool $has_header = true;
    protected string $src_encoding = "";

    abstract protected function get_source_path(): string;

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

        $filepath = $this->get_source_path();
		$wp_filesystem = Helper::get_direct_filesystem();

        if (!$wp_filesystem->is_file($filepath))     {
            throw new Exception("Failed to open the file: $filepath");
        }

        // Read csv from file
        $reader = Reader::from($this->get_source_path(), 'r');
        $reader->setDelimiter($this->delimiter);

        // If src encoding is set convert table to utf-8
        if (!empty($this->src_encoding) && $reader->supportsStreamFilterOnRead()) {
			$reader->appendStreamFilterOnRead("convert.iconv.$this->src_encoding/UTF-8");
        }

        // Maybe add header
        if ($this->has_header) {
            $reader->setHeaderOffset(0);
        }

        // Process chunks
        foreach ($reader->chunkBy($this->chunk_size) as $chunk) {
            $this->schedule_chunk($chunk->getRecords());
        }

        // Remove chunk file after sync
		$unlink_result = Helper::get_direct_filesystem()->delete($filepath);
        if ( $unlink_result === false ) {
            throw new Exception("File '$filepath' could not be deleted!");
        }
    }
}
