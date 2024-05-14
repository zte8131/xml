<?php
require_once dirname(__FILE__, 4) . '/wp-load.php';

class Woo_Import_XML {
    private $xml_url;
    private $xml_content = null;
    private $step = 0;

    public function __construct($xml_url=null, $step = 0) {
        $this->xml_url = ($xml_url ?? get_option('woo_import_xml_options')['url']);
        $this->step = isset($_POST['step']) ? intval($_POST['step']) : $step;
    }

    public function import() {
        if (empty($this->xml_url)) {
            return ['success' => false, 'message' => 'No XML URL provided.'];
        }

        $this->xml_content = $this->load_xml($this->xml_url);
        if (!$this->xml_content) {
            return ['success' => false, 'message' => 'Failed to load XML content.'];
        }

        switch ($this->step) {
            case 0:
                return $this->import_step_1();
            case 1:
                return $this->import_step_2();
            case 2:
                return $this->set_to_draft_not_in_xml();
            default:
                return ['success' => false, 'message' => 'Invalid step.'];
        }
    }
    public function import_cron() {
        $this->xml_content = $this->load_xml($this->xml_url);
        if (!$this->xml_content) {
            return ['success' => false, 'message' => 'Failed to load XML content.'];
        }

        $this->step = 1;
        $result = $this->import_step_1();
        while ($result['continue']) {
            $result = $this->import_step_2();
            echo json_encode($result) . PHP_EOL;
            sleep(2);
        }
        echo json_encode($this->set_to_draft_not_in_xml()) . PHP_EOL;
    }
    private function import_step_1() {
        $product_num = count($this->xml_content->product);
        return ['success' => true, 'message' => "Found $product_num products. Starting import...", 'continue' => true, 'next_num' => (isset($_POST['num']) ?(int)$_POST['num']:0),'total' => $product_num];
    }

