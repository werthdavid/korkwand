import test from 'node:test';
import assert from 'node:assert/strict';
import { scaleFor, toLogical, clamp } from '../../assets/js/geometry.js';

test('scaleFor: min(1, w/1000)', () => {
	assert.equal(scaleFor(375), 0.375);
	assert.equal(scaleFor(1400), 1);
	assert.equal(scaleFor(1000), 1);
});

test('toLogical: Pixel -> logische Einheiten', () => {
	assert.deepEqual(toLogical(30, 15, 0.5), { dx: 60, dy: 30 });
	assert.deepEqual(toLogical(10, 10, 1), { dx: 10, dy: 10 });
});

test('clamp: x 0..800, y 0..height-100', () => {
	assert.deepEqual(clamp(-5, 9999, 700), { x: 0, y: 600 });
	assert.deepEqual(clamp(900, 50, 700), { x: 800, y: 50 });
	assert.deepEqual(clamp(10, 10, 50), { x: 10, y: 0 });
});
