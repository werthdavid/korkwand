import test from 'node:test';
import assert from 'node:assert/strict';
import { parseNoteHash, duplicateIndexes } from '../../assets/js/hash.js';

test('parseNoteHash: valid hashes', () => {
	assert.equal(parseNoteHash('#korkwand-note-42'), 42);
	assert.equal(parseNoteHash('#korkwand-note-1'), 1);
});

test('parseNoteHash: invalid input returns null', () => {
	for (const h of ['', '#', '#korkwand-note-', '#korkwand-note-0', '#korkwand-note--3', '#korkwand-note-4x', '#korkwand-note-4 ', '#other-42', 'korkwand-note-42', '#korkwand-note-007', undefined, null, 42]) {
		assert.equal(parseNoteHash(h), null, String(h));
	}
});

test('duplicateIndexes: keeps the first occurrence, flags later ones', () => {
	assert.deepEqual(duplicateIndexes(['a', 'b', 'a', '', 'b', 'a', 'c']), [2, 4, 5]);
	assert.deepEqual(duplicateIndexes([]), []);
	assert.deepEqual(duplicateIndexes(['x', 'y']), []);
});
