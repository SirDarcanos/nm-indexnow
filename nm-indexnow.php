<?php
/**
 * Plugin Name: IndexNow Integration
 * 
 * Description: Sends IndexNow pings on publish, update, and trash for posts, pages, and products (WooCommerce).
 * Version:     1.0.0
 * Author:      Nicola Mustone
 * Author URI:  https://buthonestly.io
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: indexnow-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation: pregenerate an API key and store it.
 */
register_activation_hook( __FILE__, 'nm_indexnow_activate' );

/**
 * Bootstrap hooks.
 */
add_action( 'save_post', 'nm_indexnow_notify_on_save', 20, 3 );
add_action( 'trashed_post', 'nm_indexnow_notify_on_trashed', 20, 1 );
add_action( 'admin_init', 'nm_indexnow_register_settings' );
add_action( 'admin_notices', 'nm_indexnow_admin_notices' );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'nm_indexnow_plugin_action_links' );

/**
 * Runs on plugin activation.
 *
 * - Generates a pregenerated key if it does not exist yet.
 * - If the main API key option is empty, it is populated with the pregenerated key.
 */
function nm_indexnow_activate() {
	$pregenerated = get_option( 'nm_indexnow_pregenerated_api_key', '' );
	if ( '' === trim( (string) $pregenerated ) ) {
		$pregenerated = nm_indexnow_generate_api_key();
		add_option( 'nm_indexnow_pregenerated_api_key', $pregenerated );
	}

	$current_key = get_option( 'nm_indexnow_api_key', '' );
	if ( '' === trim( (string) $current_key ) ) {
		update_option( 'nm_indexnow_api_key', $pregenerated );
	}
}

/**
 * Generate an IndexNow-compatible API key.
 *
 * Uses UUIDv4 and strips dashes, resulting in a 32-character hex string.
 *
 * @return string
 */
function nm_indexnow_generate_api_key() {
	$api_key = wp_generate_uuid4();
	$api_key = preg_replace( '/-/', '', $api_key );

	return $api_key;
}

/**
 * Add a "Settings" link to this plugin's row in the Installed Plugins list.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function nm_indexnow_plugin_action_links( $links ) {
	$settings_url = admin_url( 'options-general.php#nm_indexnow_section' );

	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'indexnow-integration' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * On create / update of a published post.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated.
 */
function nm_indexnow_notify_on_save( $post_id, $post, $update ) {
	if ( nm_indexnow_is_localhost() ) {
		return;
	}

	$should_ping = apply_filters( 'nm_indexnow_should_ping_on_save', true, $post_id, $post, $update );
	if ( ! $should_ping ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( $post->post_status !== 'publish' ) {
		return;
	}

	$post_types = apply_filters(
		'nm_indexnow_supported_post_types',
		array( 'post', 'page', 'product' )
	);

	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}

	$urls = nm_indexnow_collect_urls_for_post( $post );

	if ( ! empty( $urls ) ) {
		nm_indexnow_submit_urls( $urls );
	}
}

/**
 * On move to trash for supported post types.
 *
 * @param int $post_id Post ID.
 */
function nm_indexnow_notify_on_trashed( $post_id ) {
	if ( nm_indexnow_is_localhost() ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}

	$should_ping = apply_filters( 'nm_indexnow_should_ping_on_trashed', true, $post_id, $post );
	if ( ! $should_ping ) {
		return;
	}

	$post_types = apply_filters(
		'nm_indexnow_supported_post_types',
		array( 'post', 'page', 'product' )
	);

	if ( ! in_array( $post->post_type, $post_types, true ) ) {
		return;
	}

	$urls = nm_indexnow_collect_urls_for_post( $post );

	if ( ! empty( $urls ) ) {
		nm_indexnow_submit_urls( $urls );
	}
}

