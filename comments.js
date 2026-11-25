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
		let likeQueue = Promise.resolve();
		const likesInFlight = new Set();

		const api = async (params) => {
			const url = new URL(root.dataset.api);
			Object.entries({action: 'riot_comments_read', post: root.dataset.post, ...params})
				.forEach(([key, value]) => url.searchParams.set(key, value));
			return request(url, {headers: {'X-Riot-Comments': '1'}, signal: AbortSignal.timeout?.(5000)});
		};

		async function request(url, options) {
			let response;
			try {
				response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', referrerPolicy: 'same-origin', ...options});
			} catch {
				// Offline, timed out, blocked, or redirected.
				const error = new Error(labels.network);
				error.network = true;
				error.retryable = true;
				throw error;
			}
			let result = null;
			try {
				result = JSON.parse(await response.text());
			} catch {
				result = null;
			}
			if (!result || typeof result !== 'object' || !('success' in result)) {
				// Something other than Riot answered: a host error page, firewall, or plugin output.
				const error = new Error(labels.error);
				error.status = response.status;
				error.unreadable = true;
				error.retryable = response.ok || response.status >= 500 || response.status === 0;
				throw error;
			}
			if (!response.ok || !result.success) {
				const error = new Error(result.data?.message || labels.error);
				error.status = response.status;
				error.result = result.data;
				error.retryable = Boolean(result.data?.retry);
				throw error;
			}
			return result.data;
		}

		const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
		const relative = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat(document.documentElement.lang || undefined, {numeric: 'auto', style: 'short'}) : null;
		function relativeTimes(scope = root) {
			if (!relative) return;
			const now = Date.now();
			for (const time of scope.querySelectorAll('.riot-byline time[datetime]')) {
				const at = Date.parse(time.getAttribute('datetime'));
				if (Number.isNaN(at)) continue;
				const seconds = Math.round((at - now) / 1000);
				if (seconds < -7 * 86400 || seconds > 60) continue;
				if (!time.dataset.full) time.dataset.full = time.textContent;
				time.title = time.dataset.full;
				const steps = [[60, 'second'], [3600, 'minute', 60], [86400, 'hour', 3600], [Infinity, 'day', 86400]];
				for (const [limit, unit, size = 1] of steps) {
					if (Math.abs(seconds) < limit) {
						time.textContent = Math.abs(seconds) < 45 ? relative.format(0, 'second') : relative.format(Math.round(seconds / size), unit);
						break;
					}
				}
			}
		}
		function enhance(scope = root) {
			relativeTimes(scope);
			if (navigator.clipboard) for (const button of scope.querySelectorAll('.riot-copy-link[hidden]')) button.hidden = false;
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

		let jump = null;
		function setCount(label, total) {
			count.textContent = label;
			if (Number.isFinite(total)) root.dataset.total = String(total);
			if (jump) {
				const value = Number(root.dataset.total) || 0;
				jump.querySelector('.riot-jump-label').textContent = value ? labels.jump : labels.jumpEmpty;
				jump.querySelector('.riot-jump-count').textContent = value ? value.toLocaleString() : '';
			}
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
			enhance(node);
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
			enhance(list);
			setCount(result.count, result.total);
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
				await likeQueue;
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

		function setLike(button) {
			const id = button.dataset.like;
			if (reading || likesInFlight.has(id)) return;
			likesInFlight.add(id);
			button.setAttribute('aria-disabled', 'true');
			const liked = button.getAttribute('aria-pressed') !== 'true';
			// Serialize clicks so the browser retains its guest cookie before a second vote starts.
			likeQueue = likeQueue.then(async () => {
				const body = new FormData();
				for (const [key, value] of Object.entries({
					action: 'riot_comments_like', post: root.dataset.post, comment: id, liked: liked ? '1' : '0'
				})) body.set(key, value);
				try {
					const result = await request(new URL(root.dataset.api), {
						method: 'POST', headers: {'X-Riot-Comments': '1'}, body, signal: AbortSignal.timeout?.(5000)
					});
					button.setAttribute('aria-pressed', String(result.liked));
					button.querySelector('[data-like-count]').textContent = result.total.toLocaleString(document.documentElement.lang || undefined);
					if (listStatus.textContent === labels.likeError) {
						listStatus.textContent = '';
						listStatus.classList.add('riot-sr');
					}
				} catch {
					listStatus.classList.remove('riot-sr');
					listStatus.textContent = labels.likeError;
				} finally {
					likesInFlight.delete(id);
					button.removeAttribute('aria-disabled');
				}
			});
		}

		const isPlainClick = (event) => !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey);
		root.addEventListener('click', (event) => {
			const like = event.target.closest('[data-like]');
			if (like) {
				setLike(like);
				return;
			}
			const copy = event.target.closest('.riot-copy-link');
			if (copy) {
				navigator.clipboard?.writeText(copy.dataset.copy).then(() => {
					const text = copy.querySelector('span');
					text.dataset.label ||= text.textContent;
					text.textContent = labels.copied;
					copy.classList.add('riot-copied');
					setTimeout(() => {
						text.textContent = text.dataset.label;
						copy.classList.remove('riot-copied');
					}, 1800);
				}).catch(() => {});
				return;
			}
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

		enhance(root);

		// A floating shortcut to the discussion for readers deep in the article.
		if (root.dataset.jump === '1' && 'IntersectionObserver' in window) {
			jump = document.createElement('a');
			jump.className = 'riot-jump';
			jump.href = '#comments';
			jump.hidden = true;
			jump.dataset.shape = root.dataset.shape || '';
			jump.style.setProperty('--riot-jump-accent', getComputedStyle(root).getPropertyValue('--riot-accent').trim() || '#ac3237');
			jump.innerHTML = '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 4.5h12a1.5 1.5 0 0 1 1.5 1.5v7a1.5 1.5 0 0 1-1.5 1.5H9l-4 3v-3H4A1.5 1.5 0 0 1 2.5 13V6A1.5 1.5 0 0 1 4 4.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg><span class="riot-jump-label"></span><span class="riot-jump-count"></span>';
			document.body.append(jump);
			setCount(count.textContent);
			let inView = false;
			const update = () => {
				const visible = !inView && scrollY > Math.min(900, innerHeight * 0.9);
				if (visible === !jump.hidden) return;
				jump.hidden = !visible;
			};
			new IntersectionObserver(([entry]) => {
				inView = entry.isIntersecting || entry.boundingClientRect.top < 0;
				update();
			}).observe(root);
			addEventListener('scroll', update, {passive: true});
			jump.addEventListener('click', (event) => {
				event.preventDefault();
				root.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
				const field = root.querySelector('[data-riot-message]');
				if (field) setTimeout(() => field.focus({preventScroll: true}), 450);
			});
		}

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

		// Start the private session only when the reader nears the discussion or starts writing.
		// Most readers never reach the comments, and every session is an uncached request.
		const urgent = /^#(comments|comment-\d+|riot-form)$/.test(location.hash) ||
			['riot_posted', 'riot_focus', 'riot_cursor', 'riot_reply', 'riot_order'].some((key) => initialUrl.searchParams.has(key));
		function whenNear(start) {
			if (urgent || !('IntersectionObserver' in window)) {
				start();
				return;
			}
			const observer = new IntersectionObserver((entries) => {
				if (entries.some((entry) => entry.isIntersecting)) {
					observer.disconnect();
					start();
				}
			}, {rootMargin: '1200px 0px'});
			observer.observe(root);
		}

		if (!form) {
			whenNear(startSession);
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
		const grow = () => {
			message.style.height = 'auto';
			message.style.height = Math.min(message.scrollHeight + 2, innerHeight * 0.6) + 'px';
		};
		message.addEventListener('input', () => {
			draft(message.value);
			grow();
		});
		message.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !event.isComposing) {
				event.preventDefault();
				form.requestSubmit ? form.requestSubmit() : button.click();
			}
		});
		const hint = form.querySelector('.riot-hint');
		if (hint && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) hint.textContent = labels.hintMac;
		if (message.value) requestAnimationFrame(grow);

		// One id per draft: retries of the same text can never create a second comment.
		let attempt = {key: '', id: ''};
		const attemptId = () => {
			const key = parent.value + '|' + message.value;
			if (attempt.key !== key) {
				const random = crypto.randomUUID?.() || Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
				attempt = {key, id: random};
			}
			return attempt.id;
		};

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
					if (data.pending?.length) watchPending();
					return data;
				}).catch(() => { session = null; });
			}
			return session;
		}

		// A held comment becomes public once it is approved. Check back for a few minutes while the page is open.
		const checkDelays = [8, 12, 15, 20, 25, 30, 45, 60, 90].map((seconds) => seconds * 1000);
		let watching = false;
		async function watchPending() {
			if (watching) return;
			watching = true;
			try {
				for (const delay of checkDelays) {
					await sleep(delay);
					while (document.visibilityState === 'hidden') {
						await new Promise((resolve) => document.addEventListener('visibilitychange', resolve, {once: true}));
					}
					if (!pending.size) return;
					let data;
					try {
						data = await api({view: 'receipt', ids: [...pending.keys()].join(',')});
					} catch {
						continue;
					}
					let published = null;
					let closed = false;
					for (const item of data.items) {
						if (item.status === 'pending') continue;
						// Anything else is no longer awaiting approval. Only an approved comment replaces its preview.
						pending.delete(item.id);
						const preview = root.querySelector('#comment-' + item.id + '[data-riot-pending]');
						if (preview) {
							const container = preview.parentElement;
							preview.remove();
							if (!container.children.length && container.classList.contains('riot-children')) container.remove();
						}
						if (item.status !== 'approved') {
							closed = true;
							continue;
						}
						published = insertComment(item);
						if (!item.parent && order === 'oldest' && more.dataset.cursor) published.setAttribute('data-riot-later', '');
					}
					if (published || closed) {
						setCount(data.count, data.total);
						if (!posting && !['submitting', 'failed', 'rejected'].includes(feedback.dataset.status)) {
							const outcome = published ? data.live : data.closed;
							showFeedback(outcome.status, outcome.detail, outcome.title);
						}
					}
				}
			} finally {
				watching = false;
			}
		}

		function moveForm(id, author, focus = true) {
			if (parent.value !== id) {
				draft(message.value);
				parent.value = id;
				message.value = draft() || '';
				grow();
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

		whenNear(() => loadSession(true));
		form.addEventListener('focusin', () => loadSession(true));

		form.addEventListener('submit', async (event) => {
			event.preventDefault();
			if (posting || reading || !message.reportValidity()) return;
			posting = true;
			button.disabled = true;
			try {
				await (session || loadSession(true));
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
				const id = attemptId();
				const send = () => {
					const body = new URLSearchParams(new FormData(form));
					body.set('riot_async', '1');
					body.set('riot_attempt', id);
					return request(form.action, {method: 'POST', redirect: 'error', body});
				};
				let result;
				let refreshed = false;
				let retried = false;
				for (;;) {
					try {
						result = await send();
						break;
					} catch (error) {
						if (error.status === 419 && !refreshed) {
							refreshed = true;
							session = null;
							if (!(await loadSession())) throw error;
							continue;
						}
						// Hosts occasionally fail one request in a row; the same attempt id makes this safe.
						if (error.retryable && !retried) {
							retried = true;
							showFeedback('submitting', labels.retrying);
							await sleep(1500);
							continue;
						}
						throw error;
					}
				}
				if (result.status === 'pending') pending.set(result.id, result);
				const added = insertComment(result);
				if (result.status === 'published' && !result.parent && order === 'oldest' && more.dataset.cursor) {
					added.setAttribute('data-riot-later', '');
				}
				setCount(result.count, result.total);
				message.value = '';
				draft('');
				grow();
				attempt = {key: '', id: ''};
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
				if (result.status === 'pending') watchPending();
			} catch (error) {
				const result = error.result || {};
				let detail = result.detail || error.message;
				if (!error.result) {
					detail = error.network || !error.status ? labels.network : labels.server.replace('%s', () => error.status);
				}
				showFeedback(result.status || 'failed', detail, result.title || (result.status && result.status !== 'failed' ? '' : labels.notPosted), result.reason);
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
