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
    }
    
    public function init() {
        // Plugin initialization code will go here
    }
}

// Start the plugin
new Veyra();