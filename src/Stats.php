<?php

namespace juvo\AS_Processor;

use DateTimeImmutable;
use juvo\AS_Processor\DB\Chunk_DB;
use juvo\AS_Processor\Entities\Chunk;
use juvo\AS_Processor\Entities\ProcessStatus;

class Stats
{

    /**
     * The group name for the sync process.
     *
     * @var string
     */
    private string $group_name;

    /**
     * Stats constructor.
     *
     * @param string $group_name The group name for the sync process.
     */
    public function __construct(string $group_name) {
        $this->group_name = $group_name;
    }

    /**
     * Gets the sync duration.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return float|string|false Duration or false if not available.
     */
    public function get_sync_duration(bool $human_time = false): float|string|false {
        $query = Chunk_DB::db()->prepare(
            "SELECT MIN(start) as sync_start, MAX(end) as sync_end 
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s",
            $this->group_name
        );

        $result = Chunk_DB::db()->get_row($query);

        if (empty($result->sync_start) || empty($result->sync_end)) {
            return false;
        }

        $duration = round((float)$result->sync_end - (float)$result->sync_start, 4);

        if ($human_time) {
            return Helper::human_time_diff_microseconds(0, $duration);
        }

        return $duration;
    }

    /**
     * Gets the total number of actions.
     *
     * @return int
     */
    public function get_total_actions(): int {
        $query = Chunk_DB::db()->prepare(
            "SELECT COUNT(*) FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s",
            $this->group_name
        );

        return (int)Chunk_DB::db()->get_var($query);
    }

    /**
     * Gets actions filtered by status.
     *
     * @param ProcessStatus|array<ProcessStatus> $status Status to filter by.
     * @param bool $include_durations Whether to include durations.
     * @param bool $human_time Whether to return human-readable time.
     * @return array
     */
    public function get_actions_by_status(ProcessStatus|array $status, bool $include_durations = false, bool $human_time = false): array
    {
        $statuses = is_array($status) ? $status : [$status];
        $status_values = array_map(static fn(ProcessStatus $status): string => $status->value, $statuses);

        $placeholders = array_fill(0, count($status_values), '%s');
        $query = Chunk_DB::db()->prepare(
            "SELECT * FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s AND status IN (" . implode(',', $placeholders) . ")",
            array_merge([$this->group_name], $status_values)
        );

        $results = Chunk_DB::db()->get_results($query, ARRAY_A);

        if ($include_durations) {
            foreach ($results as &$action) {
                if (!empty($action['start']) && !empty($action['end'])) {
                    $duration = round((float)$action['end'] - (float)$action['start'], 4);
                    $action['duration'] = $human_time
                        ? Helper::human_time_diff_microseconds(0, $duration)
                        : $duration;
                }
            }
        }

        return $results;
    }

    /**
     * Gets the average action duration.
     *
     * @param bool $human_time Whether to return human-readable time.
     * @return float|string
     */
    public function get_average_action_duration(bool $human_time = false): float|string {
        $query = Chunk_DB::db()->prepare(
            "SELECT AVG(end - start) as avg_duration 
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL",
            $this->group_name
        );

        $average = (float)Chunk_DB::db()->get_var($query);

        return $human_time ?
            Helper::human_time_diff_microseconds(0, $average) :
            $average;
    }

    /**
     * Gets the slowest action.
     *
     * @return ?Chunk
	 */
    public function get_slowest_action(): ?Chunk
	{
        $query = Chunk_DB::db()->prepare(
            "SELECT *, (end - start) as duration 
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration DESC 
            LIMIT 1",
            $this->group_name
        );
		$chunk = Chunk_DB::db()->get_chunk($query);

		if (!$chunk) {
			return null;
		}

		return $chunk;
    }

    /**
     * Gets the fastest action.
     *
     * @return ?Chunk
	 */
    public function get_fastest_action(): ?Chunk
	{
        $query = Chunk_DB::db()->prepare(
            "SELECT *, (end - start) as duration 
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s AND start IS NOT NULL AND end IS NOT NULL 
            ORDER BY duration ASC 
            LIMIT 1",
            $this->group_name
        );
        $chunk = Chunk_DB::db()->get_chunk($query);

        if (!$chunk) {
            return null;
        }

        return $chunk;
    }

