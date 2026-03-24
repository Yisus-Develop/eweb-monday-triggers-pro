<?php
/**
 * EWEB GitHub Updater
 * 
 * Provides automatic updates and professional details view from GitHub.
 * Enhanced with auto-renaming folder logic for seamless updates.
 * Wrapped in class_exists check to prevent collisions between multiple EWEB plugins.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EWEB_GitHub_Updater' ) ) {

	class EWEB_GitHub_Updater {

		private $plugin_file;
		private $github_user;
		private $github_repo;
		private $basename;
		private $active;
		private $github_response;

		public function __construct( $plugin_file, $github_user, $github_repo ) {
			$this->plugin_file = $plugin_file;
			$this->github_user = $github_user;
			$this->github_repo = $github_repo;
			$this->basename    = plugin_basename( $plugin_file );
			$this->active      = is_plugin_active( $this->basename );

			add_filter( "pre_set_site_transient_update_plugins", [ $this, "check_update" ] );
			add_filter( "plugins_api", [ $this, "plugin_popup" ], 10, 3 );
			add_filter( "upgrader_post_install", [ $this, "fix_folder_name" ], 10, 3 );
		}

		public function check_update( $transient ) {
			if ( empty( $transient->checked ) ) return $transient;

			$remote = $this->get_github_data();

			if ( $remote && isset($transient->checked[$this->basename]) && version_compare( $transient->checked[$this->basename], $remote->tag_name, "<" ) ) {
				$res = new stdClass();
				$res->slug        = $this->github_repo;
				$res->plugin      = $this->basename;
				$res->new_version = $remote->tag_name;
				$res->package     = $remote->zipball_url;
				$res->url         = "https://github.com/" . $this->github_user . "/" . $this->github_repo;

				$transient->response[$this->basename] = $res;
			}

			return $transient;
		}

		public function plugin_popup( $res, $action, $args ) {
			if ( "plugin_information" !== $action ) return $res;
			if ( $this->github_repo !== $args->slug ) return $res;

			$remote = $this->get_github_data();

			if ( $remote ) {
				$res = new stdClass();
				$res->name           = "EWEB Suite Plugin";
				$res->slug           = $this->github_repo;
				$res->version        = $remote->tag_name;
				$res->author         = "Yisus Develop";
				$res->homepage       = "https://enlaweb.co/";
				$res->download_link  = $remote->zipball_url;

				$res->banners = [
					"low"  => "https://raw.githubusercontent.com/" . $this->github_user . "/" . $this->github_repo . "/main/assets/banner.png",
					"high" => "https://raw.githubusercontent.com/" . $this->github_user . "/" . $this->github_repo . "/main/assets/banner.png"
				];
				$res->icons = [
					"1x" => "https://raw.githubusercontent.com/" . $this->github_user . "/" . $this->github_repo . "/main/assets/icon.png",
					"2x" => "https://raw.githubusercontent.com/" . $this->github_user . "/" . $this->github_repo . "/main/assets/icon.png"
				];

				$res->sections = [
					"description"  => "High-fidelity professional plugin developed by **Yisus Develop**. Part of the EWEB Plugin Suite, optimized for performance and security.",
					"installation" => "1. Upload the plugin folder to /wp-content/plugins/\n2. Activate the plugin through the Plugins menu in WordPress.",
					"changelog"    => "v" . $remote->tag_name . " - Stable release and visual identity update.",
				];

				return $res;
			}

			return $res;
		}

		private function get_github_data() {
			if ( ! empty( $this->github_response ) ) return $this->github_response;

			$url = "https://api.github.com/repos/" . $this->github_user . "/" . $this->github_repo . "/releases/latest";
			$response = wp_remote_get( $url, [ "user-agent" => "WordPress/" . get_bloginfo("version") ] );

			if ( is_wp_error( $response ) ) return false;

			$this->github_response = json_decode( wp_remote_retrieve_body( $response ) );
			return $this->github_response;
		}

		public function fix_folder_name( $response, $hook_extra, $result ) {
			global $wp_filesystem;
			$install_directory = plugin_dir_path( $this->plugin_file ) . "../";
			$proper_folder = $install_directory . $this->github_repo;

			if ( isset($result["destination_name"]) && $result["destination_name"] !== $this->github_repo ) {
				$wp_filesystem->move($result["remote_destination"], $proper_folder);
				$result["destination"] = $proper_folder;
				$result["remote_destination"] = $proper_folder;
				$result["destination_name"] = $this->github_repo;
			}
			return $result;
		}
	}
}
