<?php

namespace juvo\AS_Processor;

use Exception;
use Generator;
use Iterator;

trait Chunker
{

    /**
     * Generates a chunk file path
     *
     * @return string
     * @throws Exception
     */
    private function get_chunk_path(): string
    {
        $filename = str_replace('/', '-', "{$this->get_sync_name()}_".microtime().".txt");
        $filename = apply_filters('as_processor/chunk/filename', $filename, $this);

        $folder = $tmp = get_temp_dir();
        $folder = apply_filters('as_processor/chunk/folder', $folder, $this);

        $folder_created = wp_mkdir_p($folder);
        if (!$folder_created) {
            throw new Exception('Could not create chunk folder');
        }

        $path = strtolower($tmp . $filename);
        return apply_filters('as_processor/chunk/path', $path, $this);
    }

    /**
     * Schedules an async action to process a chunk of data. Passed items are serialized and added to a chunk.
     *
     * @param array|Iterator $chunkData
     * @return void
     * @throws Exception
     */
    protected function schedule_chunk(array|Iterator $chunkData): void
    {
        // update chunk counter
        if ( property_exists( $this, 'chunk_counter' ) ) {
            $this->chunk_counter += 1;
        }

        // check if we have a chunk limit
        if ( property_exists( $this, 'chunk_limit' ) && property_exists( $this, 'chunk_counter' ) && $this->chunk_limit != 0 && $this->chunk_counter > $this->chunk_limit ) {
            return;
        }
        
        $filename = $this->get_chunk_path();

        // Write data to Chunk file
        $file = fopen($filename, 'w');
        foreach ($chunkData as $record) {
            fwrite($file, serialize($record) . self::SERIALIZED_DELIMITER);
        }
        fclose($file);

        as_enqueue_async_action(
            $this->get_sync_name() . '/process_chunk',
            [
                'chunk_file_path' => $filename
            ], // Wrap in array to pass as single argument. Needed because of abstract child method enforcement
            $this->get_sync_group_name()
        );
    }

    /**
     * Callback function for the single chunk jobs.
     * This jobs reads the chunk file line by line and yields each line to the actual process
     *
     * @param string $chunk_file_path
     * @return void
     * @throws Exception
     */
    protected function import_chunk(string $chunk_file_path): void
    {
        if (!file_exists($chunk_file_path)) {
            throw new Exception("File '$chunk_file_path' does not exist");
        }

        $file = fopen($chunk_file_path, 'r');
        $buffer = '';

        /**
         * Always read some larger portion and check if the end delimiter is present.
         * If not, we continue reading.
         */
        $formattedDataGenerator = (function() use ($file, &$buffer) {
            $chunkSize = 8192; // Read 8 KB at a time
            while (!feof($file)) {

                // Read a chunk of data from the file and append it to the buffer
                $buffer .= fread($file, $chunkSize);

                // Check if the delimiter is present in the buffer
                while (($pos = strpos($buffer, self::SERIALIZED_DELIMITER)) !== false) {

                    // Extract the complete serialized record up to the delimiter
                    $record = substr($buffer, 0, $pos);

                    // Remove the processed record from the buffer
                    $buffer = substr($buffer, $pos + strlen(self::SERIALIZED_DELIMITER));

                    // Deserialize and yield the record if it's not empty
                    if (!empty(trim($record))) {
                        yield unserialize(trim($record));
                    }
                }
            }

            // Process any remaining data in the buffer after reading the entire file
            if (!empty(trim($buffer))) {
                yield unserialize(trim($buffer));
            }
        })();

        $this->process_chunk_data($formattedDataGenerator);

        fclose($file);

        // Remove chunk file after sync
        unlink($chunk_file_path);
    }

    /**
     * Handles the actual data processing. Should be implemented in the class lowest in hierarchy
     *
     * @param Generator $chunkData
     * @return void
     */
    abstract function process_chunk_data(Generator $chunkData): void;

}
