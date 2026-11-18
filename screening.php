<?php
defined( 'ABSPATH' ) || exit;

function riot_comments_provider_error( $provider, $error ) {
	$previous = get_option( 'riot_comments_health_' . $provider, array() );
	update_option( 'riot_comments_health_' . $provider, array(
		'checked'    => time(),
		'ok'         => '' === $error,
		'last_error' => $error ?: ( $previous['last_error'] ?? '' ),
		'error_at'   => $error ? time() : ( $previous['error_at'] ?? 0 ),
	), false );
}

function riot_comments_boolean( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( is_string( $value ) ) {
		$value = strtolower( trim( $value ) );
		if ( 'true' === $value || 'false' === $value ) {
			return 'true' === $value;
		}
	}
	return null;
}

function riot_comments_screen( $domain ) {
	// Known forwarding services are valid ways to receive mail.
	$aliases = apply_filters( 'riot_comments_privacy_domains', array(
		'privaterelay.appleid.com', 'icloud.com', 'duck.com', 'mozmail.com', 'relay.firefox.com',
		'simplelogin.com', 'simplelogin.co', 'slmail.me', 'aleeas.com', 'addy.io', 'anonaddy.com',
	) );
	if ( in_array( $domain, $aliases, true ) ) {
		return 'clean';
	}

	$generation = get_option( 'riot_comments_cache_generation', '1' );
	$key        = 'riot_domain_' . md5( $generation . ':' . $domain );
	$cached     = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}

	$domain_arg = rawurlencode( $domain );
	$providers  = array(
		'debounce' => 'https://disposable.debounce.io/?email=' . $domain_arg,
		'disify'   => 'https://disify.com/api/domain/' . $domain_arg,
	);
	foreach ( $providers as $provider => $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'             => 0.85,
			'redirection'         => 0,
			'limit_response_size' => 8192,
			'headers'             => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $response ) ) {
			riot_comments_provider_error( $provider, __( 'Network error or timeout', 'riot-comments' ) );
			continue;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			riot_comments_provider_error( $provider, sprintf( 'HTTP %d', $status ) );
			continue;
		}
		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$verdict = riot_comments_boolean( $body['disposable'] ?? null );
		if ( null === $verdict || isset( $body['error'] ) || riot_comments_boolean( $body['format'] ?? true ) !== true ) {
			riot_comments_provider_error( $provider, __( 'Unrecognized response', 'riot-comments' ) );
			continue;
		}
		riot_comments_provider_error( $provider, '' );
		$result = $verdict ? 'disposable' : 'clean';
		set_transient( $key, $result, DAY_IN_SECONDS );
		return $result;
	}

	set_transient( $key, 'unknown', MINUTE_IN_SECONDS );
	return 'unknown';
}