    private function import_step_2() {
        $num = (int)$_POST['num'] ?? 0;
        if (!isset($this->xml_content->product[$num])) {
            return ['success' => false, 'message' => "No product found at index $num."];
        }

        $product_xml = $this->xml_content->product[$num];
        $product = $this->get_product_by_id_or_name($product_xml);
        $new = false;
/*
        if ($product){
            $product->delete(true);
            $product = null;
        }
*/

        if (!$product) {
            $product = $this->initialize_product($product_xml);
            $new = true;
        }
        // Skip (or set to draft if exists) if no variations are in stock
        if(!empty($product_xml->variant)){
            $in_stock = false;
            foreach ($product_xml->variant as $variant){
                if((int)$variant->quantity > 0){
                    $in_stock = true;
                    break;
                }
            }
            if(!$in_stock){
                if ($new) {
                    return [
                        'success' => true,
                        'message' => "Product with SKU {$product_xml->SKU} has no variations in stock and is not in the database.",
                        'continue' => $num < (count($this->xml_content->product) - 1),
                        'next_num' => $num + 1
                    ];
                } else {
                    $product->set_status('draft');
                    $product->save();
                    return [
                        'success' => true,
                        'message' => "Product with ID {$product->get_id()} set to draft because no variations are in stock.",
                        'continue' => $num < (count($this->xml_content->product) - 1),
                        'next_num' => $num + 1
                    ];
                }
            }
        }

        $this->setup_product_data($product, $product_xml, $new);
        $this->handle_variations($product, $product_xml);
        $product->save();
        update_post_meta($product->get_id(), '_last_imported', time());

        return [
            'success' => true,
            'message' => "Product with ID {$product->get_id()} processed.",
            'continue' => $num < (count($this->xml_content->product) - 1),
            'next_num' => $num + 1
        ];
    }
    public function set_to_draft_not_in_xml() {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'status' => 'publish'
        ];
        $products = wc_get_products($args);
        $i = 0;
        foreach ($products as $product) {
            $product_id = $product->get_id();
            $last_imported = get_post_meta($product_id, '_last_imported', true);
            if (!$last_imported || $last_imported < strtotime('-1 day')) {
                $i++;
                $product->set_status('draft');
                $product->save();
            }
        }
        return ['success' => true, 'message' => "$i products set to draft."];
    }

    private function load_xml($url) {
        $cached_file = __DIR__ . '/last_import.xml';
        if (file_exists($cached_file) && $this->step !== 0) {
            $xml = file_get_contents($cached_file);
            return simplexml_load_string($xml);
        }

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        file_put_contents($cached_file, $body);
        return simplexml_load_string($body);
    }

    private function get_product_by_id_or_name($product_xml) {
        $args = [
            'sku' => (string)$product_xml->SKU,
            'limit' => 1,
            'return' => 'objects'
        ];
        $products = wc_get_products($args);

        return $products ? $products[0] : null;
    }

    private function initialize_product($product_xml) {
        $product = (isset($product_xml->variant[0]) && !empty($product_xml->variant[0])) ? new WC_Product_Variable() : new WC_Product_Simple();
        return $product;
    }

    private function setup_product_data($product, $product_xml, $new = false) {

        if ($new) {
            $product->set_name((string)$product_xml->name);
            $product->set_description((string)$product_xml->description);
        }
        $product->set_sku((string)$product_xml->SKU);
        if (!empty($product_xml->price)) {
            $product->set_regular_price((string)$product_xml->price);
        }
        if (!empty($product_xml->images->image)) {
            $this->handle_images($product, $product_xml->images);
        }
        if (!empty($product_xml->variant[0]->quantity)) {
            $product->set_stock_quantity((string)$product_xml->variant[0]->quantity);
        }
        if (!empty($product_xml->variant[0]->price)) {
            $product->set_regular_price((string)$product_xml->variant[0]->price);
        }
        if (!empty($product_xml->variant[0]->attributes)) {
            // Collect all unique attributes and their options from all variations
            $all_attributes = [];
            for($i = 0; $i < count($product_xml->variant); $i++) {
                $variant_xml = $product_xml->variant[$i];
                $variant_attributes = $this->parse_attributes($variant_xml->attributes);
                foreach ($variant_attributes as $attribute_name => $attribute_value) {
                    $all_attributes[$attribute_name][] = $attribute_value;
                }
            }

// Remove duplicate values and reset keys
            /*foreach ($all_attributes as $attribute_name => $values) {
                $all_attributes[$attribute_name] = array_values(array_unique($values));
            }*/

// Now set the collected attributes and their options to the parent product
            $wc_attributes = [];
            $i = 0;
            foreach ($all_attributes as $attribute_name => $attribute_values) {
                $wc_attribute = new WC_Product_Attribute();

                // Ensure the global attribute exists
                $taxonomy = $this->create_product_attribute($attribute_name);
                if (is_wp_error($taxonomy)) {
                    continue; // Handle the error accordingly
                }

                // Ensure the terms exist and get their slugs
                $term_slugs = [];
                $term_names = [];
                foreach ($attribute_values as $term_name) {
                    $term = term_exists($term_name, $taxonomy) ?: wp_insert_term($term_name, $taxonomy);
                    if (!is_wp_error($term)) {
                        $term_slugs[] = get_term_by('id', $term['term_id'], $taxonomy)->slug;
                        $term_names[] = $term_name;
                    }
                }
                // get taxonomy id

                $wc_attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
                //$wc_attribute->set_id(0);
                $wc_attribute->set_name($taxonomy);
                $wc_attribute->set_options($term_names);
                $wc_attribute->set_position($i++);
                $wc_attribute->set_visible(true);
                $wc_attribute->set_variation(true);
                $wc_attributes[] = $wc_attribute;

                wp_set_object_terms($product->get_id(), $term_slugs, $taxonomy);
            }

            $product->set_attributes($wc_attributes);

            usort($wc_attributes, function($a, $b) {
                return $a->get_name() <=> $b->get_name();
            });
            update_post_meta($product->get_id(), '_product_attributes', $wc_attributes);
        //    $product->save();
        }
        $product->save();
        //do_action('woocommerce_process_product_meta', $product->get_id());
    }

    private function handle_variations($product, $product_xml) {
        if (!empty($product_xml->variant) && $product instanceof WC_Product_Variable) {
            $variation_ids = [];
            foreach ($product_xml->variant as $i=> $variant_xml) {
                $variation_id = $this->create_or_update_variation($product, $variant_xml, $i);
                $variation_ids[] = $variation_id;
            }
            $product->set_children($variation_ids);
        }
    }

    private function create_or_update_variation($product, $variant_xml, $i) {
        $sku = (string)$variant_xml->parentSKU . '-' . (string)$variant_xml->id;
       /* if (wc_get_product_id_by_sku($sku)) {
            $variation = wc_get_product(wc_get_product_id_by_sku($sku));
            $variation->delete(true);
        }
        */

        $variation = wc_get_product_id_by_sku($sku) ? wc_get_product(wc_get_product_id_by_sku($sku)) : new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_sku($sku);
        $variation->set_regular_price((string)$variant_xml->price);
        $variation->set_stock_quantity((string)$variant_xml->quantity);

        $variation->set_manage_stock(true); // This allows you to manage stock for the variation
        $variation->set_stock_status('instock'); // Set the stock status

        $variation_attributes = [];
        $attributes = $this->parse_attributes($variant_xml->attributes);
        // add attributes to variation
        foreach ($attributes as $attribute_name => $attribute_value) {
            $taxonomy = $this->create_product_attribute($attribute_name);
            //$attribute_value =

            // Check if the term exists
            $term = term_exists($attribute_value, $taxonomy);
            if(!$term){
                $term = term_exists($attribute_value."-2", $taxonomy);
            }
            if (!$term) {
                $term = term_exists(wc_sanitize_taxonomy_name($attribute_value), $taxonomy);
            }
            if(!$term){
                $term = term_exists(wc_sanitize_taxonomy_name($attribute_value)."-2", $taxonomy);
            }
            if(!$term){
                $terms = get_terms(array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                ));
                foreach ($terms as $term){
                    if($term->name == $attribute_value || $term->name == wc_sanitize_taxonomy_name($attribute_value) || $term->slug == wc_sanitize_taxonomy_name($attribute_value) || str_contains($term->name, $attribute_value) || str_contains($term->name, wc_sanitize_taxonomy_name($attribute_value)) || str_contains($term->slug, wc_sanitize_taxonomy_name($attribute_value)) || str_contains($term->name, wc_sanitize_taxonomy_name($attribute_value)) || str_contains($term->slug, wc_sanitize_taxonomy_name($attribute_value)) || str_contains($term->name, $attribute_value) || str_contains($term->slug, $attribute_value) || str_contains($term->name, wc_sanitize_taxonomy_name($attribute_value)) || str_contains($term->slug, wc_sanitize_taxonomy_name($attribute_value))){
                        $term = $term->term_id;
                        break;
                    }
                }
            }

            // If the term does not exist, create it
            if (!$term) {
                $taxonomy = $this->create_product_attribute($attribute_name);
                    $term = wp_insert_term($attribute_value, $taxonomy);

                $term = wp_insert_term($attribute_value, $taxonomy);
                // add new term to product
                if(is_wp_error($term)) {
                    continue;
                }
                wp_set_object_terms($product->get_id(), $term['term_id'], $taxonomy, true);
                $product->save();


                // If there was an error inserting the term, continue to the next attribute
                if (is_wp_error($term)) var_dump($term);
            }

            // Get the term ID to set the variation attribute
            $term_id = $term['term_id'];
            $term_obj = get_term_by('id', $term_id, $taxonomy);

            if (!$term_obj || is_wp_error($term_obj)) {
                continue;
            }

            // Set the term slug for the variation attribute using the correct taxonomy
            $variation_attributes[$taxonomy] = $term_obj->slug;
        }

