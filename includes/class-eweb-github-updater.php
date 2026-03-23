<?php
/**
 * Professional GitHub Update System for EWEB Plugins
 * Improved version with proper User-Agent and error handling.
 */

if ( ! defined( "ABSPATH" ) ) { exit; }

if ( ! class_exists( "EWEB_GitHub_Updater" ) ) {

	class EWEB_GitHub_Updater {

		private $file;
		private $plugin_slug;
		private $github_user;
		private $github_repo;
		private $github_response;
		private $plugin_data;

		public function __construct( $file, $github_user, $github_repo ) {
			$this->file = $file;
			$this->plugin_slug = plugin_basename( $file );
			$this->github_user = $github_user;
			$this->github_repo = $github_repo;

			add_filter( "pre_set_site_transient_update_plugins", [ $this, "check_update" ] );
			add_filter( "plugins_api", [ $this, "plugin_popup" ], 10, 3 );
		}

		private function get_local_plugin_data() {
			if ( null !== $this->plugin_data ) { return $this->plugin_data; }
			if ( ! function_exists( "get_plugin_data" ) ) { require_once ABSPATH . "wp-admin/includes/plugin.php"; }
			$this->plugin_data = get_plugin_data( $this->file );
			return $this->plugin_data;
		}

		public function check_update( $transient ) {
			if ( empty( $transient->checked ) ) { return $transient; }

			$remote = $this->get_github_data();
			$local_data = $this->get_local_plugin_data();

			if ( $remote && isset( $remote->tag_name ) && version_compare( $local_data["Version"], $remote->tag_name, "<" ) ) {
				$obj = new stdClass();
				$obj->slug = dirname( $this->plugin_slug );
				$obj->new_version = $remote->tag_name;
				$obj->url = "https://github.com/" . $this->github_user . "/" . $this->github_repo;
				$obj->package = $remote->zipball_url;

				$transient->response[ $this->plugin_slug ] = $obj;
			}

			return $transient;
		}

		public function plugin_popup( $result, $action, $args ) {
			$local_data = $this->get_local_plugin_data();
			$plugin_dirname = dirname( $this->plugin_slug );

			if ( $action !== "plugin_information" || ! isset( $args->slug ) || $args->slug !== $plugin_dirname ) {
				return $result;
			}

			$remote = $this->get_github_data();
			if ( ! $remote ) { return $result; }

			$res = new stdClass();
			$res->name = $local_data["Name"];
			$res->slug = $plugin_dirname;
			$res->version = $remote->tag_name;
			$res->author = $local_data["Author"];
			$res->homepage = $local_data["PluginURI"];
			$res->download_link = $remote->zipball_url;
			$res->banners = ["low" => "https://raw.githubusercontent.com/Yisus-Develop/eweb-starter-helper/main/assets/banner.png", "high" => "https://raw.githubusercontent.com/Yisus-Develop/eweb-starter-helper/main/assets/banner.png"]; $res->icons = ["1x" => "https://raw.githubusercontent.com/Yisus-Develop/eweb-starter-helper/main/assets/icon.png", "2x" => "https://raw.githubusercontent.com/Yisus-Develop/eweb-starter-helper/main/assets/icon.png"]; 
			$res->sections = [
				"description"  => "High-fidelity professional plugin developed by **Yisus Develop**. Part of the EWEB Plugin Suite, optimized for performance and security.",
				"installation" => "1. Upload the plugin folder to /wp-content/plugins/\n2. Activate the plugin through the Plugins menu in WordPress.",
				"changelog"    => "v" . $res->version . " - Official stable release and visual identity update.",
			];
			$res->sections = [
				"description" => $local_data["Description"],
				"changelog"   => $remote->body ?? "New version available via GitHub.",
			];

			return $res;
		}

		private function get_github_data() {
			if ( ! empty( $this->github_response ) ) { return $this->github_response; }

			$url = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
			
			$args = [
				"timeout" => 15,
				"headers" => [
					"Accept" => "application/vnd.github.v3+json",
					"User-Agent" => "WordPress/6.4; " . get_bloginfo("url")
				],
			];

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) { return false; }

			$this->github_response = json_decode( wp_remote_retrieve_body( $response ) );
			return $this->github_response;
		}
	}
}
