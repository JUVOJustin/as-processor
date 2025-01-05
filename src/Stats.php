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
	 * Converts the current object state and additional data to a JSON representation.
	 *
	 * @param array $custom_data Additional custom data to be included in the JSON output.
	 * @param array $excludedFields Fields to be excluded from the JSON output for specific actions.
	 * @return string The JSON representation of the object including the provided custom data and excluded fields configuration.
	 */
    public function to_json(array $custom_data = [], array $excludedFields = []): string
    {

		$actions = Chunk_DB::db()->get_chunks_by_status(group_name: $this->group_name);
		$actions = array_map(function (Chunk $chunk) use ($excludedFields) {
			return $chunk->setJsonExcludedFields($excludedFields);
		}, $actions);

        $data = [
            'sync_start'              => Chunk_DB::db()->get_sync_start($this->group_name)?->format(DateTimeImmutable::ATOM),
            'sync_end'                => Chunk_DB::db()->get_sync_end($this->group_name)?->format(DateTimeImmutable::ATOM),
            'total_actions'           => Chunk_DB::db()->get_total_actions($this->group_name),
            'sync_duration'           => Chunk_DB::db()->get_sync_duration($this->group_name),
            'average_action_duration' => Chunk_DB::db()->get_average_action_duration($this->group_name),
            'slowest_action'          => Chunk_DB::db()->get_slowest_action($this->group_name)->setJsonExcludedFields($excludedFields),
            'fastest_action'          => Chunk_DB::db()->get_fastest_action($this->group_name)->setJsonExcludedFields($excludedFields),
            'actions'                 => $actions,
            'custom_data'             => $custom_data
        ];
        return json_encode($data);
    }

	/**
	 * Prepare an email text report, including custom data.
	 *
	 * @param array $custom_data Optional custom data to be included
	 * @return string
	 * @throws \Exception Unparsable Date.
	 */
    public function prepare_email_text(array $custom_data = []): string
    {
        $email_text = "--- ". __("Synchronization Report:", 'as-processor') . " ---\n";
        $email_text .= sprintf(__("Sync Start: %s", 'as-processor'), Chunk_DB::db()->get_sync_start($this->group_name)?->format("Y-m-d H:i:s.u T")) . "\n";
        $email_text .= sprintf(__("Sync End: %s", 'as-processor'), Chunk_DB::db()->get_sync_end($this->group_name)?->format("Y-m-d H:i:s.u T")) . "\n";
        $email_text .= sprintf(__("Total Actions: %d", 'as-processor'), Chunk_DB::db()->get_total_actions($this->group_name)) . "\n";
        $email_text .= sprintf(__("Sync Duration: %s", 'as-processor'), Chunk_DB::db()->get_sync_duration($this->group_name, true)) . "\n";
        $email_text .= sprintf(__("Average Action Duration: %s", 'as-processor'), Chunk_DB::db()->get_average_action_duration($this->group_name,true)) . "\n";
        $email_text .= sprintf(__("Slowest Action Duration: %s", 'as-processor'), Helper::human_time_diff_microseconds(0, Chunk_DB::db()->get_slowest_action($this->group_name)?->get_duration()) ) . "\n";
        $email_text .= sprintf(__("Fastest Action Duration: %s", 'as-processor'), Helper::human_time_diff_microseconds(0, Chunk_DB::db()->get_fastest_action($this->group_name)?->get_duration()) ) . "\n";

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
                $email_text .= sprintf(__("Start: %s", 'as-processor'), $chunk->get_start()->format("Y-m-d H:i:s.u T")) . "\n";
                $email_text .= sprintf(__("End: %s", 'as-processor'), $chunk->get_end()->format("Y-m-d H:i:s.u T")) . "\n";

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

	/**
	 * Sends an email report.
	 *
	 * @param string $to The recipient's email address. Defaults to admin mail.
	 * @param string $subject Optional. Subject of the email.
	 * @param array $custom_data Custom data to include in the email. Defaults to an empty array.
	 * @return void
	 * @throws \Exception Unparsable Date.
	 */
	public function send_mail_report(string $to = "", string $subject = "", array $custom_data = []): void
	{

		$message = $this->prepare_email_text($custom_data);
		$mailarray = apply_filters('asp/stats/remail_report', [
			'to'      => $to ?: get_option('admin_email'),
			'subject' => $subject ?: sprintf(__('[%s] Report of sync group %s', 'sinnewerk'), get_bloginfo('name'), $this->group_name),
			'message' => $message
		]);
		wp_mail($mailarray['to'], $mailarray['subject'], $mailarray['message']);
	}
}
