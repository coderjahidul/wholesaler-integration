<?php 
add_action('rest_api_init', 'wholesaler_api_endpoints');

function wholesaler_api_endpoints() {
    
    // urls http://localhost/wholesaler/v1/products/js
    register_rest_route('wholesaler/v1', '/products/js', array(
        'methods' => 'GET',
        'callback' => 'wholesaler_fetch_product_js_api',
    ));

    // urls http://localhost/wholesaler/v1/products/mada
    register_rest_route('wholesaler/v1', '/products/mada', array(
        'methods' => 'GET',
        'callback' => 'wholesaler_fetch_product_mada_api',
    ));

    // urls http://localhost/wholesaler/v1/products/aren
    register_rest_route('wholesaler/v1', '/products/aren', array(
        'methods' => 'GET',
        'callback' => 'wholesaler_fetch_product_aren_api',
    ));

    // https://kobiecy-akcent.pl/wp-json/wholesaler/v1/products/truncate?key=MY_SECRET_KEY_123
	register_rest_route('wholesaler/v1', '/products/truncate', array(
		'methods'  => 'POST',
		'callback' => 'wholesaler_truncate_products_table',
		'permission_callback' => '__return_true' // allow external, but we'll check secret key
	));
}

function wholesaler_fetch_product_js_api() {
    return insert_product_js_api_to_database();
}

function wholesaler_fetch_product_mada_api(){
    return insert_product_mada_api_to_database();
}

function wholesaler_fetch_product_aren_api(){
    return insert_product_aren_api_to_database();
}

function wholesaler_truncate_products_table( WP_REST_Request $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'sync_wholesaler_products_data';

    // Check secret key
    $secret_key = 'MY_SECRET_KEY_123'; // change this
    $provided_key = $request->get_param('key');

    if ($provided_key !== $secret_key) {
        return new WP_Error('forbidden', 'Invalid secret key', array('status' => 403));
    }

    $result = $wpdb->query("TRUNCATE TABLE $table");

    if ($result === false) {
        return [
            'success' => false,
            'message' => 'Failed to truncate table.'
        ];
    }

    return [
        'success' => true,
        'message' => 'Table truncated successfully.'
    ];
}