<?php
class WC_Auto_Update_Order_Statuses_Over_Time
{
    /**
     * @var string The name of the scheduled event.
     */
    const EVENT_HOOK = 'wc_auto_update_order_statuses_over_time';

    /**
     * @var int The number of days without updates after which an order should be updated.
     * Set in constructor. Can be set directly.
     * Default is 90.
     */
    private $days;

    /**
     * @var array|string The order statuses that should be updated.
     * Set in constructor. Can be set directly.
     * Default is 'pending'.
     */
    private $target_statuses;


    /**
     * @var string The status to update the order to.
     * Set in constructor. Can be set directly.
     * Default is 'cancelled'.
     */
    private $new_status;

    /**
     * @var int The number of orders to update per event.
     * Set in constructor. Can be set directly.
     * Default is -1, which means all orders will be updated.
     */
    private $limit;

    /**
     * @var string The frequency with which to run the event.
     * Set in constructor. Can be set directly.
     * Default is 'daily'.
     */
    private $frequency;

    /**
     * @var int The time to start the event.
     * Set in constructor. Can be set directly.
     * Default is time().
     */
    private $start;

    /**
     * @var bool Whether or not to hide errors.
     * Can be set directly.
     * Default is false.
     */
    private $hide_errors;

    /**
     * @var bool Whether or not to block exceptions.
     * Can be set directly.
     * Default is false.
     */
    private $block_exceptions;

    /**
     * @var bool Whether or not the class has been invalidated.
     * Used to prevent the class from running if the settings are invalid upon instantiation while blocking exceptions.
     */
    private $invalidated;

    /**
     * Initialize the class and set hooks.
     * 
     * @param array $args The settings for the class:
     * • @param int $days The number of days after which an order should be updated.
     * • @param array $target_statuses The order statuses that should be updated.
     * • @param string $new_status The status to update the order to.
     * • @param int $limit The number of orders to update per event.
     * • @param string $frequency The frequency with which to run the event.
     * • @param int|string $start The time to start the event. Can be a Unix timestamp or a string that can be parsed by strtotime().
     */
    public function __construct($args = [])
    {
        // Check if WooCommerce is active
        $active_plugins = (array) get_option('active_plugins', []);
        if (!in_array('woocommerce/woocommerce.php', $active_plugins)) {
            $this->throw_error('WooCommerce must to be activated first to use ' . __CLASS__ . '.');
            $this->invalidated = true;
            return;
        }

        $defaults = array(
            'days' => 90,
            'target_statuses' => ['pending'],
            'new_status' => 'cancelled',
            'limit' => -1,
            'frequency' => 'daily',
            'start' => time(),
            'hide_errors' => false,
            'block_exceptions' => false,
        );

        $args = wp_parse_args($args, $defaults);

        if (!$this->validate_settings($args)) {
            $this->throw_exception('Invalid settings provided. Check the WordPress error log for details.', 'InvalidArgumentException');

            // If exceptions are hidden, invalidate the class so that it doesn't run.
            $this->invalidated = true;
            return;
        }

        // Schedule the event if it's not already scheduled.
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event($this->start, $this->frequency, self::EVENT_HOOK);
        }

