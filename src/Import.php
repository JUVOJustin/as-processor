<?php

namespace juvo\AS_Processor;

use Exception;

abstract class Import extends Sync
{
    protected int $chunk_counter = 0;
    protected int $chunk_limit = 0;

    /**
     * Adds the hooks for the chunking
     *
     * @return  void
     * @throws Exception
     */
	public function set_hooks(): void
	{
		parent::set_hooks();
		add_action($this->get_sync_name(), [$this, 'split_data_into_chunks']);
        add_action($this->get_sync_name(), function() {
            $this->get_stats()->start_sync();
        });
	}

    /**
	 * Split the data, wherever it comes from into chunks.
	 * This function has to be implemented within each "Import".
	 * The basic workflow is:
	 * 	1. Get the source data
	 *  2. Split the data into the smaller subsets called chunks.
	 * 	3. Schedule chunks to of data using the Chunker.php trait
	 * 
	 * @return void
	 */
	abstract public function split_data_into_chunks():void;
    
    /**
     * @param string $chunk_file_path
     * @throws Exception
     */
    public function process_chunk(string $chunk_file_path): void
    {
        $this->import_chunk($chunk_file_path);
    }

}
