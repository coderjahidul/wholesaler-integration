<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

/**
 * Performance manager for wholesaler imports
 * Handles queue management, performance monitoring, and optimization
 */
class Wholesaler_Performance_Manager {

    private $queue_table;
    private $stats_table;
    
    public function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'wholesaler_import_queue';
        $this->stats_table = $wpdb->prefix . 'wholesaler_import_stats';
        
        $this->init_hooks();
        $this->create_tables();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_performance_endpoints' ] );
        add_action( 'wholesaler_process_queue', [ $this, 'process_import_queue' ] );
        add_action( 'wholesaler_cleanup_queue', [ $this, 'cleanup_completed_jobs' ] );
        
        // Schedule cleanup every hour
        if ( ! wp_next_scheduled( 'wholesaler_cleanup_queue' ) ) {
            wp_schedule_event( time(), 'hourly', 'wholesaler_cleanup_queue' );
        }
    }

    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Import queue table
        $queue_sql = "CREATE TABLE IF NOT EXISTS {$this->queue_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_type varchar(50) NOT NULL,
            job_data longtext NOT NULL,
            priority int(11) DEFAULT 5,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            max_attempts int(11) DEFAULT 3,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            started_at datetime NULL,
            completed_at datetime NULL,
            error_message text NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_priority (status, priority),
            KEY idx_job_type (job_type),
            KEY idx_scheduled (scheduled_at)
        ) $charset_collate;";
        
        // Performance stats table
        $stats_sql = "CREATE TABLE IF NOT EXISTS {$this->stats_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_type varchar(50) NOT NULL,
            batch_size int(11) NOT NULL,
            processing_time decimal(10,3) NOT NULL,
            memory_usage bigint(20) NOT NULL,
            success_count int(11) NOT NULL,
            error_count int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_job_type_time (job_type, created_at),
            KEY idx_performance (processing_time, memory_usage)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $queue_sql );
        dbDelta( $stats_sql );
    }

    /**
     * Register performance monitoring endpoints
     */
    public function register_performance_endpoints() {
        // Queue management endpoint
        register_rest_route( 'wholesaler/v1', '/queue', [
            'methods' => 'POST',
            'callback' => [ $this, 'add_to_queue' ],
            'permission_callback' => '__return_true',
            'args' => [
                'job_type' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'job_data' => [
                    'required' => true,
                ],
                'priority' => [
                    'default' => 5,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // Performance stats endpoint
        register_rest_route( 'wholesaler/v1', '/performance-stats', [
            'methods' => 'GET',
            'callback' => [ $this, 'get_performance_stats' ],
            'permission_callback' => '__return_true',
        ] );

        // Queue status endpoint
        register_rest_route( 'wholesaler/v1', '/queue-status', [
            'methods' => 'GET',
            'callback' => [ $this, 'get_queue_status' ],
            'permission_callback' => '__return_true',
        ] );

        // Optimized import endpoint with queue
        register_rest_route( 'wholesaler/v1', '/smart-import', [
            'methods' => 'POST',
            'callback' => [ $this, 'smart_import' ],
            'permission_callback' => '__return_true',
            'args' => [
                'batch_size' => [
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ],
                'use_queue' => [
                    'default' => true,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
                'priority' => [
                    'default' => 5,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    /**
     * Smart import with automatic optimization
     */
    public function smart_import( WP_REST_Request $request ) {
        $batch_size = $request->get_param( 'batch_size' );
        $use_queue = $request->get_param( 'use_queue' );
        $priority = $request->get_param( 'priority' );

        // Analyze current server performance
        $server_load = $this->get_server_load();
        $optimal_batch_size = $this->calculate_optimal_batch_size( $server_load );
        
        // Adjust batch size based on server performance
        $batch_size = min( $batch_size, $optimal_batch_size );

        if ( $use_queue ) {
            // Add to queue for background processing
            $job_id = $this->add_import_job_to_queue( $batch_size, $priority );
            
            return new WP_REST_Response( [
                'success' => true,
                'message' => 'Import job added to queue',
                'job_id' => $job_id,
                'estimated_batch_size' => $batch_size,
                'server_load' => $server_load
            ], 200 );
        } else {
            // Process immediately with performance monitoring
            return $this->process_with_monitoring( $batch_size );
        }
    }

    /**
     * Add job to import queue
     */
    public function add_to_queue( WP_REST_Request $request ) {
        $job_type = $request->get_param( 'job_type' );
        $job_data = $request->get_param( 'job_data' );
        $priority = $request->get_param( 'priority' );

        $job_id = $this->add_job_to_queue( $job_type, $job_data, $priority );

        if ( $job_id ) {
            // Schedule immediate processing if no jobs are running
            if ( $this->get_running_jobs_count() === 0 ) {
                wp_schedule_single_event( time() + 5, 'wholesaler_process_queue' );
            }

            return new WP_REST_Response( [
                'success' => true,
                'job_id' => $job_id,
                'message' => 'Job added to queue successfully'
            ], 200 );
        } else {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Failed to add job to queue'
            ], 500 );
        }
    }

    /**
     * Add import job to queue
     */
    private function add_import_job_to_queue( $batch_size, $priority = 5 ) {
        $job_data = [
            'batch_size' => $batch_size,
            'performance_mode' => true,
            'timestamp' => time()
        ];

        return $this->add_job_to_queue( 'batch_import', $job_data, $priority );
    }

    /**
     * Add job to queue
     */
    private function add_job_to_queue( $job_type, $job_data, $priority = 5 ) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->queue_table,
            [
                'job_type' => $job_type,
                'job_data' => wp_json_encode( $job_data ),
                'priority' => $priority,
                'status' => 'pending'
            ],
            [ '%s', '%s', '%d', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Process import queue
     */
    public function process_import_queue() {
        $max_concurrent_jobs = $this->get_max_concurrent_jobs();
        $running_jobs = $this->get_running_jobs_count();

        if ( $running_jobs >= $max_concurrent_jobs ) {
            return; // Too many jobs running
        }

        // Get next job from queue
        $job = $this->get_next_job();
        
        if ( ! $job ) {
            return; // No jobs in queue
        }

        // Mark job as running
        $this->update_job_status( $job->id, 'running' );

        try {
            $start_time = microtime( true );
            $start_memory = memory_get_usage();

            // Process the job based on type
            $result = $this->process_job( $job );

            $end_time = microtime( true );
            $end_memory = memory_get_usage();

            // Record performance stats
            $this->record_performance_stats( 
                $job->job_type,
                $result['batch_size'] ?? 0,
                $end_time - $start_time,
                $end_memory - $start_memory,
                $result['success_count'] ?? 0,
                $result['error_count'] ?? 0
            );

            // Mark job as completed
            $this->update_job_status( $job->id, 'completed' );

            // Schedule next job processing
            wp_schedule_single_event( time() + 2, 'wholesaler_process_queue' );

        } catch ( Exception $e ) {
            // Mark job as failed and increment attempts
            $this->handle_job_failure( $job->id, $e->getMessage() );
        }
    }

    /**
     * Process individual job
     */
    private function process_job( $job ) {
        $job_data = json_decode( $job->job_data, true );

        switch ( $job->job_type ) {
            case 'batch_import':
                return $this->process_batch_import_job( $job_data );
            
            case 'image_processing':
                return $this->process_image_job( $job_data );
            
            default:
                throw new Exception( "Unknown job type: {$job->job_type}" );
        }
    }

    /**
     * Process batch import job
     */
    private function process_batch_import_job( $job_data ) {
        $batch_size = $job_data['batch_size'] ?? 50;
        
        // Initialize batch import
        $batch_import = new Wholesaler_Batch_Import( 
            site_url(), 
            get_option( 'wholesaler_consumer_key', '' ), 
            get_option( 'wholesaler_consumer_secret', '' ) 
        );

        // Process with performance mode enabled
        $result = $batch_import->batch_import_products( $batch_size );

        return [
            'batch_size' => $batch_size,
            'success_count' => $result['created'] + $result['updated'],
            'error_count' => count( $result['errors'] ?? [] )
        ];
    }

    /**
     * Process image job
     */
    private function process_image_job( $job_data ) {
        $helper = new Wholesaler_Bulk_Import_Helpers();
        $helper->process_images_background( $job_data['images'] );

        return [
            'batch_size' => count( $job_data['images'] ),
            'success_count' => count( $job_data['images'] ),
            'error_count' => 0
        ];
    }

    /**
     * Get next job from queue
     */
    private function get_next_job() {
        global $wpdb;

        return $wpdb->get_row(
            "SELECT * FROM {$this->queue_table} 
             WHERE status = 'pending' 
             AND scheduled_at <= NOW()
             AND attempts < max_attempts
             ORDER BY priority DESC, id ASC 
             LIMIT 1"
        );
    }

    /**
     * Update job status
     */
    private function update_job_status( $job_id, $status ) {
        global $wpdb;

        $update_data = [ 'status' => $status ];
        
        if ( $status === 'running' ) {
            $update_data['started_at'] = current_time( 'mysql' );
        } elseif ( in_array( $status, [ 'completed', 'failed' ] ) ) {
            $update_data['completed_at'] = current_time( 'mysql' );
        }

        return $wpdb->update(
            $this->queue_table,
            $update_data,
            [ 'id' => $job_id ],
            array_fill( 0, count( $update_data ), '%s' ),
            [ '%d' ]
        );
    }

    /**
     * Handle job failure
     */
    private function handle_job_failure( $job_id, $error_message ) {
        global $wpdb;

        $wpdb->update(
            $this->queue_table,
            [
                'status' => 'failed',
                'attempts' => new WP_Query( "attempts + 1" ),
                'error_message' => $error_message,
                'completed_at' => current_time( 'mysql' )
            ],
            [ 'id' => $job_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        // Reschedule if attempts remaining
        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->queue_table} WHERE id = %d",
            $job_id
        ) );

        if ( $job && $job->attempts < $job->max_attempts ) {
            // Reschedule with exponential backoff
            $delay = pow( 2, $job->attempts ) * 60; // 1min, 2min, 4min, etc.
            
            $wpdb->update(
                $this->queue_table,
                [
                    'status' => 'pending',
                    'scheduled_at' => date( 'Y-m-d H:i:s', time() + $delay )
                ],
                [ 'id' => $job_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
        }
    }

    /**
     * Get running jobs count
     */
    private function get_running_jobs_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->queue_table} WHERE status = 'running'"
        );
    }

    /**
     * Get maximum concurrent jobs based on server resources
     */
    private function get_max_concurrent_jobs() {
        $server_load = $this->get_server_load();
        
        if ( $server_load < 0.5 ) {
            return 3; // Low load - allow more concurrent jobs
        } elseif ( $server_load < 1.0 ) {
            return 2; // Medium load
        } else {
            return 1; // High load - limit to one job
        }
    }

    /**
     * Get current server load
     */
    private function get_server_load() {
        if ( function_exists( 'sys_getloadavg' ) ) {
            $load = sys_getloadavg();
            return $load[0]; // 1-minute average
        }
        
        // Fallback: estimate based on memory usage
        $memory_limit = $this->parse_size( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage( true );
        
        return $memory_usage / $memory_limit;
    }

    /**
     * Calculate optimal batch size based on server performance
     */
    private function calculate_optimal_batch_size( $server_load ) {
        $base_batch_size = 50;
        
        if ( $server_load < 0.3 ) {
            return min( 100, $base_batch_size * 2 ); // Low load - increase batch size
        } elseif ( $server_load < 0.7 ) {
            return $base_batch_size; // Normal load
        } else {
            return max( 10, $base_batch_size / 2 ); // High load - reduce batch size
        }
    }

    /**
     * Parse memory size string to bytes
     */
    private function parse_size( $size ) {
        $unit = preg_replace( '/[^bkmgtpezy]/i', '', $size );
        $size = preg_replace( '/[^0-9\.]/', '', $size );
        
        if ( $unit ) {
            return round( $size * pow( 1024, stripos( 'bkmgtpezy', $unit[0] ) ) );
        }
        
        return round( $size );
    }

    /**
     * Record performance statistics
     */
    private function record_performance_stats( $job_type, $batch_size, $processing_time, $memory_usage, $success_count, $error_count ) {
        global $wpdb;

        return $wpdb->insert(
            $this->stats_table,
            [
                'job_type' => $job_type,
                'batch_size' => $batch_size,
                'processing_time' => $processing_time,
                'memory_usage' => $memory_usage,
                'success_count' => $success_count,
                'error_count' => $error_count
            ],
            [ '%s', '%d', '%f', '%d', '%d', '%d' ]
        );
    }

    /**
     * Get performance statistics
     */
    public function get_performance_stats( WP_REST_Request $request ) {
        global $wpdb;

        $stats = $wpdb->get_results(
            "SELECT 
                job_type,
                AVG(processing_time) as avg_processing_time,
                AVG(memory_usage) as avg_memory_usage,
                AVG(batch_size) as avg_batch_size,
                SUM(success_count) as total_success,
                SUM(error_count) as total_errors,
                COUNT(*) as total_jobs
             FROM {$this->stats_table} 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY job_type",
            ARRAY_A
        );

        return new WP_REST_Response( [
            'success' => true,
            'stats' => $stats,
            'server_load' => $this->get_server_load(),
            'optimal_batch_size' => $this->calculate_optimal_batch_size( $this->get_server_load() )
        ], 200 );
    }

    /**
     * Get queue status
     */
    public function get_queue_status( WP_REST_Request $request ) {
        global $wpdb;

        $status = $wpdb->get_row(
            "SELECT 
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM {$this->queue_table}",
            ARRAY_A
        );

        return new WP_REST_Response( [
            'success' => true,
            'queue_status' => $status,
            'max_concurrent_jobs' => $this->get_max_concurrent_jobs()
        ], 200 );
    }

    /**
     * Process with performance monitoring
     */
    private function process_with_monitoring( $batch_size ) {
        $start_time = microtime( true );
        $start_memory = memory_get_usage();

        try {
            // Initialize batch import
            $batch_import = new Wholesaler_Batch_Import( 
                site_url(), 
                get_option( 'wholesaler_consumer_key', '' ), 
                get_option( 'wholesaler_consumer_secret', '' ) 
            );

            $result = $batch_import->batch_import_products( $batch_size );

            $end_time = microtime( true );
            $end_memory = memory_get_usage();

            // Record performance stats
            $this->record_performance_stats( 
                'direct_import',
                $batch_size,
                $end_time - $start_time,
                $end_memory - $start_memory,
                $result['created'] + $result['updated'],
                count( $result['errors'] ?? [] )
            );

            return new WP_REST_Response( array_merge( $result, [
                'processing_time' => round( $end_time - $start_time, 3 ),
                'memory_used' => $this->format_bytes( $end_memory - $start_memory )
            ] ), 200 );

        } catch ( Exception $e ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500 );
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function format_bytes( $bytes, $precision = 2 ) {
        $units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        
        for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
            $bytes /= 1024;
        }
        
        return round( $bytes, $precision ) . ' ' . $units[$i];
    }

    /**
     * Cleanup completed jobs
     */
    public function cleanup_completed_jobs() {
        global $wpdb;

        // Remove completed jobs older than 7 days
        $wpdb->query(
            "DELETE FROM {$this->queue_table} 
             WHERE status IN ('completed', 'failed') 
             AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // Remove old performance stats (keep 30 days)
        $wpdb->query(
            "DELETE FROM {$this->stats_table} 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
}

// Initialize performance manager
new Wholesaler_Performance_Manager();
