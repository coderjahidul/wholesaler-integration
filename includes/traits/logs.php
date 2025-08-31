<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

trait Wholesaler_Logs_Trait {

    public function log_message( $data ) {
        $directory = __DIR__ . '/../program_logs/';
        if ( !file_exists( $directory ) ) {
            if ( !wp_mkdir_p( $directory ) ) {
                return "Failed to create directory.";
            }
        }

        $file_name = $directory . 'import_products.log';
        $current_datetime = gmdate( 'Y-m-d H:i:s' );
        $data             = $data . ' - ' . $current_datetime;

        if ( file_put_contents( $file_name, $data . "\n\n", FILE_APPEND | LOCK_EX ) !== false ) {
            return "Data appended to file successfully.";
        }
        return "Failed to append data to file.";
    }
} 