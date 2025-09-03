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

    /**
     * Check if a product variation already exists for given attributes
     *
     * @param int   $product_id
     * @param array $attributes (e.g. ['Color' => 'Red', 'Size' => 'M'])
     * @return int|false  Variation ID if exists, false otherwise
     */
    public function check_variation_exists( $product_id, $attributes ) {
        global $wpdb;

        // Prepare meta query for attributes
        $meta_query = [];
        foreach ( $attributes as $attribute_name => $attribute_value ) {
            $taxonomy     = 'attribute_' . sanitize_title( $attribute_name );
            $meta_query[] = $wpdb->prepare(
                "(meta_key = %s AND meta_value = %s)",
                $taxonomy,
                $attribute_value
            );
        }

        if ( empty( $meta_query ) ) {
            return false;
        }

        // Query variations
        $query = "
        SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_parent = %d
        AND p.post_type = 'product_variation'
        AND (" . implode( ' OR ', $meta_query ) . ")
        GROUP BY p.ID
        ";

        $variation_id = $wpdb->get_var( $wpdb->prepare( $query, $product_id ) );

        return $variation_id ? intval( $variation_id ) : false;
    }

    /**
     * Check if variation ID belongs to a given product ID
     */
    public function variation_belongs_to_product( int $variation_id, int $product_id ) : bool {
        if ( $variation_id <= 0 || $product_id <= 0 ) {
            return false;
        }
        $parent_id = wp_get_post_parent_id( $variation_id );
        return (int) $parent_id === (int) $product_id;
    }

    /**
     * Check if variation exists by SKU
     */
    public function get_variation_id_by_sku( $sku ) {
        global $wpdb;

        $variation_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
                $sku
            )
        );

        return $variation_id ? intval( $variation_id ) : false;
    }

    /**
     * Generate normalized variation SKU based on base SKU, supplier code and attribute values
     */
    public function generate_variation_sku( $baseSku, $supplierCode, $attributes = [] ) {
        $parts = [];
        if ( is_string( $baseSku ) && $baseSku !== '' ) {
            $parts[] = $baseSku;
        }
        if ( is_string( $supplierCode ) && $supplierCode !== '' ) {
            $parts[] = $supplierCode;
        }
        foreach ( $attributes as $attr ) {
            $slug = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( (string) $attr ) );
            if ( $slug !== '' ) {
                $parts[] = $slug;
            }
        }
        $sku = trim( implode( '-', $parts ), '-' );
        $sku = preg_replace( '/-+/', '-', $sku );
        if ( $sku === '' ) {
            $sku = uniqid( 'var-' );
        }
        return $sku;
    }

    /**
     * Update product taxonomies (categories, tags, brand)
     */
    public function update_product_taxonomies( int $product_id, array $mapped_product ) {
        $categories    = isset( $mapped_product['categories'] ) ? $mapped_product['categories'] : [];
        $tags          = isset( $mapped_product['tags'] ) ? $mapped_product['tags'] : [];
        $product_brand = isset( $mapped_product['brand'] ) ? $mapped_product['brand'] : '';

        if ( !empty( $categories ) ) {
            wp_set_object_terms( $product_id, $categories, 'product_cat' );
        }

        if ( !empty( $tags ) ) {
            wp_set_object_terms( $product_id, $tags, 'product_tag' );
        }

        if ( !empty( $product_brand ) ) {
            wp_set_object_terms( $product_id, $product_brand, 'product_brand' );
        }
    }

    public function mark_as_complete( string $table_name, int $serial_id ) {
        try {
            global $wpdb;

            $wpdb->update(
                $table_name,
                [ 'status' => Status_Enum::COMPLETED->value ],
                [ 'id' => $serial_id ],
                [ '%s' ],
                [ '%d' ]
            );

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}