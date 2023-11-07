<?php

class WC_Auto_Update_Order_Statuses_Over_Time
{
    const DATE_TYPES = [
        'date_modified', 
        'date_created', 
        'date_completed', 
        'date_paid'
    ];

    const UNEDITABLE_PROPERTIES = [
        'slug',
        'event_hook',
        'lock_transient_name',
        'batching_transient_name',
    ];

    /**
     * @var string The slug for the event hook and transients.
     */
    private string $slug;

    /**
     * $var string Unique identifier for scheduled event pertaining to this instance of the class.
     */
    private string $event_hook;

    /**
     * $var string Unique identifier for lock transient pertaining to this instance of the class.
     */
    private string $lock_transient_name;

    /**
     * $var string Unique identifier for batching transient pertaining to this instance of the class.
     */
    private string $batching_transient_name;

    /**
     * @var int The number of days without updates after which an order should be updated.
     * Set in constructor. Can be set directly.
     * Default is 90.
     */
    private int $days;

    /**
     * @var string From what event should we start counting days? 
     * 
     * Set in constructor. Can be set directly.
     * Default is 'date_modified'. See DATE_TYPES for all valid values.
     */
    private string $since;
    

    /**
     * @var array The order statuses that should be updated.
     * Set in constructor. Can be set directly.
     * Default is 'pending'.
     */
    private array $target_statuses;

    /**
     * @var string The status to update the order to.
     * Set in constructor. Can be set directly.
     * Default is 'cancelled'.
     */
    private string $new_status;

    /**
     * @var int The number of orders to update per event.
     * Set in constructor. Can be set directly.
     * Default is -1, which means all orders will be updated.
     */
    private int $limit;

    /**
     * @var string The frequency with which to run the event.
     * Set in constructor. Can be set directly.
     * Default is 'daily'.
     */
    private string $frequency;

    /**
     * @var int The time to start the event.
     * Set in constructor. Can be set directly.
     * Default is time().
     */
    private int $start;

    /**
     * Initialize the class and set hooks.
     * 
     * @param array $args The settings for the class:
     * • @param int $days The number of days after which an order should be updated.
     * • @param string $since The date event to compare the number of days.
     * • @param array $target_statuses The order statuses that should be updated.
     * • @param string $new_status The status to update the order to.
     * • @param int $limit The number of orders to update per event.
     * • @param string $frequency The frequency with which to run the event.
     * • @param int|string $start The time to start the event. Can be a Unix timestamp or a string that can be parsed by strtotime().
     */
    public function __construct(string $slug, array $args = [])
    {
        $this->slug = $slug;
        $this->check_dependencies();
        $this->init($args);

        // Schedule the event if it's not already scheduled.
        if (!wp_next_scheduled($this->event_hook)) {
            wp_schedule_event($this->start, $this->frequency, $this->event_hook);
        }
        
        // Check if transient exists, in case we are already processing an event that was batched out.
        if (get_transient($this->batching_transient_name)) {
            delete_transient($this->batching_transient_name);
            $this->update_orders();

        // Hook into the scheduled event.
        } else {
            add_action($this->event_hook, array($this, 'update_orders'));
        }
    }
    
    protected function check_dependencies(){
        // Check if WooCommerce is active
        $active_plugins = (array) get_option('active_plugins', []);
        if (!in_array('woocommerce/woocommerce.php', $active_plugins)) {
            $this->throw_notice('WooCommerce must to be activated first to use ' . __CLASS__ . '.');
            return;
        }        
    }
    
    /**
     * Initialize the class properties & args.
     */
    protected function init($args){
        $this->event_hook = "wc_auosot_update_orders_{$this->slug}";
        $this->lock_transient_name = "{$this->event_hook}_lock";
        $this->batching_transient_name = "{$this->event_hook}_batch";

        $this->days = 90;
        $this->since = self::DATE_TYPES[0];
        $this->target_statuses = ['pending'];
        $this->new_status = 'cancelled';
        $this->limit = 25;
        $this->frequency = 'daily';
        $this->start = time();

        // Validate the settings and override default property values.
        if ( ! $this->validate_settings($args) ) {
            $this->throw_exception('Invalid settings provided. Check the WordPress error log for details.', 'InvalidArgumentException');    
            return;
        }
    }

    /**
     * Delay starting the process until after all other processes have run.
     * Because something else was interfering with the Data Store object, 
     * modifying our query causing it to return all orders regardless of 
     * status as well as update statuses without the wc- prefix on batch 
     * runs triggered via batch transient.
     */
    public function update_orders()
    {
        // Burying this near the end of order of operations.
        add_action( 'shutdown', [$this, 'really_update_orders']);
    }
    
