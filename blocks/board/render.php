<?php
/**
 * Serverseitiges Rendering des Blocks korkwand/board.
 *
 * @var array<string, mixed> $attributes Block-Attribute.
 */

defined( 'ABSPATH' ) || exit;

// Render::board() liefert bereits vollstaendig escaptes Markup.
echo '<div ' . get_block_wrapper_attributes() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo \Korkwand\Render::board( (string) ( $attributes['board'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</div>';
