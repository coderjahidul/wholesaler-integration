<?php
class Wholesaler_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
    }

    public function enqueue_admin_styles() {
        echo '<style>
            .wholesaler-section { border: 1px solid #ccc; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
            .wholesaler-section h2 { margin-top: 0; }
            .wholesaler-section input { width: 60%; padding: 5px; margin: 5px 0; }
            .wholesaler-section-small input { width: 20%; padding: 5px; margin: 5px 0; }
            .wp-core-ui p .button { width: 10%;}
            table.widefat { margin-top: 10px; }
        </style>';
    }

    public function add_plugin_settings_page() {
        add_menu_page(
            __( 'Wholesaler Settings', 'wholesaler' ),
            __( 'Wholesaler Settings', 'wholesaler' ),
            'manage_options',
            'wholesaler-settings',
            array( $this, 'create_admin_page' ),
            'dashicons-networking',
            100
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Wholesaler Settings', 'wholesaler' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wholesaler-settings-group' ); ?>

                <!-- API Settings -->
                <div class="wholesaler-section">
                    <h2>API Settings</h2>
                    <p>
                        <label>JS XML URL</label><br>
                        <?php $this->wholesaler_js_url_callback(); ?>
                    </p>
                    <p>
                        <label>MADA CSV/API URL</label><br>
                        <?php $this->wholesaler_mada_url_callback(); ?>
                    </p>
                    <p>
                        <label>AREN XML URL</label><br>
                        <?php $this->wholesaler_aren_url_callback(); ?>
                    </p>
                </div>

                <!-- WooCommerce Client Setup -->
                <div class="wholesaler-section">
                    <h2>WooCommerce Client Setup</h2>
                    <p>
                        <label>Consumer Key</label><br>
                        <?php $this->wholesaler_consumer_key_callback(); ?>
                    </p>
                    <p>
                        <label>Consumer Secret</label><br>
                        <?php $this->wholesaler_consumer_secret_callback(); ?>
                    </p>
                </div>

                <!-- Options -->
                <div class="wholesaler-section">
                    <h2>Options</h2>
                    <p>
                        <label>Retail Margin (%)</label><br>
                        <?php $this->wholesaler_retail_margin_callback(); ?>
                    </p>
                    <p>
                        <label>Limit (Product Update every minute)</label><br>
                        <?php $this->wholesaler_product_update_limit_callback(); ?>
                    </p>
                    <?php submit_button(); ?>
                </div>
            </form>

            <!-- API Endpoints -->
            <div class="wholesaler-section">
                <h2>API Endpoints</h2>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Endpoint</th>
                            <th>Summary</th>
                            <th>Copy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $endpoints = [
                            ['GET', '/wp-json/wholesaler/v1/get-brands', 'Fetch all brands'],
                            ['POST', '/wp-json/wholesaler/v1/seed-brands', 'Seed brands'],
                            ['GET', '/wp-json/wholesaler/v1/download-js-products', 'Download JS products File'],
                            ['GET', '/wp-json/wholesaler/v1/download-mada-products', 'Download MADA products File'],
                            ['GET', '/wp-json/wholesaler/v1/download-aren-products', 'Download AREN products File'],
                            ['GET', '/wp-json/wholesaler/v1/insert-js-products', 'Insert JS products from file to DB'],
                            ['GET', '/wp-json/wholesaler/v1/insert-mada-products', 'Insert MADA products from file to DB'],
                            ['GET', '/wp-json/wholesaler/v1/insert-aren-products', 'Insert AREN products from file to DB'],

                            ['POST', '/wp-json/wholesaler/v1/products/truncate?key=MY_SECRET_KEY_123', 'Truncate products table'],
                        ];

                        foreach ($endpoints as $ep) {
                            $url = site_url($ep[1]);
                            echo '<tr>
                                <td>' . esc_html($ep[0]) . '</td>
                                <td>' . esc_url($url) . '</td>
                                <td>' . esc_html($ep[2]) . '</td>
                                <td><button class="button" onclick="navigator.clipboard.writeText(\'' . esc_js($url) . '\')">Copy</button></td>
                            </tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function register_settings() {
        // Register all settings
        $settings = array(
            'wholesaler_js_url'          => 'esc_url_raw',
            'wholesaler_mada_url'        => 'esc_url_raw',
            'wholesaler_aren_url'        => 'esc_url_raw',
            'wholesaler_consumer_key'    => 'sanitize_text_field',
            'wholesaler_consumer_secret' => 'sanitize_text_field',
            'wholesaler_retail_margin'   => 'absint',
            'wholesaler_product_update_limit' => 'absint'
        );

        foreach ( $settings as $key => $sanitize ) {
            register_setting( 'wholesaler-settings-group', $key, array(
                'sanitize_callback' => $sanitize
            ));
        }

        // Add settings section
        add_settings_section(
            'wholesaler-settings-section',
            __( 'Wholesaler Settings', 'wholesaler' ),
            array( $this, 'settings_section_callback' ),
            'wholesaler-settings'
        );

        // Add settings fields
        $fields = array(
            'wholesaler_js_url' => __( 'JS XML URL', 'wholesaler' ),
            'wholesaler_mada_url' => __( 'MADA CSV/API URL', 'wholesaler' ),
            'wholesaler_aren_url' => __( 'AREN XML URL', 'wholesaler' ),
            'wholesaler_consumer_key' => __( 'Consumer Key', 'wholesaler' ),
            'wholesaler_consumer_secret' => __( 'Consumer Secret', 'wholesaler' ),
            'wholesaler_retail_margin' => __( 'Retail Margin (%)', 'wholesaler' ),
            'wholesaler_product_update_limit' => __( 'Limit (Product Update every minute)', 'wholesaler' ),
        );

        foreach ( $fields as $id => $label ) {
            add_settings_field(
                $id,
                $label,
                array( $this, $id . '_callback' ),
                'wholesaler-settings',
                'wholesaler-settings-section'
            );
        }
    }

    public function settings_section_callback() {
        echo '<p>' . esc_html__( 'Enter the URLs and options for wholesaler integrations below.', 'wholesaler' ) . '</p>';
    }

    // Callback functions
    public function wholesaler_js_url_callback() {
        $value = get_option('wholesaler_js_url', '');
        echo '<input type="url" id="wholesaler_js_url" name="wholesaler_js_url" value="' . esc_attr($value) . '" style="width:60%">';
    }

    public function wholesaler_mada_url_callback() {
        $value = get_option('wholesaler_mada_url', '');
        echo '<input type="url" id="wholesaler_mada_url" name="wholesaler_mada_url" value="' . esc_attr($value) . '" style="width:60%">';
    }

    public function wholesaler_aren_url_callback() {
        $value = get_option('wholesaler_aren_url', '');
        echo '<input type="url" id="wholesaler_aren_url" name="wholesaler_aren_url" value="' . esc_attr($value) . '" style="width:60%">';
    }

    public function wholesaler_consumer_key_callback() {
        $value = get_option('wholesaler_consumer_key', '');
        echo '<input type="text" id="wholesaler_consumer_key" name="wholesaler_consumer_key" value="' . esc_attr($value) . '" style="width:60%">';
    }

    public function wholesaler_consumer_secret_callback() {
        $value = get_option('wholesaler_consumer_secret', '');
        echo '<input type="text" id="wholesaler_consumer_secret" name="wholesaler_consumer_secret" value="' . esc_attr($value) . '" style="width:60%">';
    }

    public function wholesaler_retail_margin_callback() {
        $value = get_option('wholesaler_retail_margin', '');
        echo '<input type="number" id="wholesaler_retail_margin" name="wholesaler_retail_margin" value="' . esc_attr($value) . '" style="width:20%">';
    }

    public function wholesaler_product_update_limit_callback() {
        $value = get_option('wholesaler_product_update_limit', '');
        echo '<input type="number" id="wholesaler_product_update_limit" name="wholesaler_product_update_limit" value="' . esc_attr($value) . '" style="width:20%">';
    }
}
