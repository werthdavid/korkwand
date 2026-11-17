// nz-pinnwand Frontend: Skalierung, Drag & Drop, Zettelmenue, Anlegen/Bearbeiten/Loeschen, Lightbox.
// Nutzer-Inhalte werden ausschliesslich per textContent/createElement gerendert (nie innerHTML).
import { scaleFor, toLogical, clamp } from 'nzp-geometry';
import { listPins, createPin, updatePin, deletePin, SessionExpiredError } from 'nzp-api';
import { openPinDialog } from 'nzp-dialog';
import { openLightbox } from 'nzp-lightbox';

const LIST_BREAKPOINT = 480;
const DRAG_THRESHOLD = 4;
const LONG_PRESS_MS = 300;

/* ------------------------------ Rendering ------------------------------ */

function renderPin(p, loggedIn) {
	const isImage = p.type === 'image' && p.image;
	const a = document.createElement('article');
	a.className = 'nzp-pin nzp-pin--' + (isImage ? 'image' : 'postit') + (p.sticky ? ' nzp-pin--sticky' : '');
	a.dataset.id = String(p.id);
	a.dataset.x = String(p.x);
	a.dataset.y = String(p.y);
	a.dataset.canEdit = p.can_edit ? '1' : '0';
	a.dataset.sticky = p.sticky ? '1' : '0';
	a.style.setProperty('--x', p.x + 'px');
	a.style.setProperty('--y', p.y + 'px');
	a.style.setProperty('--r', (p.rotation || 0) + 'deg');

	if (isImage) {
		const link = document.createElement('a');
		link.className = 'nzp-lightbox';
		link.href = p.image.full;
		const img = document.createElement('img');
		img.src = p.image.thumb;
		img.width = p.image.width;
		img.height = p.image.height;
		img.alt = p.text || '';
		link.appendChild(img);
		a.appendChild(link);
		if (p.text) {
			const fc = document.createElement('figcaption');
			fc.textContent = p.text;
			fc.style.whiteSpace = 'pre-line';
			a.appendChild(fc);
		}
	} else {
		const h = document.createElement('h3');
		h.textContent = p.title;
		a.appendChild(h);
		if (p.text) {
			const t = document.createElement('p');
			t.textContent = p.text;
			t.style.whiteSpace = 'pre-line';
			a.appendChild(t);
		}
	}
	if (loggedIn && p.author) {
		const au = document.createElement('span');
		au.className = 'nzp-author';
		au.textContent = p.author;
		a.appendChild(au);
	}
	if (p.can_edit) addMenuButton(a);
	return a;
}

function addMenuButton(article) {
	if (article.querySelector(':scope > .nzp-menu-btn')) return;
	const b = document.createElement('button');
	b.type = 'button';
	b.className = 'nzp-menu-btn';
	b.setAttribute('aria-label', 'Zettel-Menü öffnen');
	b.setAttribute('aria-haspopup', 'true');
	b.textContent = '⋯';
	article.appendChild(b);
}

/* ------------------------------ Board-Klasse ------------------------------ */

class Board {
	constructor(root) {
		this.root = root;
		this.slug = root.dataset.board;
		this.height = parseInt(root.dataset.height, 10) || 700;
		this.canPost = root.dataset.canPost === '1';
		this.board = root.querySelector('.nzp-board');
		this.canvas = root.querySelector('.nzp-canvas');
		this.scale = 1;
		this.loggedIn = this.canPost || !!root.querySelector('.nzp-author');
		this.drag = null;
		this.suppressClickUntil = 0;
		this.noticeTimer = 0;

		this.layout();
		new ResizeObserver(() => this.layout()).observe(root);

		this.canvas.querySelectorAll('.nzp-pin[data-can-edit="1"]').forEach(addMenuButton);

		const add = root.querySelector('.nzp-add');
		if (add) add.addEventListener('click', () => this.openCreate(add));

		root.addEventListener('click', (e) => this.onClick(e), true);
		root.addEventListener('dragstart', (e) => e.preventDefault());
		root.addEventListener('pointerdown', (e) => this.onPointerDown(e));
		// Permanente, nicht-passive Touch-Listener: der Browser entscheidet beim touchstart, ob die Geste
		// abbrechbar ist. Nur waehrend eines aktiven Long-Press-Drags wird das Scrollen unterbunden.
		root.addEventListener('touchstart', () => {}, { passive: false });
		root.addEventListener('touchmove', (e) => {
			if (this.drag && this.drag.active && e.cancelable) e.preventDefault();
		}, { passive: false });
	}

