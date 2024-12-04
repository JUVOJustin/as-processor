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
     * @param int $chunk_id
     * @throws Exception
     */
    public function process_chunk(int $chunk_id): void
    {
        $this->import_chunk($chunk_id);
    }

}