    /**
     * Gets the sync start time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_start(): ?DateTimeImmutable
    {
        $query = Chunk_DB::db()->prepare(
            "SELECT start
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s
            AND start IS NOT NULL
            ORDER BY start ASC
            LIMIT 1",
            $this->group_name
        );
        
        $start = Chunk_DB::db()->get_var($query);
        
        if (empty($start)) {
            return null;
        }
        
        return Helper::convert_microtime_to_datetime($start);
    }
    
    /**
     * Gets the sync end time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_end(): ?DateTimeImmutable
    {
        $query = Chunk_DB::db()->prepare(
            "SELECT end
            FROM ". Chunk_DB::db()->get_table_name() ."
            WHERE `group` = %s
            AND end IS NOT NULL
            ORDER BY end DESC
            LIMIT 1",
            $this->group_name
        );
        
        $end = Chunk_DB::db()->get_var($query);
        
        if (empty($end)) {
            return null;
        }
        
        return Helper::convert_microtime_to_datetime($end);
    }

    /**
     * Get the object as a JSON string, including custom data.
     *
     * @param array $custom_data Optional custom data to be included
     * @return string
     */
    public function to_json(array $custom_data = []): string
    {
        $data = [
            'sync_start'              => $this->get_sync_start()?->format(DateTimeImmutable::ATOM),
            'sync_end'                => $this->get_sync_end()?->format(DateTimeImmutable::ATOM),
            'total_actions'           => $this->get_total_actions(),
            'sync_duration'           => $this->get_sync_duration(),
            'average_action_duration' => $this->get_average_action_duration(),
            'slowest_action'          => $this->get_slowest_action(),
            'fastest_action'          => $this->get_fastest_action(),
            'actions'                 => Chunk_DB::db()->get_chunks_by_status(group_name: $this->group_name),
            'custom_data'             => $custom_data
        ];
        return json_encode($data);
    }

    /**
     * Prepare an email text report, including custom data.
     *
     * @param array $custom_data Optional custom data to be included
     * @return string
     */
    public function prepare_email_text(array $custom_data = []): string
    {
        $email_text = "--- ". __("Synchronization Report:", 'as-processor') . " ---\n";
        $email_text .= sprintf(__("Sync Start: %s", 'as-processor'), $this->get_sync_start()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Sync End: %s", 'as-processor'), $this->get_sync_end()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Total Actions: %d", 'as-processor'), $this->get_total_actions()) . "\n";
        $email_text .= sprintf(__("Sync Duration: %s", 'as-processor'), $this->get_sync_duration(true)) . "\n";
        $email_text .= sprintf(__("Average Action Duration: %s", 'as-processor'), $this->get_average_action_duration(true)) . "\n";
        $email_text .= sprintf(__("Slowest Action Duration: %s", 'as-processor'), Helper::human_time_diff_microseconds(0, $this->get_slowest_action()?->get_duration()) ) . "\n";
        $email_text .= sprintf(__("Fastest Action Duration: %s", 'as-processor'), Helper::human_time_diff_microseconds(0, $this->get_fastest_action()?->get_duration()) ) . "\n";

		// Append custom data if available
		if (!empty($custom_data)) {
			$email_text .= "\n-- " . __("Custom Data:", 'as-processor') . " --\n";
			foreach ($custom_data as $key => $value) {
				$email_text .= sprintf(__("%s: %s", 'as-processor'), ucfirst($key), (is_array($value) ? json_encode($value) : $value)) . "\n";
			}
		}

        // Failed actions
		$failed_actions = Chunk_DB::db()->get_chunks_by_status(group_name: $this->group_name, status: ProcessStatus::FAILED);
		if (!empty($failed_actions)) {
            $email_text .= "\n-- " . __("Failed Actions Detail:", 'as-processor') . " --\n";
            foreach ($failed_actions as $chunk) {
                $email_text .= sprintf(__("Action ID: %s", 'as-processor'), $chunk->get_action_id()) . "\n";
                $email_text .= sprintf(__("Status: %s", 'as-processor'), $chunk->get_status()->value) . "\n";
                $email_text .= sprintf(__("Start: %s", 'as-processor'), $chunk->get_start()->format('Y-m-d H:i:s')) . "\n";
                $email_text .= sprintf(__("End: %s", 'as-processor'), $chunk->get_end()->format('Y-m-d H:i:s')) . "\n";

				if ($chunk->get_status()->value === 'failed') {
                    $email_text .= __("Log Messages:", 'as-processor') . "\n";
                    foreach ( $chunk->get_logs() as $message ) {
						$decoded_message = htmlspecialchars_decode($message, ENT_QUOTES);
						$email_text .= sprintf(__("%s", 'as-processor'), $decoded_message) . "\n";
                    }
                }
                $email_text .= "\n";
            }
        }

        return $email_text;
    }
}
