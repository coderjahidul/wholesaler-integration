<?php

// fetch js product api
function wholesaler_fetch_js_product_api() {
    // get wholesaler_js_url
    $wholesaler_js_url = get_option('wholesaler_js_url');

    if ( empty($wholesaler_js_url) ) {
        echo("JS API URL not found.");
        return false;
    }

    $curl = curl_init();

    curl_setopt_array($curl, array(
    CURLOPT_URL => $wholesaler_js_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return $response;
}

// fetch mada product api
function wholesaler_fetch_mada_product_api() {
    $wholesaler_mada_url = get_option('wholesaler_mada_url');

    if ( empty($wholesaler_mada_url) ) {
        echo("MADA API URL not found.");
        return false;
    }

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $wholesaler_mada_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false, // Try disabling SSL verification temporarily
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_HEADER => true, // Include headers in response for debugging
    ));

    $response = curl_exec($curl);
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);

    if (curl_errno($curl)) {
        $error_msg = curl_error($curl);
        curl_close($curl);
        return false;
    }

    curl_close($curl);


    // Save as temp zip file
    $upload_dir = wp_upload_dir();
    $temp_zip = $upload_dir['basedir'] . "/mada_products.zip";
    file_put_contents($temp_zip, $body);

    // Verify the saved file
    if (filesize($temp_zip) === 0) {
        return false;
    }

    // Extract ZIP
    $zip = new ZipArchive;
    if ($zip->open($temp_zip) === TRUE) {
        $extract_path = $upload_dir['basedir'] . "/mada_products/";

        // Create directory if not exists
        if (!file_exists($extract_path)) {
            mkdir($extract_path, 0755, true);
        }
        $zip->extractTo($extract_path);
        $zip->close();

        
        // Assuming inside ZIP there is products.xml
        $xml_file = $extract_path . "products.xml";
        if (file_exists($xml_file)) {
            return file_get_contents($xml_file);
        } else {
            return false;
        }

    } else {
        echo("Failed to open Mada ZIP file.");
        return false;
    }
}

// fetch aren product api
function wholesaler_fetch_aren_product_api() {
    $wholesaler_aren_url = get_option('wholesaler_aren_url');

    if ( empty($wholesaler_aren_url) ) {
        echo("AREN API URL not found.");
        return false;
    }

    $curl = curl_init();

    curl_setopt_array($curl, array(
    CURLOPT_URL => $wholesaler_aren_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'GET',
    CURLOPT_HTTPHEADER => array(
        'Cookie: ARENB2B_SID=lsrq5tp7scm7jfbtm44lcr1b1s; AtomStore[personalization_sid]=Q2FrZQ%3D%3D.7lfZZ5YHdMcJhrVu95t1OJBRfHauu2O7mGU%3D; _LoggedUser=0; _csrfToken=e0cffa49edb2652d1e5681f0f0ce957345a8ef5511471488f93015c4'
    ),
    ));

    $response = curl_exec($curl);

    curl_close($curl);


    // WordPress upload dir বের করা
    $upload_dir   = wp_upload_dir();
    $extract_path = $upload_dir['basedir'] . "/aren_products/";

    // ডিরেক্টরি না থাকলে তৈরি করা
    if (!file_exists($extract_path)) {
        wp_mkdir_p($extract_path);
    }

    // ফাইলের নাম ঠিক করা
    $xml_file = $extract_path . "oferta-produktow-pelna.xml";

    // API response ফাইলে সেভ করা
    return file_put_contents($xml_file, $response);
    
}

// download js products
function wholesaler_download_js_products() {
    $response = wholesaler_fetch_js_product_api();

    if (!$response) {
        return [
            'success' => false,
            'message' => 'Failed to fetch API data.'
        ];
    }

    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/wholesaler_js_products.xml';

    file_put_contents($file_path, $response);

    return [
        'success' => true,
        'message' => 'Data saved to file successfully.',
        'file_path' => $file_path
    ];
}

