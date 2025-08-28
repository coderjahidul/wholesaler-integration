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
    // file_put_contents($xml_file, $response);
    // আবার পড়া ও return করা
    return file_get_contents($xml_file);
    
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

    // Get all product brands
    $brands = get_all_product_brands();

    // Convert all brands to lowercase for comparison
    $brands_upper = array_map('strtoupper', $brands);

    if (!isset($product_list['articles']['article'])) {
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

    // Success Total Products insert
    if(empty($product_list['articles']['article'])){
        echo("No products found in API response");
        return;
    }else{
        $total_products = count($product_list['articles']['article']);
        echo ("JS Product data inserted successfully. Total Products: $total_products");
    }
}




// insert product mada api to database
function insert_product_mada_api_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';

    // Fetch XML
    $xml_content = wholesaler_fetch_mada_product_api();
    if (! $xml_content) {
        return;
    }

    // Parse XML
    $xml = simplexml_load_string($xml_content);
    if (!$xml) {
        return;
    }

    // Convert to array
    $json = json_encode($xml);
    $product_list = json_decode($json, true);

    if (empty($product_list['PRODUCTS']['PRODUCT'])) {
        return;
    }

    foreach ($product_list['PRODUCTS']['PRODUCT'] as $product) {
        // SKU
        $sku = $product['ID'] ?? '';

        // Brand (use PRODUCER instead of BRAND)
        $brand = $product['PRODUCER'] ?? '';

        // Encode product data
        $product_data = wp_json_encode($product);

        // Prepare SQL
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

        // Execute query
        $wpdb->query($sql);
    }

    // Success Total Products insert
    if(empty($product_list['PRODUCTS']['PRODUCT'])) {
        echo("No products found in API response");
        return;
    }else{
        $total_products = count($product_list['PRODUCTS']['PRODUCT']);
        echo ("MADA Product data inserted successfully. Total Products: $total_products");
    }
}


// insert product aren api to database
function insert_product_aren_api_to_database() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'sync_wholesaler_products_data';
    $api_response = wholesaler_fetch_aren_product_api();
    
    // Load XML
    $xml = simplexml_load_string($api_response, "SimpleXMLElement", LIBXML_NOCDATA);
    if (!$xml) {
        return;
    }

    // Convert to JSON & then array
    $json = json_encode($xml, JSON_UNESCAPED_UNICODE);
    $product_list = json_decode($json, true);

    if (isset($product_list['product']) && isset($product_list['product']['id'])) {
        $product_list['product'] = [$product_list['product']];
    }

    if (!isset($product_list['product'])) {
        return;
    }


    foreach ($product_list['product'] as $product) {
        // Basic info
        $product_data = json_encode($product);
        $sku   = $product['code'];
        $brand = $product['producer'] ?? null;
        if (is_array($brand)) {
            $brand = implode(', ', $brand); // or just $brand[0]
        }

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

    // Success Total Products insert
    if(empty($product_list['product'])) {
        echo("No products found in API response");
        return;
    }else{
        $total_products = count($product_list['product']);
        echo ("AREN Product data inserted successfully. Total Products: $total_products");
    }
}

