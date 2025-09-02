<?php

function get_all_product_brands( $hide_empty = false ) {
    $terms = get_terms( [
        'taxonomy'   => 'product_brand', // Change if your brand taxonomy is different (e.g. 'pwb-brand')
        'orderby'    => 'name',
        'hide_empty' => $hide_empty,
    ] );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return [];
    }

    // Extract only brand names into a simple array
    return wp_list_pluck( $terms, 'name' );
}

// Function to calculate final product price
function calculate_product_price_with_margin( $wholesaler_price, $brand ) {
    // Ensure base_price is float
    $wholesaler_price = (float) $wholesaler_price;

    // Get profit margin from settings (default 0 if not set)
    $profit_margin = (float) get_option( 'wholesaler_retail_margin', 0 );

    // If brand is Mediolano, return wholesale price only
    if ( strtolower( $brand ) === 'mediolano' ) {
        return number_format( $wholesaler_price, 2, '.', '' );
    }

    // Otherwise, add margin
    $product_regular_price = $wholesaler_price * ( 1 + ( $profit_margin / 100 ) );

    return number_format( $product_regular_price, 2, '.', '' );
}

function put_program_logs( $data ) {

    // Ensure the directory for logs exists
    $directory = WHOLESALER_PLUGIN_PATH . '/program_logs/';
    if ( !file_exists( $directory ) ) {
        mkdir( $directory, 0777, true );
    }

    // Construct the log file path
    // $file_name  = $directory . 'program_logs.txt';
    $file_name = $directory . 'program_logs.log';

    // Append the current datetime to the log entry
    $current_datetime = date( 'Y-m-d H:i:s' );
    $data             = $data . ' - ' . $current_datetime;

    // Write the log entry to the file
    if ( file_put_contents( $file_name, $data . "\n\n", FILE_APPEND | LOCK_EX ) !== false ) {
        return "Data appended to file successfully.";
    } else {
        return "Failed to append data to file.";
    }
}