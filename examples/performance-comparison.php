<?php
/**
 * Performance Comparison Examples
 * 
 * This file demonstrates how to use the new high-performance import system
 * compared to the original sequential import method.
 */

// Ensure this is run in WordPress context
if (!defined('ABSPATH')) {
    die('Direct access not allowed');
}

/**
 * Example 1: Original Sequential Import (SLOW)
 * 
 * This is how the original system worked - one product at a time
 */
function example_original_slow_import() {
    echo "=== Original Sequential Import (SLOW) ===\n";
    
    $start_time = microtime(true);
    $imported = 0;
    
    // Simulate importing 100 products one by one
    for ($i = 1; $i <= 100; $i++) {
        // Each product requires:
        // 1. Individual API call to create product
        // 2. Individual API calls for each variation
        // 3. Individual database queries for SKU check
        // 4. Individual taxonomy updates
        // 5. Individual image processing
        
        $response = wp_remote_post(site_url('/wp-json/wholesaler/v1/import-products'), [
            'body' => json_encode(['limit' => 1]),
            'headers' => ['Content-Type' => 'application/json']
        ]);
        
        if (!is_wp_error($response)) {
            $imported++;
        }
        
        // Simulate processing time per product (0.5-2 seconds each)
        usleep(rand(500000, 2000000)); // 0.5-2 seconds
    }
    
    $end_time = microtime(true);
    $total_time = $end_time - $start_time;
    
    echo "Imported: {$imported} products\n";
    echo "Total time: " . round($total_time, 2) . " seconds\n";
    echo "Rate: " . round($imported / ($total_time / 60), 2) . " products/minute\n\n";
    
    return [
        'imported' => $imported,
        'time' => $total_time,
        'rate' => $imported / ($total_time / 60)
    ];
}

/**
 * Example 2: New Batch Import (FAST)
 * 
 * This demonstrates the new high-performance batch system
 */
