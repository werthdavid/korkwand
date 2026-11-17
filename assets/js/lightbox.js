// Lightbox: <dialog> mit grossem Bild; Esc oder Klick schliesst.
let dlg = null;
let lastTrigger = null;

function ensure() {
	if (dlg) return dlg;
	dlg = document.createElement('dialog');
	dlg.className = 'nzp-lightbox-dialog';
	dlg.setAttribute('aria-label', 'Bildansicht');
	Object.assign(dlg.style, { padding: '0', border: '0', background: 'transparent', maxWidth: '96vw', maxHeight: '96vh', overflow: 'visible' });

	const img = document.createElement('img');
	Object.assign(img.style, { display: 'block', maxWidth: '96vw', maxHeight: '90vh', margin: '0 auto', cursor: 'zoom-out' });
	const cap = document.createElement('p');
	Object.assign(cap.style, { margin: '.5em 0 0', color: '#fff', textAlign: 'center' });
	const close = document.createElement('button');
	close.type = 'button';
	close.textContent = '×';
	close.setAttribute('aria-label', 'Schließen');
	Object.assign(close.style, { position: 'absolute', top: '-8px', right: '-8px', width: '36px', height: '36px', borderRadius: '50%', border: '0', fontSize: '22px', cursor: 'pointer' });
	dlg.append(img, cap, close);

	// Klick irgendwo (Bild, Hintergrund, X) schliesst.
	dlg.addEventListener('click', () => dlg.close());
	dlg.addEventListener('close', () => {
		img.removeAttribute('src');
		if (lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
	});
	document.body.appendChild(dlg);
	return dlg;
}

export function openLightbox(href, caption, trigger) {
	const d = ensure();
	lastTrigger = trigger || null;
	const img = d.querySelector('img');
	img.src = href;
	img.alt = caption || '';
	d.querySelector('p').textContent = caption || '';
	d.showModal();
	d.querySelector('button').focus();
}
