import { Controller } from '@hotwired/stimulus';

const DISPLAY_MODES = new Set(['default', 'inline', 'off']);
const SORT_MODES = new Set(['recent', 'domain', 'custom']);

export default class extends Controller {
  static targets = [
    'configuration', 'detection', 'detectionLabel', 'version', 'mode', 'card',
    'sort', 'sortCard', 'feedback', 'exclusionInput', 'exclusionSubmit',
    'exclusionsList', 'exclusionsEmpty',
  ];

  static values = {
    checking: String,
    installed: String,
    notFound: String,
    ready: String,
    saving: String,
    saved: String,
    saveError: String,
    updateRequired: String,
    invalidDomain: String,
    removeLabel: String,
  };

  connect() {
    this.requests = new Map();
    this.currentMode = 'default';
    this.currentSortMode = 'recent';
    this.supportsDisplayModes = false;
    this.supportsSortModes = false;
    this.supportsExcludedDomains = false;
    this.excludedDomains = [];
    this.handleMessage = this.handleMessage.bind(this);
    this.handleFocus = this.handleFocus.bind(this);
    window.addEventListener('message', this.handleMessage);
    window.addEventListener('focus', this.handleFocus);
    this.detectExtension();
  }

  disconnect() {
    window.removeEventListener('message', this.handleMessage);
    window.removeEventListener('focus', this.handleFocus);
    window.clearTimeout(this.retryTimer);
    this.requests.forEach(({ reject, timeout }) => {
      window.clearTimeout(timeout);
      reject(new Error('Controller disconnected'));
    });
    this.requests.clear();
  }

  async selectMode(event) {
    const mode = event.currentTarget.value;
    if (!this.supportsDisplayModes || !DISPLAY_MODES.has(mode) || mode === this.currentMode) {
      this.renderMode(this.currentMode);
      return;
    }

    const previousMode = this.currentMode;
    this.renderMode(mode);
    this.setBusy(true);
    this.feedbackTarget.textContent = this.savingValue;
    this.feedbackTarget.className = 'extension-save-status is-saving';

    try {
      const response = await this.sendRequest({
        type: 'MYKEYNEST_EXTENSION_SET_DISPLAY_MODE',
        mode,
      });

      if (!response?.ok || !DISPLAY_MODES.has(response.displayMode)) {
        throw new Error(response?.error || 'Unable to save extension preference');
      }

      this.currentMode = response.displayMode;
      this.renderMode(this.currentMode);
      this.feedbackTarget.textContent = this.savedValue;
      this.feedbackTarget.className = 'extension-save-status is-saved';
    } catch (error) {
      this.renderMode(previousMode);
      this.feedbackTarget.textContent = this.saveErrorValue;
      this.feedbackTarget.className = 'extension-save-status is-error';
    } finally {
      this.setBusy(false);
    }
  }

  async selectSort(event) {
    const mode = event.currentTarget.value;
    if (!this.supportsSortModes || !SORT_MODES.has(mode) || mode === this.currentSortMode) {
      this.renderSort(this.currentSortMode);
      return;
    }

    const previousMode = this.currentSortMode;
    this.renderSort(mode);
    this.setBusy(true);
    this.feedbackTarget.textContent = this.savingValue;
    this.feedbackTarget.className = 'extension-save-status is-saving';

    try {
      const response = await this.sendRequest({
        type: 'MYKEYNEST_EXTENSION_SET_SORT_MODE',
        mode,
      });

      if (!response?.ok || !SORT_MODES.has(response.sortMode)) {
        throw new Error(response?.error || 'Unable to save credential sort preference');
      }

      this.currentSortMode = response.sortMode;
      this.renderSort(this.currentSortMode);
      this.feedbackTarget.textContent = this.savedValue;
      this.feedbackTarget.className = 'extension-save-status is-saved';
    } catch (error) {
      this.renderSort(previousMode);
      this.feedbackTarget.textContent = this.saveErrorValue;
      this.feedbackTarget.className = 'extension-save-status is-error';
    } finally {
      this.setBusy(false);
    }
  }

  async addExclusion(event) {
    event.preventDefault();
    if (!this.supportsExcludedDomains) return;

    const domain = this.normalizeDomain(this.exclusionInputTarget.value);
    if (!domain) {
      this.feedbackTarget.textContent = this.invalidDomainValue;
      this.feedbackTarget.className = 'extension-save-status is-error';
      this.exclusionInputTarget.focus();
      return;
    }

    await this.updateExclusions({
      type: 'MYKEYNEST_EXTENSION_ADD_EXCLUDED_DOMAIN',
      domain,
    });
    this.exclusionInputTarget.value = '';
  }

  async removeExclusion(event) {
    const domain = event.currentTarget.dataset.domain;
    if (!domain || !this.supportsExcludedDomains) return;
    await this.updateExclusions({
      type: 'MYKEYNEST_EXTENSION_REMOVE_EXCLUDED_DOMAIN',
      domain,
    });
  }

  async updateExclusions(payload) {
    this.setBusy(true);
    this.feedbackTarget.textContent = this.savingValue;
    this.feedbackTarget.className = 'extension-save-status is-saving';

    try {
      const response = await this.sendRequest(payload);
      if (!response?.ok || !Array.isArray(response.excludedDomains)) {
        throw new Error(response?.error || 'Unable to save excluded domains');
      }
      this.excludedDomains = response.excludedDomains;
      this.renderExclusions();
      this.feedbackTarget.textContent = this.savedValue;
      this.feedbackTarget.className = 'extension-save-status is-saved';
    } catch (error) {
      this.feedbackTarget.textContent = this.saveErrorValue;
      this.feedbackTarget.className = 'extension-save-status is-error';
    } finally {
      this.setBusy(false);
    }
  }

