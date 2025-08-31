<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

class Wholesaler_MADA_Wholesaler_Service {

    public function map( $product_obj ) {
        $payload = is_string( $product_obj->product_data ) ? json_decode( $product_obj->product_data, true ) : (array) $product_obj->product_data;

        // Extract basic product information
        $name = $this->extract_name( $payload['NAME'] ?? [] );
        if ( empty( $name ) && isset( $product_obj->sku ) ) {
            $name = $product_obj->sku;
        }

        $brand = $this->extract_brand( $payload['PRODUCER'] ?? '', $product_obj->brand ?? '' );
        $description = $this->extract_description( $payload['DESC'] ?? [], $payload['PRODUCER_SECURITY_INFO'] ?? '' );
        
        // Extract images
        $images_payload = $this->build_images_payload_from_mada( $payload['IMAGES'] ?? [] );
        
        // Extract categories
        $categories_terms = $this->parse_mada_categories( $payload['CATEGORIES'] ?? [] );
        
        // Extract price and calculate retail price
        $wholesaler_price = isset( $payload['PRICE'] ) ? (float) $payload['PRICE'] : 0;
        $product_regular_price = calculate_product_price_with_margin( $wholesaler_price, $brand );
        
        // Extract size and color options from models
        $size_options = [];
        $color_options = [];
        $variations = [];
        
        if ( isset( $payload['MODELS']['MODEL'] ) && is_array( $payload['MODELS']['MODEL'] ) ) {
            foreach ( $payload['MODELS']['MODEL'] as $model ) {
                // Extract sizes
                if ( isset( $model['SIZE'] ) && is_array( $model['SIZE'] ) ) {
                    foreach ( $model['SIZE'] as $size ) {
                        if ( !empty( $size ) && !in_array( $size, $size_options, true ) ) {
                            $size_options[] = $size;
                        }
                    }
                }
                
                // Extract colors (if available)
                if ( isset( $model['COLOR'] ) && is_array( $model['COLOR'] ) ) {
                    foreach ( $model['COLOR'] as $color ) {
                        if ( !empty( $color ) && !in_array( $color, $color_options, true ) ) {
                            $color_options[] = $color;
                        }
                    }
                }
            }
            
            // Create variations for each model
            foreach ( $payload['MODELS']['MODEL'] as $index => $model ) {
                $variation_sku = $product_obj->sku . '-' . ( $index + 1 );
                
                $variation = [
                    'sku'            => $variation_sku,
                    'regular_price'  => (string) $product_regular_price,
                    'wholesale_price' => (string) $wholesaler_price,
                    'manage_stock'   => true,
                    'stock_quantity' => 0, // Default stock, can be updated later
                    'attributes'     => [],
                    'meta_data'      => [
                        [ 'key' => '_mada_model_index', 'value' => $index + 1 ],
                    ],
                ];
                
                // Add size attribute if available
                if ( isset( $model['SIZE'] ) && is_array( $model['SIZE'] ) && !empty( $model['SIZE'] ) ) {
                    $variation['attributes'][] = [
                        'name'  => 'Size',
                        'option' => $model['SIZE'][0] // Use first size as default
                    ];
                }
                
                // Add color attribute if available
                if ( isset( $model['COLOR'] ) && is_array( $model['COLOR'] ) && !empty( $model['COLOR'] ) ) {
                    $variation['attributes'][] = [
                        'name'  => 'Color',
                        'option' => $model['COLOR'][0] // Use first color as default
                    ];
                }
                
                $variations[] = $variation;
            }
        }
        
        // Build attributes array for WooCommerce
        $attributes = [];
        if ( !empty( $color_options ) ) {
            $attributes[] = [
                'name'      => 'Color',
                'position'  => 0,
                'visible'   => true,
                'variation' => true,
                'options'   => $color_options,
            ];
        }
        if ( !empty( $size_options ) ) {
            $attributes[] = [
                'name'      => 'Size',
                'position'  => 1,
                'visible'   => true,
                'variation' => true,
                'options'   => $size_options,
            ];
        }
        
        // Add VAT information as meta
        $vat_rate = isset( $payload['VAT'] ) ? (int) $payload['VAT'] : 0;
        
        return [
            'name'           => $name,
            'sku'            => (string) ( $product_obj->sku ?? '' ),
            'brand'          => $brand,
            'description'    => $description,
            'regular_price'  => (string) $product_regular_price,
            'sale_price'     => '',
            'wholesale_price' => (string) $wholesaler_price,
            'images_payload' => $images_payload,
            'categories'     => $categories_terms,
            'category_terms' => array_map( function ( $name ) { return [ 'name' => $name ]; }, $categories_terms ),
            'tags'           => [],
            'attributes'     => $attributes,
            'variations'     => $variations,
            'meta_data'      => [
                [ 'key' => '_mada_vat_rate', 'value' => $vat_rate ],
                [ 'key' => '_mada_producer_address', 'value' => $payload['PRODUCER_ADDRESS'] ?? '' ],
                [ 'key' => '_mada_similar_products', 'value' => $payload['SIMILAR_PRODUCTS']['SIMILAR'] ?? '' ],
            ],
        ];
    }
    
