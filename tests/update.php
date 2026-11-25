<?php
// Run with WP-CLI eval-file on a development site with this plugin active.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

$slug   = basename( dirname( __DIR__ ) );
$plugin = "$slug/$slug.php";
$repo   = "https://github.com/riotrequest/$slug";
$api    = "https://api.github.com/repos/riotrequest/$slug/releases/latest";
$zip    = "$repo/releases/download/v9.8.7/$slug.zip";

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$headers = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
$check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$check( $repo === $headers['UpdateURI'], 'Unexpected Update URI.' );

$release = array(
	'tag_name' => 'v9.8.7',
	'assets'   => array( array( 'name' => "$slug.zip", 'state' => 'uploaded', 'browser_download_url' => $zip ) ),
);
$reply = $release;
$calls = 0;
$mock = static function ( $pre, $args, $url ) use ( $api, $check, &$reply, &$calls ) {
	$check( $api === $url, 'Unexpected update endpoint.' );
	$check( ! isset( array_change_key_case( $args['headers'] )['authorization'] ), 'Update check sent credentials.' );
	++$calls;
	return is_wp_error( $reply ) ? $reply : array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $reply ),
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );

$offer = static function () use ( $headers, $plugin ) {
	return apply_filters( 'update_plugins_github.com', false, $headers, $plugin );
};
try {
	$check( false === apply_filters( 'update_plugins_github.com', false, $headers, "renamed/$slug.php" ), 'Renamed plugin was offered an update.' );
	$check( 0 === $calls, 'Unrelated plugin triggered an HTTP request.' );
	$update = $offer();
	$check( '9.8.7' === ( $update['version'] ?? '' ) && $zip === ( $update['package'] ?? '' ), 'Stable package was not offered.' );

	$reply['prerelease'] = true;
	$check( false === $offer(), 'Prerelease was offered.' );
	$reply = $release;
	$reply['assets'][0]['browser_download_url'] = "https://example.org/$slug.zip";
	$check( false === $offer(), 'Unexpected package URL was offered.' );
	$reply['assets'] = array();
	$check( false === $offer(), 'A release without an uploaded ZIP was offered.' );
	$reply = new WP_Error( 'offline', 'Fixture network error' );
	$check( false === $offer(), 'An HTTP failure produced an update.' );
} finally {
	remove_filter( 'pre_http_request', $mock );
}
WP_CLI::success( "Verified $slug release updates." );
