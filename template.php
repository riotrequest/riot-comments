<?php
defined( 'ABSPATH' ) || exit;
echo riot_comments_discussion( get_queried_object_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped on render.
