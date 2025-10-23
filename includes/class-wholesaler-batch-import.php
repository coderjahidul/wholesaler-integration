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

    /**
     * Excluded category slugs - products with these categories will be skipped
     */
    private $excluded_categories = [
        "Podwiązki",
        "matowe",
        "Odzież",
        "Dresy",
        "Leginsy",
        "Garniturowe",
        "Fantazyjna / Biustonosze",
        "Skarpety / Stopki",
        "Torby Papierowe",
        "Spodnie, Spodenki",
        "Pończosznictwo / Leginsy",
        "Fantazyjna / Perfumy",
        "X-Mas",
        "skarpety dziecięce i młodzieżowe",
        "Mokasynki",
        "wiskoza",
        "Szale",
        "biustonosz",
        "Skarpety",
        "Stopki",
        "Opaski Na Oczy",
        "Spódnice, Sukienki",
        "Pudełka",
        "Halki i półhalki",
        "Ramiączka",
        "Bluzki, Podkoszulki",
        "Akcesoria Plażowe",
        "Stojaki, Wieszaki",
        "akcesoria do biustonoszy",
        "zdrowotne",
        "dziewczynka",
        "koszule i piżamy",
        "Fantazyjna / Pas do pończoch",
        "Bielizna erotyczna / Perfumy",
        "Okazjonalne",
        "Stroje Kąpielowe / Sukienki",
        "pozostałe akcesoria",
        "Pareo, Sukienki",
        "Reklamówki",
        "Bielizna erotyczna / Majteczki",
        "Bielizna dziecięca / Rajstopy",
        "Bielizna dziecięca / Piżama dziewczęca",
        "długi rękaw",
        "rajstopy dziecięce",
        "bezuciskowe",
        "spodenki gimnastyczne",
        "nerki",
        "komplety pościeli",
        "duże oczka",
        "na zime",
        "torby prezentowe",
        "chłopiec",
        "koszulka",
        "narzuty",
        "Halki, Półhalki",
        "Okazjonalne / Walentynki",
        "Fantazyjna / Przebieranki",
        "Pończosznictwo / Ślubna",
        "medyczne",
        "biustonosze silikonowe",
        "wzorzyste",
        "berety",
        "koszyki",
        "chusteczki",
        "Inne",
        "Kominy, Kominiarki",
        "T Shirty",
        "Bielizna dziecięca / Biustonosze",
        "Bielizna damska / Pasy do pończoch",
        "Sukienki",
        "Skarpety / Podkolanówki",
        "bielizna bezszwowa",
        "Bielizna dziecięca, młodzieżowa",
        "bambus",
        "3",
        "do włosów",
        "akryl",
        "Podkoszulki, Koszulki",
        "Kalesony",
        "Slipy i bokserki",
        "Bielizna erotyczna / Przebieranki",
        "Dodatki",
        "Stroje kąpielowe dziecięce",
        "akcesoria niemowlęce",
        "koce",
        "Męska",
        "Podkładki Pod Ramiączka",
        "Męskie",
        "Manekiny I Ekspozytory",
        "koszulki nocne",
        "Pończosznictwo",
        "Kąpielówki",
        "Okazjonalne / X-Mas",
        "rozpinana",
        "bielizna ciążowa i dla mamy",
        "koszulki",
        "Koszulki",
        "Kosmetyki",
        "Podkoszulki",
        "Body, Halki",
        "gładkie",
        "antypoślizgowe",
        "ażur",
        "kombinezony",
        "Pasy",
        "Opaski",
        "Bluzki",
        "młodzieżowe",
        "dla domu",
        "Podkoszulki,Koszulki",
        "Piżama dziewczęca",
        "Bielizna erotyczna / Stringi",
        "Fantazyjna / Sukienki",
        "Dres",
        "wełniane",
        "Artykuły sklepowe / Uroda",
        "Bielizna męska / Slipy i bokserki",
        "Bielizna męska / Piżamy",
        "ramiączko",
        "podkolanówki męskie",
        "Bielizna damska / Figi",
        "małe oczka",
        "reformy",
        "bawełna",
        "Pozostałe",
        "Podkolanówki",
        "Ciążowe",
        "Bielizna damska / Gorsety",
        "bielizna termoaktywna",
        "kapelusze",
        "bez fiszbin",
        "żakardowe",
        "Dziecięce",
        "Zakolanówki",
        "Pończosznictwo / Pończochy",
        "Fantazyjna / Pończochy",
        "Koszulki dziewczęce",
        "ręczniki plażowe",
        "Stroje kąpielowe damskie",
        "Czapki, Opaski",
        "Sezonowa I Thermo",
        "Bluzy",
        "Piżama chłopięca",
        "Legginsy",
        "Opakowania",
        "Torby",
        "Bielizna męska / Szlafroki",
        "Bielizna dziecięca",
        "Bielizna damska",
        "Bielizna damska / Koszule Nocne",
        "Bielizna erotyczna / Sukienki",
        "Majteczki",
        "bawełniane",
        "sportowe",
        "Czapki",
        "podkolanówki dziecięce",
        "szaliki",
        "topy",
        "halki",
        "spodnie",
        "4 rękaw",
        "na głowę",
        "ręczniki",
        "Koszule I Koszulki Nocne",
        "Do Biustonoszy",
        "Wkładki",
        "Meble I Oznaczenia",
        "Tuniki",
        "apaszki",
        "Balerinki",
        "niemowlęce",
        "żakard",
        "Skarpety / Skarpetki",
        "Getry",
        "relaksujące",
        "dziewczęce",
    ];

    public function __construct( string $website_url, string $consumer_key, string $consumer_secret ) {

        // Set up the API client with optimized settings for live server
        $this->client = new Client(
            $website_url,
            $consumer_key,
            $consumer_secret,
            [
                'verify_ssl'     => false,
                'wp_api'         => true,
                'version'        => 'wc/v3',
                'timeout'        => 600, // Increased timeout for live server
                'query_string_auth' => true, // Use query string auth for better compatibility
                'user_agent'     => 'WholesalerIntegration/1.0',
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
                    'batch_size'       => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $param ) {
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
                    'total_batches'      => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param ) && $param > 0 && $param <= 50;
                        }
                    ],
                    'products_per_batch' => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                    'process_images'     => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'update_existing'    => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ] );

            // High-performance bulk delete endpoint
            register_rest_route( 'wholesaler/v1', '/bulk-delete-products', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_bulk_delete_products' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'batch_size'       => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        }
                    ],
                    'delete_images'    => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                    'cleanup_database' => [
                        'default'           => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ] );

            // Background job status endpoint
            register_rest_route( 'wholesaler/v1', '/background-status/(?P<job_id>\d+)', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_background_job_status' ],
                'permission_callback' => '__return_true',
            ] );

            // Background jobs list endpoint
            register_rest_route( 'wholesaler/v1', '/background-jobs', [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_background_jobs_list' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'status' => [
                        'default'           => 'all',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit'  => [
                        'default'           => 20,
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
        $batch_size             = $request->get_param( 'batch_size' );
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
            $create_batch          = [];
            $update_batch          = [];
            $product_ids_to_update = [];
            $skipped_product_ids   = [];

            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                $existing_id    = $existing_products[$mapped_product['sku']] ?? null;

                // Skip products with excluded categories
                if ( $this->has_excluded_category( $mapped_product ) ) {
                    $skipped_product_ids[] = $product->id;
                    $category_names        = array_map( function ( $cat ) {
                        return $cat['name'] ?? '';
                    }, $mapped_product['category_terms'] ?? [] );

                    put_program_logs( sprintf(
                        'Skipping product %s (SKU: %s) - excluded category: %s',
                        $mapped_product['name'] ?? 'N/A',
                        $mapped_product['sku'] ?? 'N/A',
                        implode( ', ', $category_names )
                    ) );
                    
                    continue; // Skip to next product
                }

                if ( $existing_id ) {
                    $update_batch[]          = [
                        'id'       => $existing_id,
                        'data'     => $this->prepare_update_data( $mapped_product ),
                        'original' => $product,
                    ];
                    $product_ids_to_update[] = $product->id;
                } else {
                    $create_batch[] = [
                        'data'     => $this->prepare_create_data( $mapped_product ),
                        'original' => $product,
                    ];
                }
            }

            $results = [
                'created' => 0,
                'updated' => 0,
                'errors'  => [],
            ];

            // Process create batch
            if ( !empty( $create_batch ) ) {
                $create_result      = $this->batch_create_products( $create_batch );
                $results['created'] = $create_result['success_count'];
                $results['errors']  = array_merge( $results['errors'], $create_result['errors'] );
            }

            // Process update batch
            if ( !empty( $update_batch ) ) {
                $update_result      = $this->batch_update_products( $update_batch );
                $results['updated'] = $update_result['success_count'];
                $results['errors']  = array_merge( $results['errors'], $update_result['errors'] );
            }

            // Mark successfully processed products as complete
            $successfully_processed_ids = [];
            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                
                // Only mark as complete if product was actually processed (not skipped)
                if ( !$this->has_excluded_category( $mapped_product ) ) {
                    $successfully_processed_ids[] = $product->id;
                }
            }
            
            // Bulk update database status for successfully processed products
            if ( !empty( $successfully_processed_ids ) ) {
                $this->bulk_mark_as_complete( $successfully_processed_ids );
            }
            
            // Mark skipped products as SKIPPED to prevent them from being processed again
            if ( !empty( $skipped_product_ids ) ) {
                $this->bulk_mark_as_skipped( $skipped_product_ids );
            }

            // Collect products with images for automatic processing
            $products_with_images = [];
            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                $existing_id    = $existing_products[$mapped_product['sku']] ?? null;

                if ( !empty( $mapped_product['images_payload'] ) ) {
                    $product_id = $existing_id;

                    // For new products, find the created product ID
                    if ( !$existing_id && !empty( $create_result['created_products'] ) ) {
                        foreach ( $create_result['created_products'] as $created ) {
                            if ( $created['sku'] === $mapped_product['sku'] ) {
                                $product_id = $created['wc_id'];
                                break;
                            }
                        }
                    }

                    if ( $product_id ) {
                        $products_with_images[] = [
                            'product_id' => $product_id,
                            'images'     => $mapped_product['images_payload'],
                        ];
                    }
                }
            }

            // Automatically schedule image processing (with duplicate prevention)
            if ( !empty( $products_with_images ) ) {
                $this->schedule_bulk_image_processing( $products_with_images );
            }

            return [
                'success'          => true,
                'message'          => sprintf(
                    'Batch import completed. Created: %d, Updated: %d, Skipped: %d, Images scheduled: %d',
                    $results['created'],
                    $results['updated'],
                    count( $skipped_product_ids ),
                    count( $products_with_images )
                ),
                'created'          => $results['created'],
                'updated'          => $results['updated'],
                'skipped'          => count( $skipped_product_ids ),
                'errors'           => $results['errors'],
                'total_processed'  => count( $products ),
                'images_scheduled' => count( $products_with_images ),
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

        $skus = array_map( function ( $product ) {
            $mapped = $this->map_product_data( $product->wholesaler_name, $product );
            return $mapped['sku'];
        }, $products );

        if ( empty( $skus ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );

        // First, try to find products using postmeta (standard WooCommerce method)
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as sku, p.ID as product_id 
             FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' 
             AND pm.meta_value IN ($placeholders) 
             AND p.post_type = 'product'
             AND p.post_status != 'trash'",
            ...$skus
        );

        $results = $wpdb->get_results( $query );

        $existing_products = [];
        foreach ( $results as $result ) {
            $existing_products[$result->sku] = (int) $result->product_id;
        }

        // If we didn't find all products, also check the WooCommerce lookup table as fallback
        $found_skus = array_keys( $existing_products );
        $missing_skus = array_diff( $skus, $found_skus );
        
        if ( !empty( $missing_skus ) ) {
            put_program_logs( "Checking WooCommerce lookup table for missing SKUs: " . implode( ', ', $missing_skus ) );
            
            $lookup_placeholders = implode( ',', array_fill( 0, count( $missing_skus ), '%s' ) );
            $lookup_query = $wpdb->prepare(
                "SELECT sku, product_id 
                 FROM {$wpdb->prefix}wc_product_meta_lookup 
                 WHERE sku IN ($lookup_placeholders)",
                ...$missing_skus
            );
            
            $lookup_results = $wpdb->get_results( $lookup_query );
            
            foreach ( $lookup_results as $result ) {
                $existing_products[$result->sku] = (int) $result->product_id;
                put_program_logs( "Found existing product via lookup table: SKU {$result->sku} -> ID {$result->product_id}" );
            }
        }

        put_program_logs( "Found " . count( $existing_products ) . " existing products out of " . count( $skus ) . " checked" );
        
        return $existing_products;
    }

    /**
     * Batch create products using WooCommerce batch API
     */
    private function batch_create_products( $create_batch ) {
        $batch_data = [
            'create' => array_column( $create_batch, 'data' ),
        ];

        $success_count = 0;
        $errors        = [];

        // log the create batch data
        // put_program_logs("create batch data: " . json_encode( $batch_data ) );

        try {
            $response = $this->client->post( 'products/batch', $batch_data );

            if ( isset( $response->create ) ) {
                foreach ( $response->create as $index => $result ) {
                    if ( isset( $result->id ) ) {
                        $success_count++;

                        // Handle variations for created products
                        $original_product = $create_batch[$index]['original'];
                        $mapped_product   = $this->map_product_data( $original_product->wholesaler_name, $original_product );

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

        } catch (HttpClientException $e) {
            $errors[] = "Batch create API error: " . $e->getMessage();
        }

        return [
            'success_count' => $success_count,
            'errors'        => $errors,
        ];
    }

    /**
     * Batch update products using WooCommerce batch API
     */
    private function batch_update_products( $update_batch ) {
        $batch_data = [
            'update' => array_map( function ( $item ) {
                return array_merge( [ 'id' => $item['id'] ], $item['data'] );
            }, $update_batch ),
        ];

        $success_count = 0;
        $errors        = [];

        // log the update batch data
        // put_program_logs("update batch data: " . json_encode( $batch_data ) );

        try {
            $response = $this->client->post( 'products/batch', $batch_data );

            if ( isset( $response->update ) ) {
                foreach ( $response->update as $index => $result ) {
                    if ( isset( $result->id ) ) {
                        $success_count++;

                        // Handle variations for updated products
                        $original_product = $update_batch[$index]['original'];
                        $mapped_product   = $this->map_product_data( $original_product->wholesaler_name, $original_product );

                        if ( !empty( $mapped_product['variations'] ) ) {
                            $this->batch_update_variations( $result->id, $mapped_product['variations'] );
                        }

                    } else {
                        $errors[] = "Failed to update product ID {$update_batch[$index]['id']}: " . ( $result->error->message ?? 'Unknown error' );
                    }
                }
            }

        } catch (HttpClientException $e) {
            $errors[] = "Batch update API error: " . $e->getMessage();
        }

        return [
            'success_count' => $success_count,
            'errors'        => $errors,
        ];
    }

    /**
     * Batch create variations for a product
     */
    private function batch_create_variations( $product_id, $variations ) {

        // log the create variations data
        // put_program_logs("create variations data: " . json_encode( $variations ) );

        if ( empty( $variations ) || count( $variations ) > 100 ) {
            // Fall back to individual creation for large variation sets
            foreach ( array_chunk( $variations, 50 ) as $chunk ) {
                $this->batch_create_variations( $product_id, $chunk );
            }
            return;
        }

        $batch_data = [
            'create' => $variations,
        ];

        try {
            $this->client->post( "products/{$product_id}/variations/batch", $batch_data );
        } catch (HttpClientException $e) {
            $this->log_message( "Batch variation creation failed for product {$product_id}: " . $e->getMessage() );
        }
    }

    /**
     * Batch update variations for a product
     */
    private function batch_update_variations( $product_id, $variations ) {
        // Get existing variations
        $existing_variations = $this->get_existing_variations( $product_id );

        // log the update variations data
        // put_program_logs("update variations data: " . json_encode( $variations ) );

        $update_batch = [];
        $create_batch = [];

        foreach ( $variations as $variation ) {
            $existing_id = $existing_variations[$variation['sku']] ?? null;

            if ( $existing_id ) {
                $update_batch[] = array_merge( [ 'id' => $existing_id ], $variation );
            } else {
                $create_batch[] = $variation;
            }
        }

        // Process updates
        if ( !empty( $update_batch ) ) {
            try {
                $this->client->post( "products/{$product_id}/variations/batch", [
                    'update' => $update_batch,
                ] );
            } catch (HttpClientException $e) {
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
            $variations[$result->sku] = (int) $result->ID;
        }

        return $variations;
    }

    /**
     * Prepare product data for creation
     */
    private function prepare_create_data( $mapped_product ) {
        // log the prepare create data
        // put_program_logs("prepare create data: " . json_encode( $mapped_product ) );

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
        // log the prepare update data
        // put_program_logs("prepare update data: " . json_encode( $mapped_product ) );

        return [
            'name'        => $mapped_product['name'],
            'description' => $mapped_product['description'],
            // 'regular_price' => $mapped_product['regular_price'] ?? '',
            'attributes'  => $mapped_product['attributes'] ?? [],
        ];
    }

    /**
     * Bulk mark products as complete in database
     */
    private function bulk_mark_as_complete( $product_ids ) {
        // log the bulk mark as complete data
        // put_program_logs("bulk mark as complete data: " . json_encode( $product_ids ) );

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
     * Bulk mark products as skipped in database
     */
    private function bulk_mark_as_skipped( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';

        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_name} SET status = %s WHERE id IN ({$placeholders})",
            Status_Enum::SKIPPED->value,
            ...$product_ids
        ) );

        put_program_logs( "Marked " . count( $product_ids ) . " products as SKIPPED in database" );
        
        return $result !== false;
    }

    /**
     * Mark a single product as failed in database
     */
    private function mark_product_as_failed( $product_id, $error_message ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';

        $result = $wpdb->update(
            $table_name,
            [
                'status' => Status_Enum::FAILED->value,
                'error_message' => $error_message,
                'updated_at' => current_time( 'mysql' )
            ],
            [ 'id' => $product_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $result !== false ) {
            put_program_logs( "Marked product ID {$product_id} as FAILED: {$error_message}" );
        }
        
        return $result !== false;
    }

    /**
     * Check if product has any excluded categories
     * 
     * @param array $mapped_product The mapped product data
     * @return bool True if product has excluded categories, false otherwise
     */
    private function has_excluded_category( $mapped_product ) {
        if ( empty( $mapped_product['category_terms'] ) || !is_array( $mapped_product['category_terms'] ) ) {
            return false;
        }

        // Extract category names from category_terms array
        $product_categories = array_map( function ( $cat ) {
            return isset( $cat['name'] ) ? $cat['name'] : '';
        }, $mapped_product['category_terms'] );

        // Check if any product category matches excluded categories
        foreach ( $product_categories as $category ) {
            if ( in_array( $category, $this->excluded_categories, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get products from database with optimized query
     */
    public function get_products_from_db( $limit = 50 ) {
        try {
            global $wpdb;

            $products_table   = $wpdb->prefix . 'sync_wholesaler_products_data';
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
        // log the handle background import data
        // put_program_logs("handle background import data: " . json_encode( $request->get_params() ) );

        $total_batches      = $request->get_param( 'total_batches' );
        $products_per_batch = $request->get_param( 'products_per_batch' );
        $process_images     = $request->get_param( 'process_images' );
        $update_existing    = $request->get_param( 'update_existing' );

        // Create background job data
        $job_data = [
            'total_batches'      => $total_batches,
            'products_per_batch' => $products_per_batch,
            'process_images'     => $process_images,
            'update_existing'    => $update_existing,
            'started_at'         => current_time( 'mysql' ),
            'status'             => 'scheduled',
        ];

        // Store job data for tracking
        $job_id = $this->create_background_job( $job_data );

        // Schedule background processing with multiple fallback methods
        $scheduled = wp_schedule_single_event( time() + 10, 'wholesaler_background_import', [ $job_id, $job_data ] );

        // log the scheduled data
        // put_program_logs("scheduled data: " . json_encode( $scheduled ) );

        // Fallback 1: Try immediate processing if scheduling fails
        if ( !$scheduled ) {
            wp_schedule_single_event( time() + 5, 'wholesaler_background_import', [ $job_id, $job_data ] );
        }

        // Fallback 2: Add to performance manager queue as backup
        $this->add_to_performance_queue( $job_id, $job_data );

        // Fallback 3: Set up a recurring check for stuck jobs
        if ( !wp_next_scheduled( 'wholesaler_check_stuck_jobs' ) ) {
            wp_schedule_event( time() + 60, 'every_minute', 'wholesaler_check_stuck_jobs' );
        }

        return new WP_REST_Response( [
            'success'                  => true,
            'message'                  => "Background import scheduled for {$total_batches} batches with {$products_per_batch} products each",
            'job_id'                   => $job_id,
            'total_products_estimated' => $total_batches * $products_per_batch,
            'process_images'           => $process_images,
            'update_existing'          => $update_existing,
            'scheduled'                => $scheduled,
            'fallbacks_enabled'        => true,
        ], 200 );
    }

    /**
     * Create background job for tracking
     */
    private function create_background_job( $job_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wholesaler_background_jobs';

        // Create table if it doesn't exist
        $this->create_background_jobs_table();

        $wpdb->insert(
            $table_name,
            [
                'job_data'   => wp_json_encode( $job_data ),
                'status'     => 'scheduled',
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s' ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Create background jobs table for tracking
     */
    private function create_background_jobs_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'wholesaler_background_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_data longtext NOT NULL,
            status varchar(20) DEFAULT 'scheduled',
            progress int(11) DEFAULT 0,
            total_processed int(11) DEFAULT 0,
            total_created int(11) DEFAULT 0,
            total_updated int(11) DEFAULT 0,
            total_errors int(11) DEFAULT 0,
            error_messages longtext NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Add job to performance manager queue as fallback
     */
    private function add_to_performance_queue( $job_id, $job_data ) {
        // Add to the performance manager queue system as backup
        if ( class_exists( 'Wholesaler_Performance_Manager' ) ) {
            $performance_manager = new Wholesaler_Performance_Manager();

            // Schedule via performance manager as backup
            wp_schedule_single_event( time() + 30, 'wholesaler_process_queue', [
                'job_type' => 'background_import',
                'job_data' => [
                    'job_id'   => $job_id,
                    'job_data' => $job_data,
                ],
            ] );
        }
    }

    /**
     * Check for stuck jobs and process them
     */
    public function check_stuck_jobs() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wholesaler_background_jobs';

        // Find jobs that have been scheduled for more than 5 minutes
        $stuck_jobs = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE status = 'scheduled' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             LIMIT 5"
        );

        foreach ( $stuck_jobs as $job ) {
            $job_data = json_decode( $job->job_data, true );

            // Mark as running to prevent duplicate processing
            $wpdb->update(
                $table_name,
                [ 'status' => 'processing_stuck' ],
                [ 'id' => $job->id ],
                [ '%s' ],
                [ '%d' ]
            );

            // Process the stuck job immediately
            try {
                $this->process_background_job( $job->id, $job_data );
            } catch (Exception $e) {
                // Mark as failed if processing fails
                $wpdb->update(
                    $table_name,
                    [
                        'status'         => 'failed',
                        'error_messages' => wp_json_encode( [ 'Stuck job processing failed: ' . $e->getMessage() ] ),
                        'completed_at'   => current_time( 'mysql' ),
                    ],
                    [ 'id' => $job->id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
            }
        }
    }

    /**
     * Process background job with proper tracking and stats
     */
    public function process_background_job( $job_id, $job_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wholesaler_background_jobs';

        try {
            // Update job status to running
            $wpdb->update(
                $table_name,
                [
                    'status'     => 'running',
                    'started_at' => current_time( 'mysql' ),
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            $total_batches      = $job_data['total_batches'];
            $products_per_batch = $job_data['products_per_batch'];
            $process_images     = $job_data['process_images'];
            $update_existing    = $job_data['update_existing'];

            $total_created        = 0;
            $total_updated        = 0;
            $total_errors         = 0;
            $error_messages       = [];
            $products_with_images = [];
            $product_state        = '';

            // Enable performance mode for background processing
            $this->enable_performance_mode();

            for ( $i = 0; $i < $total_batches; $i++ ) {
                try {
                    // Process batch with enhanced tracking
                    $result = $this->batch_import_products_enhanced(
                        $products_per_batch,
                        $update_existing
                    );

                    if ( $result['success'] ) {
                        $total_created += $result['created'];
                        $total_updated += $result['updated'];
                        $product_state = $result['product_state'];

                        // Collect products with images for later processing
                        if ( $process_images && 'new' == $product_state && !empty( $result['products_with_images'] ) ) {
                            $products_with_images = array_merge(
                                $products_with_images,
                                $result['products_with_images']
                            );
                        }
                    }

                    if ( !empty( $result['errors'] ) ) {
                        $total_errors += count( $result['errors'] );
                        $error_messages = array_merge( $error_messages, $result['errors'] );
                    }

                    // Update progress
                    $progress = round( ( ( $i + 1 ) / $total_batches ) * 100 );
                    $wpdb->update(
                        $table_name,
                        [
                            'progress'        => $progress,
                            'total_processed' => $total_created + $total_updated,
                            'total_created'   => $total_created,
                            'total_updated'   => $total_updated,
                            'total_errors'    => $total_errors,
                        ],
                        [ 'id' => $job_id ],
                        [ '%d', '%d', '%d', '%d', '%d' ],
                        [ '%d' ]
                    );

                    // Small delay between batches
                    sleep( 2 );

                } catch (Exception $e) {
                    $total_errors++;
                    $error_messages[] = "Batch {$i}: " . $e->getMessage();
                }
            }

            // Process images in background if requested
            if ( $process_images && 'new' == $product_state && !empty( $products_with_images ) ) {
                // log the schedule bulk image processing data
                // put_program_logs("schedule bulk image processing data: " . json_encode( $products_with_images ) );

                $this->schedule_bulk_image_processing( $products_with_images );
            }

            // Disable performance mode
            $this->disable_performance_mode();

            // Mark job as completed
            $wpdb->update(
                $table_name,
                [
                    'status'          => 'completed',
                    'progress'        => 100,
                    'total_processed' => $total_created + $total_updated,
                    'total_created'   => $total_created,
                    'total_updated'   => $total_updated,
                    'total_errors'    => $total_errors,
                    'error_messages'  => wp_json_encode( $error_messages ),
                    'completed_at'    => current_time( 'mysql' ),
                ],
                [ 'id' => $job_id ],
                [ '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' ],
                [ '%d' ]
            );

        } catch (Exception $e) {
            // Mark job as failed
            $wpdb->update(
                $table_name,
                [
                    'status'         => 'failed',
                    'error_messages' => wp_json_encode( [ $e->getMessage() ] ),
                    'completed_at'   => current_time( 'mysql' ),
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            $this->disable_performance_mode();
        }
    }

    /**
     * Enhanced batch import with better variation handling and image collection
     */
    public function batch_import_products_enhanced( $batch_size = 50, $update_existing = true ) {
        try {
            // Get products from database
            $products = $this->get_products_from_db( $batch_size );

            if ( empty( $products ) ) {
                return [
                    'success'              => true,
                    'message'              => 'No pending products to import',
                    'created'              => 0,
                    'updated'              => 0,
                    'errors'               => [],
                    'products_with_images' => [],
                ];
            }

            // Pre-load existing products for faster lookups
            $existing_products = $this->bulk_check_existing_products( $products );

            // Separate products into create and update batches
            $create_batch          = [];
            $update_batch          = [];
            $product_ids_to_update = [];
            $products_with_images  = [];
            $product_state         = '';
            $skipped_product_ids   = [];

            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                $existing_id    = $existing_products[$mapped_product['sku']] ?? null;

                // put_program_logs( 'category: ' . json_encode( $mapped_product['category_terms'] ) );

                // Check if product should be skipped due to category exclusion
                $is_skipped = $this->has_excluded_category( $mapped_product );
                
                if ( $is_skipped ) {
                    $skipped_product_ids[] = $product->id;
                    $category_names        = array_map( function ( $cat ) {
                        return $cat['name'] ?? '';
                    }, $mapped_product['category_terms'] ?? [] );

                    put_program_logs( sprintf(
                        'Skipping product %s (SKU: %s) - excluded category: %s',
                        $mapped_product['name'] ?? 'N/A',
                        $mapped_product['sku'] ?? 'N/A',
                        implode( ', ', $category_names )
                    ) );

                    // Still collect image data but mark as skipped for processing check
                    if ( !empty( $mapped_product['images_payload'] ) ) {
                        $products_with_images[] = [
                            'product_id'       => $existing_id ?: 'new',
                            'images'           => $mapped_product['images_payload'],
                            'original_product' => $product,
                            'is_skipped'       => true,
                        ];
                    }
                    
                    continue; // Skip to next product
                }

                // Collect products with images for later processing (non-skipped products)
                if ( !empty( $mapped_product['images_payload'] ) ) {
                    $products_with_images[] = [
                        'product_id'       => $existing_id ?: 'new',
                        'images'           => $mapped_product['images_payload'],
                        'original_product' => $product,
                        'is_skipped'       => false,
                    ];
                }

                if ( $existing_id && $update_existing ) {

                    // set product state as existing
                    $product_state = 'existing';

                    $update_batch[]          = [
                        'id'             => $existing_id,
                        'data'           => $this->prepare_update_data( $mapped_product ),
                        'original'       => $product,
                        'mapped_product' => $mapped_product,
                    ];
                    $product_ids_to_update[] = $product->id;
                } elseif ( !$existing_id ) {

                    // set product state as new
                    $product_state = 'new';

                    $create_batch[] = [
                        'data'           => $this->prepare_create_data( $mapped_product ),
                        'original'       => $product,
                        'mapped_product' => $mapped_product,
                    ];
                }
            }

            $results = [
                'created' => 0,
                'updated' => 0,
                'errors'  => [],
            ];

            // Process create batch
            if ( !empty( $create_batch ) ) {
                $create_result      = $this->batch_create_products_enhanced( $create_batch );
                $results['created'] = $create_result['success_count'];
                $results['errors']  = array_merge( $results['errors'], $create_result['errors'] );

                // Update products_with_images with new product IDs
                $this->update_image_product_ids( $products_with_images, $create_result['created_products'] );
            }

            // Process update batch with proper variation handling
            if ( !empty( $update_batch ) ) {
                $update_result      = $this->batch_update_products_enhanced( $update_batch );
                $results['updated'] = $update_result['success_count'];
                $results['errors']  = array_merge( $results['errors'], $update_result['errors'] );
            }

            // Mark successfully processed products as complete
            $successfully_processed_ids = [];
            foreach ( $products as $product ) {
                $mapped_product = $this->map_product_data( $product->wholesaler_name, $product );
                $existing_id    = $existing_products[$mapped_product['sku']] ?? null;
                
                // Only mark as complete if product was actually processed (not skipped)
                if ( !$this->has_excluded_category( $mapped_product ) ) {
                    $successfully_processed_ids[] = $product->id;
                }
            }
            
            // Bulk update database status for successfully processed products
            if ( !empty( $successfully_processed_ids ) ) {
                $this->bulk_mark_as_complete( $successfully_processed_ids );
            }
            
            // Mark skipped products as SKIPPED to prevent them from being processed again
            if ( !empty( $skipped_product_ids ) ) {
                $this->bulk_mark_as_skipped( $skipped_product_ids );
            }

            return [
                'success'              => true,
                'message'              => sprintf(
                    'Enhanced batch import completed. Created: %d, Updated: %d, Skipped: %d',
                    $results['created'],
                    $results['updated'],
                    count( $skipped_product_ids )
                ),
                'created'              => $results['created'],
                'updated'              => $results['updated'],
                'skipped'              => count( $skipped_product_ids ),
                'errors'               => $results['errors'],
                'total_processed'      => count( $products ),
                'product_state'        => $product_state, // is the product new or existing
                'products_with_images' => $products_with_images,
            ];

        } catch (Exception $e) {
            $this->log_message( "Enhanced batch import process failed: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Enhanced batch create with better tracking
     */
    private function batch_create_products_enhanced( $create_batch ) {
        $batch_data = [
            'create' => array_column( $create_batch, 'data' ),
        ];

        $success_count    = 0;
        $errors           = [];
        $created_products = [];

        try {
            put_program_logs( "Attempting to create " . count( $create_batch ) . " products via batch API" );
            
            $response = $this->client->post( 'products/batch', $batch_data );

            if ( isset( $response->create ) ) {
                foreach ( $response->create as $index => $result ) {
                    if ( isset( $result->id ) && $result->id > 0 ) {
                        $success_count++;
                        $created_products[] = [
                            'wc_id'          => $result->id,
                            'original_index' => $index,
                            'sku'            => $create_batch[$index]['data']['sku'],
                        ];

                        put_program_logs( "Successfully created product ID: {$result->id} for SKU: " . $create_batch[$index]['data']['sku'] );

                        // Handle variations for created products with enhanced variation support
                        $original_product = $create_batch[$index]['original'];
                        $mapped_product   = $create_batch[$index]['mapped_product'];

                        if ( !empty( $mapped_product['variations'] ) ) {
                            $this->batch_create_variations_enhanced( $result->id, $mapped_product['variations'] );
                        }

                        // Update taxonomies
                        $this->helpers->update_product_taxonomies( $result->id, $mapped_product );

                    } else {
                        $error_msg = "Failed to create product: " . ( $result->error->message ?? 'Unknown error' );
                        $errors[] = $error_msg;
                        put_program_logs( $error_msg . " - SKU: " . ( $create_batch[$index]['data']['sku'] ?? 'unknown' ) );
                        
                        // If it's a duplicate SKU error, mark the product as failed in database
                        if ( strpos( $error_msg, 'already present in the lookup table' ) !== false ) {
                            $this->mark_product_as_failed( $create_batch[$index]['original']->id, $error_msg );
                        }
                    }
                }
            } else {
                $errors[] = "No create results returned from API";
                put_program_logs( "API response did not contain 'create' results: " . json_encode( $response ) );
            }

        } catch (HttpClientException $e) {
            $error_msg = "Batch create API error: " . $e->getMessage();
            $errors[] = $error_msg;
            put_program_logs( $error_msg );
        } catch (Exception $e) {
            $error_msg = "Unexpected error during batch create: " . $e->getMessage();
            $errors[] = $error_msg;
            put_program_logs( $error_msg );
        }

        return [
            'success_count'    => $success_count,
            'errors'           => $errors,
            'created_products' => $created_products,
        ];
    }

    /**
     * Enhanced batch update with proper variation handling
     */
    private function batch_update_products_enhanced( $update_batch ) {
        $batch_data = [
            'update' => array_map( function ( $item ) {
                return array_merge( [ 'id' => $item['id'] ], $item['data'] );
            }, $update_batch ),
        ];

        $success_count = 0;
        $errors        = [];

        // log the update batch data
        // put_program_logs("update batch data: " . json_encode( $batch_data ) );

        try {
            $response = $this->client->post( 'products/batch', $batch_data );

            if ( isset( $response->update ) ) {
                foreach ( $response->update as $index => $result ) {
                    if ( isset( $result->id ) ) {
                        $success_count++;

                        // Handle variations for updated products with enhanced support
                        $original_product = $update_batch[$index]['original'];
                        $mapped_product   = $update_batch[$index]['mapped_product'];

                        if ( !empty( $mapped_product['variations'] ) ) {
                            $this->batch_update_variations_enhanced( $result->id, $mapped_product['variations'] );
                        }

                    } else {
                        $errors[] = "Failed to update product ID {$update_batch[$index]['id']}: " . ( $result->error->message ?? 'Unknown error' );
                    }
                }
            }

        } catch (HttpClientException $e) {
            $errors[] = "Batch update API error: " . $e->getMessage();
        }

        return [
            'success_count' => $success_count,
            'errors'        => $errors,
        ];
    }

    /**
     * Enhanced variation creation with better error handling
     */
    private function batch_create_variations_enhanced( $product_id, $variations ) {
        if ( empty( $variations ) ) {
            return;
        }

        // Process variations in smaller chunks for better reliability
        $chunk_size = 25;
        foreach ( array_chunk( $variations, $chunk_size ) as $chunk ) {
            $batch_data = [
                'create' => $chunk,
            ];

            try {
                $this->client->post( "products/{$product_id}/variations/batch", $batch_data );
            } catch (HttpClientException $e) {
                $this->log_message( "Enhanced variation creation failed for product {$product_id}: " . $e->getMessage() );

                // Fallback: try individual creation
                foreach ( $chunk as $variation ) {
                    try {
                        $this->client->post( "products/{$product_id}/variations", $variation );
                    } catch (HttpClientException $fallback_e) {
                        // Log but continue
                        $this->log_message( "Individual variation creation failed: " . $fallback_e->getMessage() );
                    }
                }
            }
        }
    }

    /**
     * Enhanced variation update with proper handling
     */
    private function batch_update_variations_enhanced( $product_id, $variations ) {
        // Get existing variations with better caching
        $existing_variations = $this->get_existing_variations_enhanced( $product_id );

        $update_batch = [];
        $create_batch = [];

        foreach ( $variations as $variation ) {
            $existing_id = $existing_variations[$variation['sku']] ?? null;

            if ( $existing_id ) {
                $update_batch[] = array_merge( [ 'id' => $existing_id ], $variation );
            } else {
                $create_batch[] = $variation;
            }
        }

        // Process updates
        if ( !empty( $update_batch ) ) {
            try {
                $this->client->post( "products/{$product_id}/variations/batch", [
                    'update' => $update_batch,
                ] );
            } catch (HttpClientException $e) {
                $this->log_message( "Enhanced variation update failed for product {$product_id}: " . $e->getMessage() );
            }
        }

        // Process creates
        if ( !empty( $create_batch ) ) {
            $this->batch_create_variations_enhanced( $product_id, $create_batch );
        }
    }

    /**
     * Enhanced get existing variations with caching
     */
    private function get_existing_variations_enhanced( $product_id ) {
        static $variations_cache = [];

        if ( isset( $variations_cache[$product_id] ) ) {
            return $variations_cache[$product_id];
        }

        global $wpdb;

        $query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value as sku 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_parent = %d 
             AND p.post_type = 'product_variation' 
             AND pm.meta_key = '_sku'
             AND p.post_status != 'trash'",
            $product_id
        );

        $results = $wpdb->get_results( $query );

        $variations = [];
        foreach ( $results as $result ) {
            $variations[$result->sku] = (int) $result->ID;
        }

        $variations_cache[$product_id] = $variations;
        return $variations;
    }

    /**
     * Update product IDs in image data after creation
     */
    private function update_image_product_ids( &$products_with_images, $created_products ) {
        foreach ( $products_with_images as &$image_data ) {
            if ( $image_data['product_id'] === 'new' ) {
                // Find matching created product by SKU or index
                foreach ( $created_products as $created ) {
                    if ( $created['sku'] === $image_data['original_product']->sku ) {
                        $image_data['product_id'] = $created['wc_id'];
                        break;
                    }
                }
            }
        }
    }

    /**
     * Schedule bulk image processing for products (with duplicate prevention)
     */
    private function schedule_bulk_image_processing( $products_with_images ) {
        // log the schedule bulk image processing data
        // put_program_logs("schedule bulk image processing data: " . json_encode( $products_with_images ) );

        foreach ( $products_with_images as $image_data ) {
            // Skip products that were marked as skipped due to category exclusion
            if ( isset( $image_data['is_skipped'] ) && $image_data['is_skipped'] === true ) {
                put_program_logs( "Skipping image processing for excluded product: " . ( $image_data['original_product']->sku ?? 'unknown' ) );
                continue; // Skip processing images for excluded products
            }
            
            // Only process images for products that actually exist and have valid product IDs
            if ( $image_data['product_id'] !== 'new' && 
                 is_numeric( $image_data['product_id'] ) && 
                 $image_data['product_id'] > 0 && 
                 !empty( $image_data['images'] ) ) {

                // Verify product actually exists before processing images
                if ( !$this->product_exists( $image_data['product_id'] ) ) {
                    put_program_logs( "Product ID {$image_data['product_id']} does not exist, skipping image processing" );
                    continue;
                }

                // Check if product already has images to prevent duplicates
                if ( $this->product_has_images( $image_data['product_id'] ) ) {
                    put_program_logs( "Product ID {$image_data['product_id']} already has images, skipping" );
                    continue; // Skip if product already has images
                }

                // Extract image URLs from the images payload
                $image_urls = [];
                foreach ( $image_data['images'] as $image ) {
                    if ( isset( $image['src'] ) ) {
                        $image_urls[] = $image['src'];
                    }
                }

                if ( !empty( $image_urls ) ) {
                    // Filter out images that already exist for this product
                    $new_image_urls = $this->filter_existing_images( $image_data['product_id'], $image_urls );

                    // log the new image urls data
                    // put_program_logs("new image urls data: " . json_encode( $new_image_urls ) );

                    if ( !empty( $new_image_urls ) ) {
                        // Schedule image processing with a small delay and better error handling
                        $scheduled = wp_schedule_single_event( time() + rand( 30, 120 ), 'wholesaler_process_images_batch', [
                            [
                                'product_id'    => $image_data['product_id'],
                                'images'        => $new_image_urls,
                                'batch_index'   => 0,
                                'total_batches' => 1,
                                'attempt_count' => 1, // Track attempts to prevent infinite loops
                            ],
                        ] );
                        
                        if ( $scheduled ) {
                            put_program_logs( "Scheduled image processing for product ID: {$image_data['product_id']} with " . count( $new_image_urls ) . " images" );
                        } else {
                            put_program_logs( "Failed to schedule image processing for product ID: {$image_data['product_id']}" );
                        }
                    } else {
                        put_program_logs( "No new images to process for product ID: {$image_data['product_id']}" );
                    }
                }
            }
        }
    }

    /**
     * Check if product exists in database
     */
    private function product_exists( $product_id ) {
        global $wpdb;
        
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'product' AND post_status != 'trash'",
            $product_id
        ) );
        
        return $exists > 0;
    }

    /**
     * Check if product already has images
     */
    private function product_has_images( $product_id ) {
        // Check for featured image
        $featured_image = get_post_thumbnail_id( $product_id );
        if ( $featured_image ) {
            return true;
        }

        // Check for gallery images
        $gallery_images = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( !empty( $gallery_images ) ) {
            return true;
        }

        return false;
    }

    /**
     * Filter out images that already exist for the product
     */
    private function filter_existing_images( $product_id, $image_urls ) {
        global $wpdb;

        // Get existing image URLs for this product
        $existing_urls = [];

        // Get featured image URL
        $featured_id = get_post_thumbnail_id( $product_id );
        if ( $featured_id ) {
            $existing_urls[] = wp_get_attachment_url( $featured_id );
        }

        // Get gallery image URLs
        $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
        if ( $gallery_ids ) {
            $gallery_array = explode( ',', $gallery_ids );
            foreach ( $gallery_array as $gallery_id ) {
                $url = wp_get_attachment_url( $gallery_id );
                if ( $url ) {
                    $existing_urls[] = $url;
                }
            }
        }

        // Also check by original URL metadata to catch previously imported images
        $existing_original_urls = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.meta_value 
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_original_url'
             AND p.post_parent = %d",
            $product_id
        ) );

        $existing_urls = array_merge( $existing_urls, $existing_original_urls );

        // Filter out URLs that already exist
        $new_urls = [];
        foreach ( $image_urls as $url ) {
            $url_basename = basename( $url );
            $url_exists   = false;

            foreach ( $existing_urls as $existing_url ) {
                if ( $existing_url === $url || basename( $existing_url ) === $url_basename ) {
                    $url_exists = true;
                    break;
                }
            }

            if ( !$url_exists ) {
                $new_urls[] = $url;
            }
        }

        return $new_urls;
    }

    /**
     * Handle bulk delete products with comprehensive cleanup
     */
    public function handle_bulk_delete_products( WP_REST_Request $request ) {
        $batch_size       = $request->get_param( 'batch_size' );
        $delete_images    = $request->get_param( 'delete_images' );
        $cleanup_database = $request->get_param( 'cleanup_database' );

        try {
            $result = $this->bulk_delete_products( $batch_size, $delete_images, $cleanup_database );
            return new WP_REST_Response( $result, 200 );
        } catch (Exception $e) {
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
                'success'            => true,
                'message'            => 'No products found to delete',
                'deleted_count'      => 0,
                'images_deleted'     => 0,
                'variations_deleted' => 0,
                'processing_time'    => 0,
            ];
        }

        $deleted_products   = 0;
        $deleted_images     = 0;
        $deleted_variations = 0;
        $errors             = [];

        // Separate main products from variations
        $main_products = [];
        $variations    = [];

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
            if ( !empty( $variations ) ) {
                $deleted_variations = $this->bulk_delete_variations( $variations );
            }

            // 3. Get all child variations for main products and delete them
            if ( !empty( $main_products ) ) {
                $child_variations = $this->get_all_child_variations( $main_products );
                if ( !empty( $child_variations ) ) {
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

            $end_time        = microtime( true );
            $processing_time = $end_time - $start_time;

            return [
                'success'            => true,
                'message'            => sprintf(
                    'Bulk delete completed. Deleted %d products, %d variations, %d images in %.2f seconds',
                    $deleted_products,
                    $deleted_variations,
                    $deleted_images,
                    $processing_time
                ),
                'deleted_count'      => $deleted_products,
                'variations_deleted' => $deleted_variations,
                'images_deleted'     => $deleted_images,
                'processing_time'    => round( $processing_time, 3 ),
                'errors'             => $errors,
            ];

        } catch (Exception $e) {
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
            $ids             = explode( ',', $gallery_string );
            $all_gallery_ids = array_merge( $all_gallery_ids, array_filter( $ids ) );
        }

        // Combine all image IDs
        $all_image_ids = array_unique( array_merge( $featured_images, $all_gallery_ids ) );

        // Delete images in chunks to avoid memory issues
        $deleted_count = 0;
        $chunk_size    = 50;

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

    /**
     * Get background job status
     */
    public function get_background_job_status( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'wholesaler_background_jobs';

        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $job_id
        ) );

        if ( !$job ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Job not found',
            ], 404 );
        }

        $job_data       = json_decode( $job->job_data, true );
        $error_messages = $job->error_messages ? json_decode( $job->error_messages, true ) : [];

        return new WP_REST_Response( [
            'success' => true,
            'job'     => [
                'id'              => $job->id,
                'status'          => $job->status,
                'progress'        => $job->progress,
                'total_processed' => $job->total_processed,
                'total_created'   => $job->total_created,
                'total_updated'   => $job->total_updated,
                'total_errors'    => $job->total_errors,
                'error_messages'  => $error_messages,
                'started_at'      => $job->started_at,
                'completed_at'    => $job->completed_at,
                'created_at'      => $job->created_at,
                'estimated_total' => $job_data['total_batches'] * $job_data['products_per_batch'],
                'configuration'   => [
                    'total_batches'      => $job_data['total_batches'],
                    'products_per_batch' => $job_data['products_per_batch'],
                    'process_images'     => $job_data['process_images'],
                    'update_existing'    => $job_data['update_existing'],
                ],
            ],
        ], 200 );
    }

    /**
     * Get background jobs list
     */
    public function get_background_jobs_list( WP_REST_Request $request ) {
        $status = $request->get_param( 'status' );
        $limit  = $request->get_param( 'limit' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'wholesaler_background_jobs';

        $where_clause = '';
        $params       = [];

        if ( $status !== 'all' ) {
            $where_clause = 'WHERE status = %s';
            $params[]     = $status;
        }

        $params[] = $limit;

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d",
            ...$params
        ) );

        $formatted_jobs = [];
        foreach ( $jobs as $job ) {
            $job_data       = json_decode( $job->job_data, true );
            $error_messages = $job->error_messages ? json_decode( $job->error_messages, true ) : [];

            $formatted_jobs[] = [
                'id'              => $job->id,
                'status'          => $job->status,
                'progress'        => $job->progress,
                'total_processed' => $job->total_processed,
                'total_created'   => $job->total_created,
                'total_updated'   => $job->total_updated,
                'total_errors'    => $job->total_errors,
                'error_count'     => count( $error_messages ),
                'started_at'      => $job->started_at,
                'completed_at'    => $job->completed_at,
                'created_at'      => $job->created_at,
                'estimated_total' => $job_data['total_batches'] * $job_data['products_per_batch'],
                'duration'        => $job->completed_at && $job->started_at ?
                    strtotime( $job->completed_at ) - strtotime( $job->started_at ) : null
            ];
        }

        // Get summary statistics
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_jobs,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(total_processed) as total_products_processed,
                SUM(total_created) as total_products_created,
                SUM(total_updated) as total_products_updated
             FROM {$table_name}",
            ARRAY_A
        );

        return new WP_REST_Response( [
            'success' => true,
            'jobs'    => $formatted_jobs,
            'stats'   => $stats,
            'filter'  => [
                'status' => $status,
                'limit'  => $limit,
            ],
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
add_action( 'wholesaler_background_import', function ( $job_id, $job_data ) {
    $batch_import = new Wholesaler_Batch_Import(
        site_url(),
        get_option( 'wholesaler_consumer_key', '' ),
        get_option( 'wholesaler_consumer_secret', '' )
    );

    $batch_import->process_background_job( $job_id, $job_data );
}, 10, 2 );

// Register stuck job checker hook
add_action( 'wholesaler_check_stuck_jobs', function () {
    $batch_import = new Wholesaler_Batch_Import(
        site_url(),
        get_option( 'wholesaler_consumer_key', '' ),
        get_option( 'wholesaler_consumer_secret', '' )
    );

    $batch_import->check_stuck_jobs();
} );

// Add custom cron schedule for every minute
add_filter( 'cron_schedules', function ( $schedules ) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => __( 'Every Minute' ),
    ];
    return $schedules;
} );

// Manual trigger endpoint for testing
add_action( 'rest_api_init', function () {
    register_rest_route( 'wholesaler/v1', '/trigger-stuck-jobs', [
        'methods'             => 'POST',
        'callback'            => function () {
            $batch_import = new Wholesaler_Batch_Import(
                site_url(),
                get_option( 'wholesaler_consumer_key', '' ),
                get_option( 'wholesaler_consumer_secret', '' )
            );

            $batch_import->check_stuck_jobs();

            return new WP_REST_Response( [
                'success' => true,
                'message' => 'Stuck jobs check triggered',
            ], 200 );
        },
        'permission_callback' => '__return_true',
    ] );
} );
