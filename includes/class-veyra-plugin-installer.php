<?php
/**
 * Plugin Installer for the Veyra Plugin Manager.
 *
 * Stripped-down port of Aardvark_Plugin_Installer. Downloads the veyra repo
 * branch zip, extracts it to a temp dir, and swaps the live veyra plugin
 * folder. Target folder is fixed to whatever directory veyra is installed in
 * (no DB table of plugin metadata needed).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once VEYRA_PLUGIN_PATH . 'includes/class-veyra-github-client.php';

class Veyra_Plugin_Installer {

    private $github_client;

    public function __construct($github_token = null) {
        $this->github_client = new Veyra_GitHub_Client($github_token);
    }

    /**
     * Update the veyra plugin in place from its GitHub repo.
     *
     * @param string $github_url  e.g. https://github.com/transatlanticvoyage/veyra
     * @param string $branch
     * @param string $dest_dir_name  the folder name veyra lives in under wp-content/plugins
     */
    public function update_from_github($github_url, $branch = 'main', $dest_dir_name = 'veyra', $github_token = null) {
        if ($github_token) {
            $this->github_client = new Veyra_GitHub_Client($github_token);
        }

        $repo_info = Veyra_GitHub_Client::parse_github_url($github_url);
        if (!$repo_info) {
            return array('error' => 'Invalid GitHub URL format');
        }

        $owner = $repo_info['owner'];
        $repo  = $repo_info['repo'];

        $download_result = $this->github_client->download_repository($owner, $repo, $branch);
        if (isset($download_result['error'])) {
            return array('error' => 'Download failed: ' . $download_result['error']);
        }

        $install_result = $this->extract_and_install($download_result['data'], $repo, $branch, $dest_dir_name);
        if (isset($install_result['error'])) {
            return $install_result;
        }

        $this->clear_plugin_cache();

        return array(
            'success'     => true,
            'message'     => 'Plugin updated successfully from GitHub',
            'plugin_path' => $install_result['plugin_path'],
        );
    }

    /**
     * Extract the downloaded zip and move it over the live plugin folder.
     */
    private function extract_and_install($zip_data, $repo_name, $branch, $dest_dir_name) {
        $upload_dir = wp_upload_dir();
        $temp_dir   = $upload_dir['basedir'] . '/veyra-temp';

        if (!wp_mkdir_p($temp_dir)) {
            return array('error' => 'Cannot create temporary directory');
        }

        $zip_file = $temp_dir . '/' . $repo_name . '-' . $branch . '.zip';
        $written  = file_put_contents($zip_file, $zip_data);
        if ($written === false) {
            $this->cleanup_temp_files($temp_dir);
            return array('error' => 'Cannot write ZIP file');
        }

        $extract_dir = $temp_dir . '/' . $repo_name . '-extract';
        $result = $this->extract_zip($zip_file, $extract_dir);
        if (isset($result['error'])) {
            $this->cleanup_temp_files($temp_dir);
            return $result;
        }

        $plugin_source = $this->find_plugin_directory($extract_dir, $repo_name, $branch);
        if (!$plugin_source) {
            $this->cleanup_temp_files($temp_dir);
            return array('error' => 'Cannot find plugin directory in extracted files');
        }

        // Sanity check: the extracted source must contain a main plugin file.
        if (!$this->find_main_plugin_file($plugin_source)) {
            $this->cleanup_temp_files($temp_dir);
            return array('error' => 'Downloaded archive does not look like a valid plugin (no Plugin Name header)');
        }

        $plugin_dest = WP_PLUGIN_DIR . '/' . $dest_dir_name;

        if (is_dir($plugin_dest)) {
            $this->remove_directory($plugin_dest);
        }

        if (!rename($plugin_source, $plugin_dest)) {
            $this->cleanup_temp_files($temp_dir);
            return array('error' => 'Cannot move plugin into the plugins directory');
        }

        $main_plugin_file = $this->find_main_plugin_file($plugin_dest);
        if (!$main_plugin_file) {
            $this->cleanup_temp_files($temp_dir);
            return array('error' => 'Cannot find main plugin file after install');
        }

        $this->cleanup_temp_files($temp_dir);

        return array(
            'success'     => true,
            'plugin_path' => $dest_dir_name . '/' . basename($main_plugin_file),
        );
    }

    private function extract_zip($zip_file, $extract_to) {
        if (!class_exists('ZipArchive')) {
            return array('error' => 'ZipArchive class not available');
        }

        $zip = new ZipArchive();
        $result = $zip->open($zip_file);
        if ($result !== true) {
            return array('error' => 'Cannot open ZIP file: ' . $result);
        }

        if (!wp_mkdir_p($extract_to)) {
            $zip->close();
            return array('error' => 'Cannot create extraction directory');
        }

        $extracted = $zip->extractTo($extract_to);
        $zip->close();

        if (!$extracted) {
            return array('error' => 'Cannot extract ZIP file');
        }

        return array('success' => true);
    }

    private function find_plugin_directory($extract_dir, $repo_name, $branch) {
        $possible_dirs = array(
            $extract_dir . '/' . $repo_name . '-' . $branch,
            $extract_dir . '/' . $repo_name . '-main',
            $extract_dir . '/' . $repo_name,
        );

        foreach ($possible_dirs as $dir) {
            if (is_dir($dir) && $this->dir_has_php($dir)) {
                return $dir;
            }
        }

        // Fallback: first subdirectory that contains PHP files.
        $dirs = scandir($extract_dir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $full_path = $extract_dir . '/' . $dir;
            if (is_dir($full_path) && $this->dir_has_php($full_path)) {
                return $full_path;
            }
        }

        return false;
    }

    private function dir_has_php($dir) {
        foreach (scandir($dir) as $file) {
            if (substr($file, -4) === '.php') {
                return true;
            }
        }
        return false;
    }

    private function find_main_plugin_file($plugin_dir) {
        foreach (scandir($plugin_dir) as $file) {
            if (substr($file, -4) === '.php') {
                $full_path = $plugin_dir . '/' . $file;
                if ($this->is_main_plugin_file($full_path)) {
                    return $full_path;
                }
            }
        }
        return false;
    }

    private function is_main_plugin_file($file) {
        if (!file_exists($file)) {
            return false;
        }
        $content = file_get_contents($file, false, null, 0, 2048);
        return strpos($content, 'Plugin Name:') !== false;
    }

    private function remove_directory($dir) {
        if (!is_dir($dir)) {
            return true;
        }
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    private function cleanup_temp_files($temp_dir) {
        if (is_dir($temp_dir)) {
            $this->remove_directory($temp_dir);
        }
    }

    private function clear_plugin_cache() {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('plugins', 'plugins');
            wp_cache_delete('get_plugins', 'plugins');
        }

        delete_site_transient('update_plugins');
        delete_transient('plugin_slugs');

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        get_plugins();
    }
}
