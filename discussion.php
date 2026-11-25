<?php
defined( 'ABSPATH' ) || exit;

function riot_comments_order( $input ) {
	return 'oldest' === riot_comments_input( $input, 'riot_order' ) ? 'oldest' : 'newest';
}

function riot_comments_count_label( $post_id ) {
	$count = (int) get_comments_number( $post_id );
	return sprintf( _n( '%s comment', '%s comments', $count, 'riot-comments' ), number_format_i18n( $count ) );
}

function riot_comments_result( $status, $reason = '' ) {
	$title  = '';
	$detail = '';
	if ( 'published' === $status ) {
		$title  = __( 'Comment posted!', 'riot-comments' );
		$detail = __( 'Your comment is now part of the conversation.', 'riot-comments' );
	} elseif ( 'disposable_email' === $reason ) {
		$title = __( 'Email address flagged.', 'riot-comments' );
		$detail = 'pending' === $status
			? __( 'Our email security system identified this as a temporary or disposable email address. Your comment has been submitted for review.', 'riot-comments' )
			: __( 'Our email security system identified this as a temporary or disposable email address. Please use another email address to post your comment.', 'riot-comments' );
	} elseif ( 'pending' === $status ) {
		$title  = __( 'Awaiting approval.', 'riot-comments' );
		$detail = __( 'Your comment was submitted successfully and will appear once approved.', 'riot-comments' );
	} elseif ( 'spam' === $status ) {
		$title  = __( 'Comment could not be published.', 'riot-comments' );
		$detail = __( 'Please review your comment and try again.', 'riot-comments' );
	}
	return array(
		'status' => $status, 'reason' => $reason, 'title' => $title, 'detail' => $detail,
		'message' => trim( $title . ' ' . $detail ),
	);
}

function riot_comments_feedback( $result = array(), $class = '' ) {
	?>
	<div class="riot-feedback <?php echo esc_attr( $class ); ?>" role="status" aria-live="polite" aria-atomic="true" data-status="<?php echo esc_attr( $result['status'] ?? '' ); ?>" data-reason="<?php echo esc_attr( $result['reason'] ?? '' ); ?>">
		<span class="riot-feedback-icon" aria-hidden="true"></span>
		<p class="riot-feedback-text"><strong><?php echo esc_html( $result['title'] ?? '' ); ?></strong><?php echo empty( $result['title'] ) ? '' : ' '; ?><span><?php echo esc_html( $result['detail'] ?? '' ); ?></span></p>
	</div>
	<?php
}

