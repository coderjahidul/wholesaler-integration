<?php
class Wholesaler_Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                settings_fields( 'wholesaler-settings-group' ); 
                do_settings_sections( 'wholesaler-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        // Register settings with sanitization
        register_setting( 'wholesaler-settings-group', 'wholesaler_js_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting( 'wholesaler-settings-group', 'wholesaler_mada_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting( 'wholesaler-settings-group', 'wholesaler_aren_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        // Retail Margin (%)
        register_setting('wholesaler-settings-group', 'wholesaler_retail_margin', array(
            'sanitize_callback' => 'absint'
        ));

        // Add settings section
        add_settings_section(
            'wholesaler-settings-section',
            __( 'Wholesaler Settings', 'wholesaler' ),
            array( $this, 'settings_section_callback' ),
            'wholesaler-settings'
        );

        // Add settings fields
        add_settings_field(
            'wholesaler_js_url',
            __( 'JS XML URL', 'wholesaler' ),
            array( $this, 'js_url_callback' ),
            'wholesaler-settings',
            'wholesaler-settings-section'
        );

        add_settings_field(
            'wholesaler_mada_url',
            __( 'MADA CSV/API URL', 'wholesaler' ),
            array( $this, 'mada_url_callback' ),
            'wholesaler-settings',
            'wholesaler-settings-section'
        );

        add_settings_field(
            'wholesaler_aren_url',
            __( 'AREN XML URL', 'wholesaler' ),
            array( $this, 'aren_url_callback' ),
            'wholesaler-settings',
            'wholesaler-settings-section'
        );

        add_settings_field(
            'wholesaler_retail_margin',
            __( 'Retail Margin (%)', 'wholesaler' ),
            array( $this, 'retail_margin_callback' ),
            'wholesaler-settings',
            'wholesaler-settings-section'
        );
    }

    // Section description (optional)
    public function settings_section_callback() {
        echo '<p>' . esc_html__( 'Enter the URLs for wholesaler integrations below.', 'wholesaler' ) . '</p>';
    }

    // Fields
    public function js_url_callback() {
        $value = esc_attr( get_option( 'wholesaler_js_url', '' ) );
        echo '<input type="url" class="regular-text" id="wholesaler_js_url" name="wholesaler_js_url" value="' . $value . '" style="width:60%">';
    }

    public function mada_url_callback() {
        $value = esc_attr( get_option( 'wholesaler_mada_url', '' ) );
        echo '<input type="url" class="regular-text" id="wholesaler_mada_url" name="wholesaler_mada_url" value="' . $value . '" style="width:60%">';
    }

    public function aren_url_callback() {
        $value = esc_attr( get_option( 'wholesaler_aren_url', '' ) );
        echo '<input type="url" class="regular-text" id="wholesaler_aren_url" name="wholesaler_aren_url" value="' . $value . '" style="width:60%">';
    }

    public function retail_margin_callback() {
        $value = esc_attr( get_option( 'wholesaler_retail_margin', '' ) );
        echo '<input type="number" class="regular-text" id="wholesaler_retail_margin" name="wholesaler_retail_margin" value="' . $value . '">';
    }
}
