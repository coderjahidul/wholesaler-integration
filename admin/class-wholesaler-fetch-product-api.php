<?php
// fetch js product api
function wholesaler_fetch_js_product_api() {
    // get wholesaler_js_url
    $wholesaler_js_url = get_option('wholesaler_js_url');

    if ( empty($wholesaler_js_url) ) {
        put_program_logs("JS API URL not found.");
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
        put_program_logs("MADA API URL not found.");
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
        put_program_logs("Saved zip file is empty. Check directory permissions: " . $upload_dir['basedir']);
        return false;
    }

    put_program_logs("Downloaded Successfully. File size: " . filesize($temp_zip) . " bytes");

    // Extract ZIP
    $zip = new ZipArchive;
    if ($zip->open($temp_zip) === TRUE) {
        $extract_path = $upload_dir['basedir'] . "/mada_products/";
        $zip->extractTo($extract_path);
        $zip->close();

        put_program_logs("Mada ZIP extracted successfully.");
        
        // Assuming inside ZIP there is products.xml
        $xml_file = $extract_path . "products.xml";
        if (file_exists($xml_file)) {
            return file_get_contents($xml_file);
        } else {
            put_program_logs("products.xml not found inside ZIP.");
            return false;
        }

    } else {
        put_program_logs("Failed to open Mada ZIP file.");
        return false;
    }
}

// fetch aren product api
function wholesaler_fetch_aren_product_api() {
    $wholesaler_aren_url = get_option('wholesaler_aren_url');

    if ( empty($wholesaler_aren_url) ) {
        put_program_logs("AREN API URL not found.");
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
    return $response;
}

// insert product js api to database
function insert_product_js_api_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
    $api_response = wholesaler_fetch_js_product_api();

    // Convert XML → array
    $xml  = simplexml_load_string($api_response);
    $json = json_encode($xml);
    $product_list = json_decode($json, true);

    // Allowed brands
    // $brands = [
    //     "AVA", "Ava Active", "Gaia", "Gorsenia", "Konrad", "Mediolano",
    //     "Mat", "Mefemi by Nipplex", "Henderson Laydies", "Lupoline",
    //     "Babell", "Julimex", "Key", "Lama", "Lapinee", "Mitex",
    //     "De Lafense", "Dekaren", "Donna", "Eldar", "Funny day",
    //     "Taro", "Cornette", "Henderson", "Delafense", "Obsessive",
    //     "Gatta Bodywear", "Gatta", "Gabriella", "Fiore", "Mona", "Ava swimwear"
    // ];

    // Get all product brands
    $brands = get_all_product_brands();

    // Convert all brands to lowercase for comparison
    $brands_upper = array_map('strtoupper', $brands);

    if (!isset($product_list['articles']['article'])) {
        put_program_logs("No products found in API response");
        return;
    }

    foreach ($product_list['articles']['article'] as $article) {
        // get product data
        $product_data = json_encode($article);
        // Extract values
        $sku = $article['@attributes']['sku'] ?? null;
        $brand = $article['brand']['name'] ?? null; // if exists

        // Skip if brand is not in the allowed list 
        if (!in_array($brand, $brands_upper)){ continue; }

        // Insert or update by SKU
        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (sku, brand, product_data, status, created_at, updated_at)
            VALUES (%s, %s, %s, 'Pending', NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                brand = VALUES(brand),
                product_data = VALUES(product_data),
                status = 'Pending',
                updated_at = NOW()",
            $sku, $brand, $product_data
        );

        $wpdb->query($sql);
    }

    put_program_logs("Product data inserted successfully.");
}




