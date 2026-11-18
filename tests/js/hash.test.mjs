import test from 'node:test';
import assert from 'node:assert/strict';
import { parseNoteHash } from '../../assets/js/hash.js';

test('parseNoteHash: valid hashes', () => {
	assert.equal(parseNoteHash('#korkwand-note-42'), 42);
	assert.equal(parseNoteHash('#korkwand-note-1'), 1);
});

test('parseNoteHash: invalid input returns null', () => {
	for (const h of ['', '#', '#korkwand-note-', '#korkwand-note-0', '#korkwand-note--3', '#korkwand-note-4x', '#korkwand-note-4 ', '#other-42', 'korkwand-note-42', '#korkwand-note-007', undefined, null, 42]) {
		assert.equal(parseNoteHash(h), null, String(h));
	}
});