    /**
     * Update orders with target statuses that are over a certain number of days old.
     */
    public function really_update_orders(){
        if (!get_transient($this->lock_transient_name)) {
            set_transient($this->lock_transient_name, true, (int) ini_get('max_execution_time') ?: 180);
            try {
                // Get the current date and time.
                $current_date = new DateTime();

                // Calculate the date $this->days days ago.
                $days_ago = $current_date->modify("-{$this->days} days")->format('Y-m-d 00:00:00');

                // Query for orders with target statuses.
                $args = [
                    'status' => $this->target_statuses,
                    'limit' => $this->limit,
                    $this->since => '<=' . $days_ago,
                    'type'  => 'shop_order', // Excludes subscriptions, refunds, etc. Still returns orders that have been refunded or contain subscription, etc.
                ];
                $orders = wc_get_orders($args);

                // Loop through each order and update its status.
                foreach ($orders as $order) {
                    $previous_status = $order->get_status();
                    
                    /** 
                     * Allow short circuit.
                     * 
                     * @param bool Whether or not to interrupt the order status update.
                     * @param WC_Order $order The order that was updated.
                     * @param string $previous_status The previous status of the order.
                     * @param string $new_status The new status of the order.
                     * @param int $days The minimum number of days since the order was previously updated. This represents the settings at the time this was triggered... not the actual number of days since the order was previously updated.
                     */
                    if( apply_filters("wc_auosot_interrupt_{$this->slug}", false, $order, $previous_status, $this->new_status, $this->days) ){
                        // $this->throw_notice("wc_auosot_interrupt_{$this->slug} filter interrupted order {$order->get_id()} status update from {$previous_status} to {$this->new_status}.");
                        continue;
                    }
                    
                    $order->update_status($this->new_status, "Order status updated from {$previous_status} to {$this->new_status} due to {$this->days} days since {$this->since}. ({$this->slug})");
                    
                    /** 
                     * Trigger an action after the order status is updated.
                     * 
                     * @param WC_Order $order The order that was updated.
                     * @param string $previous_status The previous status of the order.
                     * @param string $new_status The new status of the order.
                     * @param int $days The minimum number of days since the order was previously updated. This represents the settings at the time this was triggered... not the actual number of days since the order was previously updated.
                     */
                    do_action("wc_auosot_updated_{$this->slug}", $order, $previous_status, $this->new_status, $this->days);
                }
                
                // If we hit the limit and there may be more orders left, so run again.
                if ($this->limit > 0 && count($orders) >= $this->limit ) {
                    // Make sure the next batch doesn't overlap with any scheduled events.
                    $next_event_timestamp = wp_next_scheduled($this->event_hook);
                    $expiration = $next_event_timestamp - time();
                    
                    // Make sure we don't conflict with next crons.
                    if( $expiration > 90){
                        // Set a transient to trigger the next batch.
                        set_transient($this->batching_transient_name, true, $expiration - 90 );
                    }
                }

                delete_transient($this->lock_transient_name);
                wp_cache_flush();
            } catch (Exception $e) {
                $this->clear_events();
                $this->throw_exception($e->getMessage(), get_class($e));
                return null;
            }
        }
    }

    /**
     * Clear the scheduled event.
     */
    public function clear_events()
    {
        delete_transient($this->lock_transient_name);
        delete_transient($this->batching_transient_name);
        wp_clear_scheduled_hook($this->event_hook);
    }

    /**
     * Update the scheduled event.
     */
    public function update_events()
    {
        $this->clear_events();
        wp_schedule_event($this->start, $this->frequency, $this->event_hook);
    }

