// Pure helper (no DOM dependency, testable with node:test).
// The URL hash is #korkwand-note-N, but pin elements carry id="korkwand-pin-N": with no element matching the
// hash, the browser performs no native fragment scroll and only the deep link handler scrolls.
export const PIN_ID_PREFIX = 'korkwand-pin-';

// "#korkwand-note-42" -> 42; anything else (or id <= 0, trailing junk) -> null.
export function parseNoteHash(hash) {
	if (typeof hash !== 'string') return null;
	const m = /^#korkwand-note-([1-9]\d{0,14})$/.exec(hash);
	return m ? parseInt(m[1], 10) : null;
}

// Indexes of later duplicates in a list of ids (the first occurrence in DOM order keeps its id).
export function duplicateIndexes(ids) {
	const seen = new Set();
	const dups = [];
	ids.forEach((id, i) => {
		if (!id) return;
		if (seen.has(id)) dups.push(i);
		else seen.add(id);
	});
	return dups;
}
