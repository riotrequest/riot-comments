<?php
defined( 'ABSPATH' ) || exit;

function riot_comments_review_owner( $create = false ) {
	if ( is_user_logged_in() ) {
		return 'user:' . get_current_user_id();
	}

	$cookie = 'riot_comment_review_' . COOKIEHASH;
	$key    = riot_comments_input( $_COOKIE, $cookie );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $key ) ) {
		if ( ! $create ) {
			return '';
		}
		$key = bin2hex( random_bytes( 32 ) );
	}
	if ( $create ) {
		setcookie( $cookie, $key, array(
			'expires'  => time() + WEEK_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl() || 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME ),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
	}
	return 'browser:' . wp_hash( $key );
}

function riot_comments_private_item( $comment ) {
	return array(
		'id'     => (int) $comment->comment_ID,
		'parent' => (int) $comment->comment_parent,
		'html'   => riot_comments_item( $comment, array(), false, true ),
	);
}

function riot_comments_pending( $post_id ) {
	$owner = riot_comments_review_owner();
	if ( ! $owner ) {
		return array();
	}
	$comments = get_comments( array(
		'post_id'    => $post_id,
		'status'     => 'hold',
		'meta_key'   => '_riot_review_owner',
		'meta_value' => $owner,
		'orderby'    => 'comment_date_gmt',
		'order'      => 'ASC',
	) );
	return array_map( 'riot_comments_private_item', $comments );
}

// Only the explicit non-JavaScript receipt can contain private previews in HTML.
add_action( 'template_redirect', function () {
	if ( is_singular() && in_array( riot_comments_input( $_GET, 'riot_posted' ), array( 'held', 'yes' ), true ) ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Riot Comments private receipt' );
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0', true );
	}
}, 0 );
