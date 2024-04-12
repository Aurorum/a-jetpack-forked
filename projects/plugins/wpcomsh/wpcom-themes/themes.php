<?php
/**
 * Registers a filter for the themes API result to add WPCom themes.
 *
 * @package wpcom-themes
 */

/**
 * Loads the WPCom themes service instance.
 *
 * @return WPCom_Themes_Service
 */
function wpcomsh_get_wpcom_themes_service_instance(): WPCom_Themes_Service {
	static $instance;
	if ( ! $instance ) {
		// Load dependencies.
		require_once __DIR__ . '/includes/class-wpcom-themes-mapper.php';
		require_once __DIR__ . '/includes/class-wpcom-themes-cache.php';
		require_once __DIR__ . '/includes/class-wpcom-themes-service.php';
		require_once __DIR__ . '/includes/class-wpcom-themes-api.php';

		// Resolve and Inject dependencies.
		$mapper               = new WPCom_Themes_Mapper();
		$cache                = new WPCom_Themes_Cache();
		$api                  = new WPCom_Themes_Api( $cache );
		$wpcom_themes_service = new WPCom_Themes_Service( $api, $mapper );

		$instance = $wpcom_themes_service;
	}

	return $instance;
}

/**
 * Process the themes API result and add the recommended WPCom themes to the Popular tab.
 *
 * @param mixed  $res    The result object.
 * @param string $action The action.
 * @param mixed  $args   The arguments.
 *
 * @return mixed|stdClass
 */
function wpcomsh_popular_wpcom_themes_api_result( $res, string $action, $args ) {
	// Pre-requisites checks.
	$browse = $args->browse ?? '';
	if (
		'query_themes' !== $action ||
		'popular' !== $browse ||
		! wpcom_is_nav_redesign_enabled() ||
		! get_option( 'wpcom_themes_on_atomic' )
	) {
		return $res;
	}

	$wpcom_themes_service = wpcomsh_get_wpcom_themes_service_instance();

	// Add results to the resulting array.
	return $wpcom_themes_service->filter_themes_api_result_recommended( $res );
}
add_filter( 'themes_api_result', 'wpcomsh_popular_wpcom_themes_api_result', 0, 3 );

/**
 * Process the themes API result and add WPCom themes when using the search.
 *
 * @param mixed  $res    The result object.
 * @param string $action The action.
 * @param mixed  $args   The arguments.
 *
 * @return mixed|stdClass
 */
function wpcomsh_search_wpcom_themes_api_result( $res, string $action, $args ) {
	// Pre-requisites checks.
	$search = $args->search ?? '';
	if (
		'query_themes' !== $action ||
		'' === $search ||
		! wpcom_is_nav_redesign_enabled() ||
		! get_option( 'wpcom_themes_on_atomic' )
	) {
		return $res;
	}

	$wpcom_themes_service = wpcomsh_get_wpcom_themes_service_instance();

	// Add results to the resulting array.
	return $wpcom_themes_service->filter_themes_api_result_search( $res, $search );
}
add_filter( 'themes_api_result', 'wpcomsh_search_wpcom_themes_api_result', 0, 3 );

/**
 * Process the themes API result theme_information for WPCom themes if needed.
 *
 * @param mixed  $res    The result object.
 * @param string $action The action.
 * @param mixed  $args   The arguments.
 *
 * @return mixed|stdClass|WP_Error|null
 */
function wpcomsh_theme_information_wpcom_themes_api_result( $res, string $action, $args ) {
	// Pre-requisites checks.
	if ( 'theme_information' !== $action || ! wpcom_is_nav_redesign_enabled() || ! get_option( 'wpcom_themes_on_atomic' ) ) {
		return $res;
	}

	$wpcom_themes_service = wpcomsh_get_wpcom_themes_service_instance();

	return $wpcom_themes_service->get_theme( $args->slug ) ?? $res;
}
add_filter( 'themes_api_result', 'wpcomsh_theme_information_wpcom_themes_api_result', 0, 3 );