        // Hook into the scheduled event.
        add_action(self::EVENT_HOOK, array($this, 'update_orders'));
    }

    /**
     * Update orders with target statuses that are over a certain number of days old.
     */
    public function update_orders()
    {
        if ($this->invalidated) {
            return null;
        }
        // Get the current date and time.
        $current_date = new DateTime();

        // Calculate the date $this->days days ago.
        $days_ago = $current_date->modify("-{$this->days} days")->format('Y-m-d H:i:s');

        // Query for orders with target statuses.
        $orders = wc_get_orders(array(
            'status' => $this->target_statuses,
            'limit' => $this->limit,
            'date_modified' => '<' . $days_ago, // Only select orders modified more than $this->days days ago or more.
        ));

        // Loop through each order and update its status.
        foreach ($orders as $order) {
            $previous_status = $order->get_status();
            $order->update_status($this->new_status, "Order status updated to {$this->new_status} after being in {$previous_status} status for {$this->days} days.");

            /** 
             * Trigger an action after the order status is updated.
             * 
             * @param WC_Order $order The order that was updated.
             * @param string $previous_status The previous status of the order.
             * @param string $new_status The new status of the order.
             * @param int $days The minimum number of days since the order was previously updated. This represents the settings at the time this was triggered... not the actual number of days since the order was previously updated.
             */
            do_action('wc_auto_update_order_statuses_over_time', $order, $previous_status, $this->new_status, $this->days);
        }
    }

    /**
     * Clear the scheduled event.
     */
    public function clear_events()
    {
        if ($this->invalidated) {
            return null;
        }
        wp_clear_scheduled_hook(self::EVENT_HOOK);
    }

    /**
     * Update the scheduled event.
     */
    private function update_events()
    {
        $this->clear_events();
        wp_schedule_event($this->start, $this->frequency, self::EVENT_HOOK);
    }

    /**
     * Get private properties.
     * 
     * @param string $name The name of the property to get.
     * @return mixed The value of the property, or null if it doesn't exist.
     */
    public function __get($name)
    {
        if ($this->invalidated) {
            return null;
        }
        switch ($name) {
            case 'days':
            case 'target_statuses':
            case 'new_status':
            case 'limit':
            case 'frequency':
            case 'start':
            case 'hide_errors':
            case 'block_exceptions':
            case 'invalidated':
                return $this->{$name};
            case 'event_hook':
                return self::EVENT_HOOK;
            default:
                return null;
        }
    }

    /**
     * Set private properties.
     * 
     * @param string $name The name of the property to set.
     * @param mixed $value The value to set the property to.
     */
    public function __set($name, $value)
    {
        if ($this->invalidated) {
            return null;
        }
        switch ($name) {
            case 'days':
            case 'target_statuses':
            case 'new_status':
            case 'limit':
            case 'frequency':
            case 'start':
                if ($this->validate_settings([$name => $value])) {
                    return $this->{$name};
                }
                break;
            case 'hide_errors':
            case 'block_exceptions':
                if (is_bool($value)) {
                    return $this->{$name} = $value;
                }
                $this->throw_error('The ' . $name . ' property must be a boolean.', false);
                break;
        }

        $this->throw_exception('Invalid value "' . $value . '" provided for property "' . $name . '".', 'InvalidArgumentException');
        return null;
    }

    /**
     * Validate all settings
     * 
     * @param array $args The settings to validate.
     * @return bool True if the settings are valid, false otherwise.
     */
    protected function validate_settings($args)
    {
        extract($args);

        $invalid_settings = [];
        foreach ($args as $name => $value) {
            $method_name = 'validate_' . $name;

            if (!method_exists($this, $method_name)) {
                $this->throw_error('Invalid setting "' . $name . '" provided.');
                $invalid_settings[] = $name;
                continue;
            }

            $valid_value = $this->$method_name($value);
            if ($valid_value !== null) {
                $this->{$name} = $valid_value;
            } else {
                $invalid_settings[] = $name;
            }
        }

        return empty($invalid_settings);
    }

    /**
     * Validate the days setting.
     * 
     * @param int|string $days The number of days after which an order should be updated.
     * @return int|null The number of days after which an order should be updated, or null if the setting is invalid.
     */
    protected function validate_days($days)
    {
        // Force the days to be an integer.
        if (!is_numeric($days)) {
            $this->throw_error('The days must be numeric.');
            return null;
        }

        $days = intval($days);
        if ($days < 1) {
            $this->clear_events();
            return null;
        }

        return $days;
    }

    /**
     * Validate the target statuses setting.
     * 
     * @param array|string $target_statuses The order statuses that should be updated.
     * @return array|null The order statuses that should be updated, or null if the setting is invalid.
     */
    protected function validate_target_statuses($target_statuses)
    {
        // Force the target statuses to be an array.
        $target_statuses = is_array($target_statuses)
            ? $target_statuses
            : [$target_statuses];

        // Trigger a WordPress error if the target statuses are not strings.    
        foreach ($target_statuses as $status) {
            if (!is_string($status)) {
                $this->throw_error('The target statuses must be strings.');
                return null;
            }
        }
        if ($this->statuses_conflict($this->new_status, $target_statuses)) {
            return null;
        }
        return $target_statuses;
    }

    /**
     * Validate the new status setting.
     * 
     * @param string $new_status The status to update the order to.
     * @return string|null The status to update the order to, or null if the setting is invalid.
     */
    protected function validate_new_status($new_status)
    {
        // Trigger a WordPress error if the target statuses are not strings.
        if (!is_string($new_status)) {
            $this->throw_error('The new status must be a string.');
            return null;
        }
        if ($this->statuses_conflict($new_status, $this->target_statuses)) {
            return null;
        }

        return $new_status;
    }

    /**
     * Check that the new status is not in the target statuses.
     * 
     * @param string $new_status The new status to check.
     * @param array $target_statuses The target statuses to check against.
     * @return bool True if the new status is in the target statuses, false otherwise.
     */
    private function statuses_conflict($new_status, $target_statuses)
    {
        // Trigger a WordPress error if the new status is in the target statuses.
        if (in_array($new_status, $target_statuses)) {
            $this->throw_error('The new status cannot be one of the target statuses.');
            return true;
        }
        return false;
    }

    /**
     * Validate the limit setting.
     * 
     * @param int $limit The number of orders to update per event.
     * @return int|null The number of orders to update per event, or null if the setting is invalid.
     */
    protected function validate_limit($limit)
    {
        // Force the limit to be an integer per the documentation for wc_get_orders().
        if (!is_numeric($limit)) {
            $this->throw_error('The limit must be an integer.');
            return null;
        }
        $limit = intval($limit);
        if ($limit < -1) {
            $this->throw_error('The limit must be greater than or equal to -1.');
            return null;
        }
        return $limit;
    }

    /**
     * Validate the frequency setting.
     * 
     * @param string $frequency The frequency with which to run the event.
     * @return string|null The frequency with which to run the event, or null if the setting is invalid.
     */
    protected function validate_frequency($frequency)
    {
        // Ensure the frequency is a valid frequency.
        $frequencies = array_keys(wp_get_schedules());
        if (!in_array($frequency, $frequencies)) {
            $this->throw_error('The frequency must be one of the following: ' . implode(', ', $frequencies));
            return null;
        }
        return $frequency;
    }

    /**
     * Validate the start setting.
     * 
     * @param int|string $start The time to start the event. Can be a Unix timestamp or a string that can be parsed by strtotime().
     * @return int|null The time to start the event, or null if the setting is invalid.
     */
    protected function validate_start($start)
    {
        // Force the start to a valid timestamp.
        if (is_numeric($start)) {
            $start = intval($start);
        } else if (is_string($start)) {
            $start = strtotime($start);
        }

        if (!$start || $start < 0) {
            $this->throw_error('The start must be a valid timestamp or a string that can be parsed by strtotime().');
            return null;
        }
        return $start;
    }

    /**
     * Trigger a WordPress error.
     * 
     * @param string $message The message to include in the error.
     * @param bool $clear_events Whether or not to clear the scheduled event.
     */
    protected function throw_error($message, $clear_events = true)
    {
        if ($this->hide_errors) {
            return;
        }

        $message = __CLASS__ . ': ' . $message;
        if ($clear_events) {
            $this->clear_events();
            $message .= ' The scheduled event "' . self::EVENT_HOOK . '" has been cleared.';
        }

        if (WP_DEBUG_LOG) {
            error_log($message);
        }
        switch (wp_get_environment_type()) {
            case 'local':
            case 'development':
            case 'staging':
                trigger_error($message);
                break;
            case 'production':
            default:
                break;
        }
    }

    /**
     * Trigger an exception.
     * 
     * @param string $message The message to include in the exception.
     * @param string $exception_class The class of the exception to throw.
     */
    protected function throw_exception($message, $exception_class = 'Exception')
    {
        if ($this->block_exceptions) {
            return;
        }

        $message = __CLASS__ . ': ' . $message;
        switch (wp_get_environment_type()) {
            case 'local':
            case 'development':
                throw new $exception_class($message);
                break;
        }
    }
}
