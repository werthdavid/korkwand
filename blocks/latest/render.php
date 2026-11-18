<?php
/**
 * Server-side rendering of the korkwand/latest block.
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Latest::render() already returns fully escaped markup.
echo '<div ' . get_block_wrapper_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo \Korkwand\Latest::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	array(
		'board'     => (string) ( $attributes['board'] ?? '' ),
		'count'     => (int) ( $attributes['count'] ?? 5 ),
		'show_date' => (bool) ( $attributes['showDate'] ?? true ),
	)
);
echo '</div>';