// insert js products
function wholesaler_insert_js_products_from_file_stream() {
    global $wpdb;

    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/wholesaler_js_products.xml';

    if (!file_exists($file_path)) {
        return [
            'success' => false,
            'message' => 'Data file not found. Please download first.'
        ];
    }

    // Get allowed brands
    $brands = get_all_product_brands();
    $brands_upper = array_map('strtoupper', $brands);

    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';

    // Initialize XMLReader
    $reader = new XMLReader();
    $reader->open($file_path);

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'article') {
            $node = new SimpleXMLElement($reader->readOuterXML());

            $sku = (string) ($node['sku'] ?? '');
            $brand = isset($node->brand->name) ? strtoupper((string) $node->brand->name) : '';

            // Skip if brand not in allowed list
            if (!in_array($brand, $brands_upper)) continue;

            // Convert XML structure to desired format
            $article_data = [
                'article' => [
                    'name' => (string) $node->name,
                    'gpsr' => (string) $node->gpsr,
                    'brand' => [
                        'name' => (string) $node->brand->name
                    ],
                    'category_keys' => (string) $node->category_keys,
                    'attributes' => [],
                    'price' => (string) $node->price,
                    'price_orginal' => (string) $node->price_orginal,
                    'images' => ['image' => []],
                    'related' => ['item' => []],
                    'units' => ['unit' => []],
                    '_id' => (string) $node['id'],
                    '_sku' => (string) $node['sku'],
                    '_ean' => (string) $node['ean']
                ]
            ];

            // Process attributes
            if ($node->attributes) {
                foreach ($node->attributes->children() as $attr_name => $attr_value) {
                    $article_data['article']['attributes'][$attr_name] = (string) $attr_value;
                }
            }

            // Process images
            if ($node->images) {
                foreach ($node->images->image as $image) {
                    $article_data['article']['images']['image'][] = [
                        'image_url' => (string) $image->image_url
                    ];
                }
            }

            // Process related items
            if ($node->related) {
                foreach ($node->related->item as $item) {
                    $article_data['article']['related']['item'][] = [
                        '_id' => (string) $item['id'],
                        '_sku' => (string) $item['sku'],
                        '_ean' => (string) $item['ean']
                    ];
                }
            }

            // Process units
            if ($node->units) {
                foreach ($node->units->unit as $unit) {
                    $article_data['article']['units']['unit'][] = [
                        'color' => (string) $unit->color,
                        'color_basic' => (string) $unit->color_basic,
                        'size' => (string) $unit->size,
                        'pattern' => (string) $unit->pattern,
                        'miska' => (string) $unit->miska,
                        'obwod' => (string) $unit->obwod,
                        'stock' => (string) $unit->stock,
                        'image_url' => (string) $unit->image_url,
                        '_id' => (string) $unit['id'],
                        '_sku' => (string) $unit['sku'],
                        '_ean' => (string) $unit['ean']
                    ];
                }
            }

            $product_data = json_encode($article_data);

            // Insert or update product
            $sql = $wpdb->prepare(
                "INSERT INTO $table_name (wholesaler_name, sku, brand, product_data, status, created_at, updated_at)
                VALUES ('JS', %s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    brand = VALUES(brand),
                    product_data = VALUES(product_data),
                    status = %s,
                    updated_at = NOW()",
                $sku,
                $brand,
                $product_data,
                Status_Enum::PENDING->value,
                Status_Enum::PENDING->value
            );

            $wpdb->query($sql);
        }
    }

    $reader->close();

    return [
        'success' => true,
        'message' => 'Products inserted/updated successfully (streamed).'
    ];
}

// download mada products
function wholesaler_download_mada_products() {
    $upload_dir = wp_upload_dir();
    $extract_path = $upload_dir['basedir'] . "/mada_products/";

    // Fetch API
    $xml_content = wholesaler_fetch_mada_product_api(); // your CURL function already saves & extracts ZIP

    if (!$xml_content) {
        return [
            'success' => false,
            'message' => 'Failed to fetch MADA API data.'
        ];
    }

    // Save XML to extracted folder
    if (!file_exists($extract_path)) {
        mkdir($extract_path, 0755, true);
    }
    $xml_file = $extract_path . "products.xml";
    file_put_contents($xml_file, $xml_content);

    return [
        'success' => true,
        'message' => 'MADA API data downloaded successfully.',
        'file_path' => $xml_file
    ];
}



