<?php

defined("ABSPATH") || exit("Direct Access Not Allowed");

class Wholesaler_AREN_Wholesaler_Service {

    public function map($product_obj) {
        $payload = is_string($product_obj->product_data) ? json_decode($product_obj->product_data, true) : (array) $product_obj->product_data;

        // Extract basic product information
        $name        = $this->extract_name($payload, $product_obj);
        $brand       = $this->extract_brand($payload, $product_obj);
        $description = $this->extract_description($payload);

        // Extract images
        $images_payload = $this->build_images_payload($payload);

        // Extract categories
        $categories_terms = $this->parse_categories($payload);

        $wholesale_price = $payload['base_price_netto'] ?? 0;

        // Extract attributes and variations
        $attributes = $this->build_attributes($payload);
        $variations = $this->build_variations($payload, $product_obj);

        // Extract EAN and other meta data
        $ean = $this->extract_ean($payload);

        // return mapped data
        return [
            'name'            => $name,
            'sku'             => (string) ($product_obj->sku ?? ''),
            'brand'           => $brand,
            'description'     => $description,
            'wholesale_price' => $wholesale_price,
            'images_payload'  => $images_payload,
            'categories'      => $categories_terms,
            'category_terms'  => array_map(function ($name) {
                return ['name' => $name];
            }, $categories_terms),
            'tags'            => [],
            'attributes'      => $attributes,
            'variations'      => $variations,
            'meta_data'       => [
                ['key' => '_ean', 'value' => $ean],
                ['key' => '_aren_tax_rate', 'value' => $payload['tax']['value'] ?? ''],
                ['key' => '_aren_unit', 'value' => $payload['unit'] ?? ''],
                ['key' => '_aren_weight', 'value' => $payload['weight'] ?? ''],
            ],
        ];
    }

    /**
     * Extract product name
     */
    private function extract_name($payload, $product_obj) {
        if (!empty($payload['name'])) {
            return $payload['name'];
        }
        return $product_obj->sku ?? '';
    }

    /**
     * Extract brand information
     */
    private function extract_brand($payload, $product_obj) {
        if (!empty($payload['producer'])) {
            return $payload['producer'];
        }
        return $product_obj->brand ?? '';
    }

    /**
     * Extract product description
     */
    private function extract_description($payload) {
        return $payload['description'] ?? '';
    }

    /**
     * Extract EAN code
     */
    private function extract_ean($payload) {
        if (isset($payload['attributes']['attribute']) && is_array($payload['attributes']['attribute'])) {
            foreach ($payload['attributes']['attribute'] as $attr) {
                if (($attr['name'] ?? '') === 'EAN' && isset($attr['values']['value'])) {
                    return $attr['values']['value'];
                }
            }
        }
        return '';
    }

    /**
     * Build images payload
     */
    private function build_images_payload($payload) {
        $result = [];
        if (isset($payload['images']['image'])) {
            $images = $payload['images']['image'];
            if (isset($images['url'])) {
                $result[] = ['src' => $images['url']];
            } elseif (is_array($images)) {
                foreach ($images as $img) {
                    if (isset($img['url'])) {
                        $result[] = ['src' => $img['url']];
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Parse categories (safe for single/multiple)
     */
    private function parse_categories($payload) {
        $categories = [];
        if (!empty($payload['categories']['category'])) {
            $category_data = $payload['categories']['category'];
            if (is_array($category_data)) {
                foreach ($category_data as $cat) {
                    $categories[] = trim($cat);
                }
            } else {
                $categories = array_map('trim', explode('/', $category_data));
            }
        }
        return $categories;
    }

    /**
     * Build WooCommerce attributes
     */
    private function build_attributes(array $payload) {
        $attributes = [];
        if (isset($payload['combinations']['combination'])) {
            $combinations = $payload['combinations']['combination'];
            if (isset($combinations['id'])) {
                $combinations = [$combinations];
            }
            $options_map = [];
            foreach ($combinations as $combo) {
                if (isset($combo['attributes']['attribute'])) {
                    foreach ($combo['attributes']['attribute'] as $attr) {
                        $name  = $attr['name'] ?? '';
                        $value = $attr['value'] ?? '';
                        if (!$name || !$value) continue;
                        if (!isset($options_map[$name])) {
                            $options_map[$name] = [];
                        }
                        if (!in_array($value, $options_map[$name], true)) {
                            $options_map[$name][] = $value;
                        }
                    }
                }
            }
            foreach ($options_map as $name => $options) {
                $attributes[] = [
                    'name'      => $name,
                    'slug'      => sanitize_title($name),
                    'visible'   => true,
                    'variation' => true,
                    'options'   => $options,
                ];
            }
        }
        return $attributes;
    }

    /**
     * Build product variations
     */
    private function build_variations($payload, $product_obj) {
        $variations = [];
        if (isset($payload['combinations']['combination'])) {
            $combination = $payload['combinations']['combination'];
            if (isset($combination['id'])) {
                $variations[] = $this->create_variation($combination, $product_obj);
            } elseif (is_array($combination)) {
                foreach ($combination as $combo) {
                    if (isset($combo['id'])) {
                        $variations[] = $this->create_variation($combo, $product_obj);
                    }
                }
            }
        }
        return $variations;
    }

    /**
     * Create individual variation (safe for attributes + images)
     */
    private function create_variation(array $combination, object $product_obj) {
        $wholesaler_price      = $combination['price_netto'] ?? 0;
        $brand                 = $product_obj->brand ?? '';
        $product_regular_price = calculate_product_price_with_margin($wholesaler_price, $brand);

        $color = $size = '';
        $color_key = $size_key = '';

        if (isset($combination['attributes']['attribute']) && is_array($combination['attributes']['attribute'])) {
            foreach ($combination['attributes']['attribute'] as $attr) {
                if (($attr['name'] ?? '') === 'Kolor') {
                    $color_key = $attr['name'];
                    $color     = $attr['value'] ?? '';
                } elseif (($attr['name'] ?? '') === 'Rozmiar') {
                    $size_key = $attr['name'];
                    $size     = $attr['value'] ?? '';
                }
            }
        }

        // Unique SKU
        $helpers    = new Wholesaler_Import_Helpers();
        $unique_sku = $helpers->generate_variation_sku(
            $product_obj->sku ?? 'AREN',
            $combination['code'] ?? '',
            [$color, $size]
        );

        // Variation images (normalize)
        $images = [];
        if (isset($combination['image'])) {
            $img_data = $combination['image'];
            if (is_array($img_data)) {
                foreach ($img_data as $img) {
                    $images[] = ['src' => $img];
                }
            } elseif (is_string($img_data)) {
                $images[] = ['src' => $img_data];
            }
        }

        $variation = [
            'sku'             => $unique_sku,
            'regular_price'   => (string) $product_regular_price,
            'wholesale_price' => (string) $wholesaler_price,
            'manage_stock'    => true,
            'stock_quantity'  => (int) ($combination['quantity'] ?? 0),
            'attributes'      => [],
            'meta_data'       => [
                ['key' => '_price_value', 'value' => $combination['price_value'] ?? ''],
                ['key' => '_price_modifier', 'value' => $combination['price_modifier'] ?? ''],
                ['key' => '_default_price_netto', 'value' => $combination['default_price_netto'] ?? ''],
            ],
        ];

        if ($color_key && $color) {
            $variation['attributes'][] = ['name' => $color_key, 'option' => $color];
        }
        if ($size_key && $size) {
            $variation['attributes'][] = ['name' => $size_key, 'option' => $size];
        }
        if (!empty($images)) {
            $variation['image'] = $images[0];
        }

        return $variation;
    }
}