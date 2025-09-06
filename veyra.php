<?php
/**
 * Plugin Name: Veyra
 * Plugin URI: https://github.com/transatlanticvoyage/veyra
 * Description: Veyra - WordPress plugin for zen data management
 * Version: 1.0.0
 * Author: Veyra Team
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VEYRA_PLUGIN_VERSION', '1.0.0');
define('VEYRA_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('VEYRA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
class Veyra {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_bar_menu', array($this, 'add_elephant_tools_to_admin_bar'), 100);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_veyra_get_post_title', array($this, 'ajax_get_post_title'));
        add_action('wp_ajax_veyra_update_post_title', array($this, 'ajax_update_post_title'));
        add_action('wp_ajax_veyra_inject_replex_content', array($this, 'ajax_inject_replex_content'));
    }
    
    public function init() {
        // Plugin initialization code will go here
    }
    
    public function add_elephant_tools_to_admin_bar($wp_admin_bar) {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        
        $wp_admin_bar->add_node(array(
            'id' => 'elephant-tools',
            'title' => 'Elephant Tools',
            'href' => '#',
            'meta' => array(
                'onclick' => 'VeyraElephantTools.openModal(); return false;'
            )
        ));
    }
    
    public function enqueue_scripts() {
        if (!is_user_logged_in() || !current_user_can('edit_posts')) {
            return;
        }
        
        wp_enqueue_script('veyra-elephant-tools', VEYRA_PLUGIN_URL . 'assets/elephant-tools.js', array('jquery'), VEYRA_PLUGIN_VERSION, true);
        wp_enqueue_style('veyra-elephant-tools', VEYRA_PLUGIN_URL . 'assets/elephant-tools.css', array(), VEYRA_PLUGIN_VERSION);
        
        wp_localize_script('veyra-elephant-tools', 'veyra_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('veyra_elephant_tools'),
            'current_post_id' => get_queried_object_id()
        ));
    }
    
    public function ajax_get_post_title() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post) {
            wp_send_json_error('Post not found');
        }
        
        wp_send_json_success(array(
            'post_title' => $post->post_title,
            'post_id' => $post_id
        ));
    }
    
    public function ajax_update_post_title() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $post_id = intval($_POST['post_id']);
        $new_title = sanitize_text_field($_POST['new_title']);
        
        if (!$post_id || !$new_title) {
            wp_send_json_error('Invalid data');
        }
        
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $new_title
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error('Failed to update post');
        }
        
        wp_send_json_success(array(
            'message' => 'Post title updated successfully',
            'new_title' => $new_title
        ));
    }
    
    public function ajax_inject_replex_content() {
        check_ajax_referer('veyra_elephant_tools', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $replex_content = isset($_POST['replex_content']) ? wp_kses_post($_POST['replex_content']) : '';
        
        // Remove backslashes from all shortcode content
        $replex_content = preg_replace_callback('/\[[^[\]]+\]/', function($matches) {
            return stripslashes($matches[0]);
        }, $replex_content);
        
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
            return;
        }
        
        if (empty($replex_content)) {
            wp_send_json_error('No content provided');
            return;
        }
        
        // Parse the replex content to extract ##code and replacement text
        $replacements = array();
        $lines = preg_split('/\r\n|\r|\n/', $replex_content);
        $current_code = '';
        $current_text = '';
        
        foreach ($lines as $line) {
            // Check if line starts with ##
            if (preg_match('/^##(.+)$/', $line, $matches)) {
                // Save previous code/text pair if exists
                if ($current_code && $current_text) {
                    $replacements[$current_code] = trim($current_text);
                }
                // Start new code
                $current_code = trim($matches[1]);
                $current_text = '';
            } else if ($current_code) {
                // Add to current text
                $current_text .= ($current_text ? "\n" : "") . $line;
            }
        }
        
        // Save last code/text pair if exists
        if ($current_code && $current_text) {
            $replacements[$current_code] = trim($current_text);
        }
        
        if (empty($replacements)) {
            wp_send_json_error('No valid ##codes found in content');
            return;
        }

        // Get Elementor data
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (!$data) {
            wp_send_json_error('No Elementor data found for this page. Make sure this page uses Elementor.');
            return;
        }

        // Decode the data
        $elements = json_decode($data, true);
        if (!is_array($elements)) {
            wp_send_json_error('Failed to decode Elementor data');
            return;
        }

        // Process the elements
        $processed_count = 0;
        $elements = $this->process_replex_elements($elements, $replacements, $processed_count);

        // Save the updated data
        $updated_data = wp_json_encode($elements);
        if ($updated_data === false) {
            wp_send_json_error('Failed to encode updated data');
            return;
        }

        // Use Elementor's internal save mechanism if available
        if (class_exists('Elementor\Plugin')) {
            $elementor_instance = \Elementor\Plugin::$instance;
            $document = $elementor_instance->documents->get($post_id);
            if ($document) {
                // Update the document data
                $document->save([
                    'elements' => $elements,
                    'settings' => $document->get_settings()
                ]);
                
                // Clear Elementor cache
                if (isset($elementor_instance->files_manager) && method_exists($elementor_instance->files_manager, 'clear_cache')) {
                    $elementor_instance->files_manager->clear_cache();
                }
                
                wp_send_json_success(array(
                    'message' => 'Content replaced successfully! ' . $processed_count . ' replacement(s) made.'
                ));
                return;
            }
        }

        // Fallback: Save directly to post meta
        update_post_meta($post_id, '_elementor_data', $updated_data);
        
        wp_send_json_success(array(
            'message' => 'Content replaced successfully! ' . $processed_count . ' replacement(s) made.'
        ));
    }
    
    private function process_replex_elements($elements, $replacements, &$processed_count) {
        foreach ($elements as &$el) {
            if (isset($el['settings']) && is_array($el['settings'])) {
                // Store original settings
                $original_settings = $el['settings'];
                
                // Update content fields based on widget type
                if (isset($el['widgetType'])) {
                    switch ($el['widgetType']) {
                        case 'heading':
                            if (isset($original_settings['title'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title'] === $code) {
                                        $el['settings']['title'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve typography and other settings
                                        foreach ($original_settings as $setting_key => $setting_val) {
                                            if ($setting_key !== 'title' && !isset($el['settings'][$setting_key])) {
                                                $el['settings'][$setting_key] = $setting_val;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'text-editor':
                            if (isset($original_settings['editor'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    // Check for the code both with and without HTML tags
                                    $code_with_tags = '<p>' . $code . '</p>';
                                    if ($original_settings['editor'] === $code || $original_settings['editor'] === $code_with_tags) {
                                        // If the original had HTML tags, wrap the new content in them
                                        if (strpos($original_settings['editor'], '<p>') !== false) {
                                            $el['settings']['editor'] = '<p>' . $replacement_text . '</p>';
                                        } else {
                                            $el['settings']['editor'] = $replacement_text;
                                        }
                                        $processed_count++;
                                        
                                        // Preserve typography and other settings
                                        foreach ($original_settings as $setting_key => $setting_val) {
                                            if ($setting_key !== 'editor' && !isset($el['settings'][$setting_key])) {
                                                $el['settings'][$setting_key] = $setting_val;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'image-box':
                            if (isset($original_settings['title_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title_text'] === $code) {
                                        $el['settings']['title_text'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve title typography
                                        if (isset($original_settings['title_typography_typography'])) {
                                            $el['settings']['title_typography_typography'] = $original_settings['title_typography_typography'];
                                        }
                                    }
                                }
                            }
                            if (isset($original_settings['description_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['description_text'] === $code) {
                                        $el['settings']['description_text'] = $replacement_text;
                                        $processed_count++;
                                        
                                        // Preserve description typography
                                        if (isset($original_settings['description_typography_typography'])) {
                                            $el['settings']['description_typography_typography'] = $original_settings['description_typography_typography'];
                                        }
                                    }
                                }
                            }
                            break;
                            
                        case 'button':
                            if (isset($original_settings['text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['text'] === $code) {
                                        $el['settings']['text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            break;
                            
                        case 'icon-box':
                            if (isset($original_settings['title_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['title_text'] === $code) {
                                        $el['settings']['title_text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            if (isset($original_settings['description_text'])) {
                                foreach ($replacements as $code => $replacement_text) {
                                    if ($original_settings['description_text'] === $code) {
                                        $el['settings']['description_text'] = $replacement_text;
                                        $processed_count++;
                                    }
                                }
                            }
                            break;
                    }
                }
            }
            
            // Process child elements
            if (isset($el['elements']) && is_array($el['elements'])) {
                $el['elements'] = $this->process_replex_elements($el['elements'], $replacements, $processed_count);
            }
        }
        return $elements;
    }
}

// Start the plugin
new Veyra();