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
     * /**
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
        $this->actions[$id]['duration'] = (float)$this->actions[$id]['end']->format('U.u') - (float)$this->actions[$id]['start']->format('U.u');
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

    /**
     * Get the total duration of the sync process.
     *
     * @return float
     */
    public function get_sync_duration(): float
    {
        if (isset($this->sync_start) && isset($this->sync_end)) {
            return (float)$this->sync_end->format('U.u') - (float)$this->sync_start->format('U.u');
        }
        return 0.0;
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
     * Get the count of actions by status.
     *
     * @param string $status
     * @return int
     */
    public function get_action_count_by_status(string $status): int
    {
        return count(array_filter($this->actions, fn($action) => $action['status'] === $status));
    }

    /**
     * Calculate the average duration of the actions.
     *
     * @return float
     */
    public function get_average_action_duration(): float
    {
        $total_duration = array_sum(array_column($this->actions, 'duration'));
        return $this->get_total_actions() > 0 ? $total_duration / $this->get_total_actions() : 0;
    }

    /**
     * /**
     * Find the action with the longest duration.
     *
     * @return array|null
     */
    public function get_slowest_action(): ?array
    {
        if (empty($this->actions)) {
            return null;
        }
        return array_reduce($this->actions, function($a, $b) {
            return ($a['duration'] ?? 0) > ($b['duration'] ?? 0) ? $a : $b;
        });
    }

    /**
     * Find the action with the shortest duration.
     *
     * @return array|null
     */
    public function get_fastest_action(): ?array
    {
        if (empty($this->actions)) {
            return null;
        }
        return array_reduce($this->actions, function($a, $b) {
            return ($a['duration'] ?? PHP_INT_MAX) < ($b['duration'] ?? PHP_INT_MAX) ? $a : $b;
        });
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
            'sync_duration'           => $this->get_sync_duration(),
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
        $email_text .= sprintf(__("Sync Duration: %s seconds", 'as-processor'), $this->get_sync_duration()) . "\n";
        $email_text .= sprintf(__("Average Action Duration: %s seconds", 'as-processor'), $this->get_average_action_duration()) . "\n";
        $email_text .= sprintf(__("Slowest Action Duration: %s seconds", 'as-processor'), $this->get_slowest_action()['duration'] ?? __('N/A', 'as-processor')) . "\n";
        $email_text .= sprintf(__("Fastest Action Duration: %s seconds", 'as-processor'), $this->get_fastest_action()['duration'] ?? __('N/A', 'as-processor')) . "\n";

        $email_text .= "\n" . __("Actions Detail:", 'as-processor') . "\n";
        foreach ($this->actions as $action) {
            $email_text .= sprintf(__("Action ID: %s", 'as-processor'), $action['id']) . "\n";
            $email_text .= sprintf(__("Status: %s", 'as-processor'), $action['status']) . "\n";
            $email_text .= sprintf(__("Start: %s", 'as-processor'), $action['start']->format('Y-m-d H:i:s')) . "\n";
            $email_text .= sprintf(__("End: %s", 'as-processor'), $action['end']->format('Y-m-d H:i:s')) . "\n";
            $email_text .= sprintf(__("Duration: %s seconds", 'as-processor'), $action['duration']) . "\n";
            if ($action['status'] === 'failed') {
                $email_text .= sprintf(__("Error Message: %s", 'as-processor'), $action['error_message']) . "\n";
            }
            $email_text .= "\n";
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