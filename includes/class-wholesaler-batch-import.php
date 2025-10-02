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

            // High-performance bulk delete endpoint
            register_rest_route( 'wholesaler/v1', '/bulk-delete-products', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_bulk_delete_products' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'batch_size' => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ($param) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                    'delete_images' => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'cleanup_database' => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
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

    /**
     * Handle bulk delete products with comprehensive cleanup
     */
    public function handle_bulk_delete_products( WP_REST_Request $request ) {
        $batch_size = $request->get_param( 'batch_size' );
        $delete_images = $request->get_param( 'delete_images' );
        $cleanup_database = $request->get_param( 'cleanup_database' );

        try {
            $result = $this->bulk_delete_products( $batch_size, $delete_images, $cleanup_database );
            return new WP_REST_Response( $result, 200 );
        } catch ( Exception $e ) {
            $this->log_message( "Bulk delete error: " . $e->getMessage() );
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Bulk delete failed: ' . $e->getMessage()
            ], 500 );
        }
    }

    /**
     * High-performance bulk delete products
     */
    public function bulk_delete_products( $batch_size = 50, $delete_images = true, $cleanup_database = true ) {
        global $wpdb;
        
        $start_time = microtime( true );
        
        // Get products to delete
        $product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type IN ('product', 'product_variation') 
             ORDER BY ID ASC 
             LIMIT %d",
            $batch_size
        ) );

        if ( empty( $product_ids ) ) {
            return [
                'success' => true,
                'message' => 'No products found to delete',
                'deleted_count' => 0,
                'images_deleted' => 0,
                'variations_deleted' => 0,
                'processing_time' => 0
            ];
        }

        $deleted_products = 0;
        $deleted_images = 0;
        $deleted_variations = 0;
        $errors = [];

        // Separate main products from variations
        $main_products = [];
        $variations = [];
        
        foreach ( $product_ids as $product_id ) {
            $post_type = get_post_type( $product_id );
            if ( $post_type === 'product' ) {
                $main_products[] = $product_id;
            } elseif ( $post_type === 'product_variation' ) {
                $variations[] = $product_id;
            }
        }

        // Enable performance mode for bulk operations
        if ( $cleanup_database ) {
            $this->enable_performance_mode();
        }

        try {
            // 1. Delete images in bulk if requested
            if ( $delete_images ) {
                $deleted_images = $this->bulk_delete_product_images( array_merge( $main_products, $variations ) );
            }

            // 2. Delete variations first
            if ( ! empty( $variations ) ) {
                $deleted_variations = $this->bulk_delete_variations( $variations );
            }

            // 3. Get all child variations for main products and delete them
            if ( ! empty( $main_products ) ) {
                $child_variations = $this->get_all_child_variations( $main_products );
                if ( ! empty( $child_variations ) ) {
                    if ( $delete_images ) {
                        $deleted_images += $this->bulk_delete_product_images( $child_variations );
                    }
                    $deleted_variations += $this->bulk_delete_variations( $child_variations );
                }
            }

            // 4. Clean up database tables in bulk
            if ( $cleanup_database ) {
                $this->bulk_cleanup_database_tables( array_merge( $main_products, $variations, $child_variations ) );
            }

            // 5. Delete main products
            $deleted_products = $this->bulk_delete_main_products( $main_products );

            // 6. Final cleanup
            if ( $cleanup_database ) {
                $this->bulk_cleanup_orphaned_data();
                $this->disable_performance_mode();
            }

            $end_time = microtime( true );
            $processing_time = $end_time - $start_time;

            return [
                'success' => true,
                'message' => sprintf( 
                    'Bulk delete completed. Deleted %d products, %d variations, %d images in %.2f seconds', 
                    $deleted_products, 
                    $deleted_variations, 
                    $deleted_images, 
                    $processing_time 
                ),
                'deleted_count' => $deleted_products,
                'variations_deleted' => $deleted_variations,
                'images_deleted' => $deleted_images,
                'processing_time' => round( $processing_time, 3 ),
                'errors' => $errors
            ];

        } catch ( Exception $e ) {
            if ( $cleanup_database ) {
                $this->disable_performance_mode();
            }
            throw $e;
        }
    }

    /**
     * Bulk delete product images
     */
    private function bulk_delete_product_images( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return 0;
        }

        global $wpdb;
        
        // Get all image IDs associated with these products
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        
        // Get featured images
        $featured_images = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} 
             WHERE post_id IN ($placeholders) 
             AND meta_key = '_thumbnail_id' 
             AND meta_value != ''",
            ...$product_ids
        ) );

        // Get gallery images
        $gallery_images = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} 
             WHERE post_id IN ($placeholders) 
             AND meta_key = '_product_image_gallery' 
             AND meta_value != ''",
            ...$product_ids
        ) );

        // Parse gallery image IDs
        $all_gallery_ids = [];
        foreach ( $gallery_images as $gallery_string ) {
            $ids = explode( ',', $gallery_string );
            $all_gallery_ids = array_merge( $all_gallery_ids, array_filter( $ids ) );
        }

        // Combine all image IDs
        $all_image_ids = array_unique( array_merge( $featured_images, $all_gallery_ids ) );
        
        // Delete images in chunks to avoid memory issues
        $deleted_count = 0;
        $chunk_size = 50;
        
        foreach ( array_chunk( $all_image_ids, $chunk_size ) as $chunk ) {
            foreach ( $chunk as $image_id ) {
                if ( wp_delete_attachment( $image_id, true ) ) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Get all child variations for main products
     */
    private function get_all_child_variations( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return [];
        }

        global $wpdb;
        
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_parent IN ($placeholders) 
             AND post_type = 'product_variation'",
            ...$product_ids
        ) );
    }

    /**
     * Bulk delete variations
     */
    private function bulk_delete_variations( $variation_ids ) {
        if ( empty( $variation_ids ) ) {
            return 0;
        }

        global $wpdb;
        
        $placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
        
        // Delete variation posts
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
            ...$variation_ids
        ) );

        return $deleted;
    }

    /**
     * Bulk delete main products
     */
    private function bulk_delete_main_products( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return 0;
        }

        global $wpdb;
        
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        
        // Delete product posts
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
            ...$product_ids
        ) );

        return $deleted;
    }

    /**
     * Bulk cleanup database tables
     */
    private function bulk_cleanup_database_tables( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;
        
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        // Clean up postmeta
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
            ...$product_ids
        ) );

        // Clean up term relationships
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
            ...$product_ids
        ) );

        // Clean up comments
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders)",
            ...$product_ids
        ) );

        // Clean up WooCommerce lookup tables
        $wc_tables = [
            $wpdb->prefix . 'wc_product_meta_lookup',
            $wpdb->prefix . 'wc_product_attributes_lookup'
        ];

        foreach ( $wc_tables as $table ) {
            $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
            if ( $table_exists ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$table} WHERE product_id IN ($placeholders)",
                    ...$product_ids
                ) );
            }
        }
    }

    /**
     * Clean up orphaned data after bulk deletion
     */
    private function bulk_cleanup_orphaned_data() {
        global $wpdb;

        // Clean up orphaned postmeta
        $wpdb->query( 
            "DELETE pm FROM {$wpdb->postmeta} pm 
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE p.ID IS NULL" 
        );

        // Clean up orphaned term relationships
        $wpdb->query( 
            "DELETE tr FROM {$wpdb->term_relationships} tr 
             LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID 
             WHERE p.ID IS NULL" 
        );

        // Clean up orphaned comments
        $wpdb->query( 
            "DELETE c FROM {$wpdb->comments} c 
             LEFT JOIN {$wpdb->posts} p ON c.comment_post_ID = p.ID 
             WHERE p.ID IS NULL" 
        );

        // Update term counts
        $taxonomies = [ 'product_cat', 'product_tag', 'product_brand' ];
        foreach ( $taxonomies as $taxonomy ) {
            if ( taxonomy_exists( $taxonomy ) ) {
                wp_update_term_count_now( [], $taxonomy );
            }
        }
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
