<?php
// File: wholesaler-brands-api.php (inside your plugin)

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Wholesaler_Reset_API {

    private $file_path;

    public function __construct() {
        $this->file_path = plugin_dir_path( __FILE__ ) . 'data/brands.json';

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        // Export brands
        register_rest_route( 'wholesaler/v1', '/get-brands', [
            'methods'  => 'GET',
            'callback' => [ $this, 'get_brands' ],
            'permission_callback' => "__return_true"
        ] );

        // Import/seed brands
        register_rest_route( 'wholesaler/v1', '/seed-brands', [
            'methods'  => 'POST',
            'callback' => [ $this, 'seed_brands' ],
            'permission_callback' => "__return_true"
        ] );

        // remove out of stock and less stock products
        register_rest_route( 'wholesaler/v1', '/remove-out-of-stock-products', [
            'methods'  => 'POST',
            'callback' => [ $this, 'remove_out_of_stock_products' ],
            'permission_callback' => "__return_true",
            'args' => [
                'batch_size' => [
                    'default'           => 50,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($param) {
                        return is_numeric( $param ) && $param > 0 && $param <= 200;
                    }
                ]
            ]
        ] );
    }

    /**
     * Export brands from live site and store into brands.json
     */
    public function get_brands( $request ) {
        $terms = get_terms( [
            'taxonomy'   => 'product_brand',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) ) {
            return [
                'status'  => 'error',
                'message' => $terms->get_error_message(),
            ];
        }

        $brands = [];
        foreach ( $terms as $term ) {
            $brands[] = [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        // Ensure data dir exists
        $dir = dirname( $this->file_path );
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        // Write to file
        file_put_contents( $this->file_path, wp_json_encode( $brands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

        return [
            'status' => 'success',
            'count'  => count( $brands ),
            'file'   => $this->file_path,
            'brands' => $brands,
        ];
    }

    /**
     * Seed brands into local site from brands.json
     */
    public function seed_brands( $request ) {
        if ( ! file_exists( $this->file_path ) ) {
            return [
                'status'  => 'error',
                'message' => 'brands.json not found. Run /get-brands first on client site and copy the file.',
            ];
        }

        $brands = json_decode( file_get_contents( $this->file_path ), true );
        if ( empty( $brands ) ) {
            return [
                'status'  => 'error',
                'message' => 'brands.json is empty or invalid.',
            ];
        }

        $inserted = [];
        foreach ( $brands as $brand ) {
            $name = $brand['name'];
            $term = term_exists( $name, 'product_brand' );

            if ( ! $term ) {
                $new_term = wp_insert_term( $name, 'product_brand', [
                    'slug' => sanitize_title( $name )
                ] );
                if ( ! is_wp_error( $new_term ) ) {
                    $inserted[] = $name;
                }
            }
        }

        return [
            'status'   => 'success',
            'inserted' => $inserted,
            'count'    => count( $inserted ),
        ];
    }

    /**
     * Remove out of stock and less stock products
     * Triggers a background job to process product removal based on stock rules:
     * 1. Products with 1 or fewer pieces across all variations
     * 2. Products that dropped to just one piece (same as rule 1)
     * 3. BRAS category products with fewer than 5 pieces
     */
    public function remove_out_of_stock_products( $request ) {
        // Load the stock cleanup class
        require_once plugin_dir_path( __FILE__ ) . 'class-wholesaler-stock-cleanup.php';
        
        // Get batch size from request (default: 50)
        $batch_size = $request->get_param( 'batch_size' ) ?? 50;
        $batch_size = absint( $batch_size );
        
        if ( $batch_size < 1 || $batch_size > 200 ) {
            return new WP_REST_Response( [
                'status'  => 'error',
                'message' => 'Batch size must be between 1 and 200',
            ], 400 );
        }
        
        // Start the cleanup job
        $cleanup = new Wholesaler_Stock_Cleanup();
        $result = $cleanup->start_cleanup_job( $batch_size );
        
        return new WP_REST_Response( $result, 200 );
    }
}

new Wholesaler_Reset_API();