// insert product mada api to database
function wholesaler_insert_mada_products_from_file_stream() {
    global $wpdb;

    $upload_dir = wp_upload_dir();
    $xml_file = $upload_dir['basedir'] . "/mada_products/products.xml";

    if (!file_exists($xml_file)) {
        return [
            'success' => false,
            'message' => 'MADA products XML file not found. Please download first.'
        ];
    }

    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';

    $reader = new XMLReader();
    $reader->open($xml_file);

    $total_inserted = 0;

    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'PRODUCT') {
            $node = new SimpleXMLElement($reader->readOuterXML());

            // Base fields
            $product = [
                'ID' => (string) $node->ID,
                'NAME' => (string) $node->NAME,
                'DESC' => (string) $node->DESC,
                'PRODUCER' => (string) $node->PRODUCER,
                'PRODUCER_ADDRESS' => (string) $node->PRODUCER_ADDRESS,
                'PRODUCER_SECURITY_INFO' => (string) $node->PRODUCER_SECURITY_INFO,
                'FLAGS' => [],
                'PRICE' => (string) $node->PRICE,
                'VAT' => (string) $node->VAT,
                'CATEGORIES' => [],
                'MODELS' => [],
                'ATTRIBUTES' => [],
                'IMAGES' => []
            ];

            // FLAGS
            if (isset($node->FLAGS->FLAG)) {
                $product['FLAGS']['FLAG'] = (string) $node->FLAGS->FLAG;
            }

            // CATEGORIES
            if (isset($node->CATEGORIES->CATEGORY)) {
                $cat = $node->CATEGORIES->CATEGORY;
                $product['CATEGORIES']['CATEGORY'] = [
                    '_c1' => (string) $cat['c1'],
                    '_c2' => (string) $cat['c2'],
                    '_c3' => (string) $cat['c3'],
                    '__cdata' => (string) $cat
                ];
            }

            // MODELS (supports multiple MODEL elements)
            if (isset($node->MODELS->MODEL)) {
                $models = [];
                foreach ($node->MODELS->MODEL as $model_node) {
                    $model = [
                        'COLOR' => (string) $model_node->COLOR,
                        'SIZE' => []
                    ];

                    if (isset($model_node->SIZE)) {
                        foreach ($model_node->SIZE as $size) {
                            $model['SIZE'][] = [
                                '_amount' => (string) $size['amount'],
                                '_ean' => (string) $size['ean'],
                                '_pattern' => (string) $size['pattern'],
                                '_pattern_img_id' => (string) $size['pattern_img_id'],
                                '__text' => (string) $size
                            ];
                        }
                    }
                    $models[] = $model;
                }

                // If only one MODEL, keep as object; else as array
                $product['MODELS']['MODEL'] = count($models) === 1 ? $models[0] : $models;
            }

            // ATTRIBUTES
            if (isset($node->ATTRIBUTES->ATTRIBUTE)) {
                $attr_node = $node->ATTRIBUTES->ATTRIBUTE;
                $product['ATTRIBUTES']['ATTRIBUTE'] = [
                    '_id' => (string) $attr_node['id'],
                    '_group_id' => (string) $attr_node['group_id'],
                    '__cdata' => (string) $attr_node
                ];
            }

            // IMAGES
            if (isset($node->IMAGES->IMG)) {
                foreach ($node->IMAGES->IMG as $img) {
                    $product['IMAGES']['IMG'][] = [
                        '_id' => (string) $img['id'],
                        '__text' => (string) $img
                    ];
                }
            }

            // Wrap in PRODUCT key
            $product_data = ['PRODUCT' => $product];

            $sku = $product['ID'];
            $brand = $product['PRODUCER'];

            if (empty($sku)) continue;

            $sql = $wpdb->prepare(
                "INSERT INTO $table_name (wholesaler_name, sku, brand, product_data, status, created_at, updated_at)
                VALUES ('MADA', %s, %s, %s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    brand = VALUES(brand),
                    product_data = VALUES(product_data),
                    status = %s,
                    updated_at = NOW()",
                $sku,
                $brand,
                wp_json_encode($product_data, JSON_UNESCAPED_UNICODE),
                Status_Enum::PENDING->value,
                Status_Enum::PENDING->value
            );

            $wpdb->query($sql);
            $total_inserted++;
        }
    }

    $reader->close();

    return [
        'success' => true,
        'message' => "MADA products inserted/updated successfully.",
        'total_inserted' => $total_inserted
    ];
}

// insert product aren api to database
function wholesaler_insert_aren_products_from_file_stream() {
    global $wpdb;
    
    // WordPress upload dir
    $upload_dir = wp_upload_dir();
    $extract_path = $upload_dir['basedir'] . "/aren_products/";
    $xml_file = $extract_path . "oferta-produktow-pelna.xml";
    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
    
    if (!file_exists($xml_file)) {
        echo "XML file not found";
        return;
    }
    
    $reader = new XMLReader();
    $reader->open($xml_file);
    
    $product_count = 0;
    
    while ($reader->read()) {
        if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'product') {
            $node = simplexml_load_string($reader->readOuterXML(), "SimpleXMLElement", LIBXML_NOCDATA);
            
            if ($node) {
                $json = json_encode($node, JSON_UNESCAPED_UNICODE);
                $product = json_decode($json, true);
                
                $sku = $product['code'] ?? '';
                $brand = $product['producer'] ?? '';
                
                if (is_array($brand)) {
                    $brand = implode(', ', $brand);
                }
                
                $product_data = json_encode($product);
                
                $sql = $wpdb->prepare(
                    "INSERT INTO $table_name (wholesaler_name, sku, brand, product_data, status, created_at, updated_at) 
                     VALUES ('AREN', %s, %s, %s, %s, NOW(), NOW()) 
                     ON DUPLICATE KEY UPDATE brand = VALUES(brand), product_data = VALUES(product_data), status = %s, updated_at = NOW()",
                    $sku, $brand, $product_data, Status_Enum::PENDING->value, Status_Enum::PENDING->value
                );
                
                $wpdb->query($sql);
                $product_count++;
            }
        }
    }
    
    $reader->close();
    
    echo $product_count > 0 
        ? "AREN Product data inserted successfully. Total Products: $product_count"
        : "No products found in XML file";
}