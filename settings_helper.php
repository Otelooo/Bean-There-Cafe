<?php
function get_system_settings(mysqli $conn): array
{
    $defaults = [
        'cafe_name' => 'Bean There Café',
        'cafe_address' => '',
        'cafe_contact' => '',
        'tax_rate' => '0.12',
        'discount_rate' => '0.20',
        'critical_stock_threshold' => '5',
        'low_stock_threshold' => '10',
        'receipt_footer_message' => 'Thank you for bean here!',
        'ewallet_qr_image' => '',
    ];

    $result = $conn->query('SELECT setting_key, setting_value FROM system_settings');
    while ($row = $result->fetch_assoc()) {
        $defaults[$row['setting_key']] = $row['setting_value'];
    }

    return $defaults;
}
