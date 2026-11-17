// REST client for korkwand/v1. Errors: ApiError (message from the server) or SessionExpiredError.
import { t } from 'korkwand-i18n';

export class SessionExpiredError extends Error {
	constructor() {
		super(t('sessionExpired'));
		this.name = 'SessionExpiredError';
	}
}

export class ApiError extends Error {
	constructor(message, code, status) {
		super(message);
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
	}
}

async function request(path, options = {}) {
	const cfg = window.Korkwand || {};
	const headers = { 'X-WP-Nonce': cfg.nonce || '', Accept: 'application/json' };
	if (options.json !== undefined) {
		headers['Content-Type'] = 'application/json';
	}
	let res;
	try {
		res = await fetch(cfg.rest + path, {
			method: options.method || 'GET',
			headers,
			credentials: 'same-origin',
			body: options.json !== undefined ? JSON.stringify(options.json) : options.body,
		});
	} catch (e) {
		throw new ApiError(t('networkError'), 'korkwand_network', 0);
	}
	let data = null;
	try {
		data = await res.json();
	} catch (e) {
		data = null;
	}
	if (!res.ok) {
		const code = data && data.code ? data.code : '';
		if (res.status === 401 || (res.status === 403 && code === 'rest_cookie_invalid_nonce')) {
			throw new SessionExpiredError();
		}
		throw new ApiError((data && data.message) || t('unknownError'), code, res.status);
	}
	return data;
}

export const listPins = (slug) => request(`boards/${encodeURIComponent(slug)}/pins`);

export const createPin = (slug, formData) =>
	request(`boards/${encodeURIComponent(slug)}/pins`, { method: 'POST', body: formData });

export const updatePin = (id, data) => request(`pins/${id}`, { method: 'PATCH', json: data });

export const deletePin = (id) => request(`pins/${id}`, { method: 'DELETE' });
