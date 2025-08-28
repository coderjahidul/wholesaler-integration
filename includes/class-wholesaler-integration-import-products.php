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
            $products_table   = $wpdb->prefix . 'sync_wholesaler_products_data';
            $this->table_name = $products_table;

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
                try {
                    $this->log_message( "Processing product ID: {$product->id}" );

                    // Import single product
                    $result = $this->import_single_product( $product );

                    if ( $result['success'] ) {
                        $imported_count++;
                        $this->mark_as_complete( $this->table_name, (int) $product->id );
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
            $serial_id       = $product->id;
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
            $this->log_message( "Error importing product {$product->id}: " . $e->getMessage() );
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

        switch ( $wholesaler_name ) {
            case 'JS':
                $mapped_product = $this->map_js_product_data( $product );
                break;
            case 'MADA':
                echo 'mada';
                // $mapped_product = $this->map_target_product_data( $product );
                break;
            case 'AREN':
                echo 'aren';
                // $mapped_product = $this->map_walmart_product_data( $product );
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
    private function update_existing_product( int $existing_product_id, array $product ) {
        try {
            $this->log_message( "Updating existing product ID: {$existing_product_id}" );

            $product_data = [
                'name'          => $product['name'],
                'description'   => $product['description'],
                'regular_price' => $product['regular_price'],
                'price'         => $product['sale_price'],
                'sale_price'    => $product['sale_price'],
            ];

            // Update product via API
            $this->client->put( 'products/' . $existing_product_id, $product_data );

            // update stock variable product

            $this->log_message( "Successfully updated product ID: {$existing_product_id}" );

            return [
                'success'    => true,
                'message'    => 'Product updated successfully',
                'product_id' => $existing_product_id,
            ];

        } catch (Exception $e) {
            $this->log_message( "Error updating product {$existing_product_id}: " . $e->getMessage() );
            throw $e;
        }
    }

    /**
     * Create new product in WooCommerce
     */
    private function create_new_product( array $product ) {
        try {
            $this->log_message( "Creating new product" );

            $product_data = [
                'name'        => $product['name'],
                'sku'         => $product['sku'],
                'type'        => 'variable',
                'description' => $product['description'],
                'attributes'  => $product['attributes'],
                'categories'  => $product['category_terms'],
                'images'      => $product['images_payload'],
            ];

            // Create the product via API
            $wc_product = $this->client->post( 'products', $product_data );
            $product_id = $wc_product->id;

            // Set product information
            wp_set_object_terms( $product_id, $this->product_type, 'product_type' );
            update_post_meta( $product_id, '_visibility', 'visible' );

            // Update product category and tags
            $this->update_product_taxonomies( $product_id, $product );

            // Create variations if provided
            if ( !empty( $product['variations'] ) ) {
                foreach ( $product['variations'] as $variation ) {
                    try {
                        $this->client->post( 'products/' . $product_id . '/variations', $variation );
                    } catch (HttpClientException $e) {
                        $this->log_message( 'WooCommerce API error creating variation for product ' . $product_id . ': ' . $e->getMessage() );
                        continue;
                    }
                }
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
        // Expecting mapped array structure
        $categories = isset( $product['categories'] ) ? $product['categories'] : [];
        $tags       = isset( $product['tags'] ) ? $product['tags'] : [];

        if ( !empty( $categories ) ) {
            wp_set_object_terms( $product_id, $categories, 'product_cat' );
        }

        if ( !empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, 'product_tag' );
        }
    }

    /**
     * Handle product stock
     */
    private function handle_product_stock( int $product_id, int $quantity ) {
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
    public function mark_as_complete( string $table_name, int $serial_id ) {
        try {
            global $wpdb;

            $result = $wpdb->update(
                $table_name,
                [ 'status' => 'Completed' ],
                [ 'id' => $serial_id ],
                [ '%s' ],
                [ '%d' ]
            );

            if ( $result === false ) {
                throw new Exception( "Failed to update product status in database" );
            }

            $this->log_message( "Product ID {$serial_id} marked as completed" );
            return true;

        } catch (Exception $e) {
            $this->log_message( "Error marking product {$serial_id} as complete: " . $e->getMessage() );
            return false;
        }
    }

    private function parse_category_path_to_terms( $category_path ) {
        // Example: "Bielizna|Damska|Majtki" => [ 'Bielizna', 'Damska', 'Majtki' ]
        if ( empty( $category_path ) ) {
            return [];
        }
        $parts = array_map( 'trim', explode( '|', $category_path ) );
        return $parts;
    }

    private function map_js_product_data( $product_obj ) {
        // $product_obj is stdClass from DB; decode nested JSON
        $payload = is_string( $product_obj->product_data ) ? json_decode( $product_obj->product_data, true ) : (array) $product_obj->product_data;

        $name = isset( $payload['name'] ) && is_array( $payload['name'] ) ? ( $payload['name']['en'] ?? ( $payload['name']['pl'] ?? '' ) ) : ( $payload['name'] ?? '' );
        if ( empty( $name ) && isset( $product_obj->sku ) ) {
            $name = $product_obj->sku;
        }

        $brand       = isset( $payload['brand']['name'] ) ? $payload['brand']['name'] : ( $product_obj->brand ?? '' );
        $description = isset( $payload['attributes']['opis'] ) && is_array( $payload['attributes']['opis'] ) ? implode( "\n", $payload['attributes']['opis'] ) : '';

        $images_urls    = isset( $payload['images'] ) && is_array( $payload['images'] ) ? $payload['images'] : [];
        $images_payload = array_map( function ($url) {
            return [ 'src' => $url ];
        }, $images_urls );

        $categories_terms = $this->parse_category_path_to_terms( $payload['category_keys'] ?? '' );

        $size_options  = [];
        $color_options = [];
        $variations    = [];

        if ( isset( $payload['units']['unit'] ) && is_array( $payload['units']['unit'] ) ) {
            foreach ( $payload['units']['unit'] as $unit ) {
                $size  = isset( $unit['size'] ) ? (string) $unit['size'] : '';
                $color = isset( $unit['color'] ) ? (string) $unit['color'] : '';
                if ( $size !== '' && !in_array( $size, $size_options, true ) ) {
                    $size_options[] = $size;
                }
                if ( $color !== '' && !in_array( $color, $color_options, true ) ) {
                    $color_options[] = $color;
                }
            }

            foreach ( $payload['units']['unit'] as $unit ) {
                $unitSku  = $unit['@attributes']['sku'] ?? '';
                $unitEan  = $unit['@attributes']['ean'] ?? '';
                $size     = $unit['size'] ?? '';
                $color    = $unit['color'] ?? '';
                $stockQty = isset( $unit['stock'] ) ? (int) $unit['stock'] : 0;

                $variations[] = [
                    'sku'            => $unitSku,
                    'regular_price'  => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
                    'manage_stock'   => true,
                    'stock_quantity' => $stockQty,
                    'attributes'     => [
                        [ 'name' => 'Color', 'option' => $color ],
                        [ 'name' => 'Size', 'option' => $size ],
                    ],
                    'meta_data'      => [
                        [ 'key' => '_ean', 'value' => $unitEan ],
                    ],
                ];
            }
        }

        $attributes = [];
        if ( !empty( $color_options ) ) {
            $attributes[] = [
                'name'      => 'Color',
                'position'  => 0,
                'visible'   => true,
                'variation' => true,
                'options'   => $color_options,
            ];
        }
        if ( !empty( $size_options ) ) {
            $attributes[] = [
                'name'      => 'Size',
                'position'  => 1,
                'visible'   => true,
                'variation' => true,
                'options'   => $size_options,
            ];
        }

        return [
            'name'           => $name,
            'sku'            => (string) ( $product_obj->sku ?? '' ),
            'brand'          => $brand,
            'description'    => $description,
            'regular_price'  => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
            'sale_price'     => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
            'images_payload' => $images_payload,
            'categories'     => $categories_terms,
            'category_terms' => array_map( function ($name) {
                return [ 'name' => $name ];
            }, $categories_terms ),
            'tags'           => [],
            'attributes'     => $attributes,
            'variations'     => $variations,
        ];
    }

}


$website_url     = site_url();
$consumer_key    = get_option('wholesaler_consumer_key', '');
$consumer_secret = get_option('wholesaler_consumer_secret', '');
new Wholesaler_Integration_Import_Products( $website_url, $consumer_key, $consumer_secret );