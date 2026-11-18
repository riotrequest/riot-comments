<?php
/**
 * Plugin Name: Riot Comments
 * Description: A lightweight, guest-first interface for native WordPress comments with optional disposable-domain screening.
 * Version: 1.2.0
 * Author: riotrequest
 * Author URI: https://github.com/riotrequest
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: riot-comments
 */

defined( 'ABSPATH' ) || exit;

define( 'RIOT_COMMENTS_VERSION', '1.2.0' );
define( 'RIOT_COMMENTS_FILE', __FILE__ );

require __DIR__ . '/screening.php';
require __DIR__ . '/discussion.php';
require __DIR__ . '/review.php';
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
		);
		if ( '1' === riot_comments_input( $_GET, 'initial' ) ) {
			$session['page'] = riot_comments_page( $post_id, riot_comments_order( $_GET ), riot_comments_input( $_GET, 'riot_cursor' ), absint( riot_comments_input( $_GET, 'riot_focus' ) ) );
		}
		wp_send_json_success( $session );
	}
	$order = riot_comments_order( $_GET );
	$page  = riot_comments_page( $post_id, $order, riot_comments_input( $_GET, 'riot_cursor' ), absint( riot_comments_input( $_GET, 'riot_focus' ) ) );
	if ( $private ) {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
		$page['pending'] = riot_comments_pending( $post_id );
	}
	wp_send_json_success( $page );
}

add_action( 'wp_ajax_riot_comments_read', 'riot_comments_api' );
add_action( 'wp_ajax_nopriv_riot_comments_read', 'riot_comments_api' );

function riot_comments_error( $result, $status, $post, $draft, $ajax ) {
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
	echo '<body><main class="riot-discussion riot-retry" data-mode="auto"><h1>' . esc_html__( 'Your comment was not posted', 'riot-comments' ) . '</h1>';
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
		if ( $options['screening'] && '' !== $draft['email'] ) {
			$screen = riot_comments_screen( strtolower( substr( strrchr( $draft['email'], '@' ), 1 ) ) );
		}
		if ( 'disposable' === $screen && 'reject' === $options['action'] ) {
			return new WP_Error( 'riot_disposable', '', 422 );
		}
		return 'disposable' === $screen ? 0 : $status;
	};
	add_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );
	// Some spam plugins terminate in preprocess_comment. Preserve the draft in that path too.
	add_filter( 'wp_die_handler', static function () use ( $fail ) {
		return static function () use ( $fail ) {
			$fail( __( 'The comment could not be submitted. Please review it and try again.', 'riot-comments' ), 403 );
		};
	} );

	$bee_buffer = 0;
	if ( class_exists( 'Antispam_Bee' ) ) {
		ob_start();
		$bee_buffer = ob_get_level();
		// Antispam Bee can discard spam with an immediate HTTP 403 instead of storing a row.
		add_action( 'shutdown', static function () use ( &$bee_buffer, $fail ) {
			if ( $bee_buffer && ob_get_level() === $bee_buffer && 403 === http_response_code() && 'Spam deleted.' === trim( ob_get_contents() ) ) {
				ob_end_clean();
				$bee_buffer = 0;
				$fail( riot_comments_result( 'spam' ), 403 );
			}
		}, 0 );
	}
	$comment = wp_handle_comment_submission( array(
		'comment_post_ID' => $post_id,
		'comment_parent'  => $draft['parent'],
		'author'          => $draft['author'],
		'email'           => $draft['email'],
		'comment'         => $draft['comment'],
	) );
	if ( $bee_buffer && ob_get_level() === $bee_buffer ) {
		ob_end_flush();
	}
	$bee_buffer = 0;
	remove_filter( 'pre_comment_approved', $approval, PHP_INT_MAX );
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
		$fail( $messages[ $comment->get_error_code() ] ?? __( 'The comment could not be submitted. Your draft is still here.', 'riot-comments' ), $code >= 400 && $code <= 599 ? $code : 400 );
	}

	if ( 'off' !== $screen ) {
		add_comment_meta( $comment->comment_ID, '_riot_email_screening', $screen, true );
	}
	$comment = get_comment( $comment->comment_ID );
	$status  = wp_get_comment_status( $comment );
	if ( 'spam' === $status || 'trash' === $status ) {
		$fail( riot_comments_result( 'spam' ), 403 );
	}
	if ( ! in_array( $status, array( 'approved', 'unapproved' ), true ) ) {
		$fail( __( 'Your comment could not be posted. Please review your draft and try again.', 'riot-comments' ), 403 );
	}
	$public = 'approved' === $status;
	if ( ! $public ) {
		add_comment_meta( $comment->comment_ID, '_riot_review_owner', riot_comments_review_owner( true ), true );
	}
	do_action( 'set_comment_cookies', $comment, wp_get_current_user(), $draft['consent'] );
	$result = riot_comments_result( $public ? 'published' : 'pending', ! $public && 'disposable' === $screen ? 'disposable_email' : '' );
	if ( $ajax ) {
		wp_send_json_success( $result + array(
			'id'      => (int) $comment->comment_ID,
			'parent'  => (int) $comment->comment_parent,
			'html'    => riot_comments_item( $comment, array(), $public, ! $public ),
			'count'   => riot_comments_count_label( $post_id ),
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
	wp_safe_redirect( apply_filters( 'comment_post_redirect', $location, $comment ), 303 );
	exit;
}, 20 );