function riot_comments_page( $post_id, $order, $cursor = '', $focus = 0, $with_likes = false ) {
	global $wpdb;
	$direction = 'oldest' === $order ? 'ASC' : 'DESC';
	$args = array(
		'post_id' => $post_id,
		'status'  => 'approve',
		'orderby' => array( 'comment_date_gmt' => $direction, 'comment_ID' => $direction ),
		'parent'  => 0,
		'number'  => 11,
	);

	$target = $focus ? get_comment( $focus ) : null;
	$seen   = array();
	while ( $target && (int) $target->comment_post_ID === $post_id && '1' === $target->comment_approved
		&& $target->comment_parent && ! isset( $seen[ $target->comment_ID ] ) ) {
		$seen[ $target->comment_ID ] = true;
		$target = get_comment( $target->comment_parent );
	}

	$where = null;
	if ( $target && (int) $target->comment_post_ID === $post_id && '1' === $target->comment_approved && ! $target->comment_parent ) {
		$args['comment__in'] = array( (int) $target->comment_ID );
	} elseif ( preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\|[1-9]\d{0,18}$/D', $cursor ) ) {
		list( $date, $id ) = explode( '|', $cursor );
		$id = (int) $id;
		// Core uses its known query arguments for the persistent cache key.
		$args['date_query'] = array( array(
			'column'    => 'comment_date_gmt',
			( 'ASC' === $direction ? 'after' : 'before' ) => $date,
			'inclusive' => true,
		) );
		$args['comment__not_in'] = array( $id );
		$args['riot_cursor_query'] = $cursor;
		$where = static function ( $clauses, $query ) use ( $direction, $cursor, $date, $id, $wpdb ) {
			if ( ( $query->query_vars['riot_cursor_query'] ?? '' ) === $cursor ) {
				$operator = 'ASC' === $direction ? '>' : '<';
				$clauses['where'] .= $wpdb->prepare(
					" AND ($wpdb->comments.comment_date_gmt <> %s OR $wpdb->comments.comment_ID $operator %d)",
					$date, $id
				);
			}
			return $clauses;
		};
		add_filter( 'comments_clauses', $where, 10, 2 );
	}
	$roots = get_comments( $args );
	if ( $where ) {
		remove_filter( 'comments_clauses', $where, 10 );
	}

	$more = count( $roots ) > 10;
	if ( $more ) {
		array_pop( $roots );
	}

	$children = array();
	$parents  = wp_list_pluck( $roots, 'comment_ID' );
	$like_ids = $parents;
	while ( $parents ) {
		$replies = get_comments( array(
			'post_id'    => $post_id,
			'parent__in' => $parents,
			'status'     => 'approve',
			'orderby'    => array( 'comment_date_gmt' => 'ASC', 'comment_ID' => 'ASC' ),
		) );
		$parents = wp_list_pluck( $replies, 'comment_ID' );
		foreach ( $replies as $reply ) {
			$children[ $reply->comment_parent ][] = $reply;
			if ( $with_likes ) {
				$like_ids[] = $reply->comment_ID;
			}
		}
	}

	$html = '';
	$open = comments_open( $post_id ) && ( is_user_logged_in() || ! get_option( 'comment_registration' ) );
	$likes = $with_likes ? riot_comments_like_states( $like_ids ) : null;
	foreach ( $roots as $root ) {
		$html .= riot_comments_item( $root, $children, $open, false, $likes );
	}
	$last = end( $roots );
	return array(
		'html'   => $html,
		'cursor' => $more ? $last->comment_date_gmt . '|' . $last->comment_ID : '',
		'count'  => riot_comments_count_label( $post_id ),
		'total'  => (int) get_comments_number( $post_id ),
	);
}

function riot_comments_post_author( $post_id ) {
	static $authors = array();
	if ( ! isset( $authors[ $post_id ] ) ) {
		$post = get_post( $post_id );
		$authors[ $post_id ] = $post ? (int) $post->post_author : 0;
	}
	return $authors[ $post_id ];
}

