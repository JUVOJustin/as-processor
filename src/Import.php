<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Import extends Sync
{
    protected int $chunk_counter = 0;
    protected int $chunk_limit = 0;
    /**
     * @param string $chunk_file_path
     * @throws Exception
     */
    public function process_chunk(string $chunk_file_path): void
    {
        $this->import_chunk($chunk_file_path);
    }

}
