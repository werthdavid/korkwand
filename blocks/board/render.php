<?php
/**
 * Server-side rendering of the korkwand/board block.
 *
 * @var array<string, mixed> $attributes Block-Attribute.
 */

defined( 'ABSPATH' ) || exit;

// Render::board() already returns fully escaped markup.
echo '<div ' . get_block_wrapper_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo \Korkwand\Render::board( (string) ( $attributes['board'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';
