<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

class Wholesaler_AREN_Wholesaler_Service {

    public function map( $product_obj ) {
        $payload = is_string( $product_obj->product_data ) ? json_decode( $product_obj->product_data, true ) : (array) $product_obj->product_data;

        // Extract basic product information
        $name        = $this->extract_name( $payload, $product_obj );
        $brand       = $this->extract_brand( $payload, $product_obj );
        $description = $this->extract_description( $payload );

        // Extract images
        $images_payload = $this->build_images_payload( $payload );

        // Extract categories
        $categories_terms = $this->parse_categories( $payload );

        // Extract attributes and variations
        $attributes = $this->build_attributes( $payload );
        $variations = $this->build_variations( $payload, $product_obj );

        // Extract EAN and other meta data
        $ean = $this->extract_ean( $payload );

        // return mapped data
        return [
            'name'           => $name,
            'sku'            => (string) ( $product_obj->sku ?? '' ),
            'brand'          => $brand,
            'description'    => $description,
            'images_payload' => $images_payload,
            'categories'     => $categories_terms,
            'category_terms' => array_map( function ($name) {
                return [ 'name' => $name ];
            }, $categories_terms ),
            'tags'           => [],
            'attributes'     => $attributes,
            'variations'     => $variations,
            'meta_data'      => [
                [ 'key' => '_ean', 'value' => $ean ],
                [ 'key' => '_aren_tax_rate', 'value' => $payload['tax']['value'] ?? '' ],
                [ 'key' => '_aren_unit', 'value' => $payload['unit'] ?? '' ],
                [ 'key' => '_aren_weight', 'value' => $payload['weight'] ?? '' ],
            ],
        ];
    }

    /**
     * Extract product name
     */
    private function extract_name( $payload, $product_obj ) {
        if ( isset( $payload['name'] ) && !empty( $payload['name'] ) ) {
            return $payload['name'];
        }

        // Fallback to SKU if no name found
        if ( isset( $product_obj->sku ) ) {
            return $product_obj->sku;
        }

        return '';
    }

    /**
     * Extract brand information
     */
    private function extract_brand( $payload, $product_obj ) {
        if ( isset( $payload['producer'] ) && !empty( $payload['producer'] ) ) {
            return $payload['producer'];
        }

        // Fallback to product object brand
        if ( isset( $product_obj->brand ) ) {
            return $product_obj->brand;
        }

        return '';
    }

    /**
     * Extract product description
     */
    private function extract_description( $payload ) {
        if ( isset( $payload['description'] ) && !empty( $payload['description'] ) ) {
            return $payload['description'];
        }

        return '';
    }

    /**
     * Extract EAN code
     */
    private function extract_ean( $payload ) {
        if ( isset( $payload['attributes']['attribute'] ) && is_array( $payload['attributes']['attribute'] ) ) {
            foreach ( $payload['attributes']['attribute'] as $attr ) {
                if ( isset( $attr['name'] ) && $attr['name'] === 'EAN' && isset( $attr['values']['value'] ) ) {
                    return $attr['values']['value'];
                }
            }
        }

        return '';
    }

