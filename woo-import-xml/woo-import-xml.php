<?php

/*
Plugin Name: WooCommerce Import XML
Description: Import XML files into WooCommerce
Version: 1.0
Author: KVLK
Author URI: http://kvlk.me
License: GPL2
*/
// add settings page
add_action('admin_menu', 'woo_import_xml_menu');
function woo_import_xml_menu() {
    add_menu_page('WooCommerce Import XML', 'Woo Import XML', 'manage_options', 'woo-import-xml', 'woo_import_xml_page');
}
// settings page with url field and div block for logs
function woo_import_xml_page() {
    ?>
    <div class="wrap">
        <h2>WooCommerce Import XML</h2>
        <form method="post" action="options.php">
            <?php settings_fields('woo_import_xml_options'); ?>
            <?php do_settings_sections('woo-import-xml'); ?>
            <input name="submit" class="button button-primary" type="submit" value="Save" />
        </form>

        <p>Cron script path: <code><?=dirname(__FILE__);?>/cron.php</code></p>
        Start from <input type="text" id="woo-import-xml-start-from" value="0" style="width: 50px"> product
        <button id="woo-import-xml" class="button button-primary">Import</button>
        <button id="woo-import-xml-stop" class="button button-secondary" disabled>Stop</button>
        <div id="progress" style="border: 1px solid #000;margin: 10px;width: 100%;height: 20px;position: relative">
            <div id="progress-bar" style="background: #0073aa;height: 100%;width: 0">
                <span id="progress-text" style="color: greenyellow;font-weight:600;position: absolute;text-align: center;width: 100%;">0%</span>
            </div>
        </div>
        <div id="log" style="border: 1px solid #000;margin: 10px;width: 100%;height: 500px;overflow-y: scroll"></div>
    </div>
    <?php
}
// register settings
add_action('admin_init', 'woo_import_xml_register_settings');
function woo_import_xml_register_settings() {
    register_setting('woo_import_xml_options', 'woo_import_xml_options', 'woo_import_xml_options_validate');
    add_settings_section('woo_import_xml_main', 'Main Settings', 'woo_import_xml_section_text', 'woo-import-xml');
    add_settings_field('woo_import_xml_url', 'XML URL', 'woo_import_xml_setting_url', 'woo-import-xml', 'woo_import_xml_main');
}
// validate url
function woo_import_xml_options_validate($input) {
    $newinput['url'] = trim($input['url']);
    return $newinput;
}
// section text
function woo_import_xml_section_text() {
    echo '<p>Enter the URL of the XML file you want to import.</p>';
}
// url field
function woo_import_xml_setting_url() {
    $options = get_option('woo_import_xml_options');
    echo "<input id='woo_import_xml_url' name='woo_import_xml_options[url]' size='40' type='text' value='{$options['url']}' />";
}
// enqueue scripts
add_action('admin_enqueue_scripts', 'woo_import_xml_enqueue_scripts');
function woo_import_xml_enqueue_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_script('woo-import-xml', plugin_dir_url(__FILE__) . 'woo-import-xml.js', array('jquery'), '1.2', true);
    wp_localize_script('woo-import-xml', 'woo_import_xml', array('ajaxurl' => admin_url('admin-ajax.php')));
}
// ajax action
add_action('wp_ajax_woo_import_xml', 'woo_import_xml_ajax');
function woo_import_xml_ajax() {
    $options = get_option('woo_import_xml_options');
    $url = $options['url'];
    require_once plugin_dir_path(__FILE__) . 'woo-import-xml.class.php';
    $woo_import_xml = new Woo_Import_XML($url);
    echo json_encode($woo_import_xml->import());
    wp_die();


}