// insert product mada api to database
function insert_product_mada_api_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'wholesaler_products_data';

    $xml_content = wholesaler_fetch_mada_product_api();
    if (! $xml_content) {
        put_program_logs("No XML content fetched.");
        return;
    }

    $xml = simplexml_load_string($xml_content);
    if (!$xml) {
        put_program_logs("Invalid XML format.");
        return;
    }

    $json = json_encode($xml);
    $product_list = json_decode($json, true);

    // Allowed brands
    $brands = [
        "AVA", "Ava Active", "Gaia", "Gorsenia", "Konrad", "Mediolano",
        "Mat", "Mefemi by Nipplex", "Henderson Laydies", "Lupoline",
        "Babell", "Julimex", "Key", "Lama", "Lapinee", "Mitex",
        "De Lafense", "Dekaren", "Donna", "Eldar", "Funny day",
        "Taro", "Cornette", "Henderson", "Delafense", "Obsessive",
        "Gatta Bodywear", "Gatta", "Gabriella", "Fiore", "Mona", "Ava swimwear"
    ];

    // Convert all brands to lowercase for comparison
    $brands_upper = array_map('strtoupper', $brands);

    if (!isset($product_list['PRODUCTS']['PRODUCT'])) {
        put_program_logs("No products found in API response");
        return;
    }

    foreach ($product_list['PRODUCTS']['PRODUCT'] as $product) {
        // Basic info
        $product_data = $product;

        // SKU
        $sku = $product['ID'] ?? '';

        // Brand / Producer
        $brand = $product['BRAND'] ?? '';

        // Price
        $price = isset($product['PRICE']) ? floatval($product['PRICE']) : 0;

        // Skip if brand is not in the allowed list
        if (!in_array($brand, $brands_upper)){
            continue;
        }

        // Stock: MODELS -> MODEL -> SIZE count
        $stock = 0;
        if (!empty($product['MODELS']['MODEL'])) {
            $models = $product['MODELS']['MODEL'];
            if (isset($models['SIZE'])) {
                // Single MODEL case
                $models = [$models];
            }
            foreach ($models as $model) {
                if (!empty($model['SIZE'])) {
                    $sizes = $model['SIZE'];
                    if (!is_array($sizes)) {
                        $sizes = [$sizes];
                    }
                    $stock += count($sizes);
                }
            }
        }

        // Attributes
        $attributes = $product['ATTRIBUTES']['ATTRIBUTE'] ?? null;

        // Insert full row
        $wpdb->insert(
            $table_name,
            [
                'wholesaler_name' => 'Mada',
                'sku'             => $sku,
                'wholesale_price' => $price,
                'stock'           => $stock,
                'brand'           => $brand,
                'attributes'      => wp_json_encode($attributes),
                'product_data'    => wp_json_encode($product_data),
                'last_synced'     => current_time('mysql'),
            ],
            [
                '%s','%s','%f','%d','%s','%s','%s','%s'
            ]
        );
    }
}

// insert product aren api to database
function insert_product_aren_api_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'wholesaler_products_data';
    $api_response = wholesaler_fetch_aren_product_api();
    
    // Load XML
    $xml  = simplexml_load_string($api_response, "SimpleXMLElement", LIBXML_NOCDATA);
    if (!$xml) {
        put_program_logs("❌ XML parsing failed");
        return;
    }

    // Convert to JSON & then array
    $json = json_encode($xml, JSON_UNESCAPED_UNICODE);
    $product_list = json_decode($json, true);

    // Allowed brands
    $brands = [
        "AVA", "Ava Active", "Gaia", "Gorsenia", "Konrad", "Mediolano",
        "Mat", "Mefemi by Nipplex", "Henderson Laydies", "Lupoline",
        "Babell", "Julimex", "Key", "Lama", "Lapinee", "Mitex",
        "De Lafense", "Dekaren", "Donna", "Eldar", "Funny day",
        "Taro", "Cornette", "Henderson", "Delafense", "Obsessive",
        "Gatta Bodywear", "Gatta", "Gabriella", "Fiore", "Mona", "Ava swimwear"
    ];

    // Convert all brands to lowercase for comparison
    $brands_upper = array_map('strtoupper', $brands);

    if (isset($product_list['product']) && isset($product_list['product']['id'])) {
        $product_list['product'] = [$product_list['product']];
    }

    if (!isset($product_list['product'])) {
        put_program_logs("No products found in API response");
        return;
    }

    foreach ($product_list['product'] as $product) {
        // Basic info
        $product_data = $product;
        put_program_logs("AREN API response: " . print_r($product_data, true));
        $sku   = $product['code'];
        $brand = $product['producer'] ?? null;
        $price = isset($product['base_price_netto']) ? (float) $product['base_price_netto'] : 0;
        $stock = isset($product['combinations']['combination']['quantity']) ? (int) $product['combinations']['combination']['quantity'] : 0;

        // Skip if brand is not in the allowed list
        if (!in_array($brand, $brands_upper)){
            continue;
        }

        // Insert full row
        $wpdb->insert(
            $table_name,
            [
                'wholesaler_name' => 'AREN',
                'sku'             => $sku,
                'wholesale_price' => $price,
                'stock'           => $stock,
                'brand'           => $brand,
                'attributes'      => wp_json_encode([]),
                'product_data'    => wp_json_encode($product_data), // product_data
                'last_synced'     => current_time('mysql'),
            ],
            [
                '%s','%s','%f','%d','%s','%s','%s','%s'
            ]
        );
    }
}

