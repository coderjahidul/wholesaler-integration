<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

/**
 * Bulk operations helper class for high-performance imports
 * Optimizes database queries and reduces API calls
 */
class Wholesaler_Bulk_Import_Helpers {

    /**
     * Bulk check product existence by SKUs
     * Returns array of SKU => product_id mappings
     */
    public function bulk_check_products_exist( array $skus ) {
        if ( empty( $skus ) ) {
            return [];
        }

        global $wpdb;
        
        // Remove empty SKUs
        $skus = array_filter( $skus );
        
        if ( empty( $skus ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
        
        $query = $wpdb->prepare(
            "SELECT pm.meta_value as sku, 
                    CASE 
                        WHEN p.post_type = 'product' THEN p.ID
                        WHEN p.post_type = 'product_variation' THEN p.post_parent
                        ELSE NULL
                    END as product_id
             FROM {$wpdb->postmeta} pm 
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' 
             AND pm.meta_value IN ($placeholders) 
             AND p.post_type IN ('product', 'product_variation')
             AND p.post_status != 'trash'",
            ...$skus
        );

        $results = $wpdb->get_results( $query );
        
        $existing_products = [];
        foreach ( $results as $result ) {
            if ( $result->product_id ) {
                $existing_products[ $result->sku ] = (int) $result->product_id;
            }
        }

        return $existing_products;
    }

    /**
     * Bulk get variation IDs by SKUs for a specific product
     */
    public function bulk_get_variation_ids( $product_id, array $variation_skus ) {
        if ( empty( $variation_skus ) ) {
            return [];
        }

        global $wpdb;
        
        $placeholders = implode( ',', array_fill( 0, count( $variation_skus ), '%s' ) );
        
        $query = $wpdb->prepare(
            "SELECT p.ID, pm.meta_value as sku 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_parent = %d 
             AND p.post_type = 'product_variation' 
             AND pm.meta_key = '_sku'
             AND pm.meta_value IN ($placeholders)",
            $product_id,
            ...$variation_skus
        );

        $results = $wpdb->get_results( $query );
        
        $variations = [];
        foreach ( $results as $result ) {
            $variations[ $result->sku ] = (int) $result->ID;
        }

        return $variations;
    }

    /**
     * Bulk update product statuses in sync table
     */
    public function bulk_update_sync_status( array $product_ids, $status ) {
        if ( empty( $product_ids ) ) {
            return false;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
        
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_name} 
             SET status = %s, updated_at = NOW() 
             WHERE id IN ({$placeholders})",
            $status,
            ...$product_ids
        ) );

        return $result !== false;
    }

    /**
     * Bulk insert/update product meta data
     */
    public function bulk_update_product_meta( array $meta_data ) {
        if ( empty( $meta_data ) ) {
            return false;
        }

        global $wpdb;
        
        $values = [];
        $placeholders = [];
        
        foreach ( $meta_data as $data ) {
            $values[] = $data['post_id'];
            $values[] = $data['meta_key'];
            $values[] = $data['meta_value'];
            $placeholders[] = '(%d, %s, %s)';
        }
        
        $placeholders_string = implode( ', ', $placeholders );
        
        $query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) 
                  VALUES {$placeholders_string} 
                  ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
        
