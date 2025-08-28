<?php


defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

if ( file_exists( WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php' ) ) {
    require_once WHOLESALER_PLUGIN_PATH . '/vendor/autoload.php';
}

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class Wholesaler_Integration_Import_Products {

    private $client;
    private $table_name;
    private $product_type = 'variable'; // enum: simple, variable

    public function __construct( string $website_url, string $consumer_key, string $consumer_secret ) {
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
    }

    /**
     * Log message to file
     */
    private function log_message( $data ) {
        // Ensure the directory for logs exists
        $directory = __DIR__ . '/../program_logs/';
        if ( !file_exists( $directory ) ) {
            // Use wp_mkdir_p instead of mkdir
            if ( !wp_mkdir_p( $directory ) ) {
                return "Failed to create directory.";
            }
        }

        // Construct the log file path
        $file_name = $directory . 'import_products.log';

        // Append the current datetime to the log entry
        $current_datetime = gmdate( 'Y-m-d H:i:s' ); // Use gmdate instead of date
        $data             = $data . ' - ' . $current_datetime;

        // Write the log entry to the file
        if ( file_put_contents( $file_name, $data . "\n\n", FILE_APPEND | LOCK_EX ) !== false ) {
            return "Data appended to file successfully.";
        } else {
            return "Failed to append data to file.";
        }
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
        } );
    }

    /**
     * Handle REST API request
     */
    public function handle_rest_api_request( $request ) {
        $limit = $request->get_param( 'limit' );

        try {
            $this->log_message( "REST API request received with limit: {$limit}" );
            $result = $this->import_products_to_woocommerce( $limit );
            return new \WP_REST_Response( $result, 200 );
        } catch (Exception $e) {
            $this->log_message( "REST API error: " . $e->getMessage() );
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
            $products_table = $wpdb->prefix . 'sync_wholesaler_products_data';

            // SQL query
            $sql = $wpdb->prepare( "SELECT * FROM {$products_table} WHERE status = %s LIMIT %d", 'Pending', $limit );

            // Retrieve pending products from the database
            $products = $wpdb->get_results( $sql );

            if ( empty( $products ) ) {
                $this->log_message( "No pending products found in database" );
                return [];
            }

            $this->log_message( "Found " . count( $products ) . " pending products in database" );
            return $products;

        } catch (Exception $e) {
            $this->log_message( "Database error: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Import products to WooCommerce using REST API
     */
    public function import_products_to_woocommerce( $limit = 1 ) {
        try {
            $this->log_message( "Starting product import process with limit: {$limit}" );

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
                wp_die($product);

                
                try {
                    $this->log_message( "Processing product ID: {$product->id}" );

                    // Import single product
                    $result = $this->import_single_product( $product );

                    if ( $result['success'] ) {
                        $imported_count++;
                        $this->mark_as_complete( $product->id );
                        $this->log_message( "Successfully imported product ID: {$product->id}" );
                    } else {
                        $errors[] = "Product ID {$product->id}: " . $result['message'];
                        $this->log_message( "Failed to import product ID {$product->id}: " . $result['message'] );
                    }

                } catch (Exception $e) {
                    $error_msg = "Product ID {$product->id}: " . $e->getMessage();
                    $errors[]  = $error_msg;
                    $this->log_message( $error_msg );
                }
            }

            $this->log_message( "Import process completed. Imported: {$imported_count}, Errors: " . count( $errors ) );

            return [
                'success'  => true,
                'message'  => "Import completed. Imported: {$imported_count}",
                'imported' => $imported_count,
                'errors'   => $errors,
            ];

        } catch (Exception $e) {
            $this->log_message( "Import process failed: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Import a single product to WooCommerce
     */
    private function import_single_product( $product ) {
        try {
            // Retrieve product data
            $serial_id     = $product->id;
            $sku           = $this->get_product_sku( $product );
            $title         = $this->get_product_title( $product );
            $description   = $this->get_product_description( $product );
            $quantity      = $this->get_product_quantity( $product );
            $images        = $this->get_product_images( $product );
            $category      = $this->get_product_category( $product );
            $tags          = $this->get_product_tags( $product );
            $regular_price = $this->get_product_regular_price( $product );
            $sale_price    = $this->get_product_sale_price( $product );

            // Check if product already exists
            $existing_product_id = $this->check_product_exists( $sku );

            if ( $existing_product_id ) {
                return $this->update_existing_product( $existing_product_id, $product, $quantity, $regular_price, $sale_price );
            } else {
                return $this->create_new_product( $product, $quantity, $regular_price, $sale_price );
            }

        } catch (Exception $e) {
            $this->log_message( "Error importing product {$product->id}: " . $e->getMessage() );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if product already exists in WooCommerce
     */
    private function check_product_exists( $sku ) {
        if ( empty( $sku ) ) {
            return false;
        }

        $args = array(
            'post_type'      => 'product',
            'meta_query'     => array(
                array(
                    'key'     => '_sku',
                    'value'   => $sku,
                    'compare' => '=',
                ),
            ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
        );

        $existing_products = new WP_Query( $args );

        if ( $existing_products->have_posts() ) {
            return $existing_products->posts[0];
        }

        return false;
    }

    /**
     * Update existing product in WooCommerce
     */
    private function update_existing_product( $product_id, $product, $quantity, $regular_price, $sale_price ) {
        try {
            $this->log_message( "Updating existing product ID: {$product_id}" );

            $product_data = [
                'name'        => $this->get_product_title( $product ),
                'sku'         => $this->get_product_sku( $product ),
                'type'        => $this->product_type,
                'description' => $this->get_product_description( $product ),
                'attributes'  => [],
            ];

            // Update product via API
            $this->client->put( 'products/' . $product_id, $product_data );

            // Update product stock
            $this->handle_product_stock( $product_id, $quantity );

            // Update product prices
            update_post_meta( $product_id, '_regular_price', $regular_price );
            update_post_meta( $product_id, '_price', $sale_price );

            // Update product category and tags
            $this->update_product_taxonomies( $product_id, $product );

            // Update product images
            $images = $this->get_product_images( $product );
            if ( !empty( $images ) ) {
                $this->set_product_images( $product_id, $images );
            }

            $this->log_message( "Successfully updated product ID: {$product_id}" );

            return [
                'success'    => true,
                'message'    => 'Product updated successfully',
                'product_id' => $product_id,
            ];

        } catch (Exception $e) {
            $this->log_message( "Error updating product {$product_id}: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Create new product in WooCommerce
     */
    private function create_new_product( $product, $quantity, $regular_price, $sale_price ) {
        try {
            $this->log_message( "Creating new product" );

            $product_data = [
                'name'        => $this->get_product_title( $product ),
                'sku'         => $this->get_product_sku( $product ),
                'type'        => $this->product_type,
                'description' => $this->get_product_description( $product ),
                'attributes'  => [],
            ];

            // Create the product via API
            $wc_product = $this->client->post( 'products', $product_data );
            $product_id = $wc_product->id;

            // Set product information
            wp_set_object_terms( $product_id, $this->product_type, 'product_type' );
            update_post_meta( $product_id, '_visibility', 'visible' );

            // Update product prices
            update_post_meta( $product_id, '_regular_price', $regular_price );
            update_post_meta( $product_id, '_price', $sale_price );

            // Update product category and tags
            $this->update_product_taxonomies( $product_id, $product );

            // Update product stock
            $this->handle_product_stock( $product_id, $quantity );

            // Set product images
            $images = $this->get_product_images( $product );
            if ( !empty( $images ) ) {
                $this->set_product_images( $product_id, $images );
            }

            $this->log_message( "Successfully created product ID: {$product_id}" );

            return [
                'success'    => true,
                'message'    => 'Product created successfully',
                'product_id' => $product_id,
            ];

        } catch (Exception $e) {
            $this->log_message( "Error creating product: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Update product taxonomies (category and tags)
     */
    private function update_product_taxonomies( $product_id, $product ) {
        $category = $this->get_product_category( $product );
        $tags     = $this->get_product_tags( $product );

        if ( !empty( $category ) ) {
            wp_set_object_terms( $product_id, $category, 'product_cat' );
        }

        if ( !empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, 'product_tag' );
        }
    }

    /**
     * Handle product stock
     */
    private function handle_product_stock( $product_id, $quantity ) {
        update_post_meta( $product_id, '_stock', $quantity );
        update_post_meta( $product_id, '_manage_stock', 'yes' );

        if ( $quantity <= 0 ) {
            update_post_meta( $product_id, '_stock_status', 'outofstock' );
        } else {
            update_post_meta( $product_id, '_stock_status', 'instock' );
        }
    }

    /**
     * Set product images
     */
    private function set_product_images( $product_id, $images ) {
        if ( empty( $images ) || !is_array( $images ) ) {
            return;
        }

        try {
            $gallery_ids = [];
            $first_image = true;

            foreach ( $images as $image_url ) {
                $attachment_id = $this->download_and_attach_image( $image_url, $product_id );

                if ( $attachment_id ) {
                    $gallery_ids[] = $attachment_id;

                    // Set the first image as the featured image
                    if ( $first_image ) {
                        set_post_thumbnail( $product_id, $attachment_id );
                        $first_image = false;
                    }
                }
            }

            // Update the product gallery meta field
            if ( !empty( $gallery_ids ) ) {
                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
            }

        } catch (Exception $e) {
            $this->log_message( "Error setting product images for product {$product_id}: " . $e->getMessage() );
        }
    }

    /**
     * Download and attach image to product
     */
    private function download_and_attach_image( $image_url, $product_id ) {
        try {
            // Extract image name and generate a unique name
            $image_name        = basename( $image_url );
            $unique_image_name = $product_id . '-' . time() . '-' . $image_name;

            // Get WordPress upload directory
            $upload_dir = wp_upload_dir();

            // Download the image from URL
            $image_data = file_get_contents( $image_url );

            if ( $image_data === false ) {
                throw new Exception( "Failed to download image: {$image_url}" );
            }

            $image_file      = $upload_dir['path'] . '/' . $unique_image_name;
            $file_put_result = file_put_contents( $image_file, $image_data );

            if ( $file_put_result === false ) {
                throw new Exception( "Failed to save image to: {$image_file}" );
            }

            // Prepare image data to be attached to the product
            $file_path = $image_file;
            $file_name = basename( $file_path );

            // Insert the image as an attachment
            $attachment = [
                'post_mime_type' => mime_content_type( $file_path ),
                'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];

            $attach_id = wp_insert_attachment( $attachment, $file_path, $product_id );

            if ( is_wp_error( $attach_id ) ) {
                throw new Exception( "Failed to create attachment: " . $attach_id->get_error_message() );
            }

            // Generate attachment metadata
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
            wp_update_attachment_metadata( $attach_id, $attach_data );

            return $attach_id;

        } catch (Exception $e) {
            $this->log_message( "Error downloading/attaching image {$image_url}: " . $e->getMessage() );
            return false;
        }
    }

    /**
     * Mark product as complete in database
     */
    public function mark_as_complete( int $id ) {
        try {
            global $wpdb;

            $table_prefix   = get_option( 'be-table-prefix' ) ?? '';
            $products_table = $wpdb->prefix . $table_prefix . 'sync_products';

            $result = $wpdb->update(
                $products_table,
                [ 'status' => 'completed' ],
                [ 'id' => $id ],
                [ '%s' ],
                [ '%d' ]
            );

            if ( $result === false ) {
                throw new Exception( "Failed to update product status in database" );
            }

            $this->log_message( "Product ID {$id} marked as completed" );
            return true;

        } catch (Exception $e) {
            $this->log_message( "Error marking product {$id} as complete: " . $e->getMessage() );
            return false;
        }
    }

    // Helper methods to extract product data
    private function get_product_sku( $product ) {
        return isset( $product->sku ) ? $product->sku : '';
    }

    private function get_product_title( $product ) {
        return isset( $product->title ) ? $product->title : '';
    }

    private function get_product_description( $product ) {
        return isset( $product->description ) ? $product->description : '';
    }

    private function get_product_quantity( $product ) {
        return isset( $product->quantity ) ? intval( $product->quantity ) : 0;
    }

    private function get_product_images( $product ) {
        if ( isset( $product->images ) && is_string( $product->images ) ) {
            return json_decode( $product->images, true );
        }
        return isset( $product->images ) ? $product->images : [];
    }

    private function get_product_category( $product ) {
        return isset( $product->category ) ? $product->category : '';
    }

    private function get_product_tags( $product ) {
        return isset( $product->tags ) ? $product->tags : '';
    }

    private function get_product_regular_price( $product ) {
        return isset( $product->regular_price ) ? floatval( $product->regular_price ) : 0;
    }

    private function get_product_sale_price( $product ) {
        return isset( $product->sale_price ) ? floatval( $product->sale_price ) : 0;
    }

}