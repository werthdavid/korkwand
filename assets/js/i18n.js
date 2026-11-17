// UI strings are translated in PHP (Render::js_strings) and handed over as window.Korkwand.i18n,
// because script modules cannot use wp_set_script_translations().
// t('imageTooLarge', '8') replaces each %s in order.
export function t(key, ...args) {
	const strings = (window.Korkwand && window.Korkwand.i18n) || {};
	let out = typeof strings[key] === 'string' ? strings[key] : key;
	for (const arg of args) {
		out = out.replace('%s', String(arg));
	}
	return out;
}

// Localised effective upload limit as text ("8" or "1,5"), provided by the server.
export function maxLabel() {
	return String((window.Korkwand && window.Korkwand.maxLabel) || '8');
}