    /**
     * Build images payload
     */
    private function build_images_payload( $payload ) {
        $result = [];

        if ( isset( $payload['images']['image'] ) ) {
            $images = $payload['images']['image'];

            // Case 1: Single image (associative array)
            if ( isset( $images['url'] ) ) {
                $result[] = [ 'src' => $images['url'] ];
            }
            // Case 2: Multiple images (array of associative arrays)
            elseif ( is_array( $images ) ) {
                foreach ( $images as $img ) {
                    if ( isset( $img['url'] ) ) {
                        $result[] = [ 'src' => $img['url'] ];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Parse categories
     */
    private function parse_categories( $payload ) {
        $categories = [];

        if ( isset( $payload['categories']['category'] ) && !empty( $payload['categories']['category'] ) ) {
            $category_path = $payload['categories']['category'];
            $categories    = array_map( 'trim', explode( '/', $category_path ) );
        }

        return $categories;
    }

    /**
     * Build WooCommerce attributes
     */
    private function build_attributes( array $payload ) {
        $attributes = [];

        if ( isset( $payload['combinations']['combination'] ) ) {
            $combinations = $payload['combinations']['combination'];

            if ( isset( $combinations['id'] ) ) {
                $combinations = [ $combinations ]; // normalize to array
            }

            $options_map = [];

            foreach ( $combinations as $combo ) {
                if ( isset( $combo['attributes']['attribute'] ) ) {
                    foreach ( $combo['attributes']['attribute'] as $attr ) {
                        $name  = $attr['name'];
                        $value = $attr['value'];

                        if ( !isset( $options_map[$name] ) ) {
                            $options_map[$name] = [];
                        }

                        if ( !in_array( $value, $options_map[$name], true ) ) {
                            $options_map[$name][] = $value;
                        }
                    }
                }
            }

            // Build WooCommerce-ready attributes
            foreach ( $options_map as $name => $options ) {
                $attributes[] = [
                    'name'      => $name,
                    'slug'      => sanitize_title( $name ), // "pa_kolor"
                    'visible'   => true,
                    'variation' => true,
                    'options'   => $options,
                ];
            }
        }

        // put_program_logs( "Aren Attributes (final): " . json_encode( $attributes ) );
        return $attributes;
    }

    /**
     * Build product variations
     */
    private function build_variations( $payload, $product_obj ) {
        $variations = [];

        if ( isset( $payload['combinations']['combination'] ) ) {
            $combination = $payload['combinations']['combination'];

            // Handle single combination
            if ( isset( $combination['id'] ) ) {
                $variations[] = $this->create_variation( $combination, $product_obj );
            }
            // Handle multiple combinations
            elseif ( is_array( $combination ) ) {
                foreach ( $combination as $combo ) {
                    if ( isset( $combo['id'] ) ) {
                        $variations[] = $this->create_variation( $combo, $product_obj );
                    }
                }
            }
        }

        // put to log file
        // put_program_logs( "Aren Variations: " . json_encode( $variations ) );

        return $variations;
    }

    /**
     * Create individual variation
     */
    private function create_variation( array $combination, object $product_obj ) {
        // Extract price
        $wholesaler_price = $combination['price_netto'] ?? 0;
        $brand = $product_obj->brand ?? '';

        // calculate aren product price with margin
        $product_regular_price = calculate_product_price_with_margin( $wholesaler_price, $brand );

        // Define default values
        $color = '';
        $size  = '';

        // Extract color and size keys
        $color_key = isset( $combination['attributes']['attribute'][0]['name'] ) ? $combination['attributes']['attribute'][0]['name'] : '';
        $size_key  = isset( $combination['attributes']['attribute'][1]['name'] ) ? $combination['attributes']['attribute'][1]['name'] : '';

        // Extract color and size values
        $color_value = isset( $combination['attributes']['attribute'][0]['value'] ) ? $combination['attributes']['attribute'][0]['value'] : '';
        $size_value  = isset( $combination['attributes']['attribute'][1]['value'] ) ? $combination['attributes']['attribute'][1]['value'] : '';

        // Assign color and size based on keys
        if ( 'Kolor' === $color_key ) {
            $color = $color_value;
        }
        if ( 'Rozmiar' === $size_key ) {
            $size = $size_value;
        }

        // Generate a unique SKU
        $sku        = $combination['code'] ?? $product_obj->sku;
        $unique_sku = $sku . '-' . uniqid();

        // Build variation array
        $variation = [
            'sku'            => $unique_sku,
            'regular_price'  => (string) $product_regular_price, 
            'manage_stock'   => true,
            'stock_quantity' => (int) ( $combination['quantity'] ?? 0 ),
            'attributes'     => [
                [ 'name' => $color_key, 'option' => $color ],
                [ 'name' => $size_key, 'option' => $size ],
            ],
            'meta_data'      => [
                [ 'key' => '_price_value', 'value' => $combination['price_value'] ?? '' ],
                [ 'key' => '_price_modifier', 'value' => $combination['price_modifier'] ?? '' ],
                [ 'key' => '_default_price_netto', 'value' => $combination['default_price_netto'] ?? '' ],
            ],
        ];

        return $variation;
    }
}