<?php
defined( 'ABSPATH' ) || exit;

function riot_comments_review_owner( $create = false ) {
	if ( is_user_logged_in() ) {
		return 'user:' . get_current_user_id();
	}

	static $issued = '';
	$cookie = 'riot_comment_review_' . COOKIEHASH;
	$key    = $issued ?: riot_comments_input( $_COOKIE, $cookie );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $key ) ) {
		if ( ! $create ) {
			return '';
		}
		$key = bin2hex( random_bytes( 32 ) );
	}
	if ( $create && $issued !== $key && ! headers_sent() ) {
		$issued = $key;
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

/**
 * Current status of this browser's own held comments, so a preview can turn into the
 * published comment once a moderator (or the AI moderator) approves it. Comments that
 * belong to someone else are reported as gone, which reveals nothing.
 */
function riot_comments_receipt( $post_id, $ids ) {
	$owner = riot_comments_review_owner();
	$ids   = array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $ids ) ) ) ), 0, 10 );
	$open  = comments_open( $post_id ) && ( is_user_logged_in() || ! get_option( 'comment_registration' ) );
	$items = array();
	$approved = array();
	foreach ( $ids as $id ) {
		$comment = $owner ? get_comment( $id ) : null;
		if ( ! $comment || (int) $comment->comment_post_ID !== $post_id || get_comment_meta( $id, '_riot_review_owner', true ) !== $owner ) {
			$items[] = array( 'id' => $id, 'status' => 'gone' );
			continue;
		}
		$status = wp_get_comment_status( $comment );
		if ( 'approved' === $status ) {
			$approved[ $id ] = $comment;
			$items[] = array(
				'id'     => $id,
				'status' => 'approved',
				'parent' => (int) $comment->comment_parent,
			);
		} else {
			$items[] = array( 'id' => $id, 'status' => 'unapproved' === $status ? 'pending' : 'gone' );
		}
	}
	$likes = riot_comments_like_states( array_keys( $approved ) );
	foreach ( $items as &$item ) {
		if ( isset( $approved[ $item['id'] ] ) ) {
			$item['html'] = riot_comments_item( $approved[ $item['id'] ], array(), $open, false, $likes );
		}
	}
	unset( $item );
	return $items;
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