  handleMessage(event) {
    const message = event.data;
    if (
      event.source !== window
      || event.origin !== window.location.origin
      || message?.source !== 'MYKEYNEST_EXTENSION'
      || message?.type !== 'MYKEYNEST_EXTENSION_RESPONSE'
      || typeof message.requestId !== 'string'
    ) {
      return;
    }

    const pending = this.requests.get(message.requestId);
    if (!pending) {
      return;
    }

    window.clearTimeout(pending.timeout);
    this.requests.delete(message.requestId);
    pending.resolve(message.response);
  }

  handleFocus() {
    if (this.configurationTarget.hidden) {
      this.detectExtension();
    }
  }

  async detectExtension(attempt = 0) {
    window.clearTimeout(this.retryTimer);
    this.detectionTarget.className = 'extension-detection-status is-checking';
    this.detectionLabelTarget.textContent = this.checkingValue;

    try {
      const response = await this.sendRequest({ type: 'MYKEYNEST_EXTENSION_PING' }, 900);
      if (!response?.ok) {
        throw new Error(response?.error || 'Extension unavailable');
      }

      const mode = DISPLAY_MODES.has(response.displayMode) ? response.displayMode : 'default';
      const sortMode = SORT_MODES.has(response.sortMode) ? response.sortMode : 'recent';
      this.currentMode = mode;
      this.currentSortMode = sortMode;
      this.supportsDisplayModes = response.capabilities?.displayModes === true;
      this.supportsSortModes = response.capabilities?.sortModes === true;
      this.supportsExcludedDomains = response.capabilities?.excludedDomains === true;
      this.excludedDomains = Array.isArray(response.excludedDomains) ? response.excludedDomains : [];
      this.configurationTarget.hidden = false;
      this.detectionTarget.className = 'extension-detection-status is-installed';
      this.detectionLabelTarget.textContent = this.installedValue;
      this.versionTarget.textContent = response.version ? `Version ${response.version}` : 'MYKEYNEST';
      this.renderMode(mode);
      this.renderSort(sortMode);
      this.renderExclusions();
      this.setBusy(false);
      const isReady = this.supportsDisplayModes && this.supportsSortModes && this.supportsExcludedDomains;
      this.feedbackTarget.textContent = isReady ? this.readyValue : this.updateRequiredValue;
      this.feedbackTarget.className = isReady
        ? 'extension-save-status is-ready'
        : 'extension-save-status is-error';
    } catch (error) {
      if (attempt < 7) {
        this.retryTimer = window.setTimeout(() => this.detectExtension(attempt + 1), 450);
        return;
      }

      this.configurationTarget.hidden = true;
      this.detectionTarget.className = 'extension-detection-status is-missing';
      this.detectionLabelTarget.textContent = this.notFoundValue;
    }
  }

  renderMode(mode) {
    this.modeTargets.forEach((input) => {
      input.checked = input.value === mode;
    });
    this.cardTargets.forEach((card) => {
      const input = card.querySelector('input[type="radio"]');
      card.classList.toggle('is-selected', input?.value === mode);
    });
  }

  renderSort(mode) {
    this.sortTargets.forEach((input) => {
      input.checked = input.value === mode;
    });
    this.sortCardTargets.forEach((card) => {
      const input = card.querySelector('input[type="radio"]');
      card.classList.toggle('is-selected', input?.value === mode);
    });
  }

  renderExclusions() {
    this.exclusionsListTarget.textContent = '';
    this.exclusionsEmptyTarget.hidden = this.excludedDomains.length > 0;

    this.excludedDomains.forEach((domain) => {
      const item = document.createElement('span');
      item.className = 'extension-exclusion-chip';

      const label = document.createElement('span');
      label.textContent = domain;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.dataset.domain = domain;
      remove.title = this.removeLabelValue;
      remove.setAttribute('aria-label', `${this.removeLabelValue} ${domain}`);
      remove.innerHTML = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m4 4 8 8m0-8-8 8" stroke-linecap="round"/></svg>';
      remove.addEventListener('click', (event) => this.removeExclusion(event));
      item.append(label, remove);
      this.exclusionsListTarget.appendChild(item);
    });
  }

  normalizeDomain(value) {
    const raw = String(value || '').trim().toLowerCase();
    if (!raw) return '';
    try {
      const url = new URL(raw.includes('://') ? raw : `https://${raw}`);
      return url.hostname.replace(/^www\./, '').replace(/\.$/, '');
    } catch (error) {
      return '';
    }
  }

  setBusy(isBusy) {
    this.modeTargets.forEach((input) => {
      input.disabled = isBusy || !this.supportsDisplayModes;
    });
    this.sortTargets.forEach((input) => {
      input.disabled = isBusy || !this.supportsSortModes;
    });
    this.exclusionInputTarget.disabled = isBusy || !this.supportsExcludedDomains;
    this.exclusionSubmitTarget.disabled = isBusy || !this.supportsExcludedDomains;
    this.configurationTarget.classList.toggle('is-busy', isBusy);
  }

  sendRequest(payload, timeoutDuration = 2200) {
    return new Promise((resolve, reject) => {
      const requestId = crypto.randomUUID
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      const timeout = window.setTimeout(() => {
        this.requests.delete(requestId);
        reject(new Error('Extension response timed out'));
      }, timeoutDuration);

      this.requests.set(requestId, { resolve, reject, timeout });
      window.postMessage({
        source: 'MYKEYNEST_WEB',
        type: 'MYKEYNEST_EXTENSION_REQUEST',
        requestId,
        payload,
      }, window.location.origin);
    });
  }
}
