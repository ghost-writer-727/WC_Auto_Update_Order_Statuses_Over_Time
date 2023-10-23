# WC Auto Update Order Statuses Over Time

## Description

This PHP class is designed to automatically update WooCommerce order statuses that have gone a certain number of days since a WooCommerce timestamped event. By default, it cancels pending orders after 90 days.

## Features

- Automatically updates order statuses based on a set number of days since select WooCommerce events.
- Allows customization of target statuses, new status, and limit.
- Provides error handling and logging.
- Provides *wc_auto_update_order_statuses_over_time_{$slug}* action hook at time of update for third-party integration.

## Requirements

- PHP 7.4 or higher
- WordPress
- WooCommerce

## Installation

1. Download the PHP file.
2. Place it in your WordPress theme or plugin directory.
3. Include it in your `functions.php` or main plugin file.

```php
include_once('path/to/class-WC_Auto_Update_Order_Statuses_Over_Time.php');
```

## Usage

Here's a basic example of how to use the class:

```php
// Initialize the class with custom settings
$auto_update = new WC_Auto_Update_Order_Statuses_Over_Time('my_plugin', [
    'target_statuses' => ['pending','awaiting-payment'],
    'start' => 'midnight +1 day', // Tonight at midnight
    'limit' => 10
] );

// Access properties
$days = $auto_update->days;

// Set properties after the fact
$auto_update->days = 45;

// Execute the update_orders method immediately without waiting for the cron job
$auto_update->update_orders(); 

// Clear scheduled events (useful at plugin deactivation if used within a plugin)
$auto_update->clear_events();
```

### Constructor Parameters

The constructor accepts a string `$slug` to allow independent operation of mulitple instances as well as an associative array `$args` with the following optional keys:

- **`days`**: *(int)* The number of days after which an order should be updated. Default is `90`.
- **`since`**: *(string)* The WooCommerce field to compare days. Can be `'date_modified'`, `'date_created'`, `'date_completed'` or `'date_paid'`. Default is `'date_modified'`.
- **`target_statuses`**: *(array)* An array of order statuses that should be updated. Default is `['pending']`.
- **`new_status`**: *(string)* The status to update the order to. Default is `'cancelled'`.
- **`limit`**: *(int)* The number of orders to update per event. Default is `-1` (no limit).
- **`frequency`**: *(string)* The frequency with which to run the event. Default is `'daily'`.
- **`start`**: *(int|string)* The time to start the event. Can be a Unix timestamp or a string that can be parsed by `strtotime()`. Default is the current time.
- **`hide_notices`**: *(bool)* Whether or not to hide notices. Default is `false`.
- **`block_exceptions`**: *(bool)* Whether or not to block exceptions. Default is `false`.

## Special Considerations

### Updating frequency and start

Using the setter to update the frequency and start will result in canceling all previously scheduled events and scheduling a new one. Care should be taken that this isn't performed on every page load else you could cause the event to trigger every page load if start is set to time() or earlier or you could cause it to never load if start is set to some point in the future.

### Error Handling and Exception Control

This class has unique features that allow you to control how errors and exceptions are handled:

- `hide_errors`: If set to `true`, WordPress errors will be suppressed.
- `block_exceptions`: If set to `true`, exceptions will be suppressed.

Even if errors and exceptions are hidden, the class can be invalidated, preventing further actions until the issues are resolved. This is controlled by the `invalidated` property, which will be set to `true` if any invalid settings are detected.

## Contributing

Feel free to fork the project and submit a pull request with your changes!

## License

This project is licensed under the MIT License.
