<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Import extends Sync
{

    /**
     * @param string $chunk_file_path
     * @throws Exception
     */
    public function process_chunk(string $chunk_file_path): void
    {
        $this->import_chunk($chunk_file_path);

    }

}
