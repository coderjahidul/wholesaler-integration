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

        // Extract price and calculate retail price
        $wholesaler_price      = $this->extract_price( $payload );
        $product_regular_price = calculate_product_price_with_margin( $wholesaler_price, $brand );

        // Extract attributes and variations
        $attributes = $this->build_attributes( $payload );
        $variations = $this->build_variations( $payload, $product_obj, $product_regular_price, $wholesaler_price );

        // Extract EAN and other meta data
        $ean   = $this->extract_ean( $payload );
        $size  = $this->extract_size( $payload );
        $color = $this->extract_color( $payload );

        return [
            'name'            => $name,
            'sku'             => (string) ( $product_obj->sku ?? '' ),
            'brand'           => $brand,
            'description'     => $description,
            'regular_price'   => (string) $product_regular_price,
            'sale_price'      => '',
            'wholesale_price' => (string) $wholesaler_price,
            'images_payload'  => $images_payload,
            'categories'      => $categories_terms,
            'category_terms'  => array_map( function ($name) {
                return [ 'name' => $name ];
            }, $categories_terms ),
            'tags'            => [],
            'attributes'      => $attributes,
            'variations'      => $variations,
            'meta_data'       => [
                [ 'key' => '_ean', 'value' => $ean ],
                [ 'key' => '_aren_size', 'value' => $size ],
                [ 'key' => '_aren_color', 'value' => $color ],
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
     * Extract product price
     */
    private function extract_price( $payload ) {
        // Try to get price from combinations first, then fallback to base price
        if ( isset( $payload['combinations']['combination']['price_netto'] ) ) {
            return (float) $payload['combinations']['combination']['price_netto'];
        }

        if ( isset( $payload['price_netto'] ) ) {
            return (float) $payload['price_netto'];
        }

        return 0;
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
     * Extract size information
     */
    private function extract_size( $payload ) {
        if ( isset( $payload['attributes']['attribute'] ) && is_array( $payload['attributes']['attribute'] ) ) {
            foreach ( $payload['attributes']['attribute'] as $attr ) {
                if ( isset( $attr['name'] ) && $attr['name'] === 'Rozmiar' && isset( $attr['values']['value'] ) ) {
                    return $attr['values']['value'];
                }
            }
        }

        return '';
    }

    /**
     * Extract color information
     */
    private function extract_color( $payload ) {
        if ( isset( $payload['attributes']['attribute'] ) && is_array( $payload['attributes']['attribute'] ) ) {
            foreach ( $payload['attributes']['attribute'] as $attr ) {
                if ( isset( $attr['name'] ) && $attr['name'] === 'Odcienie' && isset( $attr['values']['value'] ) ) {
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
    private function build_attributes( $payload ) {
        $attributes = [];

        if ( isset( $payload['attributes']['attribute'] ) && is_array( $payload['attributes']['attribute'] ) ) {
            $size_options  = [];
            $color_options = [];

            foreach ( $payload['attributes']['attribute'] as $attr ) {
                if ( isset( $attr['name'] ) && isset( $attr['values']['value'] ) ) {
                    $attr_name  = $attr['name'];
                    $attr_value = $attr['values']['value'];

                    if ( $attr_name === 'Rozmiar' ) {
                        $size_options[] = $attr_value;
                    } elseif ( $attr_name === 'Odcienie' ) {
                        $color_options[] = $attr_value;
                    }
                }
            }

            // Add size attribute
            if ( !empty( $size_options ) ) {
                $attributes[] = [
                    'name'      => 'Size',
                    'position'  => 0,
                    'visible'   => true,
                    'variation' => true,
                    'options'   => $size_options,
                ];
            }

            // Add color attribute
            if ( !empty( $color_options ) ) {
                $attributes[] = [
                    'name'      => 'Color',
                    'position'  => 1,
                    'visible'   => true,
                    'variation' => true,
                    'options'   => $color_options,
                ];
            }
        }

        // put to log file
        put_program_logs( "Aren Attributes: " . json_encode( $attributes ) );

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
        put_program_logs( "Aren Variations: " . json_encode( $variations ) );

        return $variations;
    }

    /**
     * Create individual variation
     */
    private function create_variation( $combination, $product_obj ) {

        // extract price
        $price = $combination['price_netto'] ?? 0;

        // define default values
        $color = '';
        $size  = '';

        // extract color and size keys
        $color_key = isset( $combination['attributes']['attribute'][0]['name'] ) ? $combination['attributes']['attribute'][0]['name'] : '';
        $size_key  = isset( $combination['attributes']['attribute'][1]['name'] ) ? $combination['attributes']['attribute'][1]['name'] : '';

        // extract color and size values
        $color_value = isset( $combination['attributes']['attribute'][0]['value'] ) ? $combination['attributes']['attribute'][0]['value'] : '';
        $size_value  = isset( $combination['attributes']['attribute'][1]['value'] ) ? $combination['attributes']['attribute'][1]['value'] : '';

        // Assign color and size based on keys
        if ( 'Kolor' === $color_key ) {
            $color = $color_value;
        }
        if ( 'Rozmiar' === $size_key ) {
            $size = $size_value;
        }

        // Build variation array
        $variation = [
            'sku'            => $combination['code'] ?? $product_obj->sku,
            'regular_price'  => (string) $price, // TODO: calculate price with profit margin
            'manage_stock'   => true,
            'stock_quantity' => (int) ( $combination['quantity'] ?? 0 ),
            'attributes'     => [
                [ 'name' => 'Color', 'option' => $color ],
                [ 'name' => 'Size', 'option' => $size ],
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