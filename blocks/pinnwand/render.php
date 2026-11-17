<?php
/**
 * Serverseitiges Rendering des Blocks nz/pinnwand.
 *
 * @var array<string, mixed> $attributes Block-Attribute.
 */

defined( 'ABSPATH' ) || exit;

// Render::board() liefert bereits vollstaendig escaptes Markup.
echo \NZ\Pinnwand\Render::board( (string) ( $attributes['board'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
