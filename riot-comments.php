<?php
/**
 * Plugin Name: Riot Comments
 * Plugin URI: https://github.com/riotrequest/riot-comments
 * Description: A lightweight, guest-first interface for native WordPress comments with optional disposable-domain screening.
 * Version: 1.3.5
 * Update URI: https://github.com/riotrequest/riot-comments
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: riotrequest
 * Author URI: https://github.com/riotrequest
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: riot-comments
 */

defined( 'ABSPATH' ) || exit;

define( 'RIOT_COMMENTS_VERSION', '1.3.5' );
define( 'RIOT_COMMENTS_FILE', __FILE__ );

function riot_comments_release_update( $update, $plugin_data, $plugin_file ) {
	$slug = 'riot-comments';
	$repo = "riotrequest/$slug";
	$repository = "https://github.com/$repo";
	// The ZIP must preserve the installed plugin directory.
	if ( $update || "$slug/$slug.php" !== $plugin_file || $repository !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
		return $update;
	}

	$response = wp_safe_remote_get( "https://api.github.com/repos/$repo/releases/latest", array(
		'timeout'     => 5,
		'redirection' => 0,
		'user-agent'  => $slug,
		'headers'     => array( 'Accept' => 'application/vnd.github+json' ),
	) );
	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $update;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	$tag = $release['tag_name'] ?? '';
	if ( ! is_string( $tag ) || ! preg_match( '/^v([0-9]+\.[0-9]+\.[0-9]+)$/D', $tag, $version )
		|| ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
		return $update;
	}

	$filename = "$slug.zip";
	$package = "$repository/releases/download/$tag/$filename";
	foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
		if ( $filename === ( $asset['name'] ?? '' ) && 'uploaded' === ( $asset['state'] ?? '' )
			&& $package === ( $asset['browser_download_url'] ?? '' ) ) {
			return array(
				'slug'    => $slug,
				'version' => $version[1],
				'url'     => "$repository/releases/tag/$tag",
				'package' => $package,
			);
		}
	}
	return $update;
}
add_filter( 'update_plugins_github.com', 'riot_comments_release_update', 10, 3 );

require __DIR__ . '/screening.php';
require __DIR__ . '/discussion.php';
require __DIR__ . '/review.php';
require __DIR__ . '/likes.php';
require __DIR__ . '/incidents.php';
if ( is_admin() ) {
	require __DIR__ . '/settings.php';
}

function riot_comments_options() {
	return wp_parse_args( get_option( 'riot_comments_settings', array() ), array(
		'enabled'   => 1,
		'screening' => 1,
		'action'    => 'hold',
		'accent'    => '',
		'font'      => 'inherit',
		'mode'      => 'auto',
		'shape'     => 'square',
		'eyebrow'   => __( 'Sound off', 'riot-comments' ),
		'jump'      => 1,
	) );
}

function riot_comments_input( $source, $key ) {
	return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? wp_unslash( $source[ $key ] ) : '';
}

function riot_comments_readable( $post_id ) {
	if ( ! $post_id ) {
		return false;
	}
	$status = get_post_status_object( get_post_status( $post_id ) ?: '' );
	return $status && ( $status->public || ( $status->private && current_user_can( 'read_post', $post_id ) ) )
		&& ! post_password_required( $post_id );
}

function riot_comments_assets() {
	wp_enqueue_style( 'riot-comments', plugins_url( 'comments.css', __FILE__ ), array(), RIOT_COMMENTS_VERSION );
	wp_enqueue_script( 'riot-comments', plugins_url( 'comments.js', __FILE__ ), array(), RIOT_COMMENTS_VERSION, true );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_singular() && riot_comments_options()['enabled'] && ( comments_open() || get_comments_number() ) ) {
		riot_comments_assets();
	}
} );

add_filter( 'comments_template', function ( $template ) {
	return is_singular() && riot_comments_options()['enabled'] ? __DIR__ . '/template.php' : $template;
}, 99 );

add_filter( 'pre_render_block', function ( $html, $block, $parent ) {
	$types = array( 'core/comments', 'core/post-comments', 'core/comments-query-loop', 'core/post-comments-form' );
	if ( null !== $html || ! in_array( $block['blockName'], $types, true ) || ! is_singular() || ! riot_comments_options()['enabled'] ) {
		return $html;
	}
	$post_id = get_queried_object_id();
	if ( isset( $parent->context['postId'] ) && (int) $parent->context['postId'] !== $post_id ) {
		return $html;
	}
	return riot_comments_discussion( $post_id );
}, 10, 3 );

