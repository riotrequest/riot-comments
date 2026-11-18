(() => {
	for (const root of document.querySelectorAll('[data-riot-discussion]')) {
		const labels = JSON.parse(root.dataset.labels);
		const list = root.querySelector('.riot-roots');
		const more = root.querySelector('.riot-more');
		const count = root.querySelector('.riot-count');
		const toolbar = root.querySelector('.riot-toolbar');
		const empty = root.querySelector('.riot-empty');
		const listStatus = root.querySelector('.riot-list-status');
		const privateSection = root.querySelector('.riot-private');
		const privateList = privateSection.querySelector('ol');
		const form = root.querySelector('.riot-form');
		const composer = root.querySelector('.riot-composer');
		let order = root.dataset.order;
		let reading = false;
		let posting = false;
		let readRequested = false;
		let pending = new Map();

		const api = async (params) => {
			const url = new URL(root.dataset.api);
			Object.entries({action: 'riot_comments_read', post: root.dataset.post, ...params})
				.forEach(([key, value]) => url.searchParams.set(key, value));
			return request(url, {headers: {'X-Riot-Comments': '1'}, signal: AbortSignal.timeout?.(5000)});
		};

		async function request(url, options) {
			const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'same-origin', ...options});
			let result;
			try {
				result = await response.json();
			} catch {
				throw new Error(labels.error);
			}
			if (!response.ok || !result.success) {
				const error = new Error(result.data?.message || labels.error);
				error.status = response.status;
				error.result = result.data;
				throw error;
			}
			return result.data;
		}

		function parseItems(html) {
			const template = document.createElement('template');
			template.innerHTML = html;
			return [...template.content.children];
		}

		function append(target, html, before = null) {
			for (const child of parseItems(html)) {
				if (child.id && list.querySelector('#' + child.id)) continue;
				if (before) before.before(child);
				else target.append(child);
			}
		}

		function setCount(label) {
			count.textContent = label;
			empty.hidden = list.children.length > 0 || privateList.children.length > 0;
			toolbar.hidden = !list.querySelector(':scope > li:not([data-riot-pending])');
			privateSection.hidden = privateList.children.length === 0;
		}

		function insertComment(item) {
			const existing = list.querySelector('#comment-' + item.id);
			if (existing) return existing;
			const [node] = parseItems(item.html);
			const parentNode = item.parent ? list.querySelector('#comment-' + item.parent) : null;
			let target = list;
			if (parentNode) {
				target = parentNode.querySelector(':scope > .riot-children');
				if (!target) {
					target = document.createElement('ol');
					target.className = 'riot-comments-list riot-children';
					parentNode.append(target);
				}
			} else if (item.parent) {
				target = privateList;
			}
			const direction = target === list && order === 'newest' ? -1 : 1;
			const before = [...target.children].find((child) => {
				const dates = node.dataset.commentDate.localeCompare(child.dataset.commentDate);
				return direction * (dates || Number(item.id) - Number(child.dataset.commentId)) < 0;
			});
			target.insertBefore(node, before || null);
			return node;
		}

		function clearPreviews() {
			for (const node of root.querySelectorAll('[data-riot-pending]')) {
				const container = node.parentElement;
				node.remove();
				if (!container.children.length && container.classList.contains('riot-children')) container.remove();
			}
		}

		function updatePreviews(items) {
			pending = new Map(items.map((item) => [item.id, item]));
			clearPreviews();
			for (const item of pending.values()) insertComment(item);
		}

		function applyPage(result, sort, cursor, initial = false) {
			clearPreviews();
			const replace = initial || !cursor;
			let reply = '';
			const restoreFocus = form?.contains(document.activeElement);
			if (replace && (!initial || list.innerHTML.trim() !== result.html.trim())) {
				reply = form?.elements.comment_parent.value || '0';
				if (reply !== '0') composer.append(form);
				list.replaceChildren();
				if (!initial) root.querySelector('.riot-all')?.remove();
			}
			const later = !replace ? list.querySelector(':scope > [data-riot-later]') : null;
			const previousCount = later ? [...list.children].indexOf(later) : list.children.length;
			append(list, result.html, later);
			order = sort;
			if (reply && reply !== '0') {
				const link = list.querySelector('[data-reply="' + reply + '"]');
				moveForm(link ? reply : '0', link?.dataset.author || '', false);
				if (restoreFocus) form.querySelector('[data-riot-message]').focus({preventScroll: true});
			}
			const first = replace ? null : list.children[previousCount]?.querySelector('time')?.parentElement;
			updatePreviews(result.pending || [...pending.values()]);
			setCount(result.count);
			const next = new URL(root.dataset.url);
			next.searchParams.set('riot_order', order);
			next.searchParams.set('riot_cursor', result.cursor);
			next.hash = 'comments';
			more.href = next.href;
			more.hidden = !result.cursor;
			more.dataset.cursor = result.cursor;
			for (const link of root.querySelectorAll('[data-sort]')) {
				if (link.dataset.sort === order) link.setAttribute('aria-current', 'true');
				else link.removeAttribute('aria-current');
			}
			first?.focus();
		}

		async function read(sort, cursor) {
			if (reading || posting) return;
			readRequested = true;
			reading = true;
			list.setAttribute('aria-busy', 'true');
			listStatus.classList.remove('riot-sr');
			listStatus.textContent = labels.loading;
			try {
				const result = await api({riot_order: sort, riot_cursor: cursor});
				applyPage(result, sort, cursor);
				if (!cursor) {
					const url = new URL(root.dataset.url);
					url.searchParams.set('riot_order', sort);
					url.hash = location.hash;
					history.replaceState(history.state, '', url);
				}
				listStatus.textContent = labels.loaded;
				listStatus.classList.add('riot-sr');
			} catch (error) {
				listStatus.textContent = error.message;
			} finally {
				reading = false;
				list.removeAttribute('aria-busy');
			}
		}

		const isPlainClick = (event) => !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
		root.addEventListener('click', (event) => {
			const link = event.target.closest('a');
			if (!link || !isPlainClick(event)) return;
			if (link.dataset.sort || link === more) {
				event.preventDefault();
				read(link.dataset.sort || order, link === more ? more.dataset.cursor : '');
			} else if (form && link.dataset.reply) {
				event.preventDefault();
				if (!posting && !reading) moveForm(link.dataset.reply, link.dataset.author);
			} else if (form && link.hasAttribute('data-cancel')) {
				event.preventDefault();
				if (!posting) moveForm('0', '');
			}
		});

		const initialUrl = new URL(location.href);
		const initialCursor = initialUrl.searchParams.get('riot_cursor') || '';
		const initialOrder = order;
		function startSession() {
			return api({
				view: 'session',
				initial: '1',
				riot_order: initialOrder,
				riot_cursor: initialCursor,
				riot_focus: initialUrl.searchParams.get('riot_focus') || '',
			}).then((data) => {
				if (!readRequested) {
					applyPage({...data.page, pending: data.pending}, initialOrder, initialCursor, true);
					if (!data.pending.length && initialUrl.searchParams.get('riot_posted') === 'held') {
						root.querySelector('.riot-receipt')?.remove();
					}
				}
				return data;
			}).catch(() => null);
		}

		if (!form) {
			startSession();
			continue;
		}
		const message = form.querySelector('[data-riot-message]');
		if (!message) continue;
		const identity = form.querySelector('.riot-identity');
		const feedback = form.querySelector('.riot-feedback');
		const feedbackTitle = feedback.querySelector('strong');
		const feedbackDetail = feedback.querySelector('.riot-feedback-text span');
		const button = form.querySelector('[type="submit"]');
		const context = form.querySelector('.riot-reply-context');
		const parent = form.elements.comment_parent;
		const token = form.elements.riot_token;
		const consent = form.elements['wp-comment-cookies-consent'];
		const summary = form.querySelector('.riot-identity-summary');
		let session;
		let revealed = false;
		let identityKnown = false;
		form.noValidate = true;
		if (identity) identity.hidden = true;

		function showFeedback(status = '', detail = '', title = '', reason = '') {
			feedback.dataset.status = status;
			feedback.dataset.reason = reason;
			feedback.classList.remove('riot-sr');
			feedbackTitle.textContent = title;
			feedbackDetail.textContent = title && detail ? ' ' + detail : detail;
		}

		function draft(value) {
			try {
				const key = 'riot-draft:' + root.dataset.post + ':' + parent.value;
				if (value === undefined) return sessionStorage.getItem(key) || '';
				if (value) sessionStorage.setItem(key, value);
				else sessionStorage.removeItem(key);
			} catch {
				return '';
			}
		}

		if (!message.value) message.value = draft() || '';
		message.addEventListener('input', () => draft(message.value));

		function updateIdentity() {
			if (!identity) return;
			identity.hidden = !revealed;
			summary.hidden = !identityKnown || revealed;
			summary.querySelector('[data-riot-posting-as]').textContent = labels.identity.replace('%s', () => form.elements.author.value);
		}

		function revealIdentity() {
			revealed = true;
			updateIdentity();
			form.elements.author.focus();
		}

		function loadSession(initial = false) {
			if (!session) {
				session = (initial ? startSession() : api({view: 'session'})).then((data) => {
					if (!data) {
						session = null;
						return null;
					}
					token.value = data.token;
					if (identity && initial && !revealed && data.name && data.email) {
						form.elements.author.value = data.name;
						form.elements.email.value = data.email;
						identityKnown = form.elements.email.validity.valid;
						consent.checked = identityKnown;
						updateIdentity();
					}
					return data;
				}).catch(() => { session = null; });
			}
			return session;
		}

		function moveForm(id, author, focus = true) {
			if (parent.value !== id) {
				draft(message.value);
				parent.value = id;
				message.value = draft() || '';
			}
			const replyTo = id !== '0' && list.querySelector('#comment-' + id + ' > article');
			if (replyTo) replyTo.after(form);
			else composer.append(form);
			context.hidden = id === '0';
			context.firstElementChild.textContent = id === '0' ? '' : labels.reply.replace('%s', () => author);
			button.textContent = id === '0' ? labels.post : labels.respond;
			showFeedback();
			if (focus) message.focus();
		}

		if (parent.value !== '0') {
			const link = list.querySelector('[data-reply="' + parent.value + '"]');
			if (link) moveForm(parent.value, link.dataset.author, false);
		}

		if (identity) {
			summary.querySelector('[data-riot-edit]').addEventListener('click', revealIdentity);
			const email = form.elements.email;
			const typo = form.querySelector('[data-typo]');
			const aliases = {'gmial.com': 'gmail.com', 'gamil.com': 'gmail.com', 'gmail.con': 'gmail.com', 'hotmial.com': 'hotmail.com', 'yahoo.con': 'yahoo.com', 'outlook.con': 'outlook.com'};
			email.addEventListener('input', () => {
				const at = email.value.lastIndexOf('@');
				const domain = at > 0 ? email.value.slice(at + 1).toLowerCase() : '';
				const correction = Object.hasOwn(aliases, domain) ? aliases[domain] : '';
				typo.hidden = !correction;
				if (correction) {
					typo.dataset.value = email.value.slice(0, at + 1) + correction;
					typo.textContent = labels.typo.replace('%s', () => correction);
				}
			});
			typo.addEventListener('click', () => {
				email.value = typo.dataset.value;
				typo.hidden = true;
				email.focus();
			});
		}

		loadSession(true);

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (posting || reading || !message.reportValidity()) return;
			posting = true;
			button.disabled = true;
			try {
				await session;
				if (identity && !revealed && !identityKnown) {
					revealIdentity();
					return;
				}
				if (!form.checkValidity()) {
					if (identity && !revealed) revealIdentity();
					form.reportValidity();
					return;
				}
				showFeedback('submitting', labels.posting);
				form.setAttribute('aria-busy', 'true');
				const send = () => {
					const body = new URLSearchParams(new FormData(form));
					body.set('riot_async', '1');
					return request(form.action, {method: 'POST', redirect: 'error', body});
				};
				let result;
				try {
					result = await send();
				} catch (error) {
					if (error.status !== 419) throw error;
					session = null;
					if (!(await loadSession())) throw error;
					result = await send();
				}
				if (result.status === 'pending') pending.set(result.id, result);
				const added = insertComment(result);
				if (result.status === 'published' && !result.parent && order === 'oldest' && more.dataset.cursor) {
					added.setAttribute('data-riot-later', '');
				}
				setCount(result.count);
				message.value = '';
				draft('');
				moveForm('0', '', false);
				if (identity) {
					identityKnown = Boolean(form.elements.author.value.trim() && form.elements.email.value);
					revealed = false;
					updateIdentity();
				}
				showFeedback(result.status, result.detail, result.title, result.reason);
				if (result.parent) {
					const inlineFeedback = feedback.cloneNode(true);
					inlineFeedback.removeAttribute('role');
					inlineFeedback.removeAttribute('aria-live');
					inlineFeedback.removeAttribute('aria-atomic');
					inlineFeedback.setAttribute('aria-hidden', 'true');
					added.firstElementChild.after(inlineFeedback);
					feedback.classList.add('riot-sr');
				}
				added.firstElementChild.focus({preventScroll: true});
				added.scrollIntoView({block: 'nearest'});
			} catch (error) {
				const result = error.result || {};
				showFeedback(result.status || 'failed', result.detail || (error.status ? error.message : labels.submitError), result.title, result.reason);
				if (identity && result.status === 'rejected' && result.reason === 'disposable_email') {
					revealed = true;
					updateIdentity();
					form.elements.email.focus();
				}
			} finally {
				posting = false;
				button.disabled = false;
				form.removeAttribute('aria-busy');
			}
		});
	}
})();
