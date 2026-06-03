<?php
/**
 * GitHub API Client for the Veyra Plugin Manager.
 *
 * Stripped-down port of Aardvark_GitHub_Client — only what the single-plugin
 * self-update page needs: parse a github url, read the remote Version: header
 * off a branch, and download the branch zip.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Veyra_GitHub_Client {

    private $token;

    public function __construct($token = null) {
        $this->token = $token;
    }

    /**
     * Parse a GitHub URL to extract owner and repo.
     * Accepts https://github.com/owner/repo(.git)(/) forms.
     */
    public static function parse_github_url($url) {
        $patterns = array(
            '/github\.com\/([^\/]+)\/([^\/]+?)(\.git)?$/i',
            '/github\.com\/([^\/]+)\/([^\/]+?)\/$/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return array(
                    'owner' => $matches[1],
                    'repo'  => $matches[2],
                );
            }
        }

        return false;
    }

    /**
     * Fetch the raw main plugin file (veyra.php) from a branch and read its
     * "Version:" header. Returns the version string, or array('error' => ...).
     */
    public function get_remote_version($owner, $repo, $branch = 'main', $main_file = 'veyra.php') {
        $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$main_file}";

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Veyra-Plugin-Manager/1.0',
            ),
        );
        if ($this->token) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('error' => 'Could not read remote ' . $main_file . ': HTTP ' . $status_code);
        }

        $body = wp_remote_retrieve_body($response);
        if (preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $body, $m)) {
            return trim($m[1]);
        }

        return array('error' => 'No Version: header found in remote ' . $main_file);
    }

    /**
     * Download a repository branch as a ZIP. Returns array('data' => binary)
     * or array('error' => ...).
     */
    public function download_repository($owner, $repo, $branch = 'main') {
        $url = "https://github.com/{$owner}/{$repo}/archive/refs/heads/{$branch}.zip";

        $args = array(
            'timeout' => 300, // large repos
            'headers' => array(
                'User-Agent' => 'Veyra-Plugin-Manager/1.0',
            ),
        );
        if ($this->token) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return array('error' => 'Failed to download repository: HTTP ' . $status_code);
        }

        return array('data' => wp_remote_retrieve_body($response));
    }
}