add_shortcode( 'riot_comments', function () {
	return is_singular() && riot_comments_options()['enabled'] ? riot_comments_discussion( get_queried_object_id() ) : '';
} );

function riot_comments_token( $post_id ) {
	$time = time();
	return $time . ':' . wp_create_nonce( 'riot-comment:' . $post_id . ':' . $time );
}

function riot_comments_same_origin() {
	$source = riot_comments_input( $_SERVER, 'HTTP_ORIGIN' ) ?: riot_comments_input( $_SERVER, 'HTTP_REFERER' );
	$expected = wp_parse_url( site_url( '/' ) );
	$actual   = wp_parse_url( $source );
	if ( ! isset( $actual['scheme'], $actual['host'], $expected['scheme'], $expected['host'] ) ) {
		return false;
	}
	$port = static function ( $url ) {
		return $url['port'] ?? ( 'https' === $url['scheme'] ? 443 : 80 );
	};
	return $actual['scheme'] === $expected['scheme'] && strtolower( $actual['host'] ) === strtolower( $expected['host'] )
		&& $port( $actual ) === $port( $expected );
}

function riot_comments_api() {
	$post_id = absint( riot_comments_input( $_GET, 'post' ) );
	if ( ! riot_comments_options()['enabled'] || ! riot_comments_readable( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'This discussion is unavailable.', 'riot-comments' ) ), 404 );
	}
	$private = '1' === riot_comments_input( $_SERVER, 'HTTP_X_RIOT_COMMENTS' ) && riot_comments_same_origin();
	if ( 'session' === riot_comments_input( $_GET, 'view' ) ) {
		if ( ! $private ) {
			wp_send_json_error( array( 'message' => __( 'This form session is unavailable.', 'riot-comments' ) ), 403 );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
		$person = is_user_logged_in() ? array() : wp_get_current_commenter();
		$session = array(
			'token'   => riot_comments_token( $post_id ),
			'name'    => $person['comment_author'] ?? '',
			'email'   => $person['comment_author_email'] ?? '',
			'pending' => riot_comments_pending( $post_id ),
			'count'   => riot_comments_count_label( $post_id ),
			'total'   => (int) get_comments_number( $post_id ),
		);
		if ( '1' === riot_comments_input( $_GET, 'initial' ) ) {
			$session['page'] = riot_comments_page( $post_id, riot_comments_order( $_GET ), riot_comments_input( $_GET, 'riot_cursor' ), absint( riot_comments_input( $_GET, 'riot_focus' ) ), true );
		}
		wp_send_json_success( $session );
	}
	if ( 'receipt' === riot_comments_input( $_GET, 'view' ) ) {
		if ( ! $private ) {
			wp_send_json_error( array( 'message' => __( 'This receipt is unavailable.', 'riot-comments' ) ), 403 );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
		wp_send_json_success( array(
			'items' => riot_comments_receipt( $post_id, riot_comments_input( $_GET, 'ids' ) ),
			'count' => riot_comments_count_label( $post_id ),
			'total' => (int) get_comments_number( $post_id ),
			'live'  => riot_comments_result( 'published' ),
			'closed' => riot_comments_result( 'spam' ),
		) );
	}
	$order = riot_comments_order( $_GET );
	$page  = riot_comments_page( $post_id, $order, riot_comments_input( $_GET, 'riot_cursor' ), absint( riot_comments_input( $_GET, 'riot_focus' ) ), $private );
	if ( $private ) {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
		$page['pending'] = riot_comments_pending( $post_id );
	}
	wp_send_json_success( $page );
}

add_action( 'wp_ajax_riot_comments_read', 'riot_comments_api' );
add_action( 'wp_ajax_nopriv_riot_comments_read', 'riot_comments_api' );

/**
 * Submission guard state. Once a submission starts, every exit path must end in a
 * deliberate Riot response. Anything else (a plugin calling exit, wp_die, a fatal
 * error, a redirect) is logged and replaced with a structured error.
 */
function &riot_comments_guard() {
	static $guard = array( 'active' => false, 'done' => false, 'level' => 0, 'ajax' => false, 'stage' => '', 'started' => 0.0 );
	return $guard;
}

function riot_comments_guard_stage( $stage ) {
	$guard          = &riot_comments_guard();
	$guard['stage'] = $stage;
}

function riot_comments_guard_done() {
	$guard         = &riot_comments_guard();
	$guard['done'] = true;
}

function riot_comments_guard_output() {
	$guard  = &riot_comments_guard();
	$output = '';
	while ( $guard['level'] && ob_get_level() >= $guard['level'] ) {
		$output = (string) ob_get_clean() . $output;
	}
	return $output;
}

function riot_comments_error( $result, $status, $post, $draft, $ajax ) {
	riot_comments_guard_done();
	if ( $ajax ) {
		wp_send_json_error( $result, $status );
	}
	status_header( $status );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	echo '<!doctype html><html ';
	language_attributes();
	echo '><head><meta charset="' . esc_attr( get_option( 'blog_charset' ) ) . '"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
	echo '<title>' . esc_html__( 'Comment submission', 'riot-comments' ) . '</title>';
	echo '<link rel="stylesheet" href="' . esc_url( add_query_arg( 'ver', RIOT_COMMENTS_VERSION, plugins_url( 'comments.css', __FILE__ ) ) ) . '"></head>';
	echo '<body><main class="riot-discussion riot-retry" data-mode="auto" data-shape="' . esc_attr( riot_comments_options()['shape'] ) . '"><h1>' . esc_html__( 'Your comment was not posted', 'riot-comments' ) . '</h1>';
	riot_comments_feedback( $result, 'riot-receipt' );
	if ( $post && riot_comments_readable( $post->ID ) ) {
		if ( comments_open( $post->ID ) ) {
			// Antispam Bee may perform its native textarea rewrite via template buffering.
			$level = ob_get_level();
			if ( is_callable( array( 'Antispam_Bee', 'prepare_comment_field_output_buffering' ) )
				&& ! has_filter( 'comment_form_field_comment', array( 'Antispam_Bee', 'prepare_comment_field' ) ) ) {
				Antispam_Bee::prepare_comment_field_output_buffering();
			}
			riot_comments_form( (int) $post->ID, $draft );
			if ( ob_get_level() > $level ) {
				ob_end_flush();
			}
		} else {
			echo '<p><label for="riot-recovered">' . esc_html__( 'Your draft', 'riot-comments' ) . '</label>';
			echo '<textarea id="riot-recovered" readonly>' . esc_textarea( $draft['comment'] ) . '</textarea></p>';
		}
		echo '<p><a href="' . esc_url( get_permalink( $post ) . '#comments' ) . '">' . esc_html__( 'Back to the discussion', 'riot-comments' ) . '</a></p>';
	}
	echo '</main></body></html>';
	exit;
}

function riot_comments_retry_result( $http = 0 ) {
	return array(
		'status' => 'failed',
		'reason' => 'server',
		'retry'  => true,
		'http'   => (int) $http,
		'title'  => __( 'Not posted yet.', 'riot-comments' ),
		'detail' => __( 'The site hit a snag while saving your comment. Your draft is safe. Please try again.', 'riot-comments' ),
		'message' => __( 'The site hit a snag while saving your comment. Your draft is safe. Please try again.', 'riot-comments' ),
	);
}

/**
 * Finish a stored submission. Also used to replay a submission whose first
 * response never reached the browser.
 */
function riot_comments_finish( $comment, $post_id, $consent, $ajax, $fail ) {
	$comment = get_comment( $comment->comment_ID );
	$status  = $comment ? wp_get_comment_status( $comment ) : '';
	if ( 'spam' === $status || 'trash' === $status ) {
		$fail( riot_comments_result( 'spam' ), 403 );
	}
	if ( ! in_array( $status, array( 'approved', 'unapproved' ), true ) ) {
		$fail( __( 'Your comment could not be posted. Please review your draft and try again.', 'riot-comments' ), 403 );
	}
	riot_comments_guard_stage( 'respond' );
	$public = 'approved' === $status;
	if ( ! $public ) {
		// Re-issuing keeps the private preview working when an earlier response was lost.
		update_comment_meta( $comment->comment_ID, '_riot_review_owner', riot_comments_review_owner( true ) );
	}
	do_action( 'set_comment_cookies', $comment, wp_get_current_user(), $consent );
	$screen = get_comment_meta( $comment->comment_ID, '_riot_email_screening', true );
	$result = riot_comments_result( $public ? 'published' : 'pending', ! $public && 'disposable' === $screen ? 'disposable_email' : '' );
	if ( $ajax ) {
		riot_comments_guard_done();
		wp_send_json_success( $result + array(
			'id'     => (int) $comment->comment_ID,
			'parent' => (int) $comment->comment_parent,
			'html'   => riot_comments_item( $comment, array(), $public, ! $public, $public ? riot_comments_like_states( array( (int) $comment->comment_ID ) ) : null ),
			'count'  => riot_comments_count_label( $post_id ),
			'total'  => (int) get_comments_number( $post_id ),
		) );
	}

	$query = array( 'riot_posted' => $public ? 'yes' : 'held' );
	if ( $public ) {
		$query['riot_focus'] = $comment->comment_ID;
	} else {
		$query['riot_receipt'] = $comment->comment_ID;
		if ( $comment->comment_parent ) {
			$query['riot_focus'] = $comment->comment_parent;
		}
	}
	$location = add_query_arg( $query, get_permalink( $post_id ) ) . ( $public ? '#comment-' . $comment->comment_ID : '#comments' );
	riot_comments_guard_done();
	wp_safe_redirect( apply_filters( 'comment_post_redirect', $location, $comment ), 303 );
	exit;
}

add_action( 'wp_loaded', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'wp-comments-post.php' !== basename( $_SERVER['SCRIPT_NAME'] ?? '' )
		|| '1' !== riot_comments_input( $_POST, 'riot_submit' ) ) {
		return;
	}

	nocache_headers();
	$options = riot_comments_options();
	$ajax    = '1' === riot_comments_input( $_POST, 'riot_async' ) || '1' === riot_comments_input( $_SERVER, 'HTTP_X_RIOT_COMMENTS' );
	$post_id = absint( riot_comments_input( $_POST, 'comment_post_ID' ) );
	$post    = get_post( $post_id );
	$draft   = array(
		'comment' => riot_comments_input( $_POST, 'comment' ),
		'author'  => riot_comments_input( $_POST, 'author' ),
		'email'   => trim( riot_comments_input( $_POST, 'email' ) ),
		'parent'  => absint( riot_comments_input( $_POST, 'comment_parent' ) ),
		'consent' => isset( $_POST['wp-comment-cookies-consent'] ),
	);
	$fail = static function ( $message, $status = 400 ) use ( $post, &$draft, $ajax ) {
		$result = is_array( $message ) ? $message : array( 'status' => 'failed', 'message' => $message, 'detail' => $message );
		riot_comments_error( $result, $status, $post, $draft, $ajax );
	};

	// Everything from here on is buffered so a stray exit can still be answered properly.
	$guard            = &riot_comments_guard();
	$guard['active']  = true;
	$guard['ajax']    = $ajax;
	$guard['started'] = microtime( true );
	$guard['stage']   = 'validate';
	ob_start();
	$guard['level'] = ob_get_level();

	add_action( 'shutdown', static function () use ( $fail, $post_id ) {
		$guard = &riot_comments_guard();
		if ( ! $guard['active'] || $guard['done'] ) {
			return;
		}
		$output = riot_comments_guard_output();
		$http   = http_response_code();
		$error  = error_get_last();
		// Antispam Bee can discard spam with an immediate HTTP 403 instead of storing a row.
		if ( 403 === $http && 'Spam deleted.' === trim( $output ) ) {
			$fail( riot_comments_result( 'spam' ), 403 );
		}
		riot_comments_incident( 'unexpected_exit', array(
			'stage'  => $guard['stage'],
			'http'   => $http,
			'post'   => $post_id,
			'output' => $output,
			'error'  => $error,
			'ms'     => (int) round( ( microtime( true ) - $guard['started'] ) * 1000 ),
		) );
		if ( headers_sent() ) {
			return;
		}
		header_remove( 'Location' );
		$fail( riot_comments_retry_result( $http ), 500 );
	}, 0 );

	// Plugins (and WordPress's own fatal-error screen) stop requests through wp_die.
	$die = static function () use ( $fail, $post_id ) {
		return static function ( $message = '', $title = '', $args = array() ) use ( $fail, $post_id ) {
			$guard = &riot_comments_guard();
			$args  = is_array( $args ) ? $args : array();
			$code  = (int) ( $args['response'] ?? 500 );
			$text  = is_wp_error( $message ) ? $message->get_error_message() : ( is_scalar( $message ) ? (string) $message : '' );
			riot_comments_incident( 'wp_die', array(
				'stage'  => $guard['stage'],
				'http'   => $code,
				'post'   => $post_id,
				'output' => $text,
				'error'  => error_get_last(),
				'ms'     => (int) round( ( microtime( true ) - $guard['started'] ) * 1000 ),
			) );
			riot_comments_guard_output();
			if ( $code >= 500 ) {
				$fail( riot_comments_retry_result( $code ), $code > 599 ? 500 : $code );
			}
			$fail( __( 'The comment could not be submitted. Please review it and try again.', 'riot-comments' ), $code >= 400 && $code <= 499 ? $code : 403 );
		};
	};
	foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $hook ) {
		add_filter( $hook, $die, PHP_INT_MAX );
	}

	if ( ! $options['enabled'] || ! $post_id || ! riot_comments_readable( $post_id ) || ! comments_open( $post_id ) ) {
		$fail( __( 'This discussion is closed or unavailable.', 'riot-comments' ), 403 );
	}
	if ( ! riot_comments_same_origin() ) {
		$fail( __( 'Please submit your comment from this website.', 'riot-comments' ), 403 );
	}

	$token = explode( ':', riot_comments_input( $_POST, 'riot_token' ), 2 );
	$time = (int) $token[0];
	if ( ! isset( $token[1] ) || ! wp_verify_nonce( $token[1], 'riot-comment:' . $post_id . ':' . $time ) ) {
		$fail( __( 'This form expired. Your draft is intact. Please try again.', 'riot-comments' ), 419 );
	}
	if ( '' !== riot_comments_input( $_POST, 'riot_contact_url' ) ) {
		$fail( riot_comments_result( 'spam' ), 400 );
	}

	// A random per-draft key lets the browser retry safely: a repeat returns the stored comment.
	$attempt     = riot_comments_input( $_POST, 'riot_attempt' );
	$attempt_key = preg_match( '/\A[A-Za-z0-9-]{16,64}\z/', $attempt ) ? 'riot_attempt_' . md5( wp_hash( $attempt . '|' . $post_id ) ) : '';
	if ( $attempt_key ) {
		$previous = get_transient( $attempt_key );
		$previous = $previous ? get_comment( (int) $previous ) : null;
		if ( $previous && (int) $previous->comment_post_ID === $post_id ) {
			riot_comments_finish( $previous, $post_id, $draft['consent'], $ajax, $fail );
		}
	}

	$parent = $draft['parent'] ? get_comment( $draft['parent'] ) : null;
	if ( $draft['parent'] && ( ! $parent || (int) $parent->comment_post_ID !== $post_id || '1' !== $parent->comment_approved ) ) {
		$draft['parent'] = 0;
		$fail( __( 'That reply target is unavailable. You can post your draft as a new comment.', 'riot-comments' ) );
	}
	if ( ! is_user_logged_in() ) {
		if ( get_option( 'comment_registration' ) ) {
			$fail( __( 'Please sign in to comment on this site.', 'riot-comments' ), 403 );
		}
		if ( get_option( 'require_name_email' ) && ( '' === trim( wp_strip_all_tags( $draft['author'] ) ) || '' === $draft['email'] ) ) {
			$fail( __( 'Enter your name and email address.', 'riot-comments' ) );
		}
		if ( '' !== $draft['email'] && ! is_email( $draft['email'] ) ) {
			$fail( __( 'Enter a valid email address.', 'riot-comments' ) );
		}
	}
	if ( '' === trim( $draft['comment'] ) || strlen( $draft['comment'] ) > 65525 || strlen( $draft['author'] ) > 245 || strlen( $draft['email'] ) > 100 ) {
		$fail( __( 'Enter a comment and check the length of your name, email, and message.', 'riot-comments' ) );
	}

	if ( ! is_user_logged_in() ) {
		$ip = riot_comments_input( $_SERVER, 'REMOTE_ADDR' );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$key = 'riot_rate_' . wp_hash( $ip . '|' . floor( time() / 60 ) );
			$attempts = (int) get_transient( $key );
			if ( $attempts >= 12 ) {
				$fail( __( 'Too many attempts. Wait a minute and try again.', 'riot-comments' ), 429 );
			}
			// WordPress flood and duplicate checks also run before insertion.
			set_transient( $key, $attempts + ( $attempts >= 6 && time() === $time ? 3 : 1 ), 60 );
		}
	}

	$screen = 'off';
	// Run after WordPress flood and duplicate checks, inside its approval workflow.
	$approval = static function ( $status ) use ( $options, $draft, &$screen ) {
		if ( is_wp_error( $status ) || is_user_logged_in() || 'spam' === $status || 'trash' === $status ) {
			return $status;
		}
		riot_comments_guard_stage( 'screening' );
		if ( $options['screening'] && '' !== $draft['email'] ) {
			$screen = riot_comments_screen( strtolower( substr( strrchr( $draft['email'], '@' ), 1 ) ) );
		}
		riot_comments_guard_stage( 'insert' );
		if ( 'disposable' === $screen && 'reject' === $options['action'] ) {
			return new WP_Error( 'riot_disposable', '', 422 );
		}
		return 'disposable' === $screen ? 0 : $status;
	};
	add_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );

	// Record everything the moment the row exists, before any notification work can fail.
	$stored = static function ( $comment_id, $approved ) use ( $attempt_key, &$screen ) {
		riot_comments_guard_stage( 'after_insert' );
		if ( $attempt_key ) {
			set_transient( $attempt_key, (int) $comment_id, 15 * MINUTE_IN_SECONDS );
		}
		if ( 'off' !== $screen ) {
			add_comment_meta( $comment_id, '_riot_email_screening', $screen, true );
		}
		if ( 0 === $approved || '0' === $approved ) {
			add_comment_meta( $comment_id, '_riot_review_owner', riot_comments_review_owner( true ), true );
		}
	};
	add_action( 'comment_post', $stored, 0, 2 );

	riot_comments_guard_stage( 'spam_checks' );
	try {
		$comment = wp_handle_comment_submission( array(
			'comment_post_ID' => $post_id,
			'comment_parent'  => $draft['parent'],
			'author'          => $draft['author'],
			'email'           => $draft['email'],
			'comment'         => $draft['comment'],
		) );
	} catch ( Throwable $error ) {
		$guard = &riot_comments_guard();
		riot_comments_incident( 'exception', array(
			'stage' => $guard['stage'],
			'http'  => 500,
			'post'  => $post_id,
			'error' => array( 'message' => get_class( $error ) . ': ' . $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine() ),
			'ms'    => (int) round( ( microtime( true ) - $guard['started'] ) * 1000 ),
		) );
		$fail( riot_comments_retry_result( 500 ), 500 );
	}
	remove_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );
	remove_action( 'comment_post', $stored, 0 );

	if ( is_wp_error( $comment ) ) {
		if ( 'riot_disposable' === $comment->get_error_code() ) {
			$fail( riot_comments_result( 'rejected', 'disposable_email' ), 422 );
		}
		$code = (int) $comment->get_error_data();
		// Only use reader-facing text from known WordPress errors.
		$messages = array(
			'comment_duplicate' => __( 'You already posted this comment.', 'riot-comments' ),
			'comment_flood'     => __( 'You are posting too quickly. Wait a moment and try again.', 'riot-comments' ),
		);
		if ( ! isset( $messages[ $comment->get_error_code() ] ) ) {
			$guard = &riot_comments_guard();
			riot_comments_incident( 'wp_error', array(
				'stage'  => $guard['stage'],
				'http'   => $code,
				'post'   => $post_id,
				'output' => $comment->get_error_code() . ': ' . $comment->get_error_message(),
			) );
		}
		if ( $code >= 500 ) {
			$fail( riot_comments_retry_result( $code ), $code > 599 ? 500 : $code );
		}
		$fail( $messages[ $comment->get_error_code() ] ?? __( 'The comment could not be submitted. Your draft is still here.', 'riot-comments' ), $code >= 400 && $code <= 499 ? $code : 400 );
	}

	riot_comments_finish( $comment, $post_id, $draft['consent'], $ajax, $fail );
}, 20 );
