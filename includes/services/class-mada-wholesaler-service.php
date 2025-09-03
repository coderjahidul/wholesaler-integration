<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

class Wholesaler_MADA_Wholesaler_Service {

    public function map( object|array $product_obj ) {
        $payload = is_string( $product_obj->product_data ) ? json_decode( $product_obj->product_data, true ) : (array) $product_obj->product_data;
        $product = $payload['PRODUCT'] ?? [];

        // --- Basic info ---
        $name        = $product['NAME'] ?? (string) ( $product_obj->name ?? '' );
        $brand       = $this->extract_brand( $product['PRODUCER'] ?? '', $product_obj->brand ?? '' );
        $description = $this->extract_description( $product['DESC'] ?? '', $product['PRODUCER_SECURITY_INFO'] ?? '' );

        // --- Images ---
        $images_payload = $this->build_images_payload_from_mada( $product['IMAGES'] ?? [] );

        // --- Categories ---
        $categories_terms = $this->parse_mada_categories( $product['CATEGORIES'] ?? [] );

        // --- Prices ---
        $wholesaler_price      = isset( $product['PRICE'] ) ? (float) $product['PRICE'] : 0;
        $product_regular_price = calculate_product_price_with_margin( $wholesaler_price, $brand );

        // --- Variations ---
        $size_options  = [];
        $color_options = [];
        $variations    = [];

        if ( isset( $product['MODELS']['MODEL'] ) ) {
            $models = $product['MODELS']['MODEL'];

            // Normalize single vs array of models
            if ( isset( $models['COLOR'] ) ) {
                $models = [ $models ];
            }

            foreach ( $models as $model ) {
                $colorRaw = $model['COLOR'] ?? '';
                $color    = trim( explode( '/', (string) $colorRaw )[0] );
                if ( $color !== '' && !in_array( $color, $color_options, true ) ) {
                    $color_options[] = $color;
                }

                if ( isset( $model['SIZE'] ) ) {
                    $sizes = $model['SIZE'];
                    if ( isset( $sizes['_ean'] ) ) {
                        $sizes = [ $sizes ];
                    }

                    foreach ( $sizes as $sizeEntry ) {
                        $sizeText = (string) ( $sizeEntry['__text'] ?? '' );
                        $ean      = (string) ( $sizeEntry['_ean'] ?? '' );
                        $qty      = (int) ( $sizeEntry['_amount'] ?? 0 );

                        if ( $sizeText !== '' && !in_array( $sizeText, $size_options, true ) ) {
                            $size_options[] = $sizeText;
                        }

                        $baseSku   = (string) ( $product_obj->sku ?? '' );
                        $colorSlug = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $color ) );
                        $sizeSlug  = preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $sizeText ) );
                        $varSku    = trim( $baseSku . '-' . $colorSlug . '-' . $sizeSlug, '-' );

                        $variation = [
                            'sku'            => $varSku,
                            'regular_price'  => (string) $product_regular_price,
                            'manage_stock'   => true,
                            'stock_quantity' => $qty,
                            'attributes'     => [],
                            'meta_data'      => [],
                        ];

                        if ( $color !== '' ) {
                            $variation['attributes'][] = [ 'name' => 'Color', 'option' => $color ];
                        }
                        if ( $sizeText !== '' ) {
                            $variation['attributes'][] = [ 'name' => 'Size', 'option' => $sizeText ];
                        }
                        if ( $ean !== '' ) {
                            $variation['meta_data'][] = [ 'key' => '_ean', 'value' => $ean ];
                        }

                        $variations[] = $variation;
                    }
                }
            }
        }

        // --- Attributes ---
        $attributes = [];
        if ( !empty( $color_options ) ) {
            $attributes[] = [
                'name'      => 'Color',
                'position'  => 0,
                'visible'   => true,
                'variation' => true,
                'options'   => array_values( $color_options ),
            ];
        }
        if ( !empty( $size_options ) ) {
            $attributes[] = [
                'name'      => 'Size',
                'position'  => 1,
                'visible'   => true,
                'variation' => true,
                'options'   => array_values( $size_options ),
            ];
        }

        // --- VAT ---
        $vat_rate = isset( $product['VAT'] ) ? (int) $product['VAT'] : 0;

        // put_program_logs( 'Attributes from Mada: ' . json_encode( $attributes ) );
        // put_program_logs( 'Variations from Mada: ' . json_encode( $variations ) );

        return [
            'name'            => $name,
            'sku'             => (string) ( $product_obj->sku ?? '' ),
            'brand'           => '',
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
                [ 'key' => '_mada_vat_rate', 'value' => $vat_rate ],
                [ 'key' => '_mada_producer_address', 'value' => $product['PRODUCER_ADDRESS'] ?? '' ],
                [ 'key' => '_mada_similar_products', 'value' => $product['SIMILAR_PRODUCTS']['SIMILAR'] ?? '' ],
            ],
        ];
    }


    /**
     * Extract brand information
     */
    private function extract_brand( $producer, $fallback_brand ) {
        if ( !empty( $producer ) && !preg_match( '/^\d+$/', (string) $producer ) ) {
            return $producer;
        }

        return $fallback_brand;
    }

    /**
     * Extract and format product description
     */
    private function extract_description( $desc_data, $security_info ) {
        $description_parts = [];

        // Add main description if available (string or array)
        if ( !empty( $desc_data ) ) {
            if ( is_array( $desc_data ) ) {
                foreach ( $desc_data as $desc ) {
                    if ( !empty( $desc ) ) {
                        $description_parts[] = $desc;
                    }
                }
            } elseif ( is_string( $desc_data ) ) {
                $description_parts[] = $desc_data;
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

        // Handle single image as string
        if ( is_string( $img_array ) ) {
            $result[] = [ 'src' => $img_array ];
            return $result;
        }

        // Handle array
        if ( is_array( $img_array ) ) {
            foreach ( $img_array as $img_entry ) {
                if ( is_string( $img_entry ) && $img_entry !== '' ) {
                    $result[] = [ 'src' => $img_entry ];
                } elseif ( is_array( $img_entry ) && isset( $img_entry['__text'] ) && is_string( $img_entry['__text'] ) ) {
                    $result[] = [ 'src' => $img_entry['__text'] ];
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
        $result   = [];

        // Only use __cdata path
        if ( isset( $category['__cdata'] ) && is_string( $category['__cdata'] ) && $category['__cdata'] !== '' ) {
            // Split path by slash
            $pathParts = array_map( 'trim', explode( '/', $category['__cdata'] ) );
            foreach ( $pathParts as $part ) {
                if ( $part !== '' && !in_array( $part, $result, true ) ) {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

}