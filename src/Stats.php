<?php

namespace juvo\AS_Processor;

use DateTimeImmutable;

class Stats
{
    private ?DateTimeImmutable $sync_start = null;
    private ?DateTimeImmutable $sync_end = null;
    private array $actions = [];
    private ?Stats_Saver $saver;

    public function __construct(Stats_Saver $saver)
    {
        $this->saver = $saver;
    }

    /**
     * Initialize the start time for the sync process.
     */
    public function start_sync(): void
    {
        if ($this->sync_start) {
            return;
        }

        $this->sync_start = current_datetime();
        $this->save();
    }

    /**
     * Initialize the end time for the sync process.
     */
    public function end_sync(): void
    {
        if ($this->sync_end) {
            return;
        }

        $this->sync_end = current_datetime();
        $this->save();
    }

    /**
     * Add a new action with its details.
     *
     * @param int $id
     * @throws \Exception
     */
    public function add_action(int $id): void
    {
        $this->actions[$id] = [
            'id'    => $id,
            'start' => current_datetime(),
        ];
        $this->save();
    }

    /**
     * End an action and sets endtime as well as duration.
     *
     * @param int $id
     * @return void
     * @throws \Exception
     */
    public function end_action(int $id): void
    {
        if (!isset($this->actions[$id])) {
            return;
        }

        $this->actions[$id]['end'] = current_datetime();
        $this->save();
    }

    public function mark_action_as_failed(int $id, string $error_message): void
    {
        if (!isset($this->actions[$id])) {
            throw new \Exception("Action ID $id not found");
        }

        $this->actions[$id]['status'] = 'failed';
        $this->actions[$id]['error_message'] = $error_message;
        $this->save();
    }

    public function get_action_duration(int $id, bool $human_time = false): float|string {
        if (
            !isset($this->actions[$id])
            || empty($this->actions[$id]['end'])
            || empty($this->actions[$id]['start'])
        ) {
            return false;
        }

        $duration = round((float)$this->actions[$id]['end']->format('U.u') - (float)$this->actions[$id]['start']->format('U.u'), 4);
        if ($human_time && $duration >= 1) {
            return human_time_diff(0, $duration);
        }

        return $duration;
    }

    /**
     * Get the total number of actions processed.
     *
     * @return int
     */
    public function get_total_actions(): int
    {
        return count($this->actions);
    }

    /**
     * Get the actions by status.
     *
     * @param array|string $status Single status or array of statuses
     * @param bool $include_durations Whether to include durations in the result
     * @param bool $human_time Whether to return durations in human-readable format
     * @return array
     */
    public function get_actions_by_status(array|string $status, bool $include_durations = false, bool $human_time = false): array
    {
        $statuses = is_array($status) ? $status : [$status];

        $filtered_actions = array_filter($this->actions, function($action) use ($statuses) {
            return isset($action['status']) && in_array($action['status'], $statuses, true);
        });

        if ($include_durations) {
            foreach ($filtered_actions as $id => &$action) {
                $duration = $this->get_action_duration($id, $human_time);
                if ($duration !== false) {
                    $action['duration'] = $duration;
                }
            }
            unset($action); // Unset reference to last element
        }

        return $filtered_actions;
    }

    /**
     * Calculate the average duration of the actions.
     *
     * @param bool $human_time
     * @return float|string
     */
    public function get_average_action_duration(bool $human_time = false): float|string
    {
        $total_duration = 0;
        $action_count = 0;
        foreach (array_keys($this->actions) as $id) {
            $duration = $this->get_action_duration($id);
            if ($duration !== false) {
                $total_duration += $duration;
                $action_count++;
            }
        }
        $average = $action_count > 0 ? $total_duration / $action_count : 0;
        return $human_time ? human_time_diff(0, $average) : $average;
    }

    /**
     * Find the action with the longest duration.
     *
     * @param bool $human_time
     * @return array|null
     */
    public function get_slowest_action(bool $human_time = false): ?array
    {
        $slowest = null;
        $max_duration = -1;
        foreach (array_keys($this->actions) as $id) {
            $duration = $this->get_action_duration($id);
            if ($duration !== false && $duration > $max_duration) {
                $slowest = $id;
                $max_duration = $duration;
            }
        }
        if ($slowest === null) {
            return null;
        }
        $duration = $this->get_action_duration($slowest, $human_time);
        return ['id' => $slowest, 'duration' => $duration];
    }

