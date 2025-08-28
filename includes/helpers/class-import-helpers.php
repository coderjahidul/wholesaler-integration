<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

class Wholesaler_Import_Helpers {

    public function check_product_exists( $sku ) {
        if ( empty( $sku ) ) {
            return false;
        }

        $args = [
            'post_type'      => 'product',
            'meta_query'     => [
                [
                    'key'     => '_sku',
                    'value'   => $sku,
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];

        $existing_products = new WP_Query( $args );

        if ( $existing_products->have_posts() ) {
            return (int) $existing_products->posts[0];
        }

        return false;
    }

    public function update_product_taxonomies( int $product_id, array $mapped_product ) {
        $categories = isset( $mapped_product['categories'] ) ? $mapped_product['categories'] : [];
        $tags       = isset( $mapped_product['tags'] ) ? $mapped_product['tags'] : [];

        if ( !empty( $categories ) ) {
            wp_set_object_terms( $product_id, $categories, 'product_cat' );
        }

        if ( !empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, 'product_tag' );
        }
    }

    public function mark_as_complete( string $table_name, int $serial_id ) {
        try {
            global $wpdb;

            $wpdb->update(
                $table_name,
                [ 'status' => 'completed' ],
                [ 'id' => $serial_id ],
                [ '%s' ],
                [ '%d' ]
            );

            return true;
        } catch ( Exception $e ) {
            return false;
        }
    }
} 