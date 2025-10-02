<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

/**
 * Optimized image processing for wholesaler imports
 * Handles image downloads, resizing, and optimization in background
 */
class Wholesaler_Image_Optimizer {

    private $upload_dir;
    private $temp_dir;
    private $max_image_size = 2048; // Max width/height in pixels
    private $jpeg_quality = 85;
    private $batch_size = 10; // Images to process per batch
    
    public function __construct() {
        $this->upload_dir = wp_upload_dir();
        $this->temp_dir = $this->upload_dir['basedir'] . '/wholesaler_temp/';
        
        $this->init_hooks();
        $this->ensure_temp_directory();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_image_endpoints' ] );
        add_action( 'wholesaler_process_images_batch', [ $this, 'process_images_batch' ] );
        add_action( 'wholesaler_cleanup_temp_images', [ $this, 'cleanup_temp_images' ] );
        
        // Schedule cleanup daily
        if ( ! wp_next_scheduled( 'wholesaler_cleanup_temp_images' ) ) {
            wp_schedule_event( time(), 'daily', 'wholesaler_cleanup_temp_images' );
        }
    }

    /**
     * Register image processing endpoints
     */
    public function register_image_endpoints() {
        // Batch image processing endpoint
        register_rest_route( 'wholesaler/v1', '/process-images', [
            'methods' => 'POST',
            'callback' => [ $this, 'schedule_image_processing' ],
            'permission_callback' => '__return_true',
            'args' => [
                'images' => [
                    'required' => true,
                    'validate_callback' => function( $param ) {
                        return is_array( $param );
                    }
                ],
                'product_id' => [
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'priority' => [
                    'default' => 5,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // Image optimization status endpoint
        register_rest_route( 'wholesaler/v1', '/image-status/(?P<product_id>\d+)', [
            'methods' => 'GET',
            'callback' => [ $this, 'get_image_status' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Schedule image processing for a product
     */
    public function schedule_image_processing( WP_REST_Request $request ) {
        $images = $request->get_param( 'images' );
        $product_id = $request->get_param( 'product_id' );
        $priority = $request->get_param( 'priority' );

        if ( empty( $images ) || ! $product_id ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Invalid parameters'
            ], 400 );
        }

        // Split images into batches
        $image_batches = array_chunk( $images, $this->batch_size );
        $job_ids = [];

        foreach ( $image_batches as $batch_index => $batch ) {
            $job_data = [
                'product_id' => $product_id,
                'images' => $batch,
                'batch_index' => $batch_index,
                'total_batches' => count( $image_batches )
            ];

            // Schedule with slight delay between batches
            $schedule_time = time() + ( $batch_index * 30 ); // 30 seconds between batches
            
            wp_schedule_single_event( 
                $schedule_time, 
                'wholesaler_process_images_batch', 
                [ $job_data ] 
            );

            $job_ids[] = "batch_{$batch_index}";
        }

        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Image processing scheduled',
            'job_ids' => $job_ids,
            'total_batches' => count( $image_batches )
        ], 200 );
    }

    /**
     * Process a batch of images
     */
    public function process_images_batch( $job_data ) {
        $product_id = $job_data['product_id'];
        $images = $job_data['images'];
        $batch_index = $job_data['batch_index'];

        $processed_images = [];
        $errors = [];

        foreach ( $images as $image_url ) {
            try {
                $attachment_id = $this->process_single_image( $image_url, $product_id );
                
                if ( $attachment_id ) {
                    $processed_images[] = $attachment_id;
                } else {
                    $errors[] = "Failed to process: {$image_url}";
                }
                
            } catch ( Exception $e ) {
                $errors[] = "Error processing {$image_url}: " . $e->getMessage();
            }
        }

        // Update product with processed images
        if ( ! empty( $processed_images ) ) {
            $this->attach_images_to_product( $product_id, $processed_images, $batch_index );
        }

        // Log results
        $this->log_batch_results( $product_id, $batch_index, $processed_images, $errors );
    }

    /**
     * Process a single image with optimization
     */
    private function process_single_image( $image_url, $product_id ) {
        if ( empty( $image_url ) ) {
            return false;
        }

        // Check if image already exists
        $existing_attachment = $this->find_existing_image( $image_url );
        if ( $existing_attachment ) {
            return $existing_attachment;
        }

        // Download image to temp directory
        $temp_file = $this->download_image_to_temp( $image_url );
        if ( ! $temp_file ) {
            return false;
        }

        try {
            // Optimize image
            $optimized_file = $this->optimize_image( $temp_file );
            
            // Upload to WordPress media library
            $attachment_id = $this->upload_to_media_library( 
                $optimized_file, 
                $product_id, 
                basename( $image_url ) 
            );

            // Cleanup temp files
            @unlink( $temp_file );
            if ( $optimized_file !== $temp_file ) {
                @unlink( $optimized_file );
            }

            return $attachment_id;

        } catch ( Exception $e ) {
            // Cleanup on error
            @unlink( $temp_file );
            throw $e;
        }
    }

    /**
     * Download image to temporary directory
     */
    private function download_image_to_temp( $image_url ) {
        $temp_filename = $this->temp_dir . uniqid( 'img_' ) . '_' . basename( $image_url );
        
        // Use WordPress HTTP API for better compatibility
        $response = wp_remote_get( $image_url, [
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (compatible; WholesalerBot/1.0)',
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        // Validate content type
        if ( ! $this->is_valid_image_type( $content_type ) ) {
            return false;
        }

        // Save to temp file
        if ( file_put_contents( $temp_filename, $body ) === false ) {
            return false;
        }

        return $temp_filename;
    }

    /**
     * Optimize image (resize, compress, convert format if needed)
     */
    private function optimize_image( $file_path ) {
        $image_info = getimagesize( $file_path );
        if ( ! $image_info ) {
            return $file_path; // Not a valid image
        }

        list( $width, $height, $type ) = $image_info;

        // Check if optimization is needed
        $needs_resize = $width > $this->max_image_size || $height > $this->max_image_size;
        $needs_conversion = ! in_array( $type, [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ] );

        if ( ! $needs_resize && ! $needs_conversion ) {
            return $file_path; // No optimization needed
        }

        // Create image resource
        $image = $this->create_image_resource( $file_path, $type );
        if ( ! $image ) {
            return $file_path;
        }

        // Calculate new dimensions
        if ( $needs_resize ) {
            $ratio = min( $this->max_image_size / $width, $this->max_image_size / $height );
            $new_width = (int) ( $width * $ratio );
            $new_height = (int) ( $height * $ratio );
        } else {
            $new_width = $width;
            $new_height = $height;
        }

        // Create optimized image
        $optimized_image = imagecreatetruecolor( $new_width, $new_height );
        
        // Preserve transparency for PNG
        if ( $type === IMAGETYPE_PNG ) {
            imagealphablending( $optimized_image, false );
            imagesavealpha( $optimized_image, true );
            $transparent = imagecolorallocatealpha( $optimized_image, 255, 255, 255, 127 );
            imagefill( $optimized_image, 0, 0, $transparent );
        }

        // Resize image
        imagecopyresampled( 
            $optimized_image, $image, 
            0, 0, 0, 0, 
            $new_width, $new_height, $width, $height 
        );

        // Save optimized image
        $optimized_path = $this->temp_dir . uniqid( 'opt_' ) . '.jpg';
        
        if ( $type === IMAGETYPE_PNG && $this->has_transparency( $image ) ) {
            // Keep as PNG if it has transparency
            $optimized_path = str_replace( '.jpg', '.png', $optimized_path );
            imagepng( $optimized_image, $optimized_path, 9 );
        } else {
            // Convert to JPEG for better compression
            imagejpeg( $optimized_image, $optimized_path, $this->jpeg_quality );
        }

        // Cleanup
        imagedestroy( $image );
        imagedestroy( $optimized_image );

        return $optimized_path;
    }

    /**
     * Create image resource from file
     */
    private function create_image_resource( $file_path, $type ) {
        switch ( $type ) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg( $file_path );
            case IMAGETYPE_PNG:
                return imagecreatefrompng( $file_path );
            case IMAGETYPE_GIF:
                return imagecreatefromgif( $file_path );
            case IMAGETYPE_WEBP:
                return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $file_path ) : false;
            default:
                return false;
        }
    }

    /**
     * Check if PNG image has transparency
     */
    private function has_transparency( $image ) {
        $width = imagesx( $image );
        $height = imagesy( $image );
        
        // Sample a few pixels to check for transparency
        $sample_points = [
            [0, 0], [$width-1, 0], [0, $height-1], [$width-1, $height-1],
            [$width/2, $height/2]
        ];
        
        foreach ( $sample_points as list( $x, $y ) ) {
            $rgba = imagecolorat( $image, $x, $y );
            $alpha = ( $rgba & 0x7F000000 ) >> 24;
            if ( $alpha > 0 ) {
                return true; // Has transparency
            }
        }
        
        return false;
    }

    /**
     * Upload optimized image to WordPress media library
     */
    private function upload_to_media_library( $file_path, $product_id, $original_filename ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $file_array = [
            'name' => $this->sanitize_filename( $original_filename ),
            'tmp_name' => $file_path,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id );
        
        if ( is_wp_error( $attachment_id ) ) {
            return false;
        }

        // Add metadata
        update_post_meta( $attachment_id, '_wholesaler_optimized', true );
        update_post_meta( $attachment_id, '_original_url', $original_filename );

        return $attachment_id;
    }

    /**
     * Sanitize filename for WordPress
     */
    private function sanitize_filename( $filename ) {
        $info = pathinfo( $filename );
        $ext = $info['extension'] ?? 'jpg';
        $name = sanitize_file_name( $info['filename'] ?? 'image' );
        
        return $name . '.' . $ext;
    }

    /**
     * Attach processed images to product
     */
    private function attach_images_to_product( $product_id, $attachment_ids, $batch_index ) {
        if ( empty( $attachment_ids ) ) {
            return;
        }

        // Get existing gallery images
        $existing_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        $existing_ids = $existing_gallery ? explode( ',', $existing_gallery ) : [];

        // Set featured image if none exists and this is the first batch
        $featured_image = get_post_thumbnail_id( $product_id );
        if ( ! $featured_image && $batch_index === 0 && ! empty( $attachment_ids ) ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            array_shift( $attachment_ids ); // Remove from gallery since it's now featured
        }

        // Add remaining images to gallery
        if ( ! empty( $attachment_ids ) ) {
            $all_gallery_ids = array_merge( $existing_ids, $attachment_ids );
            $all_gallery_ids = array_unique( array_filter( $all_gallery_ids ) );
            
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $all_gallery_ids ) );
        }
    }

    /**
     * Find existing image by URL to avoid duplicates
     */
    private function find_existing_image( $image_url ) {
        global $wpdb;
        
        $attachment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_original_url' 
             AND meta_value = %s 
             LIMIT 1",
            $image_url
        ) );

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Validate image content type
     */
    private function is_valid_image_type( $content_type ) {
        $valid_types = [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif',
            'image/webp'
        ];

        return in_array( strtolower( $content_type ), $valid_types );
    }

    /**
     * Get image processing status for a product
     */
    public function get_image_status( WP_REST_Request $request ) {
        $product_id = $request->get_param( 'product_id' );
        
        $featured_image = get_post_thumbnail_id( $product_id );
        $gallery = get_post_meta( $product_id, '_product_image_gallery', true );
        $gallery_ids = $gallery ? explode( ',', $gallery ) : [];
        
        $total_images = ( $featured_image ? 1 : 0 ) + count( $gallery_ids );
        
        return new WP_REST_Response( [
            'success' => true,
            'product_id' => $product_id,
            'featured_image' => $featured_image,
            'gallery_images' => count( $gallery_ids ),
            'total_images' => $total_images,
            'featured_image_url' => $featured_image ? wp_get_attachment_url( $featured_image ) : null
        ], 200 );
    }

    /**
     * Log batch processing results
     */
    private function log_batch_results( $product_id, $batch_index, $processed_images, $errors ) {
        $log_entry = [
            'timestamp' => current_time( 'mysql' ),
            'product_id' => $product_id,
            'batch_index' => $batch_index,
            'processed_count' => count( $processed_images ),
            'error_count' => count( $errors ),
            'attachment_ids' => $processed_images,
            'errors' => $errors
        ];

        // Store in option for debugging (keep last 100 entries)
        $logs = get_option( 'wholesaler_image_processing_logs', [] );
        $logs[] = $log_entry;
        
        // Keep only last 100 entries
        if ( count( $logs ) > 100 ) {
            $logs = array_slice( $logs, -100 );
        }
        
        update_option( 'wholesaler_image_processing_logs', $logs );
    }

    /**
     * Ensure temp directory exists
     */
    private function ensure_temp_directory() {
        if ( ! file_exists( $this->temp_dir ) ) {
            wp_mkdir_p( $this->temp_dir );
        }

        // Create .htaccess to prevent direct access
        $htaccess_file = $this->temp_dir . '.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            file_put_contents( $htaccess_file, "Deny from all\n" );
        }
    }

    /**
     * Cleanup old temporary images
     */
    public function cleanup_temp_images() {
        if ( ! is_dir( $this->temp_dir ) ) {
            return;
        }

        $files = glob( $this->temp_dir . '*' );
        $cutoff_time = time() - ( 24 * 60 * 60 ); // 24 hours ago

        foreach ( $files as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < $cutoff_time ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Get processing statistics
     */
    public function get_processing_stats() {
        $logs = get_option( 'wholesaler_image_processing_logs', [] );
        
        if ( empty( $logs ) ) {
            return [
                'total_batches' => 0,
                'total_processed' => 0,
                'total_errors' => 0,
                'success_rate' => 0
            ];
        }

        $total_batches = count( $logs );
        $total_processed = array_sum( array_column( $logs, 'processed_count' ) );
        $total_errors = array_sum( array_column( $logs, 'error_count' ) );
        $success_rate = $total_processed > 0 ? ( $total_processed / ( $total_processed + $total_errors ) ) * 100 : 0;

        return [
            'total_batches' => $total_batches,
            'total_processed' => $total_processed,
            'total_errors' => $total_errors,
            'success_rate' => round( $success_rate, 2 )
        ];
    }
}

// Initialize image optimizer
new Wholesaler_Image_Optimizer();