    /**
     * Find the action with the shortest duration.
     *
     * @param bool $human_time
     * @return array|null
     */
    public function get_fastest_action(bool $human_time = false): ?array
    {
        $fastest = null;
        $min_duration = PHP_FLOAT_MAX;
        foreach (array_keys($this->actions) as $id) {
            $duration = $this->get_action_duration($id);
            if ($duration !== false && $duration < $min_duration) {
                $fastest = $id;
                $min_duration = $duration;
            }
        }
        if ($fastest === null) {
            return null;
        }
        $duration = $this->get_action_duration($fastest, $human_time);
        return ['id' => $fastest, 'duration' => $duration];
    }

    /**
     * Get the sync start time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_start(): ?DateTimeImmutable
    {
        return $this->sync_start ?? null;
    }

    /**
     * Get the sync end time.
     *
     * @return DateTimeImmutable|null
     */
    public function get_sync_end(): ?DateTimeImmutable
    {
        return $this->sync_end ?? null;
    }

    /**
     * Get the details of all actions.
     *
     * @return array
     */
    public function get_actions(): array
    {
        return $this->actions;
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
            'sync_duration'           => $this->sync_end->format('U') - $this->sync_start->format('U'),
            'average_action_duration' => $this->get_average_action_duration(),
            'slowest_action'          => $this->get_slowest_action(),
            'fastest_action'          => $this->get_fastest_action(),
            'actions'                 => $this->actions,
            'custom_data'             => $custom_data
        ];
        return json_encode($data);
    }

    /**
     * /**
     * Prepare an email text report, including custom data.
     *
     * @param array $custom_data Optional custom data to be included
     * @return string
     */
    public function prepare_email_text(array $custom_data = []): string
    {
        $email_text = __("Synchronization Report:", 'as-processor') . "\n";
        $email_text .= sprintf(__("Sync Start: %s", 'as-processor'), $this->get_sync_start()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Sync End: %s", 'as-processor'), $this->get_sync_end()?->format('Y-m-d H:i:s')) . "\n";
        $email_text .= sprintf(__("Total Actions: %d", 'as-processor'), $this->get_total_actions()) . "\n";
        $email_text .= sprintf(__("Sync Duration: %s", 'as-processor'), human_time_diff($this->sync_end->format('U'), $this->sync_start->format('U'))) . "\n";
        $email_text .= sprintf(__("Average Action Duration: %s", 'as-processor'), $this->get_average_action_duration(true)) . "\n";
        $email_text .= sprintf(__("Slowest Action Duration: %s", 'as-processor'), $this->get_slowest_action(true)['duration'] ?? __('N/A', 'as-processor')) . "\n";
        $email_text .= sprintf(__("Fastest Action Duration: %s", 'as-processor'), $this->get_fastest_action(true)['duration'] ?? __('N/A', 'as-processor')) . "\n";

        // Failed actions
        $failed_actions = $this->get_actions_by_status('failed');
        if (!empty($failed_actions)) {
            $email_text .= "\n" . __("Failed Actions Detail:", 'as-processor') . "\n";
            foreach ($failed_actions as $action) {
                $email_text .= sprintf(__("Action ID: %s", 'as-processor'), $action['id']) . "\n";
                $email_text .= sprintf(__("Status: %s", 'as-processor'), $action['status']) . "\n";
                $email_text .= sprintf(__("Start: %s", 'as-processor'), $action['start']->format('Y-m-d H:i:s')) . "\n";
                if ($action['status'] === 'failed') {
                    $email_text .= sprintf(__("Error Message: %s", 'as-processor'), $action['error_message']) . "\n";
                }
                $email_text .= "\n";
            }
        }

        // Append custom data if available
        if (!empty($custom_data)) {
            $email_text .= "\n" . __("Custom Data:", 'as-processor') . "\n";
            foreach ($custom_data as $key => $value) {
                $email_text .= sprintf(__("%s: %s", 'as-processor'), ucfirst($key), (is_array($value) ? json_encode($value) : $value)) . "\n";
            }
        }

        return $email_text;
    }

    /**
     * @throws \Exception
     */
    public function save(): void
    {
        if ($this->saver === null) {
            throw new \Exception("Stats_Saver object is not set.");
        }

        $this->saver->save_stats($this);
    }
}