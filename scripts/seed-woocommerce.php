<?php

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

$targetProducts = (int) (getenv('SEED_PRODUCTS') ?: 60);
$targetOrders = (int) (getenv('SEED_ORDERS') ?: 120);

$productIds = wc_get_products([
    'limit' => -1,
    'return' => 'ids',
]);

$productCount = is_array($productIds) ? count($productIds) : 0;

for ($i = $productCount; $i < $targetProducts; $i++) {
    $product = new WC_Product_Simple();
    $product->set_name('Sample Product ' . ($i + 1));
    $product->set_regular_price((string) rand(10, 120));
    $product->set_description('Sample product generated for development.');
    $product->set_short_description('Sample product.');
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_stock_status('instock');
    $product->set_sku('sample-' . ($i + 1));
    $product->save();
    $productIds[] = $product->get_id();
}

echo "Products available: " . count($productIds) . "\n";

$orderIds = wc_get_orders([
    'limit' => -1,
    'return' => 'ids',
]);

$orderCount = is_array($orderIds) ? count($orderIds) : 0;

if (empty($productIds)) {
    fwrite(STDERR, "No products found to seed orders.\n");
    exit(1);
}

$statuses = ['processing', 'completed', 'on-hold'];

for ($i = $orderCount; $i < $targetOrders; $i++) {
    $order = wc_create_order();
    $items = rand(1, 3);
    for ($j = 0; $j < $items; $j++) {
        $productId = $productIds[array_rand($productIds)];
        $product = wc_get_product($productId);
        if ($product) {
            $order->add_product($product, rand(1, 3));
        }
    }

    $address = [
        'first_name' => 'Sample',
        'last_name' => 'Customer ' . ($i + 1),
        'email' => 'customer' . ($i + 1) . '@example.com',
        'phone' => '555-0100',
        'address_1' => '100 Market St',
        'city' => 'San Francisco',
        'state' => 'CA',
        'postcode' => '94105',
        'country' => 'US',
    ];

    $order->set_address($address, 'billing');
    $order->set_address($address, 'shipping');
    $order->set_status($statuses[array_rand($statuses)]);
    $order->calculate_totals();
    $order->save();
}

echo "Orders available: " . max($targetOrders, $orderCount) . "\n";
