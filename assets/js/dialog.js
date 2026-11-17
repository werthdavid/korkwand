// Anlegen-/Bearbeiten-Dialog (<dialog>, showModal). Baut das DOM nur mit createElement/textContent.
const DEFAULT_MAX_BYTES = 8388608;
const LIMITS = { title: 60, postit: 300, caption: 100 };

function el(tag, props = {}, ...children) {
	const n = document.createElement(tag);
	for (const [k, v] of Object.entries(props)) {
		if (k === 'text') n.textContent = v;
		else if (k in n && k !== 'list') n[k] = v;
		else n.setAttribute(k, v);
	}
	n.append(...children);
	return n;
}

// Effektives Upload-Limit (vom Server: min(8 MB, Host-Limit)).
function maxBytes() {
	const n = Number(window.Korkwand && window.Korkwand.maxBytes);
	return n > 0 ? Math.min(n, DEFAULT_MAX_BYTES) : DEFAULT_MAX_BYTES;
}

function maxLabel() {
	const mb = Math.round((maxBytes() / 1048576) * 10) / 10;
	return String(mb).replace('.', ',');
}

function field(labelText, control) {
	const id = 'korkwand-f-' + Math.random().toString(36).slice(2, 8);
	control.id = id;
	return el('div', { className: 'korkwand-field' }, el('label', { htmlFor: id, text: labelText }), control);
}

/**
 * @param {{pin?: object, trigger?: Element, onSubmit: (v: {type:string,title:string,text:string,file:?File}) => Promise<void>}} opts
 * pin gesetzt = Bearbeiten (Typ fest), sonst Anlegen.
 */
export function openPinDialog({ pin = null, trigger = null, onSubmit }) {
	const editing = !!pin;
	const dlg = el('dialog', { className: 'korkwand-dialog' });
	dlg.setAttribute('aria-labelledby', 'korkwand-dlg-title');

	const form = el('form', { method: 'dialog', noValidate: false });
	const title = el('h2', { id: 'korkwand-dlg-title', text: editing ? 'Zettel bearbeiten' : 'Neuer Zettel' });
	const error = el('p', { className: 'korkwand-dialog-error', role: 'alert' });
	error.hidden = true;

	let type = editing ? pin.type : 'postit';

	// Typwahl (nur beim Anlegen)
	const typeBox = el('fieldset', { className: 'korkwand-type' }, el('legend', { text: 'Art des Zettels' }));
	for (const [val, label] of [['postit', 'Post-it'], ['image', 'Bild']]) {
		const rid = 'korkwand-type-' + val;
		const r = el('input', { type: 'radio', id: rid, name: 'korkwand-type', value: val, checked: val === type });
		typeBox.append(el('label', { htmlFor: rid, className: 'korkwand-type-label' }, r, el('span', { text: ' ' + label })));
		r.addEventListener('change', () => { type = val; sync(); });
	}
	typeBox.hidden = editing;

	const titleIn = el('input', { type: 'text', maxLength: LIMITS.title, value: editing ? pin.title : '' });
	const textArea = el('textarea', { rows: 5, maxLength: LIMITS.postit, value: editing ? pin.text : '' });
	const captionIn = el('input', { type: 'text', maxLength: LIMITS.caption, value: editing ? pin.text : '' });
	const fileIn = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp' });

	const titleRow = field('Titel (Pflicht, max. 60 Zeichen)', titleIn);
	const textRow = field('Text (optional, max. 300 Zeichen)', textArea);
	const fileRow = field(`Bild (JPEG, PNG oder WebP, max. ${maxLabel()} MB)`, fileIn);
	const capRow = field('Bildunterschrift (optional, max. 100 Zeichen)', captionIn);

	function sync() {
		const isPostit = type === 'postit';
		titleRow.hidden = textRow.hidden = !isPostit;
		fileRow.hidden = isPostit || editing;
		capRow.hidden = isPostit;
		titleIn.required = isPostit;
		fileIn.required = !isPostit && !editing;
	}
	sync();

	const submit = el('button', { type: 'submit', className: 'korkwand-btn korkwand-btn--primary', text: editing ? 'Speichern' : 'Anheften' });
	const cancel = el('button', { type: 'button', className: 'korkwand-btn', text: 'Abbrechen' });
	cancel.addEventListener('click', () => dlg.close());
	form.append(title, typeBox, titleRow, textRow, fileRow, capRow, error, el('div', { className: 'korkwand-dialog-actions' }, cancel, submit));
	dlg.append(form);

	function showError(msg) {
		error.textContent = msg;
		error.hidden = false;
	}

	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		error.hidden = true;
		const file = fileIn.files && fileIn.files[0] ? fileIn.files[0] : null;
		if (type === 'image' && !editing) {
			if (!file) return showError('Bitte ein Bild auswählen.');
			if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return showError('Nur JPEG, PNG oder WebP sind erlaubt.');
			if (file.size > maxBytes()) return showError(`Das Bild ist zu groß (maximal ${maxLabel()} MB).`);
		}
		const values = type === 'postit'
			? { type, title: titleIn.value.trim(), text: textArea.value, file: null }
			: { type, title: '', text: captionIn.value, file };
		if (type === 'postit' && values.title === '') return showError('Bitte einen Titel eingeben.');
		submit.disabled = true;
		try {
			await onSubmit(values);
			dlg.close();
		} catch (err) {
			showError(err && err.message ? err.message : 'Unbekannter Fehler.');
			submit.disabled = false;
		}
	});

	dlg.addEventListener('close', () => {
		dlg.remove();
		if (trigger && trigger.isConnected) trigger.focus();
	});
	document.body.appendChild(dlg);
	dlg.showModal();
	(type === 'postit' ? titleIn : editing ? captionIn : typeBox.querySelector('input')).focus();
}
