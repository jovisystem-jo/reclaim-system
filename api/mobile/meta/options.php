<?php
require_once __DIR__ . '/../bootstrap.php';

mobileApiRequireMethod(['GET']);
mobileApiAuthenticate($mobileApiDb);

mobileApiSuccess([
    'departments' => mobileApiDepartments(),
    'categories' => mobileApiCategories(),
    'colors' => mobileApiColors(),
    'delivery_locations' => mobileApiDeliveryLocations(),
    'brands_by_category' => mobileApiBrandsByCategory(),
], 'Option lists loaded.');