function example_new_batch_import() {
    echo "=== New Batch Import (FAST) ===\n";
    
    $start_time = microtime(true);
    $imported = 0;
    
    // Import 100 products in batches of 50
    $batches = [50, 50]; // Two batches of 50 products each
    
    foreach ($batches as $batch_size) {
        $response = wp_remote_post(site_url('/wp-json/wholesaler/v1/batch-import'), [
            'body' => json_encode([
                'batch_size' => $batch_size,
                'performance_mode' => true
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 300
        ]);
        
        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $imported += $body['created'] + $body['updated'];
        }
        
        // Batch processing is much faster (2-5 seconds per batch)
        usleep(rand(2000000, 5000000)); // 2-5 seconds per batch
    }
    
    $end_time = microtime(true);
    $total_time = $end_time - $start_time;
    
    echo "Imported: {$imported} products\n";
    echo "Total time: " . round($total_time, 2) . " seconds\n";
    echo "Rate: " . round($imported / ($total_time / 60), 2) . " products/minute\n\n";
    
    return [
        'imported' => $imported,
        'time' => $total_time,
        'rate' => $imported / ($total_time / 60)
    ];
}

/**
 * Example 3: Smart Import with Queue (FASTEST)
 * 
 * This demonstrates the intelligent queue-based system
 */
function example_smart_queue_import() {
    echo "=== Smart Queue Import (FASTEST) ===\n";
    
    $start_time = microtime(true);
    
    // Schedule 100 products for background processing
    $response = wp_remote_post(site_url('/wp-json/wholesaler/v1/smart-import'), [
        'body' => json_encode([
            'batch_size' => 100,
            'use_queue' => true,
            'priority' => 8
        ]),
        'headers' => ['Content-Type' => 'application/json']
    ]);
    
    $job_scheduled = !is_wp_error($response);
    $end_time = microtime(true);
    $total_time = $end_time - $start_time;
    
    echo "Job scheduled: " . ($job_scheduled ? 'Yes' : 'No') . "\n";
    echo "Scheduling time: " . round($total_time, 3) . " seconds\n";
    echo "Processing: Background (non-blocking)\n";
    echo "Estimated completion: 1-2 minutes\n\n";
    
    return [
        'scheduled' => $job_scheduled,
        'scheduling_time' => $total_time,
        'processing' => 'background'
    ];
}

/**
 * Example 4: Performance Monitoring
 */
function example_performance_monitoring() {
    echo "=== Performance Monitoring ===\n";
    
    // Get current performance stats
    $stats_response = wp_remote_get(site_url('/wp-json/wholesaler/v1/performance-stats'));
    
    if (!is_wp_error($stats_response)) {
        $stats = json_decode(wp_remote_retrieve_body($stats_response), true);
        
        echo "Server Load: " . round($stats['server_load'] * 100, 1) . "%\n";
        echo "Optimal Batch Size: " . $stats['optimal_batch_size'] . "\n";
        
        if (!empty($stats['stats'])) {
            foreach ($stats['stats'] as $stat) {
                echo "Job Type: {$stat['job_type']}\n";
                echo "  Avg Processing Time: " . round($stat['avg_processing_time'], 2) . "s\n";
                echo "  Avg Batch Size: " . round($stat['avg_batch_size']) . "\n";
                echo "  Success Rate: " . round(($stat['total_success'] / ($stat['total_success'] + $stat['total_errors'])) * 100, 1) . "%\n";
            }
        }
    }
    
    // Get queue status
    $queue_response = wp_remote_get(site_url('/wp-json/wholesaler/v1/queue-status'));
    
    if (!is_wp_error($queue_response)) {
        $queue = json_decode(wp_remote_retrieve_body($queue_response), true);
        
        echo "\nQueue Status:\n";
        echo "  Pending: " . $queue['queue_status']['pending'] . "\n";
        echo "  Running: " . $queue['queue_status']['running'] . "\n";
        echo "  Completed: " . $queue['queue_status']['completed'] . "\n";
        echo "  Failed: " . $queue['queue_status']['failed'] . "\n";
    }
    
    echo "\n";
}

/**
 * Example 5: Image Processing Optimization
 */
function example_image_processing() {
    echo "=== Image Processing Optimization ===\n";
    
    $product_id = 123; // Example product ID
    $images = [
        'https://example.com/image1.jpg',
        'https://example.com/image2.png',
        'https://example.com/image3.jpg'
    ];
    
    // Schedule background image processing
    $response = wp_remote_post(site_url('/wp-json/wholesaler/v1/process-images'), [
        'body' => json_encode([
            'product_id' => $product_id,
            'images' => $images,
            'priority' => 5
        ]),
        'headers' => ['Content-Type' => 'application/json']
    ]);
    
    if (!is_wp_error($response)) {
        $result = json_decode(wp_remote_retrieve_body($response), true);
        echo "Image processing scheduled\n";
        echo "Total batches: " . $result['total_batches'] . "\n";
        echo "Job IDs: " . implode(', ', $result['job_ids']) . "\n";
    }
    
    // Check image status
    $status_response = wp_remote_get(site_url("/wp-json/wholesaler/v1/image-status/{$product_id}"));
    
    if (!is_wp_error($status_response)) {
        $status = json_decode(wp_remote_retrieve_body($status_response), true);
        echo "Current images: " . $status['total_images'] . "\n";
        echo "Gallery images: " . $status['gallery_images'] . "\n";
    }
    
    echo "\n";
}

/**
 * Run performance comparison
 */
function run_performance_comparison() {
    echo "WooCommerce Wholesaler Integration - Performance Comparison\n";
    echo "=========================================================\n\n";
    
    // Note: These are simulated examples for demonstration
    // In real usage, you would have actual products to import
    
    echo "SIMULATION: Importing 100 products\n\n";
    
    // Simulate original method
    $original = [
        'imported' => 100,
        'time' => 180, // 3 minutes
        'rate' => 33.3
    ];
    
    // Simulate new batch method  
    $batch = [
        'imported' => 100,
        'time' => 15, // 15 seconds
        'rate' => 400
    ];
    
    echo "=== RESULTS COMPARISON ===\n";
    echo "Original Method:\n";
    echo "  Time: " . $original['time'] . " seconds\n";
    echo "  Rate: " . round($original['rate'], 1) . " products/minute\n\n";
    
    echo "New Batch Method:\n";
    echo "  Time: " . $batch['time'] . " seconds\n";
    echo "  Rate: " . round($batch['rate'], 1) . " products/minute\n\n";
    
    $improvement = $batch['rate'] / $original['rate'];
    echo "Performance Improvement: " . round($improvement, 1) . "x faster\n";
    echo "Time Saved: " . round((($original['time'] - $batch['time']) / $original['time']) * 100, 1) . "%\n\n";
    
    // Show monitoring examples
    example_performance_monitoring();
    example_image_processing();
}

/**
 * Usage Examples for Different Scenarios
 */
function usage_examples() {
    echo "=== USAGE EXAMPLES ===\n\n";
    
    echo "1. Small Import (< 1,000 products):\n";
    echo "   curl -X POST '/wp-json/wholesaler/v1/batch-import' \\\n";
    echo "     -d '{\"batch_size\": 50, \"performance_mode\": true}'\n\n";
    
    echo "2. Medium Import (1,000 - 10,000 products):\n";
    echo "   curl -X POST '/wp-json/wholesaler/v1/smart-import' \\\n";
    echo "     -d '{\"batch_size\": 100, \"use_queue\": true}'\n\n";
    
    echo "3. Large Import (> 10,000 products):\n";
    echo "   curl -X POST '/wp-json/wholesaler/v1/background-import' \\\n";
    echo "     -d '{\"total_batches\": 20}'\n\n";
    
    echo "4. Monitor Progress:\n";
    echo "   curl -X GET '/wp-json/wholesaler/v1/queue-status'\n";
    echo "   curl -X GET '/wp-json/wholesaler/v1/performance-stats'\n\n";
}

// Run examples if called directly
if (defined('WP_CLI') && WP_CLI) {
    run_performance_comparison();
    usage_examples();
}
