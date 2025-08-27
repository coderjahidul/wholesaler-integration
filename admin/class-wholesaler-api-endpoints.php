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