<?php

namespace juvo\AS_Processor\Imports;
use juvo\AS_Processor\Import;
use PhpOffice\PhpSpreadsheet;

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
    protected int $chunkSize = 1000;

    /**
     * Defines if a header in the excel file exists
     *
     * @var bool
     */
    protected bool $hasHeader = true;

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
    protected bool $skipEmptyRows = true;

    /**
     * Sets the basic needed hooks
     *
     * @return  void
     */
    public function set_hooks(): void
    {
        parent::set_hooks();
        add_action($this->get_sync_name(), [$this, 'split_into_chunks']);
    }

    /**
     * Gets the path to the excel file
     *
     * @return  string
     */
    abstract function get_source_filepath(): string;

    /**
     * Splits the excel files into chunks and schedules them
     *
     * @return  void
     */
    public function split_excel_chunks()
    {
        $filepath = $this->get_source_filepath();

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
        if ( $this->hasHeader ) {
            $starting_row = 2;
            $header = $worksheet->rangeToArray('A1:'.$worksheet->getHighestColumn().'1')[0];
        }

        $rowData = [];
        $rowIterator = $worksheet->getRowIterator( $starting_row );
        foreach ($rowIterator as $row) {
            if ($this->skipEmptyRows && $row->isEmpty()) { // Ignore empty rows
                continue;
            }

            $i = 0;
            $columnIterator = $row->getCellIterator();
            $singleRowData = [];
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

                // append to single row array and check if there are headers
                // if not, just append to the array
                if ( ! empty( $header ) ) {
                    $singleRowData[$header[$i]] = $cellValue;
                } else {
                    $singleRowData[] = $cellValue;
                }
                $i++;
            }
            $rowData[] = $singleRowData;

            // schedule chunk if chunk size is reached and empty
            // the rowData because we need it at the beginning of
            // the loop again
            if ( count($rowData) >= $this->chunkSize ) {
                $this->schedule_chunk($rowData);
                $rowData = [];
            }
        }

        // push last chunk if not reached limit
        if ( ! empty( $rowData ) ) {
            $this->schedule_chunk($rowData);
        }

        unlink($filepath);
    }
}
