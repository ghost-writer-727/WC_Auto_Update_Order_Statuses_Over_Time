# WC Auto Update Order Statuses Over Time

## Description

This PHP class is designed to automatically update WooCommerce order statuses that are over a certain number of days old. By default, it cancels pending orders after 90 days.

## Features

- Automatically updates order statuses based on a set number of days.
- Allows customization of target statuses, new status, and limit.
- Provides error handling and logging.

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
$auto_update = new WC_Auto_Update_Order_Statuses_Over_Time(90, ['pending','awaiting-payment'], 'cancelled', 10);

// Access properties
$days = $auto_update->days;

// Clear scheduled events (if needed)
$auto_update->clear_events();
```

### Constructor Parameters

- `$days`: The number of days after which an order should be updated. Default is 90.
- `$target_statuses`: An array of order statuses that should be updated. Default is `['pending']`.
- `$new_status`: The status to update the order to. Default is `'cancelled'`.
- `$limit`: The number of orders to update per event. Default is `-1` (no limit).

## Special Considerations

### Error Handling and Exception Control

This class has unique features that allow you to control how errors and exceptions are handled:

- `hide_errors`: If set to `true`, WordPress errors will be suppressed.
- `hide_exceptions`: If set to `true`, exceptions will be suppressed.

Even if errors and exceptions are hidden, the class will be invalidated, preventing further actions until the issues are resolved. This is controlled by the `invalidated` property, which will be set to `true` if any invalid settings are detected.

## Contributing

Feel free to fork the project and submit a pull request with your changes!

## License

This project is licensed under the MIT License.
