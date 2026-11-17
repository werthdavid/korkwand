// Create/edit dialog (<dialog>, showModal). Builds the DOM with createElement/textContent only.
import { t, maxLabel } from 'korkwand-i18n';

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

// Effective upload limit in bytes (from the server: min(8 MB, host limit)).
function maxBytes() {
	const n = Number(window.Korkwand && window.Korkwand.maxBytes);
	return n > 0 ? Math.min(n, DEFAULT_MAX_BYTES) : DEFAULT_MAX_BYTES;
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
	const title = el('h2', { id: 'korkwand-dlg-title', text: editing ? t('editNote') : t('newNote') });
	const error = el('p', { className: 'korkwand-dialog-error', role: 'alert' });
	error.hidden = true;

	let type = editing ? pin.type : 'postit';

	// Typwahl (nur beim Anlegen)
	const typeBox = el('fieldset', { className: 'korkwand-type' }, el('legend', { text: t('noteType') }));
	for (const [val, label] of [['postit', t('postit')], ['image', t('image')]]) {
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

	const titleRow = field(t('titleLabel'), titleIn);
	const textRow = field(t('textLabel'), textArea);
	const fileRow = field(t('imageLabel', maxLabel()), fileIn);
	const capRow = field(t('captionLabel'), captionIn);

	function sync() {
		const isPostit = type === 'postit';
		titleRow.hidden = textRow.hidden = !isPostit;
		fileRow.hidden = isPostit || editing;
		capRow.hidden = isPostit;
		titleIn.required = isPostit;
		fileIn.required = !isPostit && !editing;
	}
	sync();

	const submit = el('button', { type: 'submit', className: 'korkwand-btn korkwand-btn--primary', text: editing ? t('save') : t('pin') });
	const cancel = el('button', { type: 'button', className: 'korkwand-btn', text: t('cancel') });
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
			if (!file) return showError(t('chooseImage'));
			if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return showError(t('imageTypeOnly'));
			if (file.size > maxBytes()) return showError(t('imageTooLarge', maxLabel()));
		}
		const values = type === 'postit'
			? { type, title: titleIn.value.trim(), text: textArea.value, file: null }
			: { type, title: '', text: captionIn.value, file };
		if (type === 'postit' && values.title === '') return showError(t('enterTitle'));
		submit.disabled = true;
		try {
			await onSubmit(values);
			dlg.close();
		} catch (err) {
			showError(err && err.message ? err.message : t('unknownError'));
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
