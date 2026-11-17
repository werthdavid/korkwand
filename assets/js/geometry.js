// Reine Geometrie-Helfer (keine DOM-Abhaengigkeit, node:test-bar).
export const LOGICAL_WIDTH = 1000;
export const MAX_X = 800;

export function scaleFor(containerWidth) {
	return Math.min(1, containerWidth / LOGICAL_WIDTH);
}

export function toLogical(dxPx, dyPx, scale) {
	return { dx: dxPx / scale, dy: dyPx / scale };
}

// Gleiche Grenzen wie Pins::clamp_position (PHP).
export function clamp(x, y, height) {
	return {
		x: Math.max(0, Math.min(MAX_X, Math.round(x))),
		y: Math.max(0, Math.min(Math.max(0, height - 100), Math.round(y))),
	};
}
