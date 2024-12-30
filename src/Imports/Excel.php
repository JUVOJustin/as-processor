<?php

namespace juvo\AS_Processor\Imports;
use Exception;
use juvo\AS_Processor\Helper;
use juvo\AS_Processor\Import;
use PhpOffice\PhpSpreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\CellIterator;

/**
 * This class processes an excel file and splits its content
 * into chunks which are then scheduled with the action scheduler
 *
 * @since   2024-07-15
 * @author  Laura Herzog <laura.herzog@citation.media>
 */
abstract class Excel extends Import
{
    /**
     * Defines the chunk size
     *
     * @var int
     */
    protected int $chunk_size = 1000;

    /**
     * Defines if a header in the excel file exists
     *
     * @var bool
     */
    protected bool $has_header = true;

    /**
     * Defines the name of the worksheet to be processed. If none
     * given, the active worksheet will be used
     *
     * @var string
     */
    protected string $worksheet;

    /**
     * Setting to skip empty rows
     *
     * @var bool
     */
    protected bool $skip_empty_rows = true;

    /**
     * Gets the path to the excel file
     *
     * @return  string
     */
    abstract function get_source_path(): string;

	/**
	 * Splits the excel files into chunks and schedules them
	 *
	 * @return  void
	 * @throws \Exception
	 */
    public function split_data_into_chunks(): void
	{
		$filepath = $this->get_source_path();
		$wp_filesystem = Helper::get_direct_filesystem();

		if (!$wp_filesystem->is_file($filepath)) {
			throw new Exception("Failed to open the file: $filepath");
		}

        $reader = PhpSpreadsheet\IOFactory::createReader("Xlsx");
        $spreadsheet = $reader->load($filepath);

        if ( ! empty( $this->worksheet ) ) {
            $worksheet = $spreadsheet->getSheetByName($this->worksheet);

            // fallback if sheetname is not found
            if ( is_null( $worksheet ) ) {
                $worksheet = $spreadsheet->getActiveSheet();
            }
        } else {
            $worksheet = $spreadsheet->getActiveSheet();
        }

        // get header of file
        $starting_row = 1;
        if ( $this->has_header ) {
            $starting_row = 2;
            $header = $worksheet->rangeToArray('A1:'.$worksheet->getHighestColumn().'1')[0];
        }

        $chunkData = [];
        $rowIterator = $worksheet->getRowIterator( $starting_row );
        foreach ($rowIterator as $row) {
            if ( $this->skip_empty_rows && $row->isEmpty(
				CellIterator::TREAT_EMPTY_STRING_AS_EMPTY_CELL | 
				CellIterator::TREAT_NULL_VALUE_AS_EMPTY_CELL )
			) { // Ignore empty rows
				continue;
			}

            $i = 0;
            $columnIterator = $row->getCellIterator();
            $rowData = [];
            foreach ($columnIterator as $cell) {

                // get the cell value
                $cellValue = $cell->getValue();

                // sometimes cell values can be richTextElements which is determined by excel
                // itself. We need to extract the cell values from the richTextElement to get it
                // without formatting
                if ( $cellValue instanceof PhpSpreadsheet\RichText\RichText ) {
                    foreach ($cellValue->getRichTextElements() as $richTextElement) {
                        $cellValue = $richTextElement->getText();
                    }
                }

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
            if ( count($chunkData) >= $this->chunk_size ) {
                $this->schedule_chunk($chunkData);
                $chunkData = [];
            }
        }

        // Add remaining elements into a last chunk
        if ( ! empty( $chunkData ) ) {
            $this->schedule_chunk($chunkData);
        }

		$unlink_result = Helper::get_direct_filesystem()->delete($filepath);
		if ( $unlink_result === false ) {
			throw new Exception("File '$filepath' could not be deleted!");
		}
    }
}
