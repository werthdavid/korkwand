<?php
/**
 * Server-side rendering of the korkwand/latest block.
 *
 * @var array<string, mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

// Latest::render() already returns fully escaped markup.
$korkwand_latest = \Korkwand\Latest::render(
	array(
		'board'     => (string) ( $attributes['board'] ?? '' ),
		'count'     => (int) ( $attributes['count'] ?? 5 ),
		'show_date' => (bool) ( $attributes['showDate'] ?? true ),
	)
);
if ( '' === $korkwand_latest ) {
	return; // e.g. unknown board for a non-admin: no empty wrapper.
}
echo '<div ' . get_block_wrapper_attributes() . '>' . $korkwand_latest . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
