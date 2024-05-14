<?php
require_once plugin_dir_path(__FILE__) . 'woo-import-xml.class.php';
$woo_import_xml = new Woo_Import_XML();
$woo_import_xml->import_cron();