function riot_comments_item( $comment, $children, $open, $pending = false, $likes = null ) {
	$id      = (int) $comment->comment_ID;
	$author  = wp_strip_all_tags( get_comment_author( $comment ) );
	$initial = function_exists( 'mb_substr' ) ? mb_substr( $author, 0, 1, 'UTF-8' ) : ( preg_match( '/^./u', $author, $m ) ? $m[0] : '' );
	$link    = add_query_arg( 'riot_focus', $id, get_permalink( $comment->comment_post_ID ) );
	$time    = '<time datetime="' . esc_attr( get_comment_date( DATE_W3C, $comment ) ) . '">' .
		esc_html( get_comment_date( '', $comment ) . ' · ' . get_comment_date( get_option( 'time_format' ), $comment ) ) . '</time>';
	$parent = $pending && $comment->comment_parent ? get_comment( $comment->comment_parent ) : null;
	$is_author = $comment->user_id && (int) $comment->user_id === riot_comments_post_author( (int) $comment->comment_post_ID );
	$hue = abs( crc32( strtolower( $author ) ) ) % 360;
	ob_start();
	?>
	<li class="riot-comment" id="comment-<?php echo esc_attr( $id ); ?>" data-comment-id="<?php echo esc_attr( $id ); ?>" data-comment-date="<?php echo esc_attr( $comment->comment_date_gmt ); ?>" <?php echo $pending ? 'data-riot-pending' : ''; ?>>
		<article class="riot-comment-body" tabindex="-1">
			<span class="riot-avatar<?php echo $is_author ? ' riot-avatar-author' : ''; ?>" style="--riot-hue: <?php echo esc_attr( $hue ); ?>" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
			<div class="riot-comment-content">
				<header class="riot-byline">
					<strong class="riot-author"><?php echo esc_html( $author ); ?></strong>
					<?php if ( $is_author ) : ?><span class="riot-badge"><?php esc_html_e( 'Author', 'riot-comments' ); ?></span><?php endif; ?>
					<?php if ( $pending ) : ?>
						<?php echo $time; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="riot-pending-label"><?php esc_html_e( 'Awaiting approval', 'riot-comments' ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $link . '#comment-' . $id ); ?>"><?php echo $time; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					<?php endif; ?>
				</header>
				<?php if ( $parent && '1' === $parent->comment_approved && $parent->comment_post_ID === $comment->comment_post_ID ) : ?>
					<p class="riot-pending-parent"><?php echo esc_html( sprintf( __( 'In reply to %s', 'riot-comments' ), get_comment_author( $parent ) ) ); ?></p>
				<?php endif; ?>
				<div class="riot-comment-text"><?php echo wp_kses_post( apply_filters( 'comment_text', get_comment_text( $comment ), $comment, array() ) ); ?></div>
				<?php if ( ! $pending ) : ?>
					<div class="riot-actions">
						<?php if ( $open && in_array( $comment->comment_type, array( '', 'comment' ), true ) ) : ?>
							<a class="riot-reply-link" data-reply="<?php echo esc_attr( $id ); ?>" data-author="<?php echo esc_attr( $author ); ?>" href="<?php echo esc_url( add_query_arg( 'riot_reply', $id, $link ) . '#riot-form' ); ?>">
								<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.5 3.5 2 8l4.5 4.5M2.5 8H10a4 4 0 0 1 4 4v1" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php esc_html_e( 'Reply', 'riot-comments' ); ?><span class="riot-sr"><?php echo esc_html( sprintf( __( ' to %s', 'riot-comments' ), $author ) ); ?></span>
							</a>
						<?php endif; ?>
						<?php if ( null !== $likes && in_array( $comment->comment_type, array( '', 'comment' ), true ) ) : ?>
							<button type="button" class="riot-like" data-like="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Like comment by %s', 'riot-comments' ), $author ) ); ?>" aria-pressed="<?php echo empty( $likes[ $id ]->liked ) ? 'false' : 'true'; ?>" aria-describedby="riot-like-count-<?php echo esc_attr( $id ); ?>">
								<span aria-hidden="true">👍</span>
								<span id="riot-like-count-<?php echo esc_attr( $id ); ?>" data-like-count><?php echo esc_html( number_format_i18n( (int) ( $likes[ $id ]->total ?? 0 ) ) ); ?></span>
							</button>
						<?php endif; ?>
						<button type="button" class="riot-copy-link" data-copy="<?php echo esc_url( $link . '#comment-' . $id ); ?>" hidden>
							<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6.8 9.2a3 3 0 0 0 4.2 0l2.2-2.2a3 3 0 0 0-4.2-4.2l-.9.9M9.2 6.8a3 3 0 0 0-4.2 0L2.8 9a3 3 0 0 0 4.2 4.2l.9-.9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
							<span><?php esc_html_e( 'Copy link', 'riot-comments' ); ?></span>
						</button>
					</div>
				<?php endif; ?>
			</div>
		</article>
		<?php if ( ! empty( $children[ $id ] ) ) : ?>
			<ol class="riot-comments-list riot-children">
				<?php foreach ( $children[ $id ] as $reply ) {
					echo riot_comments_item( $reply, $children, $open, false, $likes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} ?>
			</ol>
		<?php endif; ?>
	</li>
	<?php
	return ob_get_clean();
}

function riot_comments_form( $post_id, $draft = array() ) {
	if ( ! is_user_logged_in() && get_option( 'comment_registration' ) ) {
		printf( '<p><a href="%s">%s</a></p>', esc_url( wp_login_url( get_permalink( $post_id ) . '#comments' ) ), esc_html__( 'Sign in to comment', 'riot-comments' ) );
		return;
	}
	$draft  = wp_parse_args( $draft, array( 'comment' => '', 'author' => '', 'email' => '', 'parent' => 0, 'consent' => false ) );
	$required = get_option( 'require_name_email' ) ? ' required' : '';
	$parent = $draft['parent'] ? get_comment( $draft['parent'] ) : null;
	$parent = $parent && (int) $parent->comment_post_ID === $post_id && '1' === $parent->comment_approved ? $parent : null;
	$reply  = $parent ? sprintf( __( 'Replying to %s', 'riot-comments' ), wp_strip_all_tags( get_comment_author( $parent ) ) ) : '';
	?>
	<form id="riot-form" class="riot-form" method="post" action="<?php echo esc_url( site_url( '/wp-comments-post.php' ) ); ?>" data-guest="<?php echo is_user_logged_in() ? '0' : '1'; ?>">
		<div class="riot-reply-context" <?php echo $reply ? '' : 'hidden'; ?>>
			<span><?php echo esc_html( $reply ); ?></span>
			<a href="<?php echo esc_url( get_permalink( $post_id ) . '#riot-form' ); ?>" data-cancel><?php esc_html_e( 'Cancel reply', 'riot-comments' ); ?></a>
		</div>
		<label class="riot-message">
			<span class="riot-sr"><?php esc_html_e( 'Your comment', 'riot-comments' ); ?></span>
			<?php
			$field = '<textarea name="comment" data-riot-message rows="3" required maxlength="16000" placeholder="' .
				esc_attr__( 'What’s on your mind?', 'riot-comments' ) . '">' . esc_textarea( $draft['comment'] ) . '</textarea>';
			echo apply_filters( 'comment_form_field_comment', $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native form filters may add honeypots.
			?>
		</label>
		<?php if ( ! is_user_logged_in() ) : ?>
			<div class="riot-identity-summary" hidden>
				<span data-riot-posting-as></span>
				<button type="button" class="riot-text-button" data-riot-edit><?php esc_html_e( 'Edit name or email', 'riot-comments' ); ?></button>
			</div>
			<div class="riot-identity">
				<div class="riot-fields">
					<label><?php esc_html_e( 'Name', 'riot-comments' ); ?>
						<input name="author" autocomplete="name" maxlength="120" value="<?php echo esc_attr( $draft['author'] ); ?>"<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					</label>
					<label><?php esc_html_e( 'Email', 'riot-comments' ); ?>
						<input name="email" type="email" autocomplete="email" maxlength="100" value="<?php echo esc_attr( $draft['email'] ); ?>" aria-describedby="riot-email-note"<?php echo $required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					</label>
				</div>
				<?php if ( ! $required ) : ?><p class="riot-note"><?php esc_html_e( 'Name and email are optional on this site.', 'riot-comments' ); ?></p><?php endif; ?>
				<p id="riot-email-note" class="riot-note"><?php esc_html_e( 'Your email stays private.', 'riot-comments' ); ?> <button type="button" class="riot-text-button" data-typo hidden></button></p>
				<label class="riot-remember"><input type="checkbox" name="wp-comment-cookies-consent" value="yes" <?php checked( $draft['consent'] ); ?>> <?php esc_html_e( 'Remember me on this device', 'riot-comments' ); ?></label>
			</div>
		<?php endif; ?>
		<div hidden><label><?php esc_html_e( 'Leave this field empty', 'riot-comments' ); ?><input name="riot_contact_url" tabindex="-1" autocomplete="off"></label></div>
		<input type="hidden" name="comment_post_ID" value="<?php echo esc_attr( $post_id ); ?>">
		<input type="hidden" name="comment_parent" value="<?php echo esc_attr( $parent ? $parent->comment_ID : 0 ); ?>">
		<input type="hidden" name="riot_submit" value="1">
		<input type="hidden" name="riot_token" value="<?php echo esc_attr( riot_comments_token( $post_id ) ); ?>">
		<div class="riot-form-footer">
			<?php riot_comments_feedback(); ?>
			<span class="riot-hint" aria-hidden="true"><?php esc_html_e( 'Ctrl + Enter to post', 'riot-comments' ); ?></span>
			<button class="riot-primary" type="submit"><?php echo $reply ? esc_html__( 'Post reply', 'riot-comments' ) : esc_html__( 'Post comment', 'riot-comments' ); ?></button>
		</div>
	</form>
	<?php
	do_action( 'comment_form', $post_id );
}

function riot_comments_discussion( $post_id ) {
	static $rendered = array();
	if ( isset( $rendered[ $post_id ] ) || ! riot_comments_readable( $post_id ) ) {
		return '';
	}
	if ( ! comments_open( $post_id ) && ! get_comments_number( $post_id ) ) {
		return '';
	}
	$rendered[ $post_id ] = true;
	riot_comments_assets();
	$options = riot_comments_options();
	$order   = riot_comments_order( $_GET );
	$cursor  = riot_comments_input( $_GET, 'riot_cursor' );
	$focus   = absint( riot_comments_input( $_GET, 'riot_focus' ) );
	$draft   = array( 'parent' => absint( riot_comments_input( $_GET, 'riot_reply' ) ) );
	$page    = riot_comments_page( $post_id, $order, $cursor, $focus );
	$base    = get_permalink( $post_id );
	$receipt = riot_comments_input( $_GET, 'riot_posted' );
	$pending = 'held' === $receipt ? riot_comments_pending( $post_id ) : array();
	$confirmation = null;
	if ( $pending ) {
		$receipt_id = absint( riot_comments_input( $_GET, 'riot_receipt' ) );
		foreach ( $pending as $item ) {
			if ( ! $receipt_id || $item['id'] === $receipt_id ) {
				$reason = $receipt_id && 'disposable' === get_comment_meta( $receipt_id, '_riot_email_screening', true ) ? 'disposable_email' : '';
				$confirmation = riot_comments_result( 'pending', $reason );
				break;
			}
		}
	} elseif ( 'yes' === $receipt && $focus ) {
		$posted = get_comment( $focus );
		if ( $posted && (int) $posted->comment_post_ID === $post_id && 'approved' === wp_get_comment_status( $posted ) ) {
			$confirmation = riot_comments_result( 'published' );
		}
	}
	$labels  = array(
		'posting' => __( 'Posting…', 'riot-comments' ),
		'loading' => __( 'Loading comments…', 'riot-comments' ),
		'loaded'  => __( 'Comments updated.', 'riot-comments' ),
		'error'   => __( 'Something went wrong. Please try again.', 'riot-comments' ),
		'submitError' => __( 'Could not complete the submission. Your draft is still here. Please try again.', 'riot-comments' ),
		'notPosted' => __( 'Not posted yet.', 'riot-comments' ),
		'network'   => __( 'We couldn’t reach the site. Check your connection; your draft is safe.', 'riot-comments' ),
		'server'    => __( 'The site hit a snag (error %s). Your draft is safe. Please try again.', 'riot-comments' ),
		'retrying'  => __( 'Still working on it…', 'riot-comments' ),
		'copied'    => __( 'Link copied', 'riot-comments' ),
		'likeError' => __( 'Could not save your like. Please try again.', 'riot-comments' ),
		'hintMac'   => __( '⌘ + Enter to post', 'riot-comments' ),
		'jump'      => __( 'Comments', 'riot-comments' ),
		'jumpEmpty' => __( 'Comment', 'riot-comments' ),
		'reply'   => __( 'Replying to %s', 'riot-comments' ),
		'post'    => __( 'Post comment', 'riot-comments' ),
		'respond' => __( 'Post reply', 'riot-comments' ),
		'typo'    => __( 'Use %s?', 'riot-comments' ),
		'identity' => __( 'Posting as %s', 'riot-comments' ),
	);
	ob_start();
	?>
	<section id="comments" class="riot-discussion" data-riot-discussion data-post="<?php echo esc_attr( $post_id ); ?>" data-api="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-url="<?php echo esc_url( $base ); ?>" data-order="<?php echo esc_attr( $order ); ?>" data-mode="<?php echo esc_attr( $options['mode'] ); ?>" data-shape="<?php echo esc_attr( $options['shape'] ); ?>" data-jump="<?php echo $options['jump'] ? '1' : '0'; ?>" data-total="<?php echo esc_attr( $page['total'] ); ?>" data-font="<?php echo esc_attr( $options['font'] ); ?>" data-labels="<?php echo esc_attr( wp_json_encode( $labels ) ); ?>" <?php if ( sanitize_hex_color( $options['accent'] ) ) : ?>style="--riot-custom-accent: <?php echo esc_attr( $options['accent'] ); ?>"<?php endif; ?> aria-labelledby="riot-title">
		<header class="riot-heading">
			<div class="riot-heading-text">
				<?php if ( '' !== trim( (string) $options['eyebrow'] ) ) : ?><p class="riot-eyebrow"><?php echo esc_html( $options['eyebrow'] ); ?></p><?php endif; ?>
				<h2 id="riot-title"><?php esc_html_e( 'Join the conversation', 'riot-comments' ); ?></h2>
			</div>
			<span class="riot-count"><?php echo esc_html( $page['count'] ); ?></span>
		</header>
		<?php if ( $confirmation ) : ?>
			<?php riot_comments_feedback( $confirmation, 'riot-receipt' ); ?>
		<?php endif; ?>
		<div class="riot-composer">
			<?php if ( comments_open( $post_id ) ) {
				riot_comments_form( $post_id, $draft );
			} else {
				echo '<p>' . esc_html__( 'Comments are closed.', 'riot-comments' ) . '</p>';
			} ?>
		</div>
		<div class="riot-toolbar" <?php echo get_comments_number( $post_id ) ? '' : 'hidden'; ?>>
			<?php if ( $focus || $cursor ) : ?>
				<a class="riot-all" href="<?php echo esc_url( $base . '#comments' ); ?>"><?php esc_html_e( 'All comments', 'riot-comments' ); ?></a>
			<?php endif; ?>
			<nav aria-label="<?php esc_attr_e( 'Comment order', 'riot-comments' ); ?>">
				<?php foreach ( array( 'newest' => __( 'Newest', 'riot-comments' ), 'oldest' => __( 'Oldest', 'riot-comments' ) ) as $value => $label ) : ?>
					<a data-sort="<?php echo esc_attr( $value ); ?>" <?php echo $order === $value ? 'aria-current="true"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'riot_order', $value, $base ) . '#comments' ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		</div>
		<p class="riot-list-status riot-sr" role="status"></p>
		<ol class="riot-comments-list riot-roots"><?php echo $page['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></ol>
		<section class="riot-private" aria-label="<?php esc_attr_e( 'Your comments awaiting approval', 'riot-comments' ); ?>" <?php echo $pending ? '' : 'hidden'; ?>>
			<ol class="riot-comments-list riot-private-list"><?php foreach ( $pending as $item ) {
				echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} ?></ol>
		</section>
		<p class="riot-empty" <?php echo $page['html'] || $pending ? 'hidden' : ''; ?>><?php esc_html_e( 'Be the first to comment.', 'riot-comments' ); ?></p>
		<a class="riot-more" data-cursor="<?php echo esc_attr( $page['cursor'] ); ?>" href="<?php echo esc_url( add_query_arg( array( 'riot_cursor' => $page['cursor'], 'riot_order' => $order ), $base ) . '#comments' ); ?>" <?php echo $page['cursor'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Load more comments', 'riot-comments' ); ?></a>
	</section>
	<?php
	return ob_get_clean();
}
