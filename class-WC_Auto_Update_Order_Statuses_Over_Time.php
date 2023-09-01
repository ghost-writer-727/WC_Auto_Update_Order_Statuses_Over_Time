<?php

/**
 * Class to automatically update all orders that have a target status and are over a certain number days old.
 * Default is to cancel pending orders after 90 days.
 */
class WC_Auto_Update_Order_Statuses_Over_Time
{
    private $event_hook = 'wc_auto_update_order_statuses_over_time';
    private $days;
    private $target_statuses;
    private $limit;
    private $new_status;
    private $hide_errors;
    private $hide_exceptions;
    private $invalidated;

    /**
     * Initialize the class and set hooks.
     * 
     * @param int $days The number of days after which an order should be updated.
     * @param array $target_statuses The order statuses that should be updated.
     * @param string $new_status The status to update the order to.
     * @param int $limit The number of orders to update per event.
     */
    public function __construct($days = 90, $target_statuses = ['pending'], $new_status = 'cancelled', $limit = -1)
    {
        if (!$this->validate_settings($days, $target_statuses, $new_status, $limit)) {
            $this->throw_exception('Invalid settings provided. Check the WordPress error log for details.', 'InvalidArgumentException');
            return;
        }

        // Schedule the event if it's not already scheduled.
        if (!wp_next_scheduled($this->event_hook)) {
            // Calculate the timestamp for tonight at midnight
            $midnight_timestamp = strtotime('midnight +1 day');
            wp_schedule_event($midnight_timestamp, 'daily', $this->event_hook);
        }

        // Hook into the scheduled event.
        add_action($this->event_hook, array($this, 'update_orders'));
    }

    /**
     * Get private properties.
     * 
     * @param string $name The name of the property to get.
     * @return mixed The value of the property, or null if it doesn't exist.
     */
    public function __get($name)
    {
        if( $this->invalidated ){
            return null;
        }
        switch ($name) {
            case 'days':
            case 'target_statuses':
            case 'new_status':
            case 'limit':
            case 'event_hook':
            case 'hide_errors':
            case 'hide_exceptions':
            case 'invalidated':
                return $this->{$name};
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
        if( $this->invalidated ){
            return null;
        }
        switch ($name) {
            case 'days':
                if ($this->validate_settings($value, $this->target_statuses, $this->new_status, $this->limit)) {
                    return $this->{$name};
                }
                break;
            case 'target_statuses':
                if ($this->validate_settings($this->days, $value, $this->new_status, $this->limit)) {
                    return $this->{$name};
                }
                break;
            case 'new_status':
                if ($this->validate_settings($this->days, $this->target_statuses, $value, $this->limit)) {
                    return $this->{$name};
                }
                break;
            case 'limit':
                if ($this->validate_settings($this->days, $this->target_statuses, $this->new_status, $value)) {
                    return $this->{$name};
                }
                break;
            case 'hide_errors':
            case 'hide_exceptions':
                if (is_bool($value)) {
                    return $this->{$name} = $value;
                }
                $this->throw_error('The hide_errors property must be a boolean.', false);
                break;
            default:
                $this->throw_exception('Invalid property name "' . $name . '" provided.', 'InvalidArgumentException');
                return null;
        }

        $this->throw_exception('Invalid value "' . $value . '" provided for property "' . $name . '".', 'InvalidArgumentException');
        return null;
    }

    /**
     * Clear the scheduled event.
     */
    public function clear_events()
    {
        if( $this->invalidated ){
            return null;
        }
        wp_clear_scheduled_hook($this->event_hook);
    }

    /**
     * Validate all settings
     * 
     * @return bool True if the settings are valid, false otherwise.
     */
    private function validate_settings($days, $target_statuses, $new_status, $limit)
    {

        // Force the days to be an integer.
        if (!is_numeric($days)) {
            $this->throw_error('The days must be numeric.');
            return false;
        }

        $days = intval($days);
        if ($days < 1) {
            $this->clear_events();
            return false;
        }

        // Force the target statuses to be an array.
        $target_statuses = is_array($target_statuses)
            ? $target_statuses
            : [$target_statuses];

        // Trigger a WordPress error if the target statuses are not strings.
        foreach ($target_statuses as $status) {
            if (!is_string($status)) {
                $this->throw_error('The target statuses must be strings.');
                return false;
            }
        }

        // Trigger a WordPress error if the target statuses are not strings.
        if (!is_string($new_status)) {
            $this->throw_error('The new status must be a string.');
            return false;
        }

        // Trigger a WordPress error if the new status is in the target statuses.
        if (in_array($new_status, $target_statuses)) {
            $this->throw_error('The new status cannot be one of the target statuses.');
            return false;
        }

        // Force the limit to be an integer per the documentation for wc_get_orders().
        if (!is_numeric($limit)) {
            $this->throw_error('The limit must be an integer.');
            return false;
        }
        $limit = intval($limit);
        if ($limit < -1) {
            $this->throw_error('The limit must be greater than or equal to -1.');
            return false;
        }

        $this->days = $days;
        $this->target_statuses = $target_statuses;
        $this->new_status = $new_status;
        $this->limit = $limit;

        return true;
    }

    /**
     * Update orders with target statuses that are over a certain number of days old.
     */
    public function update_orders()
    {
        if( $this->invalidated ){
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
            'date_modified' => '<' . $days_ago, // Only select orders modified more than $this->days days ago.
        ));

        // Loop through each order and update its status.
        foreach ($orders as $order) {
            $previous_status = $order->get_status();
            $order->update_status($this->new_status, "Order status updated to {$this->new_status} after being in {$previous_status} status for {$this->days} days.");

            // Trigger an action after the order status is updated.
            do_action('wc_auto_update_order_statuses_over_time', $order, $previous_status, $this->new_status, $this->days);
        }
    }

    /**
     * Trigger a WordPress error.
     * 
     * @param string $message The message to include in the error.
     * @param bool $clear_events Whether or not to clear the scheduled event.
     */
    private function throw_error($message, $clear_events = true)
    {
        if ($this->hide_errors) {
            return;
        }

        $message = __CLASS__ . ': ' . $message;
        if ($clear_events) {
            $this->clear_events();
            $message .= ' The scheduled event "' . $this->event_hook . '" has been cleared.';
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
    private function throw_exception($message, $exception_class = 'Exception')
    {

        if ($this->hide_exceptions) {
            $this->invalidated = true;
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
