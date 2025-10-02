<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

if ( file_exists( WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php' ) ) {
    require_once WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php';
}

require_once __DIR__ . '/services/class-js-wholesaler-service.php';
require_once __DIR__ . '/services/class-mada-wholesaler-service.php';
require_once __DIR__ . '/services/class-aren-wholesaler-service.php';
require_once __DIR__ . '/traits/logs.php';
require_once __DIR__ . '/helpers/class-import-helpers.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

/**
 * High-performance batch import class for WooCommerce products
 * Implements batch API calls, bulk database operations, and performance optimizations
 */
class Wholesaler_Batch_Import {

    use Wholesaler_Logs_Trait;

    private $client;
    private $table_name;
    private $js_service;
    private $mada_service;
    private $aren_service;
    private $helpers;
    private $batch_size = 100; // WooCommerce batch API limit
    private $db_batch_size = 500; // Database bulk operation size
    private $performance_mode = false;

    public function __construct( string $website_url, string $consumer_key, string $consumer_secret ) {
        
        // Set up the API client with optimized settings
        $this->client = new Client(
            $website_url,
            $consumer_key,
            $consumer_secret,
            [
                'verify_ssl' => false,
                'wp_api'     => true,
                'version'    => 'wc/v3',
                'timeout'    => 300, // Increased timeout for batch operations
            ]
        );

        // Init services/helpers
        $this->js_service   = new Wholesaler_JS_Wholesaler_Service();
        $this->mada_service = new Wholesaler_MADA_Wholesaler_Service();
        $this->aren_service = new Wholesaler_AREN_Wholesaler_Service();
        $this->helpers      = new Wholesaler_Import_Helpers();

        // Register REST API endpoints
        $this->register_batch_endpoints();
    }

    /**
     * Register optimized batch import endpoints
     */
    public function register_batch_endpoints() {
        add_action( 'rest_api_init', function () {
            // High-performance batch import endpoint
            register_rest_route( 'wholesaler/v1', '/batch-import', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_batch_import' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'batch_size' => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($param) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                    'performance_mode' => [
                        'default'           => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ] );

            // Background processing endpoint
            register_rest_route( 'wholesaler/v1', '/background-import', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_background_import' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'total_batches' => [
                        'default'           => 10,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ] );
        } );
    }

    /**
     * Handle batch import request with performance optimizations
     */
    public function handle_batch_import( WP_REST_Request $request ) {
        $batch_size = $request->get_param( 'batch_size' );
        $this->performance_mode = $request->get_param( 'performance_mode' );

        // Enable performance mode optimizations
        if ( $this->performance_mode ) {
            $this->enable_performance_mode();
        }

        try {
            $result = $this->batch_import_products( $batch_size );
            
            if ( $this->performance_mode ) {
                $this->disable_performance_mode();
            }

            return new WP_REST_Response( $result, 200 );
        } catch (Exception $e) {
            if ( $this->performance_mode ) {
                $this->disable_performance_mode();
            }
            
            $this->log_message( "Batch import error: " . $e->getMessage() );
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Batch import failed: ' . $e->getMessage()
            ], 500 );
        }
    }

    /**
     * Enable performance mode - disable hooks and caching
     */
    private function enable_performance_mode() {
        // Disable cache purging during import
        add_filter( 'rocket_is_importing', '__return_true' );
        
        // Disable object cache for products during import
        wp_cache_flush();
        
        // Disable WooCommerce product sync
        remove_action( 'woocommerce_product_object_updated_props', 'wc_products_force_lookup_table_update' );
        
        // Disable search index updates
        add_filter( 'woocommerce_product_search_index_enabled', '__return_false' );
        
        // Increase memory limit if possible
        if ( function_exists( 'ini_set' ) ) {
            ini_set( 'memory_limit', '512M' );
        }
        
        // Disable WordPress auto-save and revisions temporarily
        remove_action( 'pre_post_update', 'wp_save_post_revision' );
        
        $this->log_message( "Performance mode enabled" );
    }

    /**
     * Disable performance mode - re-enable hooks and caching
     */
    private function disable_performance_mode() {
        // Re-enable cache purging
        remove_filter( 'rocket_is_importing', '__return_true' );
        
        // Re-enable WooCommerce product sync
        add_action( 'woocommerce_product_object_updated_props', 'wc_products_force_lookup_table_update' );
        
        // Re-enable search index updates
        remove_filter( 'woocommerce_product_search_index_enabled', '__return_false' );
        
        // Re-enable WordPress auto-save and revisions
        add_action( 'pre_post_update', 'wp_save_post_revision' );
        
        // Clear cache after import
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        
        $this->log_message( "Performance mode disabled" );
    }

    /**
     * High-performance batch import using WooCommerce batch API
     */
    public function batch_import_products( $batch_size = 50 ) {
        try {
            // Get products from database in batches
            $products = $this->get_products_from_db( $batch_size );

            if ( empty( $products ) ) {
                return [
                    'success'  => true,
                    'message'  => 'No pending products to import',
                    'imported' => 0,
                ];
            }

            // Pre-load existing products for faster lookups
            $existing_products = $this->bulk_check_existing_products( $products );
            
            // Separate products into create and update batches
            $create_batch = [];
            $update_batch = [];
            $product_ids_to_update = [];

            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                $existing_id = $existing_products[ $mapped_product['sku'] ] ?? null;

                if ( $existing_id ) {
                    $update_batch[] = [
                        'id' => $existing_id,
                        'data' => $this->prepare_update_data( $mapped_product ),
                        'original' => $product
                    ];
                    $product_ids_to_update[] = $product->id;
                } else {
                    $create_batch[] = [
                        'data' => $this->prepare_create_data( $mapped_product ),
                        'original' => $product
                    ];
                }
            }

            $results = [
                'created' => 0,
                'updated' => 0,
                'errors' => []
            ];

            // Process create batch
            if ( !empty( $create_batch ) ) {
                $create_result = $this->batch_create_products( $create_batch );
                $results['created'] = $create_result['success_count'];
                $results['errors'] = array_merge( $results['errors'], $create_result['errors'] );
            }

            // Process update batch
            if ( !empty( $update_batch ) ) {
                $update_result = $this->batch_update_products( $update_batch );
                $results['updated'] = $update_result['success_count'];
                $results['errors'] = array_merge( $results['errors'], $update_result['errors'] );
            }

            // Bulk update database status
            $this->bulk_mark_as_complete( array_column( $products, 'id' ) );

            return [
                'success' => true,
                'message' => sprintf( 
                    'Batch import completed. Created: %d, Updated: %d', 
                    $results['created'], 
                    $results['updated'] 
                ),
                'created' => $results['created'],
                'updated' => $results['updated'],
                'errors' => $results['errors'],
                'total_processed' => count( $products )
            ];

        } catch (Exception $e) {
            $this->log_message( "Batch import process failed: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Bulk check existing products to reduce database queries
     */
    private function bulk_check_existing_products( $products ) {
        global $wpdb;
        
        $skus = array_map( function( $product ) {
            $mapped = $this->map_product_data( $product->wholesaler_name, $product );
            return $mapped['sku'];
        }, $products );

        if ( empty( $skus ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
        
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as sku, p.ID as product_id 
             FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' 
             AND pm.meta_value IN ($placeholders) 
             AND p.post_type = 'product'",
            ...$skus
        );

        $results = $wpdb->get_results( $query );
        
        $existing_products = [];
        foreach ( $results as $result ) {
            $existing_products[ $result->sku ] = (int) $result->product_id;
        }

        return $existing_products;
    }

    /**
     * Batch create products using WooCommerce batch API
     */
    private function batch_create_products( $create_batch ) {
        $batch_data = [
            'create' => array_column( $create_batch, 'data' )
        ];

        $success_count = 0;
        $errors = [];

        try {
            $response = $this->client->post( 'products/batch', $batch_data );
            
            if ( isset( $response->create ) ) {
                foreach ( $response->create as $index => $result ) {
                    if ( isset( $result->id ) ) {
                        $success_count++;
                        
                        // Handle variations for created products
                        $original_product = $create_batch[$index]['original'];
                        $mapped_product = $this->map_product_data( $original_product->wholesaler_name, $original_product );
                        
                        if ( !empty( $mapped_product['variations'] ) ) {
                            $this->batch_create_variations( $result->id, $mapped_product['variations'] );
                        }
                        
                        // Update taxonomies
                        $this->helpers->update_product_taxonomies( $result->id, $mapped_product );
                        
                    } else {
                        $errors[] = "Failed to create product: " . ( $result->error->message ?? 'Unknown error' );
                    }
                }
            }

        } catch ( HttpClientException $e ) {
            $errors[] = "Batch create API error: " . $e->getMessage();
        }

        return [
            'success_count' => $success_count,
            'errors' => $errors
        ];
    }

    /**
     * Batch update products using WooCommerce batch API
     */
    private function batch_update_products( $update_batch ) {
        $batch_data = [
            'update' => array_map( function( $item ) {
                return array_merge( ['id' => $item['id']], $item['data'] );
            }, $update_batch )
        ];

        $success_count = 0;
        $errors = [];

        try {
            $response = $this->client->post( 'products/batch', $batch_data );
            
            if ( isset( $response->update ) ) {
                foreach ( $response->update as $index => $result ) {
                    if ( isset( $result->id ) ) {
                        $success_count++;
                        
                        // Handle variations for updated products
                        $original_product = $update_batch[$index]['original'];
                        $mapped_product = $this->map_product_data( $original_product->wholesaler_name, $original_product );
                        
                        if ( !empty( $mapped_product['variations'] ) ) {
                            $this->batch_update_variations( $result->id, $mapped_product['variations'] );
                        }
                        
                    } else {
                        $errors[] = "Failed to update product ID {$update_batch[$index]['id']}: " . ( $result->error->message ?? 'Unknown error' );
                    }
                }
            }

        } catch ( HttpClientException $e ) {
            $errors[] = "Batch update API error: " . $e->getMessage();
        }

        return [
            'success_count' => $success_count,
            'errors' => $errors
        ];
    }

    /**
     * Batch create variations for a product
     */
    private function batch_create_variations( $product_id, $variations ) {
        if ( empty( $variations ) || count( $variations ) > 100 ) {
            // Fall back to individual creation for large variation sets
            foreach ( array_chunk( $variations, 50 ) as $chunk ) {
                $this->batch_create_variations( $product_id, $chunk );
            }
            return;
        }

        $batch_data = [
            'create' => $variations
        ];

        try {
            $this->client->post( "products/{$product_id}/variations/batch", $batch_data );
        } catch ( HttpClientException $e ) {
            $this->log_message( "Batch variation creation failed for product {$product_id}: " . $e->getMessage() );
        }
    }

    /**
     * Batch update variations for a product
     */
    private function batch_update_variations( $product_id, $variations ) {
        // Get existing variations
        $existing_variations = $this->get_existing_variations( $product_id );
        
        $update_batch = [];
        $create_batch = [];

        foreach ( $variations as $variation ) {
            $existing_id = $existing_variations[ $variation['sku'] ] ?? null;
            
            if ( $existing_id ) {
                $update_batch[] = array_merge( ['id' => $existing_id], $variation );
            } else {
                $create_batch[] = $variation;
            }
        }

        // Process updates
        if ( !empty( $update_batch ) ) {
            try {
                $this->client->post( "products/{$product_id}/variations/batch", [
                    'update' => $update_batch
                ] );
            } catch ( HttpClientException $e ) {
                $this->log_message( "Batch variation update failed for product {$product_id}: " . $e->getMessage() );
            }
        }

        // Process creates
        if ( !empty( $create_batch ) ) {
            $this->batch_create_variations( $product_id, $create_batch );
        }
    }

    /**
     * Get existing variations for a product
     */
    private function get_existing_variations( $product_id ) {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value as sku 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_parent = %d 
             AND p.post_type = 'product_variation' 
             AND pm.meta_key = '_sku'",
            $product_id
        );

        $results = $wpdb->get_results( $query );
        
        $variations = [];
        foreach ( $results as $result ) {
            $variations[ $result->sku ] = (int) $result->ID;
        }

        return $variations;
    }

    /**
     * Prepare product data for creation
     */
    private function prepare_create_data( $mapped_product ) {
        $product_data = [
            'name'        => $mapped_product['name'],
            'sku'         => $mapped_product['sku'],
            'type'        => 'variable',
            'description' => $mapped_product['description'],
            'attributes'  => $mapped_product['attributes'] ?? [],
            'categories'  => $mapped_product['category_terms'] ?? [],
            'status'      => 'publish',
        ];

        // Add images if available (but defer processing in performance mode)
        if ( !$this->performance_mode && !empty( $mapped_product['images_payload'] ) ) {
            $product_data['images'] = $mapped_product['images_payload'];
        }

        return $product_data;
    }

    /**
     * Prepare product data for update
     */
    private function prepare_update_data( $mapped_product ) {
        return [
            'name'          => $mapped_product['name'],
            'description'   => $mapped_product['description'],
            'regular_price' => $mapped_product['regular_price'] ?? '',
            'attributes'    => $mapped_product['attributes'] ?? [],
        ];
    }

    /**
     * Bulk mark products as complete in database
     */
    private function bulk_mark_as_complete( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_name} SET status = %s WHERE id IN ({$placeholders})",
            Status_Enum::COMPLETED->value,
            ...$product_ids
        ) );
    }

    /**
     * Get products from database with optimized query
     */
    public function get_products_from_db( $limit = 50 ) {
        try {
            global $wpdb;
            
            $products_table = $wpdb->prefix . 'sync_wholesaler_products_data';
            $this->table_name = $products_table;

            // Optimized query with index hints
            $sql = $wpdb->prepare( 
                "SELECT * FROM {$products_table} 
                 WHERE status = %s 
                 ORDER BY id ASC 
                 LIMIT %d", 
                Status_Enum::PENDING->value, 
                $limit 
            );

            $products = $wpdb->get_results( $sql );

            if ( empty( $products ) ) {
                $this->log_message( "No pending products found in database" );
                return [];
            }

            return $products;

        } catch (Exception $e) {
            $this->log_message( "Database error: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Map product data based on wholesaler
     */
    public function map_product_data( string $wholesaler_name, $product ) {
        $wholesaler_name = strtoupper( $wholesaler_name );

        switch ($wholesaler_name) {
            case 'JS':
                return $this->js_service->map( $product );
            case 'MADA':
                return $this->mada_service->map( $product );
            case 'AREN':
                return $this->aren_service->map( $product );
            default:
                return $product;
        }
    }

    /**
     * Handle background import for very large datasets
     */
    public function handle_background_import( WP_REST_Request $request ) {
        $total_batches = $request->get_param( 'total_batches' );
        
        // Schedule background processing
        wp_schedule_single_event( time() + 10, 'wholesaler_background_import', [ $total_batches ] );
        
        return new WP_REST_Response( [
            'success' => true,
            'message' => "Background import scheduled for {$total_batches} batches"
        ], 200 );
    }
}

// Initialize the batch import class
$website_url     = site_url();
$consumer_key    = get_option( 'wholesaler_consumer_key', '' );
$consumer_secret = get_option( 'wholesaler_consumer_secret', '' );

if ( !empty( $consumer_key ) && !empty( $consumer_secret ) ) {
    new Wholesaler_Batch_Import( $website_url, $consumer_key, $consumer_secret );
}

// Register background import hook
add_action( 'wholesaler_background_import', function( $total_batches ) {
    $batch_import = new Wholesaler_Batch_Import( 
        site_url(), 
        get_option( 'wholesaler_consumer_key', '' ), 
        get_option( 'wholesaler_consumer_secret', '' ) 
    );
    
    for ( $i = 0; $i < $total_batches; $i++ ) {
        $batch_import->batch_import_products( 50 );
        
        // Small delay between batches to prevent server overload
        sleep( 1 );
    }
} );