/**
 * Build URL list for a post: permalink + related tax archives.
 *
 * Default map:
 *  - post:    category, post_tag
 *  - product: product_cat, product_tag
 *
 * Devs can filter:
 *  - nm_indexnow_taxonomy_map
 *  - nm_indexnow_collect_urls
 *
 * @param WP_Post $post Post object.
 * @return string[]
 */
function nm_indexnow_collect_urls_for_post( $post ) {
	$urls = array();

	$link = get_permalink( $post );
	if ( $link ) {
		$urls[] = $link;
	}

	/**
	 * Map post types to their taxonomy arrays.
	 *
	 * @param array $tax_map {
	 *   @type array $post    Default: category, post_tag.
	 *   @type array $product Default: product_cat, product_tag.
	 * }
	 */
	$tax_map = apply_filters(
		'nm_indexnow_taxonomy_map',
		array(
			'post'    => array(
				'category',
				'post_tag',
			),
			'product' => array(
				'product_cat',
				'product_tag',
			),
		)
	);

	if ( isset( $tax_map[ $post->post_type ] ) ) {
		foreach ( (array) $tax_map[ $post->post_type ] as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term );
					if ( ! is_wp_error( $term_link ) ) {
						$urls[] = $term_link;
					}
				}
			}
		}
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );

	/**
	 * Filter the collected URLs before submission.
	 *
	 * @param string[] $urls URL list.
	 * @param WP_Post  $post Post object.
	 */
	$urls = apply_filters( 'nm_indexnow_collect_urls', $urls, $post );

	return $urls;
}

/**
 * Submit a list of URLs to IndexNow.
 *
 * Dev filters & actions:
 *  - nm_indexnow_endpoint         (filter)
 *  - nm_indexnow_request_args     (filter)
 *  - nm_indexnow_before_submit    (action)
 *  - nm_indexnow_after_submit     (action)
 *  - nm_indexnow_submit_failed    (action)
 *  - nm_indexnow_key_location_url (filter)
 *
 * @param string[] $urls URL list.
 */
function nm_indexnow_submit_urls( array $urls ) {
	$raw_api_key      = get_option( 'nm_indexnow_api_key', '' );
	$raw_key_location = get_option( 'nm_indexnow_key_location', '' );

	$api_key = trim( (string) wp_unslash( $raw_api_key ) );

	// Checkbox: key file present?
	$use_key_file = ! empty( $raw_key_location );

	if ( $api_key === '' ) {
		return;
	}

	$urls = array_values( array_unique( array_filter( $urls ) ) );
	if ( empty( $urls ) ) {
		return;
	}

	$body = array(
		'host'    => $host,
		'key'     => $api_key,
		'urlList' => $urls,
	);

	// Only send keyLocation if checkbox is checked.
	if ( $use_key_file ) {
		$filename     = $api_key . '.txt';
		$key_location = home_url( '/' . $filename );

		/**
		 * Filter the key file URL used as keyLocation.
		 *
		 * @param string $key_location Key file URL.
		 * @param string $api_key      API key.
		 */
		$key_location = apply_filters( 'nm_indexnow_key_location_url', $key_location, $api_key );

		if ( $key_location !== '' ) {
			$body['keyLocation'] = $key_location;
		}
	}

	/**
	 * Filter the endpoint URL.
	 *
	 * @param string $endpoint Endpoint URL.
	 */
	$endpoint = apply_filters( 'nm_indexnow_endpoint', 'https://api.indexnow.org/indexnow' );

	$args = array(
		'method'      => 'POST',
		'timeout'     => 5,
		'blocking'    => false,
		'headers'     => array(
			'Content-Type' => 'application/json; charset=utf-8',
		),
		'body'        => wp_json_encode( $body ),
		'data_format' => 'body',
	);

	/**
	 * Filter the request args before wp_remote_post.
	 *
	 * @param array  $args     Request args.
	 * @param string $endpoint Endpoint URL.
	 * @param array  $body     Request body.
	 */
	$args = apply_filters( 'nm_indexnow_request_args', $args, $endpoint, $body );

	/**
	 * Action before submitting URLs to IndexNow.
	 *
	 * @param string[] $urls URL list.
	 * @param array    $body Request body.
	 */
	do_action( 'nm_indexnow_before_submit', $urls, $body );

	$response = wp_remote_post( $endpoint, $args );

	if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 400 ) {
		// Build a short, safe error message for admin notice.
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
		} else {
			$code    = (int) wp_remote_retrieve_response_code( $response );
			$message = (string) wp_remote_retrieve_response_message( $response );
			$error_message = sprintf(
				/* translators: 1: HTTP status code, 2: HTTP status text. */
				__( 'IndexNow request failed with HTTP status %1$d: %2$s.', 'indexnow-integration' ),
				$code,
				$message
			);
		}

		// Store last error for admin notice (only in admin).
		if ( is_admin() ) {
			update_option(
				'nm_indexnow_last_error',
				array(
					'message' => $error_message,
					'time'    => time(),
				)
			);
		}

		/**
		 * Action on failed submission.
		 *
		 * @param string[]             $urls      URL list.
		 * @param WP_Error|array|mixed $response  Response from wp_remote_post or WP_Error.
		 * @param array                $body      Request body.
		 */
		do_action( 'nm_indexnow_submit_failed', $urls, $response, $body );
		return;
	}

	/**
	 * Action after successful submission.
	 *
	 * @param string[] $urls      URL list.
	 * @param array    $response  Response array.
	 * @param array    $body      Request body.
	 */
	do_action( 'nm_indexnow_after_submit', $urls, $response, $body );
}

