<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 *  Force WordPress to always output English at the command line.
 */
WP_CLI::add_wp_hook( 'pre_option_WPLANG', function() {
    return 'en_US';
});

/**
 * Public methods of this class which are not marked as "Not a WP CLI command"
 * are WP CLI commands which can be used to perform actions on an AT site.
 *
 * Class WPCOMSH_CLI_Commands
 */
class WPCOMSH_CLI_Commands extends WP_CLI_Command {
	const TRANSIENT_DEACTIVATED_USER_PLUGINS = 'wpcomsh_deactivated_user_installed_plugins';

	const DONT_DEACTIVATE_PLUGINS = [
		'akismet',
		'amp',
		'jetpack',
	];

	// Plugins used by the e-commerce plan.
	// see https://wpcom.trac.automattic.com/browser/trunk/wp-content/lib/atomic/class-plan-manager.php#L96
	const ECOMMERCE_PLAN_PLUGINS = [
		'storefront-powerpack',
		'woocommerce',
		'facebook-for-woocommerce',
		'mailchimp-for-woocommerce',
		'woocommerce-services',
		'woocommerce-product-addons',
		'taxjar-simplified-taxes-for-woocommerce',
	];

	private function confirm( $question ) {
		fwrite( STDOUT, $question . ' [Y/n] ' );
		$answer = strtolower( trim( fgets( STDIN ) ) );
		return 'y' === $answer || ! $answer;
	}

	/**
	 * This generates a list of names of (active) plugins.
	 *
	 * @return Array List of plugin names.
	 */

	/**
	 * This generates a list of names of (active) plugins.
	 *
	 * @param  boolean $only_active Whether to only include active plugins.
	 * @return Array                List of plugin names.
	 */
	private function get_plugin_names( $only_active = false ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling native WordPress hook.
		$all_plugins = array_keys( apply_filters( 'all_plugins', get_plugins() ) );

		if ( $only_active ) {
			$plugins = array_filter(
				$all_plugins,
				function( $file ) {
					return is_plugin_active_for_network( $file ) || is_plugin_active( $file );
				}
			);
		}

		$plugin_names = array_map(
			function( $file ) {
					return WP_CLI\Utils\get_plugin_name( $file );
			},
			$plugins
		);

		return $plugin_names;
	}

	/**
	 * This generates a list of active plugins that can be deactivated.
	 *
	 * @return Array List of plugin names.
	 */
	private function get_deactivatable_plugin_names() {
		$active_plugins = $this->get_plugin_names( true );

		// If the site is on an e-commerce plan, we don't want to deactivate the e-commerce plugins.
		$skip_ecommerce_plugins = Atomic_Plan_Manager::current_plan_slug() !== Atomic_Plan_Manager::ECOMMERCE_PLAN_SLUG;

		$plugins = array_filter(
			$active_plugins,
			function( $name ) use ( $skip_ecommerce_plugins ) {
				if (
					in_array( $name, self::DONT_DEACTIVATE_PLUGINS )
					|| ( ! $skip_ecommerce_plugins && in_array( $name, self::ECOMMERCE_PLAN_PLUGINS ) )
				) {
					WP_CLI::log( "Plugin '$name' skipped." );
					return false;
				}

				return true;
			}
		);

		return $plugins;
	}

	/**
	 * Remove plugins that no longer exist and warn the user about it.
	 *
	 * @param  Array $plugins A list of plugin names.
	 * @return Array          A list that only includes plugins that are actually installed.
	 */
	private function remove_deleted_plugins( $plugins ) {
		// Remove plugins that are no longer installed. If we try to deactivate them wp-cli would exit mid-way.
		$missing_plugins = array_diff( $plugins, $this->get_plugin_names() );
		if ( ! empty( $missing_plugins ) ) {
			WP_CLI::warning( 'Some of the previously enabled plugins have been deleted, so we cannot enable them.' );
			WP_CLI::warning( 'Missing plugins: ' . implode( ', ', $missing_plugins ) );

			// Remove missing plugins from the list so we will not try to enable them.
			$plugins = array_diff( $plugins, $missing_plugins );
		}

		return $plugins;
	}