	get inList() {
		return this.root.clientWidth < LIST_BREAKPOINT;
	}

	layout() {
		const list = this.inList;
		// Skalierte Ansicht: kein Scrollen (overflow:hidden per CSS). Klasse vor dem Messen setzen,
		// damit clientWidth keine Scrollbar abzieht.
		this.root.classList.toggle('nzp--scaled', !list);
		if (list) {
			this.scale = 1;
			this.root.style.removeProperty('--nzp-scale');
			this.board.style.height = '';
			return;
		}
		this.scale = scaleFor(this.board.clientWidth);
		this.root.style.setProperty('--nzp-scale', String(this.scale));
		const cs = getComputedStyle(this.board);
		const extra = cs.boxSizing === 'border-box'
			? parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth)
			: 0;
		this.board.style.height = Math.ceil(this.height * this.scale + extra) + 'px';
	}

	/* ------------------------------ Hinweise ------------------------------ */

	notice(msg, persistent = false) {
		let n = this.root.querySelector('.nzp-notice');
		if (!n) {
			n = document.createElement('div');
			n.className = 'nzp-notice';
			n.setAttribute('role', 'alert');
			this.root.querySelector('.nzp-toolbar').after(n);
		}
		n.textContent = msg;
		clearTimeout(this.noticeTimer);
		if (!persistent) this.noticeTimer = setTimeout(() => n.remove(), 8000);
	}

	fail(err) {
		this.notice(err instanceof SessionExpiredError ? err.message : (err && err.message) || 'Unbekannter Fehler.', err instanceof SessionExpiredError);
	}

	/* ------------------------------ Klicks ------------------------------ */

	onClick(e) {
		if (Date.now() < this.suppressClickUntil) {
			e.preventDefault();
			e.stopPropagation();
			return;
		}
		const menuBtn = e.target.closest('.nzp-menu-btn');
		if (menuBtn) {
			e.preventDefault();
			this.openMenu(menuBtn);
			return;
		}
		const link = e.target.closest('a.nzp-lightbox');
		if (link && this.canvas.contains(link)) {
			e.preventDefault();
			const cap = link.closest('.nzp-pin').querySelector('figcaption');
			openLightbox(link.href, cap ? cap.textContent : '', link);
		}
	}

	/* ------------------------------ Drag & Drop ------------------------------ */

	onPointerDown(e) {
		if (this.drag || this.inList) return;
		const pin = e.target.closest('.nzp-pin[data-can-edit="1"]');
		if (!pin || e.target.closest('.nzp-menu-btn') || !this.canvas.contains(pin)) return;
		if (e.pointerType === 'mouse' && e.button !== 0) return;

		const d = {
			pin,
			pointerId: e.pointerId,
			touch: e.pointerType !== 'mouse',
			sx: e.clientX,
			sy: e.clientY,
			cx: e.clientX,
			cy: e.clientY,
			ox: parseInt(pin.dataset.x, 10) || 0,
			oy: parseInt(pin.dataset.y, 10) || 0,
			x: 0,
			y: 0,
			active: false,
			timer: 0,
		};
		d.x = d.ox;
		d.y = d.oy;
		this.drag = d;

		d.onMove = (ev) => this.onPointerMove(ev);
		d.onUp = (ev) => this.onPointerUp(ev, false);
		d.onCancel = (ev) => this.onPointerUp(ev, true);
		d.onContext = (ev) => { if (d.touch) ev.preventDefault(); };
		document.addEventListener('pointermove', d.onMove);
		document.addEventListener('pointerup', d.onUp);
		document.addEventListener('pointercancel', d.onCancel);
		document.addEventListener('contextmenu', d.onContext);

		if (d.touch) {
			d.timer = setTimeout(() => this.activate(), LONG_PRESS_MS);
		}
	}

	activate() {
		const d = this.drag;
		if (!d || d.active) return;
		d.active = true;
		d.pin.classList.add('nzp-pin--dragging');
		try { d.pin.setPointerCapture(d.pointerId); } catch (err) { /* Pointer evtl. schon weg */ }
		if (d.touch && navigator.vibrate) navigator.vibrate(10);
	}

	onPointerMove(e) {
		const d = this.drag;
		if (!d || e.pointerId !== d.pointerId) return;
		d.cx = e.clientX;
		d.cy = e.clientY;
		const moved = Math.hypot(d.cx - d.sx, d.cy - d.sy);
		if (!d.active) {
			if (moved <= DRAG_THRESHOLD) return;
			if (d.touch) {
				// Vor Ablauf des Long-Press: Nutzer scrollt -> Drag abbrechen.
				this.endDrag();
				return;
			}
			this.activate();
		}
		e.preventDefault();
		const { dx, dy } = toLogical(d.cx - d.sx, d.cy - d.sy, this.scale);
		const pos = clamp(d.ox + dx, d.oy + dy, this.height);
		d.x = pos.x;
		d.y = pos.y;
		d.pin.style.setProperty('--x', pos.x + 'px');
		d.pin.style.setProperty('--y', pos.y + 'px');
	}

	endDrag() {
		const d = this.drag;
		if (!d) return null;
		clearTimeout(d.timer);
		document.removeEventListener('pointermove', d.onMove);
		document.removeEventListener('pointerup', d.onUp);
		document.removeEventListener('pointercancel', d.onCancel);
		document.removeEventListener('contextmenu', d.onContext);
		d.pin.classList.remove('nzp-pin--dragging');
		try { d.pin.releasePointerCapture(d.pointerId); } catch (err) { /* ignore */ }
		this.drag = null;
		return d;
	}

	setPos(pin, x, y) {
		pin.dataset.x = String(x);
		pin.dataset.y = String(y);
		pin.style.setProperty('--x', x + 'px');
		pin.style.setProperty('--y', y + 'px');
	}

	async onPointerUp(e, cancelled) {
		const d = this.drag;
		if (!d || e.pointerId !== d.pointerId) return;
		const wasActive = d.active;
		this.endDrag();
		if (!wasActive) return;
		this.suppressClickUntil = Date.now() + 80; // Klick nach dem Ziehen nicht auslösen (z.B. Lightbox-Link)
		if (cancelled || (d.x === d.ox && d.y === d.oy)) {
			this.setPos(d.pin, d.ox, d.oy);
			return;
		}
		this.setPos(d.pin, d.x, d.y);
		try {
			const p = await updatePin(d.pin.dataset.id, { x: d.x, y: d.y });
			this.setPos(d.pin, p.x, p.y);
		} catch (err) {
			this.setPos(d.pin, d.ox, d.oy);
			this.fail(err);
		}
	}

	/* ------------------------------ Menue ------------------------------ */

	closeMenu() {
		if (!this.menu) return;
		const { el, btn, off } = this.menu;
		off();
		el.remove();
		this.menu = null;
		if (btn.isConnected) btn.focus();
	}

	async openMenu(btn) {
		if (this.menu) {
			const same = this.menu.btn === btn;
			this.closeMenu();
			if (same) return;
		}
		const article = btn.closest('.nzp-pin');
		const id = article.dataset.id;
		let pin;
		try {
			pin = await this.fetchPin(id);
		} catch (err) {
			return this.fail(err);
		}
		if (!pin) return this.notice('Dieser Zettel existiert nicht mehr.');

		const menu = document.createElement('div');
		menu.className = 'nzp-menu';
		menu.setAttribute('role', 'menu');
		const items = [
			['Bearbeiten', () => this.openEdit(pin, btn)],
			['Löschen', () => this.remove(pin)],
		];
		if (pin.can_moderate) {
			items.push([pin.sticky ? 'Sticky entfernen' : 'Sticky', () => this.toggleSticky(pin)]);
		}
		for (const [label, fn] of items) {
			const b = document.createElement('button');
			b.type = 'button';
			b.setAttribute('role', 'menuitem');
			b.textContent = label;
			b.addEventListener('click', () => { this.closeMenu(); fn(); });
			menu.appendChild(b);
		}
		document.body.appendChild(menu);
		const r = btn.getBoundingClientRect();
		menu.style.top = r.bottom + window.scrollY + 4 + 'px';
		menu.style.left = Math.max(8, Math.min(r.right + window.scrollX - menu.offsetWidth, document.documentElement.clientWidth - menu.offsetWidth - 8)) + 'px';

		const onDoc = (ev) => { if (!menu.contains(ev.target) && ev.target !== btn) this.closeMenu(); };
		const onKey = (ev) => {
			if (ev.key === 'Escape') { ev.preventDefault(); this.closeMenu(); return; }
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				const bs = [...menu.querySelectorAll('button')];
				const i = bs.indexOf(document.activeElement);
				bs[(i + (ev.key === 'ArrowDown' ? 1 : bs.length - 1)) % bs.length].focus();
			}
		};
		const onClose = () => this.closeMenu();
		document.addEventListener('pointerdown', onDoc, true);
		document.addEventListener('keydown', onKey);
		window.addEventListener('resize', onClose);
		window.addEventListener('scroll', onClose, { once: true });
		const off = () => {
			document.removeEventListener('pointerdown', onDoc, true);
			document.removeEventListener('keydown', onKey);
			window.removeEventListener('resize', onClose);
			window.removeEventListener('scroll', onClose);
		};
		this.menu = { el: menu, btn, off };
		menu.querySelector('button').focus();
	}

	async fetchPin(id) {
		const data = await listPins(this.slug);
		return data.pins.find((p) => String(p.id) === String(id)) || null;
	}

	/* ------------------------------ Aktionen ------------------------------ */

	replaceArticle(p) {
		const old = this.canvas.querySelector(`.nzp-pin[data-id="${p.id}"]`);
		const fresh = renderPin(p, this.loggedIn);
		if (old) old.replaceWith(fresh);
		else this.canvas.appendChild(fresh);
		return fresh;
	}

	openCreate(trigger) {
		openPinDialog({
			trigger,
			onSubmit: async (v) => {
				const fd = new FormData();
				fd.append('type', v.type);
				if (v.type === 'postit') fd.append('title', v.title);
				fd.append('text', v.text);
				const pos = clamp(20 + Math.random() * 560, 30 + Math.random() * Math.max(0, this.height - 330), this.height);
				fd.append('x', String(pos.x));
				fd.append('y', String(pos.y));
				if (v.type === 'image' && v.file) fd.append('image', v.file);
				const p = await createPin(this.slug, fd);
				const art = this.replaceArticle(p);
				if (this.inList) art.scrollIntoView({ block: 'nearest' });
			},
		});
	}

	openEdit(pin, trigger) {
		openPinDialog({
			pin,
			trigger,
			onSubmit: async (v) => {
				const body = pin.type === 'image' ? { text: v.text } : { title: v.title, text: v.text };
				const p = await updatePin(pin.id, body);
				this.replaceArticle(p);
			},
		});
	}

	async remove(pin) {
		if (!window.confirm('Diesen Zettel wirklich löschen?')) return;
		try {
			await deletePin(pin.id);
			const art = this.canvas.querySelector(`.nzp-pin[data-id="${pin.id}"]`);
			if (art) art.remove();
		} catch (err) {
			this.fail(err);
		}
	}

	async toggleSticky(pin) {
		try {
			this.replaceArticle(await updatePin(pin.id, { sticky: !pin.sticky }));
		} catch (err) {
			this.fail(err);
		}
	}
}

function init() {
	document.querySelectorAll('.nzp').forEach((root) => {
		if (root.dataset.nzpInit) return;
		root.dataset.nzpInit = '1';
		new Board(root);
	});
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
