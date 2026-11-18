<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'Riot Comments', 'Riot Comments', 'manage_options', 'riot-comments', 'riot_comments_settings_page' );
} );

add_action( 'admin_init', function () {
	register_setting( 'riot_comments', 'riot_comments_settings', array(
		'type'              => 'array',
		'sanitize_callback' => static function ( $input ) {
			$input = is_array( $input ) ? $input : array();
			return array(
				'enabled'   => ! empty( $input['enabled'] ) ? 1 : 0,
				'screening' => ! empty( $input['screening'] ) ? 1 : 0,
				'action'    => 'reject' === ( $input['action'] ?? '' ) ? 'reject' : 'hold',
				'accent'    => is_string( $input['accent'] ?? null ) ? sanitize_hex_color( trim( $input['accent'] ) ) ?: '' : '',
				'font'      => in_array( $input['font'] ?? '', array( 'serif', 'sans' ), true ) ? $input['font'] : 'inherit',
				'mode'      => in_array( $input['mode'] ?? '', array( 'light', 'dark' ), true ) ? $input['mode'] : 'auto',
			);
		},
	) );
} );

add_action( 'admin_post_riot_comments_clear_cache', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'riot-comments' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'riot_comments_clear_cache' );
	// Expiring old generations also works with persistent object-cache transients.
	update_option( 'riot_comments_cache_generation', wp_generate_uuid4(), false );
	wp_safe_redirect( add_query_arg( array( 'page' => 'riot-comments', 'riot-cache-cleared' => '1' ), admin_url( 'options-general.php' ) ) );
	exit;
} );

function riot_comments_settings_page() {
	$options = riot_comments_options();
	$selects = array(
		'mode'   => array( 'auto' => __( 'Follow theme', 'riot-comments' ), 'light' => __( 'Light', 'riot-comments' ), 'dark' => __( 'Dark', 'riot-comments' ) ),
		'font'   => array( 'inherit' => __( 'Follow theme', 'riot-comments' ), 'sans' => __( 'System sans serif', 'riot-comments' ), 'serif' => __( 'Georgia serif', 'riot-comments' ) ),
		'action' => array( 'hold' => __( 'Send to moderation', 'riot-comments' ), 'reject' => __( 'Ask for a different address', 'riot-comments' ) ),
	);
	$labels = array(
		'mode' => __( 'Appearance', 'riot-comments' ), 'font' => __( 'Typography', 'riot-comments' ), 'action' => __( 'Disposable email action', 'riot-comments' ),
	);
	?>
	<div class="wrap">
		<h1>Riot Comments</h1>
		<?php if ( '1' === riot_comments_input( $_GET, 'riot-cache-cleared' ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Domain verdict cache cleared.', 'riot-comments' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'riot_comments' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Comment interface', 'riot-comments' ); ?></th>
					<td><label><input type="checkbox" name="riot_comments_settings[enabled]" value="1" <?php checked( $options['enabled'] ); ?>> <?php esc_html_e( 'Use Riot Comments', 'riot-comments' ); ?></label>
						<p class="description"><?php esc_html_e( 'Clear this checkbox and save to restore the default interface. Guest access follows your WordPress Discussion settings.', 'riot-comments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="riot-accent"><?php esc_html_e( 'Accent color', 'riot-comments' ); ?></label></th>
					<td><input id="riot-accent" name="riot_comments_settings[accent]" value="<?php echo esc_attr( $options['accent'] ); ?>" maxlength="7" placeholder="#ac3237">
						<p class="description"><?php esc_html_e( 'Enter a hex color or leave blank to use the theme accent when available.', 'riot-comments' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Email screening', 'riot-comments' ); ?></th>
					<td><label><input type="checkbox" name="riot_comments_settings[screening]" value="1" <?php checked( $options['screening'] ); ?>> <?php esc_html_e( 'Check guest email domains for disposable providers', 'riot-comments' ); ?></label>
						<p class="description"><?php esc_html_e( 'DeBounce and Disify receive only the domain. If both are unavailable, WordPress applies its usual moderation rules.', 'riot-comments' ); ?></p>
					</td>
				</tr>
				<?php foreach ( $selects as $name => $values ) : ?>
					<tr>
						<th scope="row"><label for="riot-setting-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $labels[ $name ] ); ?></label></th>
						<td><select id="riot-setting-<?php echo esc_attr( $name ); ?>" name="riot_comments_settings[<?php echo esc_attr( $name ); ?>]">
							<?php foreach ( $values as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options[ $name ], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2><?php esc_html_e( 'Provider health', 'riot-comments' ); ?></h2>
		<table class="widefat striped" style="max-width: 720px">
			<thead><tr><th><?php esc_html_e( 'Provider', 'riot-comments' ); ?></th><th><?php esc_html_e( 'Latest check', 'riot-comments' ); ?></th><th><?php esc_html_e( 'Last error', 'riot-comments' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( array( 'debounce' => 'DeBounce', 'disify' => 'Disify' ) as $key => $label ) :
					$health = get_option( 'riot_comments_health_' . $key, array() );
					?>
					<tr><td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( empty( $health['checked'] ) ? __( 'No checks yet', 'riot-comments' ) :
							( $health['ok'] ? __( 'Available', 'riot-comments' ) : __( 'Unavailable', 'riot-comments' ) ) . ' · ' . wp_date( 'Y-m-d H:i', $health['checked'] ) ); ?></td>
						<td><?php echo esc_html( empty( $health['last_error'] ) ? '—' : $health['last_error'] . ' · ' . wp_date( 'Y-m-d H:i', $health['error_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="riot_comments_clear_cache">
			<?php wp_nonce_field( 'riot_comments_clear_cache' ); ?>
			<?php submit_button( __( 'Clear domain verdict cache', 'riot-comments' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