	/**
	 * Bulk deactivate user installed plugins
	 *
	 * Deactivate all user installed plugins except for important ones for Atomic.
	 *
	 * @subcommand deactivate-user-plugins
	 * @synopsis [--interactive] [--include-ecommerce]
	 * ## OPTIONS
	 *
	 * [--interactive]
	 * : Ask for each active plugin whether to deactivate
	 */
	function deactivate_user_installed_plugins( $args, $assoc_args = array() ) {
		$user_installed_plugins = $this->get_deactivatable_plugin_names();
		if ( empty( $user_installed_plugins ) ) {
			WP_CLI::warning( 'No active user installed plugins found.' );
			return;
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'interactive', false ) ) {
			foreach ( $user_installed_plugins as $k => $plugin ) {
				if ( $this->confirm( 'Deactivate plugin "' . $plugin . '"?' ) ) {
					WP_CLI::run_command( array( 'plugin', 'deactivate', $plugin ) );
				}
			}
		} else {
			// This prepares to execute the CLI command: wp plugin deactivate plugin1 plugin2 ...
			array_unshift( $user_installed_plugins, 'plugin', 'deactivate' );

			WP_CLI::run_command( $user_installed_plugins );
		}
	}

	/**
	 * Bulk re-activate user installed plugins
	 *
	 * If previously user installed plugins had been deactivated, this re-activates these plugins.
	 *
	 * @subcommand reactivate-user-plugins
	 * @synopsis [--interactive] [--include-ecommerce]
	 * ## OPTIONS
	 *
	 * [--interactive]
	 * : Ask for each previously deactivated plugin whether to activate
	 */
	function reactivate_user_installed_plugins( $args, $assoc_args = array() ) {
		$previously_deactivated_plugins = $this->remove_deleted_plugins( get_transient( self::TRANSIENT_DEACTIVATED_USER_PLUGINS ) );

		if ( false === $previously_deactivated_plugins ) {
			WP_CLI::log( "Can't find any previously deactivated plugins." );
			exit;
		}

		WP_CLI::warning( 'Activating previously deactivated user installed plugins.' );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'interactive', false ) ) {
			foreach ( $previously_deactivated_plugins as $k => $plugin ) {
				if ( $this->confirm( 'Activate plugin "' . $plugin . '"?' ) ) {
					unset( $previously_deactivated_plugins[ $k ] );
					// Update transient everytime to prevent using the original transient if loop breaks/terminated
					set_transient( self::TRANSIENT_DEACTIVATED_USER_PLUGINS, $previously_deactivated_plugins, DAY_IN_SECONDS );

					WP_CLI::run_command( array( 'plugin', 'activate', $plugin ) );
				}
			}

			if ( empty( $previously_deactivated_plugins ) ) {
				delete_transient( self::TRANSIENT_DEACTIVATED_USER_PLUGINS );
			}
		} else {
			WP_CLI::log( 'The following will be activated:' );
			foreach ( $previously_deactivated_plugins as $plugin ) {
				WP_CLI::log( '- ' . $plugin );
			}

			if ( ! $this->confirm( 'Do you wish to proceed?' ) ) {
				WP_CLI::error( 'Action cancelled.' );
				exit;
			}

			// This prepares to execute the CLI command: wp plugin deactivate plugin1 plugin2 ...
			array_unshift( $previously_deactivated_plugins, 'plugin', 'activate' );

			WP_CLI::run_command( $previously_deactivated_plugins );
			delete_transient( self::TRANSIENT_DEACTIVATED_USER_PLUGINS );
		}

	}
}
/*
 * This works just like plugin verify-checksums except it filters language translation files.
 * Language files are not part of WordPress.org's checksums so they are listed as added and
 * they obfuscate the output. This makes it hard to spot actual checksum verification errors.
 */
class Checksum_Plugin_Command_WPCOMSH extends Checksum_Plugin_Command {
	protected function filter_file( $filepath ) {
		return ! preg_match( '#^(languages/)?[a-z0-9-]+-[a-z]{2}_[A-Z]{2}(_[a-z]+)?([.](mo|po)|-[a-f0-9]{32}[.]json)$#', $filepath );
	}
}

// Keep a record of deactivated plugins so that they can be reactivated.
add_action(
	'deactivated_plugin',
	function( $file ) {
		$previously_deactivated_plugins = get_transient( WPCOMSH_CLI_Commands::TRANSIENT_DEACTIVATED_USER_PLUGINS );
		$previously_deactivated_plugins[] = WP_CLI\Utils\get_plugin_name( $file );
		set_transient( WPCOMSH_CLI_Commands::TRANSIENT_DEACTIVATED_USER_PLUGINS, array_values( array_unique( $previously_deactivated_plugins ) ), DAY_IN_SECONDS );
	}
);

WP_CLI::add_command( 'wpcomsh', 'WPCOMSH_CLI_Commands' );
WP_CLI::add_command( 'wpcomsh plugin verify-checksums', 'Checksum_Plugin_Command_WPCOMSH' );
