<?php
defined( 'ABSPATH' ) || exit;

function riot_comments_likes_ready() {
	global $wpdb;
	if ( '1' === (string) get_option( 'riot_comments_likes_schema' ) ) {
		return true;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$votes   = $wpdb->prefix . 'riot_comment_votes';
	$totals  = $wpdb->prefix . 'riot_comment_like_totals';
	$collate = $wpdb->get_charset_collate();
	dbDelta( array(
		"CREATE TABLE {$votes} (
			comment_id bigint(20) unsigned NOT NULL,
			voter binary(32) NOT NULL,
			PRIMARY KEY  (comment_id,voter)
		) ENGINE=InnoDB {$collate};",
		"CREATE TABLE {$totals} (
			comment_id bigint(20) unsigned NOT NULL,
			total bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (comment_id)
		) ENGINE=InnoDB {$collate};",
	) );
	$engines = $wpdb->get_col( $wpdb->prepare(
		'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
		$votes,
		$totals
	) );
	if ( array( 'InnoDB', 'InnoDB' ) !== $engines ) {
		return false;
	}
	update_option( 'riot_comments_likes_schema', '1', true );
	return true;
}

register_activation_hook( RIOT_COMMENTS_FILE, 'riot_comments_likes_ready' );

function riot_comments_like_voter( $create = false ) {
	$id = get_current_user_id();
	if ( $id ) {
		return hash_hmac( 'sha256', 'user:' . $id, wp_salt( 'auth' ) );
	}
	$cookie = 'riot_comment_likes_' . COOKIEHASH;
	$token = riot_comments_input( $_COOKIE, $cookie );
	if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) {
		if ( ! $create || headers_sent() ) {
			return '';
		}
		$token = bin2hex( random_bytes( 32 ) );
		if ( ! setcookie( $cookie, $token, array(
			'expires'  => time() + YEAR_IN_SECONDS,
			'path'     => '/',
			'secure'   => is_ssl() || 'https' === wp_parse_url( site_url(), PHP_URL_SCHEME ),
			'httponly' => true,
			'samesite' => 'Lax',
		) ) ) {
			return '';
		}
	}
	return hash_hmac( 'sha256', 'guest:' . $token, wp_salt( 'auth' ) );
}

// A null result keeps the controls hidden when storage is unavailable.
function riot_comments_like_states( $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	if ( ! riot_comments_likes_ready() ) {
		return null;
	}
	$votes = $wpdb->prefix . 'riot_comment_votes';
	$totals = $wpdb->prefix . 'riot_comment_like_totals';
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT t.comment_id, t.total, v.voter IS NOT NULL AS liked FROM {$totals} t
		LEFT JOIN {$votes} v ON v.comment_id = t.comment_id AND v.voter = UNHEX(%s)
		WHERE t.comment_id IN ({$placeholders})",
		array_merge( array( riot_comments_like_voter() ), $ids )
	), OBJECT_K );
	return $wpdb->last_error ? null : $rows;
}

/**
 * Lock the comment through both writes so a simultaneous moderation or deletion
 * cannot slip in between eligibility and recording the vote.
 */
function riot_comments_set_like( $id, $post_id, $voter, $liked ) {
	global $wpdb;
	$votes = $wpdb->prefix . 'riot_comment_votes';
	$totals = $wpdb->prefix . 'riot_comment_like_totals';
	$committed = false;
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return false;
	}
	try {
		$comment = $wpdb->get_row( $wpdb->prepare(
			"SELECT comment_post_ID, comment_approved, comment_type FROM {$wpdb->comments} WHERE comment_ID = %d FOR UPDATE",
			$id
		) );
		if ( $wpdb->last_error ) {
			return false;
		}
		if ( ! $comment || (int) $comment->comment_post_ID !== $post_id || '1' !== $comment->comment_approved ||
			! in_array( $comment->comment_type, array( '', 'comment' ), true ) ) {
			return null;
		}
		if ( $liked ) {
			$changed = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$votes} (comment_id, voter) VALUES (%d, UNHEX(%s))", $id, $voter
			) );
		} else {
			$changed = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$votes} WHERE comment_id = %d AND voter = UNHEX(%s)", $id, $voter
			) );
		}
		if ( false === $changed ) {
			return false;
		}
		if ( $changed ) {
			$updated = $liked
				? $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$totals} (comment_id, total) VALUES (%d, 1) ON DUPLICATE KEY UPDATE total = total + 1", $id
				) )
				: $wpdb->query( $wpdb->prepare( "UPDATE {$totals} SET total = total - 1 WHERE comment_id = %d AND total > 0", $id ) );
			if ( ! $updated ) {
				return false;
			}
		}
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT total FROM {$totals} WHERE comment_id = %d", $id ) );
		if ( $wpdb->last_error || false === $wpdb->query( 'COMMIT' ) ) {
			return false;
		}
		$committed = true;
		return array( 'total' => (int) $total, 'liked' => $liked );
	} finally {
		if ( ! $committed ) {
			$wpdb->query( 'ROLLBACK' );
		}
	}
}

function riot_comments_like_api() {
	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0', true );
	if ( 'POST' !== riot_comments_input( $_SERVER, 'REQUEST_METHOD' ) ||
		'1' !== riot_comments_input( $_SERVER, 'HTTP_X_RIOT_COMMENTS' ) || ! riot_comments_same_origin() ) {
		wp_send_json_error( array( 'message' => __( 'This like request is unavailable.', 'riot-comments' ) ), 403 );
	}
	$post_id = absint( riot_comments_input( $_POST, 'post' ) );
	$id = absint( riot_comments_input( $_POST, 'comment' ) );
	$liked = riot_comments_input( $_POST, 'liked' );
	if ( ! $id || ! in_array( $liked, array( '0', '1' ), true ) ||
		! riot_comments_options()['enabled'] || ! riot_comments_readable( $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'This comment is unavailable.', 'riot-comments' ) ), 404 );
	}
	$voter = riot_comments_like_voter( '1' === $liked );
	if ( '' === $voter && '0' === $liked ) {
		wp_send_json_error( array( 'message' => __( 'Refresh the discussion and try again.', 'riot-comments' ) ), 409 );
	}
	$result = $voter && riot_comments_likes_ready() ? riot_comments_set_like( $id, $post_id, $voter, '1' === $liked ) : false;
	if ( null === $result ) {
		wp_send_json_error( array( 'message' => __( 'This comment is unavailable.', 'riot-comments' ) ), 404 );
	}
	if ( false === $result ) {
		wp_send_json_error( array( 'message' => __( 'Could not save your like. Please try again.', 'riot-comments' ) ), 503 );
	}
	wp_send_json_success( $result );
}

add_action( 'wp_ajax_riot_comments_like', 'riot_comments_like_api' );
add_action( 'wp_ajax_nopriv_riot_comments_like', 'riot_comments_like_api' );
add_action( 'deleted_comment', static function ( $id ) {
	global $wpdb;
	if ( '1' === (string) get_option( 'riot_comments_likes_schema' ) ) {
		$wpdb->delete( $wpdb->prefix . 'riot_comment_votes', array( 'comment_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'riot_comment_like_totals', array( 'comment_id' => $id ), array( '%d' ) );
	}
} );
