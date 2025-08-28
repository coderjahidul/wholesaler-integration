<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

class Wholesaler_JS_Wholesaler_Service {

    public function map( $product_obj ) {
        $payload = is_string( $product_obj->product_data ) ? json_decode( $product_obj->product_data, true ) : (array) $product_obj->product_data;

        $name        = isset( $payload['name'] ) && is_array( $payload['name'] ) ? ( $payload['name']['en'] ?? ( $payload['name']['pl'] ?? '' ) ) : ( $payload['name'] ?? '' );
        if ( empty( $name ) && isset( $product_obj->sku ) ) {
            $name = $product_obj->sku;
        }

        $brand       = isset( $payload['brand']['name'] ) ? $payload['brand']['name'] : ( $product_obj->brand ?? '' );
        $description = isset( $payload['attributes']['opis'] ) && is_array( $payload['attributes']['opis'] ) ? implode( "\n", $payload['attributes']['opis'] ) : '';

        $images_payload = $this->build_images_payload_from_js( $payload['images'] ?? [] );

        $categories_terms = $this->parse_category_path_to_terms( $payload['category_keys'] ?? '' );

        $size_options  = [];
        $color_options = [];
        $variations    = [];

        if ( isset( $payload['units']['unit'] ) && is_array( $payload['units']['unit'] ) ) {
            foreach ( $payload['units']['unit'] as $unit ) {
                $size  = isset( $unit['size'] ) ? (string) $unit['size'] : '';
                $color = isset( $unit['color'] ) ? (string) $unit['color'] : '';
                if ( $size !== '' && !in_array( $size, $size_options, true ) ) {
                    $size_options[] = $size;
                }
                if ( $color !== '' && !in_array( $color, $color_options, true ) ) {
                    $color_options[] = $color;
                }
            }

            foreach ( $payload['units']['unit'] as $unit ) {
                $unitSku  = $unit['@attributes']['sku'] ?? '';
                $unitEan  = $unit['@attributes']['ean'] ?? '';
                $size     = $unit['size'] ?? '';
                $color    = $unit['color'] ?? '';
                $stockQty = isset( $unit['stock'] ) ? (int) $unit['stock'] : 0;

                $variations[] = [
                    'sku'            => $unitSku,
                    'regular_price'  => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
                    'manage_stock'   => true,
                    'stock_quantity' => $stockQty,
                    'attributes'     => [
                        [ 'name' => 'Color', 'option' => $color ],
                        [ 'name' => 'Size',  'option' => $size ],
                    ],
                    'meta_data'      => [
                        [ 'key' => '_ean', 'value' => $unitEan ],
                    ],
                ];
            }
        }

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

        return [
            'name'           => $name,
            'sku'            => (string) ( $product_obj->sku ?? '' ),
            'brand'          => $brand,
            'description'    => $description,
            'regular_price'  => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
            'sale_price'     => isset( $payload['price'] ) ? (string) $payload['price'] : '0',
            'images_payload' => $images_payload,
            'categories'     => $categories_terms,
            'category_terms' => array_map( function ( $name ) { return [ 'name' => $name ]; }, $categories_terms ),
            'tags'           => [],
            'attributes'     => $attributes,
            'variations'     => $variations,
        ];
    }

    private function parse_category_path_to_terms( $category_path ) {
        if ( empty( $category_path ) ) {
            return [];
        }
        $parts = array_map( 'trim', explode( '|', $category_path ) );
        return $parts;
    }

    private function build_images_payload_from_js( $images_field ) {
        $result = [];
        if ( empty( $images_field ) ) {
            return $result;
        }

        if ( is_array( $images_field ) && isset( $images_field[0] ) && is_string( $images_field[0] ) ) {
            foreach ( $images_field as $url ) {
                if ( is_string( $url ) && $url !== '' ) {
                    $result[] = [ 'src' => $url ];
                }
            }
            return $result;
        }

        if ( is_array( $images_field ) && isset( $images_field['image'] ) ) {
            $img = $images_field['image'];
            if ( is_array( $img ) && isset( $img['image_url'] ) && is_string( $img['image_url'] ) ) {
                $result[] = [ 'src' => $img['image_url'] ];
                return $result;
            }
            if ( is_array( $img ) ) {
                foreach ( $img as $entry ) {
                    if ( is_array( $entry ) && isset( $entry['image_url'] ) && is_string( $entry['image_url'] ) ) {
                        $result[] = [ 'src' => $entry['image_url'] ];
                    }
                }
                return $result;
            }
        }

        return $result;
    }
} 