/**
 * Show admin notices for IndexNow errors (if any).
 */
function nm_indexnow_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_option( 'nm_indexnow_last_error' );
	if ( empty( $error ) || empty( $error['message'] ) ) {
		return;
	}

	// One-shot: remove after displaying.
	delete_option( 'nm_indexnow_last_error' );

	$message      = (string) $error['message'];
	$settings_url = admin_url( 'options-general.php#nm_indexnow_section' );
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<strong><?php esc_html_e( 'IndexNow Integration', 'indexnow-integration' ); ?>:</strong>
			<?php echo esc_html( $message ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Check your IndexNow settings.', 'indexnow-integration' ); ?></a>
		</p>
	</div>
	<?php
}

/**
 * Register settings and fields in Settings > General.
 */
function nm_indexnow_register_settings() {
	register_setting(
		'general',
		'nm_indexnow_api_key',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		)
	);

	// Now used as a checkbox: '1' when checked, '' when not.
	register_setting(
		'general',
		'nm_indexnow_key_location',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'nm_indexnow_sanitize_checkbox',
			'default'           => '',
		)
	);

	add_settings_section(
		'nm_indexnow_section',
		__( 'IndexNow', 'indexnow-integration' ),
		'nm_indexnow_section_cb',
		'general'
	);

	add_settings_field(
		'nm_indexnow_api_key',
		__( 'IndexNow API Key', 'indexnow-integration' ),
		'nm_indexnow_api_key_field_cb',
		'general',
		'nm_indexnow_section'
	);

	add_settings_field(
		'nm_indexnow_key_location',
		__( 'IndexNow Key File', 'indexnow-integration' ),
		'nm_indexnow_key_location_field_cb',
		'general',
		'nm_indexnow_section'
	);
}

/**
 * Sanitize checkbox value.
 *
 * @param mixed $value Raw value.
 * @return string '1' or ''.
 */
function nm_indexnow_sanitize_checkbox( $value ) {
	return $value ? '1' : '';
}

/**
 * Section description callback.
 */
function nm_indexnow_section_cb() {
	$text = __( 'Configure IndexNow integration for your site.', 'indexnow-integration' );
	?>
	<p>
		<?php echo esc_html( $text ); ?> <a href="https://www.indexnow.org/" title="<?php esc_attr_e( 'IndexNow', 'indexnow-integration' ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php esc_html_e( 'Learn more', 'indexnow-integration' ); ?></a>.
	</p>
	<?php
}