        return $wpdb->query( $wpdb->prepare( $query, ...$values ) );
    }

    /**
     * Bulk set product taxonomies (categories, tags, brands)
     */
    public function bulk_set_product_taxonomies( array $taxonomy_data ) {
        if ( empty( $taxonomy_data ) ) {
            return false;
        }

        foreach ( $taxonomy_data as $data ) {
            $product_id = $data['product_id'];
            $taxonomy = $data['taxonomy'];
            $terms = $data['terms'];
            
            if ( !empty( $terms ) ) {
                wp_set_object_terms( $product_id, $terms, $taxonomy );
            }
        }

        return true;
    }

    /**
     * Optimize database for bulk operations
     */
    public function optimize_database_for_import() {
        global $wpdb;
        
        // Disable autocommit for better performance
        $wpdb->query( "SET autocommit = 0" );
        
        // Increase bulk insert buffer
        $wpdb->query( "SET bulk_insert_buffer_size = 256*1024*1024" );
        
        // Disable foreign key checks temporarily
        $wpdb->query( "SET foreign_key_checks = 0" );
        
        return true;
    }

    /**
     * Restore database settings after import
     */
    public function restore_database_settings() {
        global $wpdb;
        
        // Commit any pending transactions
        $wpdb->query( "COMMIT" );
        
        // Re-enable autocommit
        $wpdb->query( "SET autocommit = 1" );
        
        // Re-enable foreign key checks
        $wpdb->query( "SET foreign_key_checks = 1" );
        
        return true;
    }

    /**
     * Get products in batches with cursor-based pagination for better performance
     */
    public function get_products_batch_cursor( $batch_size = 100, $last_id = 0 ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE status = %s 
             AND id > %d 
             ORDER BY id ASC 
             LIMIT %d",
            Status_Enum::PENDING->value,
            $last_id,
            $batch_size
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Create database indexes for better performance
     */
    public function create_performance_indexes() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        // Index on status and id for faster batch queries
        $wpdb->query( 
            "CREATE INDEX IF NOT EXISTS idx_status_id ON {$table_name} (status, id)"
        );
        
        // Index on SKU for faster lookups
        $wpdb->query( 
            "CREATE INDEX IF NOT EXISTS idx_sku ON {$table_name} (sku)"
        );
        
        // Index on wholesaler_name for filtering
        $wpdb->query( 
            "CREATE INDEX IF NOT EXISTS idx_wholesaler ON {$table_name} (wholesaler_name)"
        );
        
        // Composite index for common queries
        $wpdb->query( 
            "CREATE INDEX IF NOT EXISTS idx_wholesaler_status ON {$table_name} (wholesaler_name, status)"
        );

        return true;
    }

    /**
     * Get import statistics for monitoring
     */
    public function get_import_statistics() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(CASE WHEN status = 'completed' THEN 
                    TIMESTAMPDIFF(SECOND, created_at, updated_at) 
                    ELSE NULL END) as avg_processing_time
             FROM {$table_name}",
            ARRAY_A
        );

        return $stats;
    }

    /**
     * Clean up failed imports older than specified days
     */
    public function cleanup_old_failed_imports( $days = 7 ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        $result = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table_name} 
             WHERE status = 'failed' 
             AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );

        return $result;
    }

    /**
     * Reset products to pending status for re-import
     */
    public function reset_products_to_pending( array $product_ids = [] ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
        
        if ( empty( $product_ids ) ) {
            // Reset all failed products
            $result = $wpdb->query(
                "UPDATE {$table_name} 
                 SET status = 'pending', updated_at = NOW() 
                 WHERE status = 'failed'"
            );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table_name} 
                 SET status = 'pending', updated_at = NOW() 
                 WHERE id IN ({$placeholders})",
                ...$product_ids
            ) );
        }

        return $result;
    }

    /**
     * Batch process image uploads in background
     */
    public function schedule_image_processing( array $image_data ) {
        // Schedule image processing as a background task
        wp_schedule_single_event( time() + 30, 'wholesaler_process_images', [ $image_data ] );
        
        return true;
    }

    /**
     * Process images in background to avoid blocking main import
     */
    public function process_images_background( array $image_data ) {
        foreach ( $image_data as $data ) {
            $product_id = $data['product_id'];
            $images = $data['images'];
            
            $processed_images = [];
            
            foreach ( $images as $image_url ) {
                $attachment_id = $this->sideload_image( $image_url, $product_id );
                if ( $attachment_id ) {
                    $processed_images[] = [ 'id' => $attachment_id ];
                }
            }
            
            if ( !empty( $processed_images ) ) {
                // Update product with processed images
                wp_update_post( [
                    'ID' => $product_id,
                    'meta_input' => [
                        '_product_image_gallery' => implode( ',', array_column( $processed_images, 'id' ) )
                    ]
                ] );
            }
        }
    }

    /**
     * Sideload image from URL
     */
    private function sideload_image( $image_url, $product_id ) {
        if ( empty( $image_url ) ) {
            return false;
        }

        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        $tmp = download_url( $image_url );
        
        if ( is_wp_error( $tmp ) ) {
            return false;
        }

        $file_array = [
            'name'     => basename( $image_url ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id );
        
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return false;
        }

        return $attachment_id;
    }
}

// Register background image processing hook
add_action( 'wholesaler_process_images', function( $image_data ) {
    $helper = new Wholesaler_Bulk_Import_Helpers();
    $helper->process_images_background( $image_data );
} );
