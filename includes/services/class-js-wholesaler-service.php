<?php

defined("ABSPATH") || exit("Direct Access Not Allowed");

class Wholesaler_JS_Wholesaler_Service {

    public function map($product_obj) {
        $payload = is_string($product_obj->product_data) ? json_decode($product_obj->product_data, true) : (array) $product_obj->product_data;

        if (!isset($payload['article'])) {
            return [];
        }

        $article = $payload['article'];

        // --- Name ---
        $name = $article['name'] ?? '';
        // if (empty($name) && isset($product_obj->sku)) {
        //     $name = $product_obj->sku;
        // }

        // --- Brand ---
        $brand = $article['brand']['name'] ?? ($product_obj->brand ?? '');

        // --- Description ---
        $description = '';
        if (!empty($article['attributes']['opis'])) {
            // decode HTML entities
            $description = html_entity_decode($article['attributes']['opis']);
        }

        // --- Images ---
        $images_payload = $this->build_images_payload_from_js($article['images'] ?? []);

        // --- Categories ---
        $categories_terms = $this->parse_category_path_to_terms($article['category_keys'] ?? '');

        // --- Prices ---
        $wholesaler_price = isset($article['price']) ? (float) $article['price'] : 0;
        $product_regular_price = calculate_product_price_with_margin($wholesaler_price, $brand);

        // --- Units -> Sizes / Colors / Variations ---
        $size_options  = [];
        $color_options = [];
        $variations    = [];

        if (isset($article['units']['unit']) && is_array($article['units']['unit'])) {
            foreach ($article['units']['unit'] as $unit) {
                $size  = $unit['size'] ?? '';
                $color = $unit['color'] ?? '';

                if ($size !== '' && !in_array($size, $size_options, true)) {
                    $size_options[] = $size;
                }
                if ($color !== '' && !in_array($color, $color_options, true)) {
                    $color_options[] = $color;
                }
            }

            foreach ($article['units']['unit'] as $unit) {
                $unitSku  = $unit['_sku'] ?? '';
                $unitEan  = $unit['_ean'] ?? '';
                $size     = $unit['size'] ?? '';
                $color    = $unit['color'] ?? '';
                $stockQty = isset($unit['stock']) ? (int) $unit['stock'] : 0;

                // Build SKU
                $baseSku = (string)($article['_sku'] ?? $product_obj->sku ?? '');
                $rawSku  = $unitSku !== '' ? ($baseSku !== '' ? $baseSku . '-' . $unitSku : $unitSku) : ($baseSku !== '' ? $baseSku . '-' . uniqid() : uniqid());
                $finalSku = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($rawSku));
                $finalSku = trim(preg_replace('/-+/', '-', $finalSku), '-');

                $variations[] = [
                    'sku'             => $finalSku,
                    'regular_price'   => (string)$product_regular_price,
                    'wholesale_price' => (string)$wholesaler_price,
                    'manage_stock'    => true,
                    'stock_quantity'  => $stockQty,
                    'attributes'      => [
                        ['name' => 'Color', 'option' => $color],
                        ['name' => 'Size', 'option' => $size],
                    ],
                    'meta_data'       => [
                        ['key' => '_ean', 'value' => $unitEan],
                    ],
                ];
            }
        }

        // --- Attributes ---
        $attributes = [];
        if (!empty($color_options)) {
            $attributes[] = [
                'name'      => 'Color',
                'position'  => 0,
                'visible'   => true,
                'variation' => true,
                'options'   => $color_options,
            ];
        }
        if (!empty($size_options)) {
            $attributes[] = [
                'name'      => 'Size',
                'position'  => 1,
                'visible'   => true,
                'variation' => true,
                'options'   => $size_options,
            ];
        }

        return [
            'name'            => $name,
            'sku'             => (string)($article['_sku'] ?? $product_obj->sku ?? ''),
            'brand'           => $brand,
            'description'     => $description,
            'regular_price'   => (string)$product_regular_price,
            'sale_price'      => '',
            'wholesale_price' => (string)$wholesaler_price,
            'images_payload'  => $images_payload,
            'categories'      => $categories_terms,
            'category_terms'  => array_map(function ($name) {
                return ['name' => $name];
            }, $categories_terms),
            'tags'            => [],
            'attributes'      => $attributes,
            'variations'      => $variations,
        ];
    }

    private function parse_category_path_to_terms($category_path) {
        if (empty($category_path)) {
            return [];
        }
        $parts = array_map('trim', explode('|', $category_path));
        return $parts;
    }

    private function build_images_payload_from_js($images_field) {
        $result = [];
        if (empty($images_field)) {
            return $result;
        }

        if (isset($images_field['image'])) {
            $img = $images_field['image'];

            if (isset($img['image_url'])) {
                // single image object
                $result[] = ['src' => $img['image_url']];
            } elseif (is_array($img)) {
                foreach ($img as $entry) {
                    if (isset($entry['image_url'])) {
                        $result[] = ['src' => $entry['image_url']];
                    }
                }
            }
        }
        return $result;
    }
}
