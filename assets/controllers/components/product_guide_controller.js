import { Controller } from '@hotwired/stimulus';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex="0"]';

export default class extends Controller {
  static values = { state: Object, available: Boolean, userId: Number, route: String, endpoint: String, csrf: String, messages: Object, urls: Object };

  connect() {
    this.portal = this.element.querySelector('[data-guide-portal]');
    this.card = this.portal.querySelector('[data-guide-card]');
    this.state = this.stateValue;
    this.queue = Promise.resolve();
    this.alive = true;
    this.isOpen = false;
    this.finished = false;
    this.minimized = false;
    this.busy = false;
    this.dismissed = false;
    this.supportsPopover = typeof this.portal.showPopover === 'function';
    if (this.supportsPopover) this.portal.setAttribute('popover', 'manual');
    this.listeners = new AbortController();
    const options = { signal: this.listeners.signal };
    this.screenQuery = window.matchMedia('(min-width: 1024px)');
    this.screenQuery.addEventListener('change', () => this.screenQuery.matches ? this.start() : this.close(), options);
    this.portal.addEventListener('click', event => this.handleClick(event), options);
    document.addEventListener('keydown', event => this.handleKey(event), options);
    document.addEventListener('focusin', event => this.handleFocus(event), options);
    window.addEventListener('resize', () => this.scheduleLayout(), options);
    window.addEventListener('scroll', () => this.scheduleLayout(), { ...options, capture: true, passive: true });
    window.addEventListener('mykeynest:credential-launched', () => this.milestone(2), options);
    window.addEventListener('mykeynest:extension-mode-saved', () => this.milestone(4), options);
    document.addEventListener('turbo:before-cache', () => this.close(), options);
    this.observer = new MutationObserver(mutations => {
      if (this.isOpen && mutations.some(mutation => !this.portal.contains(mutation.target))) this.scheduleRender();
    });
    this.observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['open', 'hidden', 'class'] });

    this.start();
  }

  start() {
    if (!this.screenQuery.matches || this.isOpen) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('guide') === '1') {
      url.searchParams.delete('guide');
      window.history.replaceState(window.history.state, '', url);
      this.rememberSkipped(false);
      this.change('restart', 1);
    } else if (this.availableValue && ['pending', 'active'].includes(this.state?.status) && !this.wasSkipped()) {
      this.open();
      if (this.state.status === 'pending') this.change('progress', this.state.step);
    }
  }

  disconnect() {
    this.alive = false;
    this.listeners.abort();
    this.observer.disconnect();
    window.cancelAnimationFrame(this.layoutFrame);
    window.cancelAnimationFrame(this.renderFrame);
    this.close();
    this.portal.remove();
  }

  async persist(action, step) {
    const save = async () => {
      const response = await fetch(this.endpointValue, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfValue },
        body: JSON.stringify({ action, step }),
      });
      if (!response.ok) throw new Error('Guide progress could not be saved');
      const data = await response.json();
      if (!data.state || !Number.isInteger(data.state.step)) throw new Error('Invalid guide state');
      this.state = data.state;
      return data.state;
    };
    this.queue = this.queue.catch(() => {}).then(save);
    return this.queue;
  }

  async change(action, step) {
    if (this.busy) return;
    if (action === 'restart') this.dismissed = false;
    this.busy = true;
    this.showError(false);
    this.updateBusy();
    try {
      const state = await this.persist(action, step);
      if (!this.alive || this.dismissed || !this.screenQuery.matches) return;
      if (state.status === 'skipped') return this.close();
      this.finished = state.status === 'completed';
      this.minimized = false;
      this.open();
    } catch {
      if (this.alive && !this.dismissed && this.screenQuery.matches) {
        if (!this.isOpen) {
          this.state ||= { status: 'active', step: 1 };
          this.open();
        }
        this.showError(true);
      }
    } finally {
      this.busy = false;
      if (this.alive) this.updateBusy();
    }
  }

  open() {
    if (!this.screenQuery.matches) return;
    if (!this.isOpen) this.previousFocus = document.activeElement;
    this.isOpen = true;
    this.portal.hidden = false;
    this.render();
    window.requestAnimationFrame(() => {
      if (this.isOpen && !this.minimized) this.portal.querySelector('[data-guide-title]').focus({ preventScroll: true });
    });
  }

  close() {
    this.isOpen = false;
    this.restoreNavigation();
    if (this.supportsPopover && this.portal.matches(':popover-open')) this.portal.hidePopover();
    this.portal.hidden = true;
    if (this.portal.parentElement !== this.element) this.element.append(this.portal);
    if (this.previousFocus?.isConnected && this.previousFocus !== document.body) this.previousFocus.focus({ preventScroll: true });
  }

  skip() {
    if (this.finished) return this.close();
    this.dismissed = true;
    this.rememberSkipped(true);
    this.close();
    this.persist('skip', this.state?.step || 1).catch(() => {});
  }

  wasSkipped() {
    try { return window.sessionStorage.getItem(`mykeynest.guide.skipped.${this.userIdValue}`) === '1'; } catch { return false; }
  }

  rememberSkipped(skipped) {
    try {
      const key = `mykeynest.guide.skipped.${this.userIdValue}`;
      if (skipped) window.sessionStorage.setItem(key, '1');
      else window.sessionStorage.removeItem(key);
    } catch { /* The server still remembers the choice when storage is unavailable. */ }
  }

  handleClick(event) {
    const action = event.target.closest('[data-guide-action]')?.dataset.guideAction;
    if (!action) return;
    event.preventDefault();
    if (action === 'skip') return this.skip();
    if (action === 'minimize' || action === 'resume') {
      this.minimized = action === 'minimize';
      this.render();
      if (!this.minimized) this.portal.querySelector('[data-guide-title]').focus({ preventScroll: true });
      return;
    }
    if (this.busy) return;
    if (action === 'back' || action === 'next') {
      document.querySelectorAll('#credential-launch-dialog[open], dialog.quick-share-modal[open]').forEach(dialog => dialog.close());
    }
    if (action === 'back') return this.change('progress', Math.max(1, this.state.step - 1));
    if (action === 'next') {
      if (this.finished) return this.close();
      return this.change(this.state.step === 4 ? 'complete' : 'progress', Math.min(4, this.state.step + 1));
    }
    if (action === 'share-page') this.navigate(this.urlsValue.shares);
    if (action === 'share-quick') this.navigate(this.urlsValue.credentials);
  }

  async navigate(url) {
    try {
      await this.persist('progress', this.state.step);
      if (new URL(url, window.location.origin).pathname === window.location.pathname) {
        this.render();
      } else {
        window.location.assign(url);
      }
    } catch { this.showError(true); }
  }

  milestone(step) {
    if (!this.isOpen || this.finished || this.state?.step !== step) return;
    if (step === 2) document.getElementById('credential-launch-dialog')?.close();
    this.change(step === 4 ? 'complete' : 'progress', Math.min(4, step + 1));
  }

  visible(selector) {
    return [...document.querySelectorAll(selector)].find(element => {
      const rect = element.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < window.innerWidth && getComputedStyle(element).visibility !== 'hidden';
    }) || null;
  }

  revealExtensionNavigation() {
    const link = document.querySelector('#user-sidebar [data-guide-nav="extension"]');
    const more = link?.closest('details');
    if (more && !more.open) {
      this.openedMore = more;
      more.open = true;
    }
  }

  restoreNavigation() {
    if (this.openedMore?.isConnected) this.openedMore.open = false;
    this.openedMore = null;
  }

  stepContext() {
    const urls = this.urlsValue;
    if (this.finished) return { title: 'done_title', text: 'done_text', target: null };
    if (this.state.step === 1) {
      const form = this.visible('[data-guide="credential-form"]');
      return { title: 'create_title', text: form ? 'create_form' : 'create_text', target: form || this.visible('[data-guide="credential-create"]'), fallback: '[data-guide-nav="credentials"]', url: urls.credentials, link: 'create_go' };
    }
    if (this.state.step === 2) {
      const dialog = this.visible('#credential-launch-dialog[open]');
      return { title: 'extension_title', text: dialog ? 'extension_dialog' : 'extension_text', target: dialog, browser: !dialog };
    }
    if (this.state.step === 3) {
      const quick = this.visible('dialog.quick-share-modal[open]');
      const form = this.visible('[data-guide="share-form"]');
      return { title: 'share_title', text: quick ? 'share_quick' : form ? 'share_form' : 'share_text', target: quick || form || this.visible('[data-guide="share-create"], [data-guide="quick-share"]'), fallback: '[data-guide-nav="shares"]', url: urls.shares, link: 'share_page' };
    }
    const modes = this.visible('[data-guide="extension-modes"]');
    const overview = this.visible('[data-guide="extension-overview"]');
    return { title: 'settings_title', text: modes ? 'settings_text' : overview ? 'settings_waiting' : 'settings_navigation', target: modes || overview, fallback: '[data-guide-nav="extension"]', url: urls.extension, link: 'settings_go' };
  }

  render() {
    if (!this.isOpen) return;
    if (!this.finished && this.state.step === 4 && this.routeValue !== 'app_extention') this.revealExtensionNavigation();
    else this.restoreNavigation();
    const context = this.stepContext();
    const dialog = this.visible('dialog[open]');
    const parent = dialog || this.element;
    if (this.portal.parentElement !== parent) parent.append(this.portal);
    if (this.supportsPopover && !this.portal.matches(':popover-open')) this.portal.showPopover();
    const previousTarget = this.target;
    this.target = dialog || context.target || (context.fallback && this.visible(context.fallback)) || null;
    this.browserContext = !!context.browser && !dialog;
    this.portal.querySelector('[data-guide-browser-pointer]').hidden = !this.browserContext || this.minimized;
    this.portal.querySelector('[data-guide-browser-preview]').hidden = !this.browserContext;
    if (this.target && this.target !== previousTarget) {
      const rect = this.target.getBoundingClientRect();
      if (rect.top >= window.innerHeight - 100 || rect.bottom <= 80) this.target.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    }
    this.formContext = !!this.target?.matches('form, dialog, [data-guide="extension-modes"]');
    const messages = this.messagesValue;
    this.card.hidden = this.minimized;
    this.portal.querySelector('.product-guide-dock').hidden = !this.minimized;
    this.portal.querySelector('[data-guide-title]').textContent = messages[context.title];
    this.portal.querySelector('[data-guide-description]').textContent = messages[context.text];
    this.portal.querySelector('[data-guide-counter]').textContent = messages.progress.replace('%step%', this.state.step);
    this.portal.querySelector('[data-guide-dock-counter]').textContent = `${this.state.step}/4`;
    this.portal.querySelector('[data-guide-action="resume"]').setAttribute('aria-label', `${messages.resume} · ${messages.progress.replace('%step%', this.state.step)}`);
    this.portal.querySelector('[data-guide-action="back"]').hidden = this.finished || this.state.step === 1;
    this.portal.querySelector('[data-guide-action="skip"]').hidden = this.finished;
    this.portal.querySelector('[data-guide-action="minimize"]').hidden = this.finished;
    this.portal.querySelector('[data-guide-action="next"]').textContent = this.finished ? messages.close : this.state.step === 4 ? messages.finish : messages.next;
    this.portal.querySelector('[data-guide-methods]').hidden = this.finished || this.state.step !== 3;
    const destination = this.portal.querySelector('[data-guide-destination]');
    destination.hidden = this.finished || !context.url || !!context.target;
    if (!destination.hidden) {
      destination.href = context.url;
      destination.textContent = messages[context.link];
    }
    this.portal.querySelector('[data-guide-hint]').hidden = this.finished;
    this.portal.querySelectorAll('[data-guide-progress]').forEach(segment => segment.classList.toggle('is-complete', this.finished || Number(segment.dataset.guideProgress) <= this.state.step));
    this.updateBusy();
    this.scheduleLayout();
  }

  updateBusy() {
    this.portal.querySelectorAll('[data-guide-action="back"], [data-guide-action="next"], [data-guide-methods] button').forEach(button => { button.disabled = this.busy; });
    this.card.setAttribute('aria-busy', this.busy ? 'true' : 'false');
  }

  showError(show) {
    const message = this.portal.querySelector('[data-guide-error]');
    message.textContent = this.messagesValue.error;
    message.hidden = !show;
  }

  scheduleRender() {
    if (this.renderFrame) return;
    this.renderFrame = window.requestAnimationFrame(() => { this.renderFrame = null; this.render(); });
  }

  scheduleLayout() {
    if (!this.isOpen || this.layoutFrame) return;
    this.layoutFrame = window.requestAnimationFrame(() => { this.layoutFrame = null; this.layout(); });
  }

  layout() {
    if (!this.isOpen) return;
    const width = window.innerWidth;
    const height = window.innerHeight;
    const rect = this.target?.getBoundingClientRect();
    let hole = null;
    if (rect && rect.width > 0 && rect.bottom > 0 && rect.top < height) {
      hole = { left: Math.max(4, rect.left - 7), top: Math.max(4, rect.top - 7), right: Math.min(width - 4, rect.right + 7), bottom: Math.min(height - 4, rect.bottom + 7) };
    }
    const boxes = hole ? {
      top: [0, 0, width, hole.top],
      left: [0, hole.top, hole.left, hole.bottom - hole.top],
      right: [hole.right, hole.top, width - hole.right, hole.bottom - hole.top],
      bottom: [0, hole.bottom, width, height - hole.bottom],
    } : { top: [0, 0, width, height], left: [0, 0, 0, 0], right: [0, 0, 0, 0], bottom: [0, 0, 0, 0] };
    this.portal.querySelectorAll('[data-guide-shade]').forEach(shade => {
      const [left, top, w, h] = boxes[shade.dataset.guideShade];
      Object.assign(shade.style, { left: `${left}px`, top: `${top}px`, width: `${Math.max(0, w)}px`, height: `${Math.max(0, h)}px` });
    });
    const spotlight = this.portal.querySelector('[data-guide-spotlight]');
    spotlight.hidden = !hole;
    if (hole) Object.assign(spotlight.style, { left: `${hole.left}px`, top: `${hole.top}px`, width: `${hole.right - hole.left}px`, height: `${hole.bottom - hole.top}px` });
    if (this.minimized) return;
    const sideSpace = hole ? Math.max(hole.left, width - hole.right) - 30 : 0;
    this.card.style.width = width >= 900 && sideSpace >= 280 && sideSpace < 354 ? `${sideSpace}px` : '';
    this.card.classList.toggle('is-scrollable', this.card.scrollHeight > height - 100);
    const card = this.card.getBoundingClientRect();
    let left = width - card.width - 20;
    let top = height - card.height - 20;
    if (hole && width >= 900) {
      if (width - hole.right >= card.width + 30) left = hole.right + 18;
      else if (hole.left >= card.width + 30) left = hole.left - card.width - 18;
      top = Math.min(hole.top, height - card.height - 20);
    }
    if (this.browserContext) top = Math.min(132, height - card.height - 20);
    if (this.finished) { left = (width - card.width) / 2; top = (height - card.height) / 2; }
    Object.assign(this.card.style, { left: `${Math.max(12, left)}px`, top: `${Math.max(80, top)}px` });
  }

  handleFocus(event) {
    if (!this.isOpen || this.portal.hidden) return;
    if (this.portal.contains(event.target)) return;
    if (this.target?.contains(event.target)) {
      if (this.formContext && event.target.matches('input:not([type="hidden"]), select, textarea, button')) {
        this.minimized = true;
        this.render();
      }
      return;
    }
    this.portal.querySelector(this.minimized ? '[data-guide-action="resume"]' : '[data-guide-title]').focus({ preventScroll: true });
  }

  handleKey(event) {
    if (!this.isOpen) return;
    if (event.key === 'Escape' && this.portal.contains(document.activeElement)) { event.preventDefault(); this.skip(); return; }
    if (event.key !== 'Tab') return;
    const elements = [...(this.target?.querySelectorAll(FOCUSABLE) || []), ...this.portal.querySelectorAll(FOCUSABLE)];
    if (this.target?.matches(FOCUSABLE)) elements.unshift(this.target);
    const visible = [...new Set(elements)].filter(element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden');
    if (!visible.length) return;
    const index = visible.indexOf(document.activeElement);
    const next = event.shiftKey ? (index <= 0 ? visible.length - 1 : index - 1) : (index + 1) % visible.length;
    event.preventDefault();
    visible[next].focus();
  }
}
