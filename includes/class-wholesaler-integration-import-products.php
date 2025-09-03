<?php


defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

if ( file_exists( WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php' ) ) {
    require_once WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php';
}

// Require services and helpers
require_once __DIR__ . '/services/class-js-wholesaler-service.php';
require_once __DIR__ . '/services/class-mada-wholesaler-service.php';
require_once __DIR__ . '/services/class-aren-wholesaler-service.php';
require_once __DIR__ . '/traits/logs.php';
require_once __DIR__ . '/helpers/class-import-helpers.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class Wholesaler_Integration_Import_Products {

    use Wholesaler_Logs_Trait;

    private $client;
    private $table_name;
    private $product_type = 'variable'; // enum: simple, variable
    private $js_service;
    private $mada_service;
    private $aren_service;
    private $statuses;
    private $helpers;

    public function __construct( string $website_url, string $consumer_key, string $consumer_secret ) {

        // register rest api
        $this->rest_api();

        // Set up the API client with WooCommerce store URL and credentials
        $this->client = new Client(
            $website_url,
            $consumer_key,
            $consumer_secret,
            [
                'verify_ssl' => false,
                'wp_api'     => true,
                'version'    => 'wc/v3',
                'timeout'    => 400,
            ]
        );


        // Init services/helpers
        $this->js_service   = new Wholesaler_JS_Wholesaler_Service();
        $this->mada_service = new Wholesaler_MADA_Wholesaler_Service();
        $this->aren_service = new Wholesaler_AREN_Wholesaler_Service();
        $this->helpers      = new Wholesaler_Import_Helpers();
    }

    /**
     * Create public GET /products endpoint with limit parameter
     */
    public function rest_api() {
        add_action( 'rest_api_init', function () {
            register_rest_route( 'wholesaler/v1', '/import-products', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_rest_api_request' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'limit' => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($param) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                ],
            ] );

            // endpoint for delete product
            register_rest_route( 'wholesaler/v1', '/delete-products', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'handle_delete_products_rest_api_request' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'limit' => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($param) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                ],
            ] );
        } );
    }

    /**
     * Handle delete products request
     */
    public function handle_delete_products_rest_api_request( WP_REST_Request $request ) {
        global $wpdb;

        $limit = $request->get_param( 'limit' );

        // Fetch product IDs (including trashed ones)
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'product' 
             ORDER BY ID ASC 
             LIMIT %d",
                $limit
            )
        );

        if ( empty( $product_ids ) ) {
            return rest_ensure_response( [
                'success' => true,
                'message' => 'No products found to delete.',
            ] );
        }

        $deleted = [];
        $errors  = [];

        foreach ( $product_ids as $product_id ) {
            $result = wp_delete_post( $product_id, true ); // true = force delete (remove from trash too)

            if ( $result ) {
                $deleted[] = $product_id;
            } else {
                $errors[] = $product_id;
            }
        }

        return rest_ensure_response( [
            'success'         => true,
            'requested_limit' => $limit,
            'deleted_count'   => count( $deleted ),
            'deleted_ids'     => $deleted,
            'failed_ids'      => $errors,
        ] );
    }


    /**
     * Handle REST API request
     */
    public function handle_rest_api_request( $request ) {

        $limit = $request->get_param( 'limit' );

        try {
            $result = $this->import_products_to_woocommerce( $limit );
            return new \WP_REST_Response( $result, 200 );
        } catch (Exception $e) {
            put_program_logs( "REST API error: " . $e->getMessage() );
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500 );
        }
    }

    /**
     * Fetch products from database where status is pending
     */
    public function get_products_from_db( $limit = 1 ) {
        try {
            global $wpdb;

            // Define products table
            $products_table   = $wpdb->prefix . 'sync_wholesaler_products_data';
            $this->table_name = $products_table;

            // SQL query
            $sql = $wpdb->prepare( "SELECT * FROM {$products_table} WHERE status = %s LIMIT %d", Status_Enum::PENDING->value, $limit );

            // Retrieve pending products from the database
            $products = $wpdb->get_results( $sql );

            if ( empty( $products ) ) {
                put_program_logs( "No pending products found in database" );
                return [];
            }

            return $products;

        } catch (Exception $e) {
            put_program_logs( "Database error: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Import products to WooCommerce using REST API
     */
    public function import_products_to_woocommerce() {
        try {

            // get products import limit
            $limit = get_option( 'wholesaler_product_update_limit', 1 );

            // Get products from database
            $products = $this->get_products_from_db( $limit );

            if ( empty( $products ) ) {
                return [
                    'success'  => true,
                    'message'  => 'No pending products to import',
                    'imported' => 0,
                ];
            }

            $imported_count = 0;
            $errors         = [];

            foreach ( $products as $product ) {
                try {

                    // Import single product
                    $result = $this->import_single_product( $product );

                    if ( $result['success'] ) {
                        $imported_count++;
                        $this->mark_as_complete( $this->table_name, (int) $product->id );
                    } else {
                        $errors[] = "Product ID {$product->id}: " . $result['message'];
                        put_program_logs( "Failed to import product ID {$product->id}: " . $result['message'] );
                    }

                } catch (Exception $e) {
                    $error_msg = "Product ID {$product->id}: " . $e->getMessage();
                    $errors[]  = $error_msg;
                    put_program_logs( $error_msg );
                }
            }

            return [
                'success'  => true,
                'message'  => "Import completed. Imported: {$imported_count}",
                'imported' => $imported_count,
                'errors'   => $errors,
            ];

        } catch (Exception $e) {
            put_program_logs( "Import process failed: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Import a single product to WooCommerce
     */
    private function import_single_product( $product ) {
        try {
            // Retrieve product data
            $wholesaler_name = $product->wholesaler_name;

            // map product data based on the wholesaler.
            $mapped_product = [];
            $mapped_product = $this->map_product_data( $wholesaler_name, $product );

            // Check if product already exists
            $existing_product_id = $this->check_product_exists( $mapped_product['sku'] );

            if ( $existing_product_id ) {
                return $this->update_existing_product( $existing_product_id, $mapped_product );
            } else {
                return $this->create_new_product( $mapped_product );
            }

        } catch (Exception $e) {
            put_program_logs( "Error importing product {$product->id}: " . $e->getMessage() );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function map_product_data( string $wholesaler_name, $product ) {

        // define default mapped product
        $mapped_product = [];

        // upper case wholesaler name
        $wholesaler_name = strtoupper( $wholesaler_name );

        switch ($wholesaler_name) {
            case 'JS':
                echo 'Wholesaler: JS';
                $mapped_product = $this->map_js_product_data( $product );
                break;
            case 'MADA':
                echo 'Wholesaler: Mada';
                $mapped_product = $this->map_mada_product_data( $product );
                break;
            case 'AREN':
                echo 'Wholesaler: Aren';
                $mapped_product = $this->map_aren_product_data( $product );
                break;
            default:
                $mapped_product = $product;
                break;
        }

        return $mapped_product;
    }

    /**
     * Check if product already exists in WooCommerce
     */
    private function check_product_exists( $sku ) {
        return $this->helpers->check_product_exists( $sku );
    }

    /**
     * Update existing product in WooCommerce
     */
    private function update_existing_product( int $existing_product_id, array $product ) {
        try {
            // put_program_logs( "Updating existing product ID: {$existing_product_id}" );

            // --- Update main product ---
            $product_data = [
                'name'          => $product['name'],
                'description'   => $product['description'],
                'regular_price' => $product['regular_price'] ?? '',
                'images'        => $product['images_payload'] ?? [],
                'attributes'    => $product['attributes'] ?? [],
                'type'          => 'variable',
            ];

            // Update product via API
            $this->client->put( "products/{$existing_product_id}", $product_data );

            // Update wholesaler price (custom field)
            update_post_meta( $existing_product_id, '_wholesaler_price', $product['wholesale_price'] );

            // --- Handle variations ---
            if ( !empty( $product['variations'] ) ) {
                foreach ( $product['variations'] as $variation ) {
                    $existing_variation_id = $this->helpers->get_variation_id_by_sku( $variation['sku'] );

                    if ( $existing_variation_id && $this->helpers->variation_belongs_to_product( (int) $existing_variation_id, (int) $existing_product_id ) ) {
                        try {
                            $this->client->put( "products/{$existing_product_id}/variations/{$existing_variation_id}", $variation );
                        } catch (HttpClientException $e) {
                            $this->client->post( "products/{$existing_product_id}/variations", $variation );
                        }
                    } else {
                        $this->client->post( "products/{$existing_product_id}/variations", $variation );
                    }
                }
            }

            return [
                'success'    => true,
                'message'    => 'Product updated successfully',
                'product_id' => $existing_product_id,
            ];

        } catch (Exception $e) {
            put_program_logs( "Error updating product {$existing_product_id}: " . $e->getMessage() );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create new product in WooCommerce
     */
    private function create_new_product( array $product ) {
        try {

            $product_data = [
                'name'        => $product['name'],
                'sku'         => $product['sku'],
                'type'        => 'variable', // TODO: need check is simple or variable product
                'description' => $product['description'],
                'attributes'  => $product['attributes'] ?? [],
                'categories'  => $product['category_terms'],
            ];
            if ( !empty( $product['images_payload'] ) ) {
                $product_data['images'] = $product['images_payload'];
            }

            // Create the product via API
            $wc_product      = $this->client->post( 'products', $product_data );
            $product_id      = $wc_product->id;
            $wholesale_price = $product['variations'][0]['wholesale_price'] ?? $product['wholesale_price'];

            // Set product information
            wp_set_object_terms( $product_id, $this->product_type, 'product_type' );
            update_post_meta( $product_id, '_visibility', 'visible' );

            // Set product wholesaler price
            update_post_meta( $product_id, '_wholesaler_price', $wholesale_price );

            // Update product category and tags
            $this->update_product_taxonomies( $product_id, $product );

            // Create variations if provided
            if ( !empty( $product['variations'] ) ) {
                foreach ( $product['variations'] as $variation ) {
                    try {
                        $this->client->post( 'products/' . $product_id . '/variations', $variation );
                    } catch (HttpClientException $e) {
                        // put_program_logs( 'WooCommerce API error creating variation for product ' . $product_id . ': ' . $e->getMessage() );
                        continue;
                    }
                }
            }

            return [
                'success'    => true,
                'message'    => 'Product created successfully',
                'product_id' => $product_id,
            ];

        } catch (Exception $e) {
            put_program_logs( "Error creating product: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Update product taxonomies (category and tags)
     */
    private function update_product_taxonomies( $product_id, $product ) {
        return $this->helpers->update_product_taxonomies( (int) $product_id, (array) $product );
    }

    /**
     * Mark product as complete in database
     */
    public function mark_as_complete( string $table_name, int $serial_id ) {
        return $this->helpers->mark_as_complete( $table_name, $serial_id );
    }

    private function map_js_product_data( $product_obj ) {
        return $this->js_service->map( $product_obj );
    }

    private function map_mada_product_data( $product_obj ) {
        return $this->mada_service->map( $product_obj );
    }

    private function map_aren_product_data( $product_obj ) {
        return $this->aren_service->map( $product_obj );
    }

}


$website_url     = site_url();
$consumer_key    = get_option( 'wholesaler_consumer_key', '' );
$consumer_secret = get_option( 'wholesaler_consumer_secret', '' );
new Wholesaler_Integration_Import_Products( $website_url, $consumer_key, $consumer_secret );