/**
 * API key field callback (text field).
 *
 * If nm_indexnow_api_key === nm_indexnow_pregenerated_api_key, we tell the user
 * they are using the pregenerated key and can replace it if they want.
 */
function nm_indexnow_api_key_field_cb() {
	$value           = (string) wp_unslash( get_option( 'nm_indexnow_api_key', '' ) );
	$pregenerated    = get_option( 'nm_indexnow_pregenerated_api_key', '' );
	$using_generated = ( $pregenerated && $pregenerated === $value );
	?>
	<input type="text" id="nm_indexnow_api_key" name="nm_indexnow_api_key" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" />
	<p class="description">
		<?php
		if ( $using_generated ) {
			esc_html_e( 'You are currently using the API key automatically generated by this plugin. You can keep using it, or replace it with your own IndexNow key.', 'indexnow-integration' );
		} else {
			esc_html_e( 'Your IndexNow API key.', 'indexnow-integration' );
		}
		?> <a href="https://www.bing.com/indexnow/getstarted#implementation" title="<?php esc_attr_e( 'Generate a free IndexNow key', 'indexnow-integration' ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php esc_html_e( 'Get a free key', 'indexnow-integration' ); ?></a>.
	</p>
	<?php
}

/**
 * Key file checkbox field callback.
 *
 * If checked, we:
 *  - Assume the key file is at https://example.com/{API_KEY}.txt
 *  - Check if the file exists and is readable under ABSPATH
 *  - Show normal text if OK or a red message if missing
 */
function nm_indexnow_key_location_field_cb() {
	$value   = get_option( 'nm_indexnow_key_location', '' );
	$checked = $value === '1' ? true : false;

	$raw_key = get_option( 'nm_indexnow_api_key', '' );
	$api_key = trim( (string) wp_unslash( $raw_key ) );
	?>
	<label for="nm_indexnow_key_location">
		<input type="checkbox" id="nm_indexnow_key_location" name="nm_indexnow_key_location" value="1" <?php checked( $checked ); ?> /> <?php esc_html_e( 'My IndexNow key file is uploaded in the root folder of my site.', 'indexnow-integration' ); ?>
	</label>
	<p class="description">
		<?php esc_html_e( '(Optional) If checked, your key file URL will be sent to IndexNow as part of each request.', 'indexnow-integration' ); ?>
	</p>
	<?php

	if ( $checked ) {
		// Only try to check file if we have a key.
		if ( $api_key === '' ) {
			?>
			<p class="description" style="color:#b32d2e;">
				<?php esc_html_e( 'Key file is enabled, but no API key is set. Save your API key first.', 'indexnow-integration' ); ?>
			</p>
			<?php
		} else {
			$filename  = $api_key . '.txt';
			$file_path = trailingslashit( ABSPATH ) . $filename;
			$file_url  = home_url( '/' . $filename );

			$file_exists = file_exists( $file_path ) && is_readable( $file_path );

			if ( $file_exists ) {
				?>
				<p class="description" style="color:#00a32a;">
					<?php
					printf(
						/* translators: %s: key file URL */
						esc_html__( 'Key file detected at %s.', 'indexnow-integration' ),
						esc_url( $file_url )
					);
					?>
				</p>
				<?php
			} else {
				?>
				<p class="description" style="color:#b32d2e;">
					<?php
					printf(
						/* translators: %s: key file URL */
						esc_html__( 'Key file not found or not readable at %s. Make sure the file exists and contains only your API key.', 'indexnow-integration' ),
						esc_url( $file_url )
					);
					?>
				</p>
				<?php
			}
		}
	}
}

/**
 * Check if the site is running on localhost.
 *
 * @return bool True if the site URL points to localhost, false otherwise.
 */
function nm_indexnow_is_localhost() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $host ) {
		return false;
	}

	$host = strtolower( $host );

	// Classic localhost values.
	if ( $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' ) {
		return true;
	}

	return false;
}
