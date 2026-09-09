import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = [
    "output", "outputContainer", "copyLabel", "lengthInput", "lengthNumber", "lengthValue",
    "uppercase", "lowercase", "numbers", "symbols", "ambiguous", "meter",
    "toastWrap", "list", "historyClear", "saveName", "credName", "credDomain", "credUsername",
  ];

  connect() {
    this.recentStorageKey = "mykeynest:generator:recent-session:v1";
    try {
      localStorage.removeItem("recentPasswords");
    } catch {}
    this.recentPasswords = this.loadRecentPasswords();
    this.currentDraftId = null;
    this.pendingSavePassword = null;
    this.handleKeydown = this.handleKeydown.bind(this);
    document.addEventListener("keydown", this.handleKeydown);
    this.syncLength(this.lengthInputTarget.value);
    this.renderRecent();
    this.createAndRenderPassword(false);
  }

  disconnect() {
    document.removeEventListener("keydown", this.handleKeydown);
  }

  t(key) {
    const d = this.element.dataset;
    const map = {
      optionsTitle: d.generatorTOptionsTitleValue,
      chooseOneType: d.generatorTChooseOneTypeValue,
      copiedTitle: d.generatorTCopiedTitleValue,
      copiedMsg: d.generatorTCopiedMsgValue,
      cleanedTitle: d.generatorTCleanedTitleValue,
      cleanedMsg: d.generatorTCleanedMsgValue,
      deletedTitle: d.generatorTDeletedTitleValue,
      deletedMsg: d.generatorTDeletedMsgValue,
      nameRequiredTitle: d.generatorTNameRequiredTitleValue,
      nameRequiredMsg: d.generatorTNameRequiredMsgValue,
      errorTitle: d.generatorTErrorTitleValue,
      saveFailed: d.generatorTSaveFailedValue,
      draftTitle: d.generatorTDraftTitleValue,
      draftSaved: d.generatorTDraftSavedValue,
      convertedTitle: d.generatorTConvertedTitleValue,
      convertedMsg: d.generatorTConvertedMsgValue,
      failed: d.generatorTFailedValue,
      networkErrorTitle: d.generatorTNetworkErrorTitleValue,
      networkErrorMsg: d.generatorTNetworkErrorMsgValue,
      loadError: d.generatorTLoadErrorValue,
      loading: d.generatorTLoadingValue,
      emptyDrafts: d.generatorTEmptyDraftsValue,
      emptyRecents: d.generatorTEmptyRecentsValue,
      entropyEmpty: d.generatorTEntropyEmptyValue,
      strengthEmpty: d.generatorTStrengthEmptyValue,
      entropyTemplate: d.generatorTEntropyTemplateValue,
      strengthTemplate: d.generatorTStrengthTemplateValue,
      strengthStrong: d.generatorTStrengthStrongValue,
      strengthMedium: d.generatorTStrengthMediumValue,
      strengthWeak: d.generatorTStrengthWeakValue,
      copy: d.generatorTCopyValue,
      saveCurrent: d.generatorTSaveCurrentValue,
      convert: d.generatorTConvertValue,
    };

    return map[key] ?? "";
  }

  generatePassword() {
    this.createAndRenderPassword(true);
  }

  createAndRenderPassword(addToHistory) {
    const pools = this.selectedPools();
    if (pools.length === 0) {
      this.toast("error", this.t("optionsTitle"), this.t("chooseOneType"));
      return;
    }

    const length = this.clampLength(this.lengthInputTarget.value);
    const alphabet = pools.join("");
    const characters = pools.map((pool) => pool[this.randomIndex(pool.length)]);

    while (characters.length < length) {
      characters.push(alphabet[this.randomIndex(alphabet.length)]);
    }

    for (let index = characters.length - 1; index > 0; index -= 1) {
      const swapIndex = this.randomIndex(index + 1);
      [characters[index], characters[swapIndex]] = [characters[swapIndex], characters[index]];
    }

    const password = characters.join("");
    this.outputTarget.textContent = password;
    this.outputContainerTarget.classList.remove("generated");
    window.requestAnimationFrame(() => this.outputContainerTarget.classList.add("generated"));

    if (addToHistory) {
      this.addRecentPassword(password);
      this.renderRecent();
    }

    this.updateMeter(password, alphabet.length);
  }

  async copyPassword() {
    const password = this.outputTarget.textContent.trim();
    if (!password) return;

    await this.writeClipboard(password);
    this.copyLabelTarget.textContent = this.t("copiedTitle");
    window.setTimeout(() => {
      if (this.hasCopyLabelTarget) this.copyLabelTarget.textContent = this.t("copy");
    }, 1600);
    this.toast("success", this.t("copiedTitle"), this.t("copiedMsg"));
  }

  updateLength(event) {
    this.syncLength(event?.currentTarget?.value ?? this.lengthInputTarget.value);
    this.clearActivePreset();
    this.createAndRenderPassword(false);
  }

  updateLengthFromNumber(event) {
    this.syncLength(event?.currentTarget?.value ?? this.lengthNumberTarget.value);
    this.clearActivePreset();
    this.createAndRenderPassword(false);
  }

  syncLength(value) {
    const length = this.clampLength(value);
    this.lengthInputTarget.value = String(length);
    this.lengthNumberTarget.value = String(length);
    this.lengthValueTarget.textContent = String(length);
    const min = Number(this.lengthInputTarget.min);
    const max = Number(this.lengthInputTarget.max);
    const progress = ((length - min) / (max - min)) * 100;
    this.lengthInputTarget.style.setProperty("--range-progress", `${progress}%`);
  }

  clampLength(value) {
    const min = Number(this.lengthInputTarget.min) || 8;
    const max = Number(this.lengthInputTarget.max) || 64;
    const parsed = Number.parseInt(value, 10);
    return Math.min(max, Math.max(min, Number.isNaN(parsed) ? 20 : parsed));
  }

  optionChanged(event) {
    if (this.selectedPools().length === 0) {
      event.currentTarget.checked = true;
      this.toast("error", this.t("optionsTitle"), this.t("chooseOneType"));
      return;
    }

    this.clearActivePreset();
    this.createAndRenderPassword(false);
  }

  applyPreset(event) {
    const presets = {
      balanced: { length: 16, upper: true, lower: true, numbers: true, symbols: false, ambiguous: true },
      strong: { length: 20, upper: true, lower: true, numbers: true, symbols: true, ambiguous: true },
      maximum: { length: 32, upper: true, lower: true, numbers: true, symbols: true, ambiguous: false },
    };
    const preset = presets[event.currentTarget.dataset.preset];
    if (!preset) return;

    this.uppercaseTarget.checked = preset.upper;
    this.lowercaseTarget.checked = preset.lower;
    this.numbersTarget.checked = preset.numbers;
    this.symbolsTarget.checked = preset.symbols;
    this.ambiguousTarget.checked = preset.ambiguous;
    this.syncLength(preset.length);
    this.element.querySelectorAll(".generator-preset").forEach((button) => {
      button.classList.toggle("is-active", button === event.currentTarget);
    });
    this.createAndRenderPassword(false);
  }

  clearActivePreset() {
    this.element.querySelectorAll(".generator-preset").forEach((button) => button.classList.remove("is-active"));
  }

  setActiveTab(button) {
    button.closest(".generator-tabs").querySelectorAll("button").forEach((tab) => {
      const active = tab === button;
      tab.classList.toggle("active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });
  }

  showRecents(event) {
    if (event?.currentTarget) this.setActiveTab(event.currentTarget);
    this.historyClearTarget.hidden = false;
    this.renderRecent();
  }

  async showDrafts(event) {
    if (event?.currentTarget) this.setActiveTab(event.currentTarget);
    this.historyClearTarget.hidden = true;
    this.listTarget.innerHTML = this.emptyState("fa-spinner fa-spin", this.t("loading"));

    try {
      const response = await fetch("/api/generator/list-drafts", { headers: { Accept: "application/json" } });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || this.t("loadError"));

      this.listTarget.innerHTML = "";
      if (!data.drafts?.length) {
        this.listTarget.innerHTML = this.emptyState("fa-bookmark", this.t("emptyDrafts"));
        return;
      }

      data.drafts.forEach((draft) => {
        const item = document.createElement("div");
        item.className = "generator-password-item";
        item.innerHTML = `
          <div class="generator-password-item__value">
            <span class="generator-password-item__label">${this.escape(draft.name)}</span>
            ${this.escape(draft.password)}
          </div>
          <div class="generator-password-item__actions">
            <button type="button" title="${this.escape(this.t("convert"))}" aria-label="${this.escape(this.t("convert"))}" data-action="click->generator#openConvertModal" data-id="${this.escape(draft.id)}" data-name="${this.escape(draft.name)}"><i class="fa-solid fa-arrow-right-to-bracket"></i></button>
            <button type="button" title="${this.escape(this.t("copy"))}" aria-label="${this.escape(this.t("copy"))}" data-action="click->generator#copyItem" data-password="${this.escape(draft.password)}"><i class="fa-regular fa-copy"></i></button>
          </div>`;
        this.listTarget.appendChild(item);
      });
    } catch (error) {
      this.listTarget.innerHTML = this.emptyState("fa-triangle-exclamation", error.message || this.t("loadError"));
    }
  }

  openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    document.body.style.overflow = "hidden";
    window.setTimeout(() => modal.querySelector("input, button")?.focus(), 0);
  }

  closeModal() {
    this.element.querySelectorAll(".generator-modal-backdrop").forEach((modal) => { modal.hidden = true; });
    document.body.style.overflow = "";
  }

  closeOnBackdrop(event) {
    if (event.target === event.currentTarget) this.closeModal();
  }

  handleKeydown(event) {
    if (event.key === "Escape") this.closeModal();
  }

  confirmClearAll() {
    this.openModal("confirmModal");
  }

  openSaveCurrent() {
    const password = this.outputTarget.textContent.trim();
    if (!password) return;
    this.pendingSavePassword = password;
    this.saveNameTarget.value = "";
    this.openModal("saveModal");
  }

  openConvertModal(event) {
    this.currentDraftId = event.currentTarget.dataset.id;
    this.credNameTarget.value = event.currentTarget.dataset.name || "";
    this.credDomainTarget.value = "";
    this.credUsernameTarget.value = "";
    this.openModal("convertModal");
  }

  async saveDraft() {
    const name = this.saveNameTarget.value.trim();
    if (!name) {
      this.toast("error", this.t("nameRequiredTitle"), this.t("nameRequiredMsg"));
      this.saveNameTarget.focus();
      return;
    }

    try {
      const response = await fetch("/api/generator/save-draft", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ name, password: this.pendingSavePassword }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || this.t("saveFailed"));

      this.closeModal();
      this.toast("success", this.t("draftTitle"), this.t("draftSaved"));
    } catch (error) {
      this.toast("error", this.t("errorTitle"), error.message || this.t("saveFailed"));
    }
  }

  async convertDraft() {
    const payload = {
      draftId: this.currentDraftId,
      name: this.credNameTarget.value,
      domain: this.credDomainTarget.value,
      username: this.credUsernameTarget.value,
    };

    try {
      const response = await fetch("/api/generator/convert-draft", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || this.t("failed"));

      this.toast("success", this.t("convertedTitle"), this.t("convertedMsg"));
      this.closeModal();
      await this.showDrafts();
    } catch (error) {
      this.toast("error", this.t("errorTitle"), error.message || this.t("networkErrorMsg"));
    }
  }

  loadRecentPasswords() {
    try {
      const data = sessionStorage.getItem(this.recentStorageKey);
      if (!data) return [];
      const parsed = JSON.parse(data);
      return Array.isArray(parsed) ? parsed.slice(0, 8) : [];
    } catch {
      return [];
    }
  }

  saveRecentPasswords() {
    try {
      sessionStorage.setItem(this.recentStorageKey, JSON.stringify(this.recentPasswords));
    } catch {}
  }

  addRecentPassword(password) {
    this.recentPasswords = [
      { password, createdAt: Date.now() },
      ...this.recentPasswords.filter((item) => item.password !== password),
    ].slice(0, 8);
    this.saveRecentPasswords();
  }

  clearAllRecents() {
    this.recentPasswords = [];
    this.saveRecentPasswords();
    this.renderRecent();
    this.toast("success", this.t("cleanedTitle"), this.t("cleanedMsg"));
    this.closeModal();
  }

  renderRecent() {
    this.listTarget.innerHTML = "";
    if (!this.recentPasswords.length) {
      this.listTarget.innerHTML = this.emptyState("fa-clock-rotate-left", this.t("emptyRecents"));
      return;
    }

    this.recentPasswords.forEach((itemData) => {
      const item = document.createElement("div");
      item.className = "generator-password-item";
      item.innerHTML = `
        <div class="generator-password-item__value">${this.escape(itemData.password)}</div>
        <div class="generator-password-item__actions">
          <button type="button" title="${this.escape(this.t("copy"))}" aria-label="${this.escape(this.t("copy"))}" data-action="click->generator#copyItem" data-password="${this.escape(itemData.password)}"><i class="fa-regular fa-copy"></i></button>
          <button type="button" title="${this.escape(this.t("saveCurrent"))}" aria-label="${this.escape(this.t("saveCurrent"))}" data-action="click->generator#openSaveModal" data-password="${this.escape(itemData.password)}"><i class="fa-regular fa-bookmark"></i></button>
          <button type="button" class="is-danger" title="${this.escape(this.t("deletedTitle"))}" aria-label="${this.escape(this.t("deletedTitle"))}" data-action="click->generator#deleteItem" data-ts="${itemData.createdAt}"><i class="fa-solid fa-xmark"></i></button>
        </div>`;
      this.listTarget.appendChild(item);
    });
  }

  async copyItem(event) {
    await this.writeClipboard(event.currentTarget.dataset.password);
    this.toast("success", this.t("copiedTitle"), this.t("copiedMsg"));
  }

  openSaveModal(event) {
    this.pendingSavePassword = event.currentTarget.dataset.password;
    this.saveNameTarget.value = "";
    this.openModal("saveModal");
  }

  deleteItem(event) {
    const timestamp = Number(event.currentTarget.dataset.ts);
    this.recentPasswords = this.recentPasswords.filter((item) => item.createdAt !== timestamp);
    this.saveRecentPasswords();
    this.renderRecent();
    this.toast("success", this.t("deletedTitle"), this.t("deletedMsg"));
  }

  toast(type, title, message) {
    const toast = document.createElement("div");
    toast.className = `generator-toast${type === "error" ? " is-error" : ""}`;
    toast.innerHTML = `<i class="fa-solid ${type === "error" ? "fa-circle-exclamation" : "fa-circle-check"}"></i><div><strong>${this.escape(title)}</strong><span>${this.escape(message)}</span></div>`;
    this.toastWrapTarget.appendChild(toast);
    window.setTimeout(() => toast.remove(), 3600);
  }

  getCharPools() {
    let pools = {
      upper: "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
      lower: "abcdefghijklmnopqrstuvwxyz",
      numbers: "0123456789",
      symbols: "!@#$%^&*()_+-=[]{};:,.<>/?",
    };

    if (this.ambiguousTarget.checked) {
      const ambiguous = new Set("O0Il1");
      pools = Object.fromEntries(Object.entries(pools).map(([key, value]) => [
        key,
        [...value].filter((character) => !ambiguous.has(character)).join(""),
      ]));
    }

    return pools;
  }

  selectedPools() {
    const pools = this.getCharPools();
    return [
      this.uppercaseTarget.checked ? pools.upper : "",
      this.lowercaseTarget.checked ? pools.lower : "",
      this.numbersTarget.checked ? pools.numbers : "",
      this.symbolsTarget.checked ? pools.symbols : "",
    ].filter(Boolean);
  }

  randomIndex(max) {
    if (!Number.isSafeInteger(max) || max <= 0) throw new RangeError("Invalid random range");
    const maximumUint32 = 0x100000000;
    const limit = Math.floor(maximumUint32 / max) * max;
    const buffer = new Uint32Array(1);
    do {
      crypto.getRandomValues(buffer);
    } while (buffer[0] >= limit);

    return buffer[0] % max;
  }

  updateMeter(password, poolSize) {
    const bar = this.meterTarget.querySelector("div");
    const entropyLabel = this.meterTarget.parentElement.querySelector("#entropyLabel");
    const strengthLabel = this.meterTarget.parentElement.querySelector("#strengthLabel");

    if (!password || poolSize === 0) {
      bar.style.width = "0%";
      entropyLabel.textContent = this.t("entropyEmpty");
      strengthLabel.textContent = this.t("strengthEmpty");
      this.meterTarget.setAttribute("aria-valuenow", "0");
      return;
    }

    const entropy = Math.round((Math.log2(poolSize) * password.length) * 10) / 10;
    let strength = "weak";
    let percentage = Math.min(42, Math.max(12, Math.round(entropy)));
    if (entropy >= 80) {
      strength = "strong";
      percentage = 100;
    } else if (entropy >= 50) {
      strength = "medium";
      percentage = Math.min(82, Math.round(entropy));
    }

    const label = strength === "strong" ? this.t("strengthStrong")
      : strength === "medium" ? this.t("strengthMedium")
        : this.t("strengthWeak");

    this.meterTarget.className = `generator-meter ${strength}`;
    this.meterTarget.setAttribute("aria-valuenow", String(percentage));
    bar.style.width = `${percentage}%`;
    entropyLabel.textContent = this.t("entropyTemplate").replace("__BITS__", String(entropy));
    strengthLabel.textContent = this.t("strengthTemplate").replace("__LABEL__", label);
  }

  async writeClipboard(value) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }

    const textArea = document.createElement("textarea");
    textArea.value = value;
    textArea.style.position = "fixed";
    textArea.style.opacity = "0";
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand("copy");
    textArea.remove();
  }

  emptyState(icon, text) {
    return `<div class="generator-empty"><div><i class="fa-solid ${this.escape(icon)}"></i>${this.escape(text)}</div></div>`;
  }

  escape(value) {
    return value === null || value === undefined ? "" : String(value).replace(/[&<>"']/g, (character) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
    }[character]));
  }
}
