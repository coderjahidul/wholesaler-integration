<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

require_once __DIR__ . '/traits/logs.php';

/**
 * Wholesaler Stock Cleanup Class
 * Handles removal of products with low/out of stock based on specific rules
 */
class Wholesaler_Stock_Cleanup {

    use Wholesaler_Logs_Trait;

    private $batch_size = 50;
    private $bras_category_id = null;

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Status endpoint for stock cleanup jobs
        register_rest_route( 'wholesaler/v1', '/stock-cleanup-status/(?P<job_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_job_status' ],
            'permission_callback' => '__return_true',
        ] );

        // List all stock cleanup jobs
        register_rest_route( 'wholesaler/v1', '/stock-cleanup-jobs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_jobs_list' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'status' => [
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'default'           => 20,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    /**
     * Start the background cleanup job
     */
    public function start_cleanup_job( $batch_size = 50 ) {
        $this->batch_size = $batch_size;

        // Create background job
        $job_data = [
            'batch_size'  => $batch_size,
            'started_at'  => current_time( 'mysql' ),
            'status'      => 'scheduled',
            'job_type'    => 'stock_cleanup'
        ];

        $job_id = $this->create_cleanup_job( $job_data );

        // Schedule background processing
        $scheduled = wp_schedule_single_event( time() + 5, 'wholesaler_stock_cleanup_process', [ $job_id, $job_data ] );

        // Fallback scheduling
        if ( ! $scheduled ) {
            wp_schedule_single_event( time() + 10, 'wholesaler_stock_cleanup_process', [ $job_id, $job_data ] );
        }

        return [
            'success'   => true,
            'message'   => 'Stock cleanup job scheduled successfully',
            'job_id'    => $job_id,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * Process the stock cleanup job
     */
    public function process_cleanup_job( $job_id, $job_data ) {
        global $wpdb;

        // stock cleanup jobs table
        $table_name = $wpdb->prefix . 'wholesaler_stock_cleanup_jobs';

        try {
            // Update job status to running
            $wpdb->update(
                $table_name,
                [
                    'status'     => 'running',
                    'started_at' => current_time( 'mysql' )
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            // batch size
            $batch_size = $job_data['batch_size'] ?? 50;

            // Get the BRAS category ID
            $this->bras_category_id = $this->get_bras_category_id();

            // initialize variables
            $total_removed = 0;
            $total_processed = 0;
            $errors = [];
            $removed_products = [];
            $deletion_stats = [
                'total_images_deleted' => 0,
                'total_variations_deleted' => 0,
                'total_meta_deleted' => 0,
                'total_terms_deleted' => 0
            ];

            // Process in batches until no more products need removal
            $has_more = true;
            $max_iterations = 100; // Safety limit
            $iterations = 0;

            while ( $has_more && $iterations < $max_iterations ) {

                // get products to remove
                $products_to_remove = $this->get_products_to_remove( $batch_size );
                // put to log
                put_program_logs( "Products to remove: " . json_encode( $products_to_remove ) );

                // if no products to remove, break
                if ( empty( $products_to_remove ) ) {
                    // put to log
                    put_program_logs( "No products to remove" );
                    $has_more = false;
                    break;
                }

                foreach ( $products_to_remove as $product_info ) {
                    // put to log
                    put_program_logs( "Processing product: " . json_encode( $product_info ) );

                    $total_processed++;

                    try {
                        $result = $this->remove_product( $product_info['product_id'] );

                        if ( $result['success'] ) {
                            $total_removed++;
                            
                            // Accumulate deletion stats
                            if ( isset( $result['deleted_items'] ) ) {
                                $deletion_stats['total_images_deleted'] += $result['deleted_items']['images'];
                                $deletion_stats['total_variations_deleted'] += $result['deleted_items']['variations'];
                                $deletion_stats['total_meta_deleted'] += $result['deleted_items']['meta'];
                                $deletion_stats['total_terms_deleted'] += $result['deleted_items']['terms'];
                            }
                            
                            $removed_products[] = [
                                'id'            => $product_info['product_id'],
                                'name'          => $product_info['name'],
                                'reason'        => $product_info['reason'],
                                'deleted_items' => $result['deleted_items'] ?? []
                            ];
                        } else {
                            $errors[] = "Failed to remove product ID {$product_info['product_id']}: {$result['message']}";
                        }
                    } catch ( Exception $e ) {
                        $errors[] = "Error removing product ID {$product_info['product_id']}: " . $e->getMessage();
                    }

                    // Update progress
                    $wpdb->update(
                        $table_name,
                        [
                            'total_processed' => $total_processed,
                            'total_removed'   => $total_removed,
                            'total_errors'    => count( $errors )
                        ],
                        [ 'id' => $job_id ],
                        [ '%d', '%d', '%d' ],
                        [ '%d' ]
                    );
                }

                $iterations++;

                // Small delay between batches
                sleep( 1 );
            }

            // Mark job as completed
            $wpdb->update(
                $table_name,
                [
                    'status'           => 'completed',
                    'total_processed'  => $total_processed,
                    'total_removed'    => $total_removed,
                    'total_errors'     => count( $errors ),
                    'error_messages'   => wp_json_encode( $errors ),
                    'removed_products' => wp_json_encode( $removed_products ),
                    'deletion_stats'   => wp_json_encode( $deletion_stats ),
                    'completed_at'     => current_time( 'mysql' )
                ],
                [ 'id' => $job_id ],
                [ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            $this->log_message( sprintf(
                "Stock cleanup job %d completed. Removed: %d products, %d images, %d variations, %d meta entries, %d term relationships. Errors: %d",
                $job_id,
                $total_removed,
                $deletion_stats['total_images_deleted'],
                $deletion_stats['total_variations_deleted'],
                $deletion_stats['total_meta_deleted'],
                $deletion_stats['total_terms_deleted'],
                count( $errors )
            ) );

        } catch ( Exception $e ) {
            // Mark job as failed
            $wpdb->update(
                $table_name,
                [
                    'status'         => 'failed',
                    'error_messages' => wp_json_encode( [ $e->getMessage() ] ),
                    'completed_at'   => current_time( 'mysql' )
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );

            $this->log_message( "Stock cleanup job {$job_id} failed: " . $e->getMessage() );
        }
    }

    /**
     * Get products that need to be removed based on stock rules
     */
    private function get_products_to_remove( $limit = 50 ) {
        global $wpdb;

        $products_to_remove = [];

        // Get all variable products with their variations
        $query = "
            SELECT 
                p.ID as product_id,
                p.post_title as name,
                GROUP_CONCAT(DISTINCT pm_stock.meta_value) as stock_quantities,
                COUNT(DISTINCT v.ID) as variation_count,
                SUM(CAST(pm_stock.meta_value AS UNSIGNED)) as total_stock
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->posts} v ON v.post_parent = p.ID AND v.post_type = 'product_variation'
            LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = v.ID AND pm_stock.meta_key = '_stock'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            GROUP BY p.ID
            HAVING total_stock IS NOT NULL
            LIMIT %d
        ";

        // put the query with limit
        put_program_logs( "Query get_products_to_remove: " . $wpdb->prepare( $query, $limit * 3 ) );

        $results = $wpdb->get_results( $wpdb->prepare( $query, $limit * 3 ) ); // Get more to filter
        // put to log
        put_program_logs( "Results get_products_to_remove: " . json_encode( $results ) );

        foreach ( $results as $row ) {
            $product_id = $row->product_id;
            $total_stock = (int) $row->total_stock;
            $variation_count = (int) $row->variation_count;

            // Rule 1 & 2: If total stock across all variations is 1 or less
            if ( $total_stock <= 1 ) {
                $products_to_remove[] = [
                    'product_id' => $product_id,
                    'name'       => $row->name,
                    'reason'     => "Total stock ({$total_stock}) across all variations is 1 or less",
                    'priority'   => 1
                ];
                continue;
            }

            // Rule 3: BRAS category - fewer than 5 pieces
            if ( $this->bras_category_id && $this->product_in_bras_category( $product_id ) ) {
                if ( $total_stock <= 5 ) {
                    $products_to_remove[] = [
                        'product_id' => $product_id,
                        'name'       => $row->name,
                        'reason'     => "BRAS category product with stock ({$total_stock}) less than 5 pieces",
                        'priority'   => 2
                    ];
                    continue;
                }
            }

            // Stop if we have enough products
            if ( count( $products_to_remove ) >= $limit ) {
                break;
            }
        }

        // Sort by priority (lower number = higher priority)
        usort( $products_to_remove, function( $a, $b ) {
            return $a['priority'] - $b['priority'];
        });

        return array_slice( $products_to_remove, 0, $limit );
    }

    /**
     * Check if product is in BRAS category
     */
    private function product_in_bras_category( $product_id ) {
        if ( ! $this->bras_category_id ) {
            return false;
        }

        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );

        if ( is_wp_error( $terms ) ) {
            return false;
        }

        return in_array( $this->bras_category_id, $terms );
    }

    /**
     * Get BRAS category ID by slug or name
     */
    private function get_bras_category_id() {
        // Try multiple variations of BRAS category name
        $category_searches = [ 'bras', 'bra', 'sostén', 'sutiã', 'soutien' ];

        foreach ( $category_searches as $search ) {
            // Try slug first
            $term = get_term_by( 'slug', $search, 'product_cat' );

            if ( $term ) {
                return $term->term_id;
            }

            // Try name
            $term = get_term_by( 'name', $search, 'product_cat' );

            if ( $term ) {
                return $term->term_id;
            }

            // Try case-insensitive search
            $terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'name__like' => $search
            ]);

            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                return $terms[0]->term_id;
            }
        }

        $this->log_message( "BRAS category not found. Searched for: " . implode( ', ', $category_searches ) );

        return null;
    }

    /**
     * Remove a product and all associated data comprehensively
     * Includes: images, variations, meta data, term relationships, WC lookup tables
     */
    private function remove_product( $product_id ) {
        global $wpdb;
        
        try {
            // Get product
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                return [
                    'success' => false,
                    'message' => 'Product not found'
                ];
            }

            $product_name = $product->get_name();
            $deleted_items = [
                'images' => 0,
                'variations' => 0,
                'meta' => 0,
                'terms' => 0
            ];

            // 1. Get all child variations
            $variation_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                 WHERE post_parent = %d 
                 AND post_type = 'product_variation'",
                $product_id
            ) );

            // 2. Delete images from main product
            $deleted_items['images'] += $this->delete_product_images( $product_id );

            // 3. Delete images and data from all variations
            if ( ! empty( $variation_ids ) ) {
                foreach ( $variation_ids as $variation_id ) {
                    // Delete variation images
                    $deleted_items['images'] += $this->delete_product_images( $variation_id );
                    
                    // Delete variation post meta
                    $meta_deleted = $wpdb->delete( 
                        $wpdb->postmeta, 
                        [ 'post_id' => $variation_id ], 
                        [ '%d' ] 
                    );
                    $deleted_items['meta'] += $meta_deleted ? $meta_deleted : 0;
                    
                    // Delete variation post
                    $wpdb->delete( 
                        $wpdb->posts, 
                        [ 'ID' => $variation_id ], 
                        [ '%d' ] 
                    );
                    
                    $deleted_items['variations']++;
                }
            }

            // 4. Delete term relationships (categories, tags, attributes)
            $terms_deleted = $wpdb->delete( 
                $wpdb->term_relationships, 
                [ 'object_id' => $product_id ], 
                [ '%d' ] 
            );
            $deleted_items['terms'] = $terms_deleted ? $terms_deleted : 0;

            // 5. Delete product meta
            $meta_deleted = $wpdb->delete( 
                $wpdb->postmeta, 
                [ 'post_id' => $product_id ], 
                [ '%d' ] 
            );
            $deleted_items['meta'] += $meta_deleted ? $meta_deleted : 0;

            // 6. Delete from WooCommerce lookup tables
            $this->cleanup_wc_lookup_tables( array_merge( [ $product_id ], $variation_ids ) );

            // 7. Delete comments/reviews
            $wpdb->delete( 
                $wpdb->comments, 
                [ 'comment_post_ID' => $product_id ], 
                [ '%d' ] 
            );

            // 8. Finally, delete the main product post
            $result = $wpdb->delete( 
                $wpdb->posts, 
                [ 'ID' => $product_id ], 
                [ '%d' ] 
            );

            if ( $result ) {
                $this->log_message( sprintf(
                    "Removed product ID %d (%s) - Deleted: %d images, %d variations, %d meta entries, %d term relationships",
                    $product_id,
                    $product_name,
                    $deleted_items['images'],
                    $deleted_items['variations'],
                    $deleted_items['meta'],
                    $deleted_items['terms']
                ) );

                return [
                    'success' => true,
                    'message' => 'Product and all associated data removed successfully',
                    'deleted_items' => $deleted_items
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to delete product post'
                ];
            }

        } catch ( Exception $e ) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete all images associated with a product or variation
     */
    private function delete_product_images( $post_id ) {
        global $wpdb;
        
        $deleted_count = 0;

        // Get featured image
        $featured_image_id = get_post_thumbnail_id( $post_id );
        if ( $featured_image_id ) {
            if ( wp_delete_attachment( $featured_image_id, true ) ) {
                $deleted_count++;
            }
        }

        // Get gallery images
        $gallery_images = get_post_meta( $post_id, '_product_image_gallery', true );
        if ( ! empty( $gallery_images ) ) {
            $gallery_ids = explode( ',', $gallery_images );
            foreach ( $gallery_ids as $image_id ) {
                $image_id = trim( $image_id );
                if ( $image_id && wp_delete_attachment( $image_id, true ) ) {
                    $deleted_count++;
                }
            }
        }

        return $deleted_count;
    }

    /**
     * Clean up WooCommerce lookup tables
     */
    private function cleanup_wc_lookup_tables( $product_ids ) {
        if ( empty( $product_ids ) ) {
            return;
        }

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        // WooCommerce product meta lookup table
        $table = $wpdb->prefix . 'wc_product_meta_lookup';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE product_id IN ($placeholders)",
                ...$product_ids
            ) );
        }

        // WooCommerce product attributes lookup table
        $table = $wpdb->prefix . 'wc_product_attributes_lookup';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE product_id IN ($placeholders)",
                ...$product_ids
            ) );
        }

        // WooCommerce order product lookup table (if exists)
        $table = $wpdb->prefix . 'wc_order_product_lookup';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE product_id IN ($placeholders)",
                ...$product_ids
            ) );
        }
    }

    /**
     * Create cleanup job in database
     */
    private function create_cleanup_job( $job_data ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wholesaler_stock_cleanup_jobs';

        // Create table if it doesn't exist
        $this->create_cleanup_jobs_table();

        $wpdb->insert(
            $table_name,
            [
                'job_data'   => wp_json_encode( $job_data ),
                'status'     => 'scheduled',
                'created_at' => current_time( 'mysql' )
            ],
            [ '%s', '%s', '%s' ]
        );

        return $wpdb->insert_id;
    }

    /**
     * Create cleanup jobs table
     */
    private function create_cleanup_jobs_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wholesaler_stock_cleanup_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_data longtext NOT NULL,
            status varchar(20) DEFAULT 'scheduled',
            total_processed int(11) DEFAULT 0,
            total_removed int(11) DEFAULT 0,
            total_errors int(11) DEFAULT 0,
            error_messages longtext NULL,
            removed_products longtext NULL,
            deletion_stats longtext NULL,
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
     * Get job status
     */
    public function get_job_status( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'wholesaler_stock_cleanup_jobs';

        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $job_id
        ) );

        if ( ! $job ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Job not found'
            ], 404 );
        }

        $job_data = json_decode( $job->job_data, true );
        $error_messages = $job->error_messages ? json_decode( $job->error_messages, true ) : [];
        $removed_products = $job->removed_products ? json_decode( $job->removed_products, true ) : [];
        $deletion_stats = $job->deletion_stats ? json_decode( $job->deletion_stats, true ) : [];

        return new WP_REST_Response( [
            'success' => true,
            'job'     => [
                'id'               => $job->id,
                'status'           => $job->status,
                'total_processed'  => $job->total_processed,
                'total_removed'    => $job->total_removed,
                'total_errors'     => $job->total_errors,
                'error_messages'   => $error_messages,
                'removed_products' => $removed_products,
                'deletion_stats'   => $deletion_stats,
                'started_at'       => $job->started_at,
                'completed_at'     => $job->completed_at,
                'created_at'       => $job->created_at,
                'configuration'    => [
                    'batch_size' => $job_data['batch_size'] ?? 50,
                ]
            ]
        ], 200 );
    }

    /**
     * Get jobs list
     */
    public function get_jobs_list( WP_REST_Request $request ) {
        $status = $request->get_param( 'status' );
        $limit = $request->get_param( 'limit' );

        global $wpdb;
        $table_name = $wpdb->prefix . 'wholesaler_stock_cleanup_jobs';

        $where_clause = '';
        $params = [];

        if ( $status !== 'all' ) {
            $where_clause = 'WHERE status = %s';
            $params[] = $status;
        }

        $params[] = $limit;

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} {$where_clause} ORDER BY created_at DESC LIMIT %d",
            ...$params
        ) );

        $formatted_jobs = [];
        foreach ( $jobs as $job ) {
            $job_data = json_decode( $job->job_data, true );
            $error_messages = $job->error_messages ? json_decode( $job->error_messages, true ) : [];

            $formatted_jobs[] = [
                'id'              => $job->id,
                'status'          => $job->status,
                'total_processed' => $job->total_processed,
                'total_removed'   => $job->total_removed,
                'total_errors'    => $job->total_errors,
                'error_count'     => count( $error_messages ),
                'started_at'      => $job->started_at,
                'completed_at'    => $job->completed_at,
                'created_at'      => $job->created_at,
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
                SUM(total_removed) as total_products_removed
             FROM {$table_name}",
            ARRAY_A
        );

        return new WP_REST_Response( [
            'success' => true,
            'jobs'    => $formatted_jobs,
            'stats'   => $stats,
            'filter'  => [
                'status' => $status,
                'limit'  => $limit
            ]
        ], 200 );
    }
}

// Initialize the class
new Wholesaler_Stock_Cleanup();

// Register background processing hook
add_action( 'wholesaler_stock_cleanup_process', function( $job_id, $job_data ) {
    $cleanup = new Wholesaler_Stock_Cleanup();
    $cleanup->process_cleanup_job( $job_id, $job_data );
}, 10, 2 );