// ... Rest of your code to save the variation

        $variation->set_attributes($variation_attributes);
        do_action('woocommerce_save_product_variation', $variation->get_id(), $i);

        $variation->save();
        //do_action('woocommerce_process_product_meta', $variation->get_id());
        return $variation->get_id();
    }

    private function handle_images($product, $images_xml) {
        $image_ids = [];
        foreach ($images_xml->image as $image) {
            $image_id = $this->import_image_by_url((string)$image->url, (string)$image->md5);
            if (!is_wp_error($image_id)) {
                $image_ids[] = $image_id;
            }
        }
        if (!empty($image_ids)) {
            $product->set_image_id(array_shift($image_ids)); // Set the first image as the main image
            if (!empty($image_ids)) {
                $product->set_gallery_image_ids($image_ids); // Set the rest as gallery images
            }
        }
    }

    private function import_image_by_url($image_url, $image_md5) {
        global $wpdb;
        $query = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_image_md5' AND meta_value = %s", $image_md5);
        $existing_image_id = $wpdb->get_var($query);
        if ($existing_image_id) {
            return $existing_image_id;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $file_array = ['name' => basename($image_url), 'tmp_name' => $tmp];
        $id = media_handle_sideload($file_array, 0);
        @unlink($tmp);
        if (is_wp_error($id)) {
            return $id;
        }
        add_post_meta($id, '_image_md5', $image_md5, true);
        return $id;
    }
    /*
    private function set_product_attributes($product, $attributes) {
        $wc_attributes = [];

        foreach ($attributes as $name => $value) {
            // Create the attribute if it doesn't exist
            $attribute_id = $this->create_product_attribute($name);

            // Ensure it's created successfully
            if (is_wp_error($attribute_id)) {
                // Handle the error, e.g., log it or output a message
                continue;
            }

            // Get the taxonomy of the attribute
            $taxonomy = wc_attribute_taxonomy_name_by_id($attribute_id['attribute_id']);

            // Check if the term already exists
            $term = term_exists($value, $taxonomy);
            if ($term === 0 || $term === null) {
                // Term doesn't exist, add it
                $term = wp_insert_term($value, $taxonomy);
            }

            // Reload to get the term slug
            $term = get_term_by('id', $term['term_id'], $taxonomy);

            // Prepare the product attribute data
            $wc_attributes[] = array(
                'id' => $attribute_id['attribute_id'],
                'name' => $taxonomy,
                'options' => array($term->slug),
                'position' => 0,
                'visible' => 1,
                'variation' => 1,
                'is_taxonomy' => 1
            );
        }

        // Set attributes and save the product
        $product->set_attributes($wc_attributes);
        $product->save();
    }
    */private function create_product_attribute($label_name) {
    // The label name is what you see in the WooCommerce UI
    $label_name = wc_clean($label_name);

    // The slug is a sanitized version of the label name, used to construct the taxonomy
    $slug = wc_sanitize_taxonomy_name($label_name);

    if (strlen($slug) >= 28) {
        // Ensure slug does not exceed 28 characters so we have room for prefixes and potentially a suffix
        $slug = substr($slug, 0, 28);
    }

    // The taxonomy name for a WooCommerce attribute always starts with 'pa_'
    $taxonomy = 'pa_' . $slug;

    if (!taxonomy_exists($taxonomy)) {
        $attribute_id = wc_create_attribute(array(
            'name'         => $label_name,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ));

        if (is_wp_error($attribute_id)) {
            // Handle errors, perhaps by logging or returning the WP_Error
            return $attribute_id;
        }

        // Get the taxonomy name using the new attribute ID
        $taxonomy = wc_attribute_taxonomy_name_by_id($attribute_id);
    }

    // Ensure the taxonomy is registered
    register_taxonomy(
        $taxonomy,
        'product',
        array(
            'label' => $label_name,
            'public' => true,
            'hierarchical' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_nav_menus' => true,
            'show_in_rest' => true,
            'show_tagcloud' => false,
            'show_in_quick_edit' => false,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => false,
        )
    );

    return $taxonomy;
}

// Use the taxonomy name to create attribute in set_product_attributes
    private function set_product_attributes($product, $attributes) {
        $wc_attributes = []; foreach ($attributes as $attribute_name => $attribute_value) {
            $taxonomy = $this->create_product_attribute($attribute_name);
            if (is_wp_error($taxonomy)) {
                // Handle the error accordingly
                continue;
            }
         //   $attribute_value = wc_clean($attribute_value);

            // Check if the term already exists
            $term = term_exists($attribute_value, $taxonomy);
            if (!$term) {
                // Insert the term if it doesn't exist
                $term = wp_insert_term($attribute_value, $taxonomy);
            }

            if (is_wp_error($term)) {
                // Handle the error accordingly
                continue;
            }

            $term_slug = get_term_by('id', $term['term_id'], $taxonomy)->slug;

            // Add each attribute to the $wc_attributes array
            $wc_attributes[$taxonomy] = array(
                'name' => $taxonomy,
                'value' => $term_slug,
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );
        }

        $product->set_attributes($wc_attributes);
        $product->save();
    }

// ...

    private function parse_attributes($attributes_xml) {
        $attributes = [];
        foreach ($attributes_xml->children() as $attribute) {
            $attributes[(string)$attribute->name] = (string)$attribute->value;
        }
        return $attributes;
    }
}