    /**
     * Get private properties.
     * 
     * @param string $name The name of the property to get.
     * @return mixed The value of the property, or null if it doesn't exist.
     */
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }
    
        $constName = get_class($this) . '::' . $name;
        if (defined($constName)) {
            return constant($constName);
        }
    
        $this->throw_exception("Property \"{$name}\" does not exist.", 'InvalidArgumentException');
        
        return null;
    }

    /**
     * Set private properties.
     * 
     * @param string $name The name of the property to set.
     * @param mixed $value The value to set the property to.
     */
    public function __set($name, $value)
    {
        if (property_exists($this, $name)) {
            if( in_array($name, self::UNEDITABLE_PROPERTIES) ){
                $this->throw_exception("Property \"{$name}\" cannot be set directly.");
            }

            if ($this->validate_settings([$name => $value])) {

                if (in_array($name, ['start', 'frequency'])) {
                    $this->update_events();
                }

                return $this->{$name};
            }
                
            $this->throw_exception(
                "Invalid value \"{$value}\" provided for property \"{$name}\".",
                'InvalidArgumentException'
            );
        }

        $this->throw_exception("Property \"{$name}\" does not exist.", 'InvalidArgumentException');
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
                $this->throw_notice('Invalid setting "' . $name . '" provided.');
                $invalid_settings[] = $name;
                continue;
            }

            // Process the value through the validation method.
            $maybe_validated_value = $this->$method_name($value);

            // Ensure that we have returned a proper type.
            // Will be NULL type if the setting is invalid.
            if ( gettype($maybe_validated_value) === gettype($this->{$name}) ) {
                // Set the property
                $this->{$name} = $maybe_validated_value;
            } else {
                // The setting is invalid.
                $invalid_settings[] = $name;
            }
        }

        // If there are no invalid settings, return true.
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
            $this->throw_notice('The days must be an integer.');
            return null;
        }

        $days = intval($days);

        if ($days < 1) {
            $this->throw_notice('The days must be greater than or equal to 1.');
            $days = null;
        }

        return $days;
    }

    /**
     * Validate the trigger type setting.
     * 
     * @param string $since The event that should trigger the countdown.
     * @return string|null The event that should trigger the countdown, or null if the setting is invalid.
     */
    protected function validate_since($since)
    {
        // Trigger a WordPress error if the trigger type is not a string.
        if (!is_string($since)) {
            $this->throw_notice('The trigger type must be a string.');
            return null;
        }

        // Trigger a WordPress error if the trigger type is not a valid trigger type.
        if (!in_array($since, self::DATE_TYPES)) {
            $this->throw_notice('The trigger type must be one of the following: ' . implode(', ', self::DATE_TYPES));
            return null;
        }

        return $since;
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
        foreach ($target_statuses as &$status) {
            if (!is_string($status)) {
                $this->throw_notice('The target statuses must be strings.');
                return null;
            }
            $status = $this->normalize_wc_prefix($status);
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
            $this->throw_notice('The new status must be a string.');
            return null;
        }

        $new_status = $this->normalize_wc_prefix($new_status);

        if ($this->statuses_conflict($new_status, $this->target_statuses)) {
            return null;
        }

        return $new_status;
    }

    /**
     * Remove wc- prefix from a status.
     * 
     * @param string $status The status
     * @return string The status without the prefix
     */
    protected function normalize_wc_prefix($status){
        if( substr($status, 0, 3) === 'wc-' ){
            $status = substr($status, 3);
        }
        return $status;
    }

    /**
     * Check that the new status is not in the target statuses.
     * 
     * @param string $new_status The new status to check.
     * @param array $target_statuses The target statuses to check against.
     * @return bool True if the new status is in the target statuses, false otherwise.
     */
    protected function statuses_conflict($new_status, $target_statuses)
    {
        // Trigger a WordPress error if the new status is in the target statuses.
        if (in_array($new_status, $target_statuses)) {
            $this->throw_notice('The new status cannot be one of the target statuses.');
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
            $this->throw_notice('The limit must be an integer.');
            return null;
        }
        $limit = intval($limit);
        if (!$limit || $limit < -1 ) {
            $this->throw_notice('The limit must be -1 (no limit) or greater than 1.');
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
            $this->throw_notice('The frequency must be one of the following: ' . implode(', ', $frequencies));
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
            $this->throw_notice('The start must be a valid timestamp or a string that can be parsed by strtotime().');
            return null;
        }
        return $start;
    }

    /**
     * Trigger a WordPress error.
     * 
     * @param string $message The message to include in the error.
     * @param string $error_class The class of the error to trigger.
     * @throws E_USER_NOTICE
     */
    protected function throw_notice($message)
    {
        $message = __CLASS__ . ': ' . $message;
        error_log($message);

        $notifiable_environments = apply_filters('wc_auosot_throw_notice_environments', ['local', 'development', 'staging']);
        if( in_array( wp_get_environment_type(), $notifiable_environments ) ){
            trigger_error($message);
        }
    }

    /**
     * Trigger an exception.
     * 
     * @param string $message The message to include in the exception.
     * @param string $exception_class The class of the exception to throw.
     * @throws Exception
     */
    protected function throw_exception($message, $exception_class = 'Exception')
    {
        $message = __CLASS__ . ': ' . $message;
        throw new $exception_class($message);
    }
}
