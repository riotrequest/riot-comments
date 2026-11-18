<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keep a short, private log of submissions that ended in something other than a
 * deliberate Riot response. It stores no comment text, names, or email addresses.
 */
function riot_comments_incident( $type, $detail = array() ) {
	$clip = static function ( $text, $length = 300 ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	};
	$entry = array(
		'time'   => time(),
		'type'   => $type,
		'stage'  => sanitize_key( $detail['stage'] ?? '' ),
		'http'   => (int) ( $detail['http'] ?? 0 ),
		'post'   => (int) ( $detail['post'] ?? 0 ),
		'ms'     => (int) ( $detail['ms'] ?? 0 ),
		'output' => $clip( $detail['output'] ?? '' ),
		'error'  => '',
		'agent'  => $clip( $_SERVER['HTTP_USER_AGENT'] ?? '', 120 ),
	);
	$error = $detail['error'] ?? null;
	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		$file = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) ( $error['file'] ?? '' ) ) );
		$entry['error'] = $clip( $error['message'] . ( $file ? ' @ ' . $file . ':' . (int) ( $error['line'] ?? 0 ) : '' ), 400 );
	}
	error_log( 'Riot Comments ' . $type . ': ' . wp_json_encode( $entry ) );
	$log = get_option( 'riot_comments_incidents', array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, $entry );
	update_option( 'riot_comments_incidents', array_slice( $log, 0, 30 ), false );
}