    /**
     * Extract product name from MADA data
     */
    private function extract_name( $name_data ) {
        if ( empty( $name_data ) ) {
            return '';
        }
        
        // If it's an array, try to find a non-empty name
        if ( is_array( $name_data ) ) {
            foreach ( $name_data as $name ) {
                if ( !empty( $name ) ) {
                    return $name;
                }
            }
        }
        
        // If it's a string, return as is
        if ( is_string( $name_data ) ) {
            return $name_data;
        }
        
        return '';
    }
    
    /**
     * Extract brand information
     */
    private function extract_brand( $producer, $fallback_brand ) {
        if ( !empty( $producer ) ) {
            return $producer;
        }
        
        return $fallback_brand;
    }
    
    /**
     * Extract and format product description
     */
    private function extract_description( $desc_data, $security_info ) {
        $description_parts = [];
        
        // Add main description if available
        if ( !empty( $desc_data ) && is_array( $desc_data ) ) {
            foreach ( $desc_data as $desc ) {
                if ( !empty( $desc ) ) {
                    $description_parts[] = $desc;
                }
            }
        }
        
        // Add security info if available
        if ( !empty( $security_info ) ) {
            $description_parts[] = "\n\n" . $security_info;
        }
        
        return implode( "\n", $description_parts );
    }
    
    /**
     * Build images payload from MADA image data
     */
    private function build_images_payload_from_mada( $images_data ) {
        $result = [];
        
        if ( empty( $images_data ) || !isset( $images_data['IMG'] ) ) {
            return $result;
        }
        
        $img_array = $images_data['IMG'];
        
        // Handle single image
        if ( is_string( $img_array ) ) {
            $result[] = [ 'src' => $img_array ];
            return $result;
        }
        
        // Handle array of images
        if ( is_array( $img_array ) ) {
            foreach ( $img_array as $img_url ) {
                if ( is_string( $img_url ) && !empty( $img_url ) ) {
                    $result[] = [ 'src' => $img_url ];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Parse MADA categories structure
     */
    private function parse_mada_categories( $categories_data ) {
        if ( empty( $categories_data ) || !isset( $categories_data['CATEGORY'] ) ) {
            return [];
        }
        
        $category = $categories_data['CATEGORY'];
        $result = [];
        
        // Extract category IDs from attributes
        if ( isset( $category['@attributes'] ) ) {
            $attrs = $category['@attributes'];
            
            if ( isset( $attrs['c1'] ) ) {
                $result[] = 'Category ' . $attrs['c1'];
            }
            
            if ( isset( $attrs['c2'] ) ) {
                $result[] = 'Category ' . $attrs['c2'];
            }
        }
        
        return $result;
    }
}