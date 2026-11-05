import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = ["panel", "stepItem", "name", "emailInput", "bulkInput", "bulkPanel", "members", "memberRow", "memberFeedback", "emptyMembers", "memberCount", "totalMembers", "credential", "credentialCount", "selectionCount", "search", "noResults", "reveal", "previewName", "initials", "permissionText", "reviewMembers", "reviewCredentials", "previous", "next", "submit", "favicon"];
  static values = { messages: Object, automaticMembers: Array, step: Number };

  connect() {
    this.element.noValidate = true;
    this.memberIndex = Math.max(-1, ...this.memberRowTargets.map(row => Number(row.dataset.index))) + 1;
    this.element.classList.add("is-enhanced");
    this.sync();
    this.showStep(this.stepValue, false);
    this.faviconTargets.forEach(image => {
      if (image.complete && image.naturalWidth > 0) image.parentElement.classList.add("has-favicon");
    });
    const header = document.querySelector(".header_top");
    if (header) {
      this.headerObserver = new ResizeObserver(() => {
        document.body.style.setProperty("--tc-header-height", `${header.getBoundingClientRect().height}px`);
      });
      this.headerObserver.observe(header);
    }
  }

  disconnect() {
    this.headerObserver?.disconnect();
    document.body.style.removeProperty("--tc-header-height");
  }

  next() {
    const step = Math.min(2, this.stepValue + 1);
    if (this.validateBefore(step)) this.showStep(step);
  }

  previous() {
    this.showStep(Math.max(0, this.stepValue - 1));
  }

  goToStep(event) {
    const step = Number(event.currentTarget.dataset.step);
    if (step <= this.stepValue || this.validateBefore(step)) this.showStep(step);
  }

  showStep(step, focus = true) {
    this.stepValue = step;
    this.panelTargets.forEach((panel, index) => { panel.hidden = index !== step; });
    this.stepItemTargets.forEach((item, index) => {
      item.dataset.state = index === step ? "current" : index < step ? "complete" : "upcoming";
      const button = item.querySelector("button");
      if (index === step) button.setAttribute("aria-current", "step");
      else button.removeAttribute("aria-current");
    });
    this.previousTarget.hidden = step === 0;
    this.nextTarget.hidden = step === 2;
    this.submitTarget.hidden = step !== 2;
    this.sync();
    if (focus) {
      const title = this.panelTargets[step].querySelector("h2");
      title.focus({ preventScroll: true });
      title.scrollIntoView({ block: "nearest", behavior: "instant" });
    }
  }

  validateBefore(step) {
    if (step === 0) return true;
    for (const input of [this.emailInputTarget, this.bulkInputTarget]) {
      if (input.value.trim() && !this.addFrom(input)) {
        this.showStep(0);
        input.focus();
        return false;
      }
    }
    this.nameTarget.value = this.nameTarget.value.trim();
    const emails = new Set(this.automaticMembersValue.map(email => email.toLowerCase()));
    this.memberRowTargets.forEach(row => {
      const input = row.querySelector('input[type="email"]');
      input.value = input.value.trim().toLowerCase();
      input.setCustomValidity(emails.has(input.value) ? this.messagesValue.duplicate_email : "");
      emails.add(input.value);
    });
    for (let index = 0; index < step; index++) {
      const invalid = Array.from(this.panelTargets[index].querySelectorAll("input, select, textarea")).find(input => !input.checkValidity());
      if (invalid) {
        this.showStep(index);
        invalid.reportValidity();
        invalid.focus();
        return false;
      }
    }
    return true;
  }

  submit(event) {
    if (this.stepValue !== 2) {
      event.preventDefault();
      this.next();
      return;
    }
    if (!this.validateBefore(2)) {
      event.preventDefault();
      return;
    }
    this.submitTarget.disabled = true;
    this.submitTarget.textContent = this.messagesValue.creating;
  }

  emailKeydown(event) {
    if (event.key === "Enter") {
      event.preventDefault();
      this.addMembers();
    }
  }

  addMembers() {
    this.addFrom(this.emailInputTarget);
  }

  addBulk() {
    this.addFrom(this.bulkInputTarget);
  }

  addFrom(input) {
    const emails = [...new Set(input.value.trim().toLowerCase().split(/[\s,;]+/).filter(Boolean))];
    this.memberFeedbackTarget.hidden = true;
    if (!emails.length) return true;
    const validator = document.createElement("input");
    validator.type = "email";
    const invalid = emails.find(email => { validator.value = email; return !validator.checkValidity(); });
    if (invalid) {
      this.feedback(this.messagesValue.invalid_email.replace("%email%", invalid));
      input.focus();
      return false;
    }
    const known = new Set([
      ...this.automaticMembersValue.map(email => email.toLowerCase()),
      ...this.memberRowTargets.map(row => row.querySelector('input[type="email"]').value.trim().toLowerCase())
    ]);
    const additions = emails.filter(email => !known.has(email));
    if (!additions.length) {
      this.feedback(this.messagesValue.duplicate_email);
      return false;
    }
    additions.forEach(email => {
      const template = document.createElement("template");
      template.innerHTML = this.membersTarget.dataset.prototype.replaceAll("__name__", String(this.memberIndex++));
      template.content.querySelector('input[type="email"]').value = email;
      this.membersTarget.append(template.content);
    });
    input.value = "";
    if (additions.length !== emails.length) this.feedback(this.messagesValue.duplicate_email);
    this.sync();
    input.focus();
    return true;
  }

  feedback(message) {
    this.memberFeedbackTarget.textContent = message;
    this.memberFeedbackTarget.hidden = false;
  }

  toggleBulk(event) {
    this.bulkPanelTarget.hidden = !this.bulkPanelTarget.hidden;
    event.currentTarget.setAttribute("aria-expanded", String(!this.bulkPanelTarget.hidden));
    if (!this.bulkPanelTarget.hidden) this.bulkInputTarget.focus();
  }

  removeMember(event) {
    event.currentTarget.closest('[data-team-create-target="memberRow"]').remove();
    this.sync();
    this.emailInputTarget.focus();
  }

  filterCredentials() {
    const query = this.searchTarget.value.trim().toLowerCase();
    this.credentialTargets.forEach(card => { card.hidden = !card.dataset.search.includes(query); });
    this.noResultsTarget.hidden = this.credentialTargets.some(card => !card.hidden);
  }

  faviconLoaded(event) {
    event.currentTarget.parentElement.classList.add("has-favicon");
  }

  faviconError(event) {
    event.currentTarget.parentElement.classList.remove("has-favicon");
    event.currentTarget.remove();
  }

  selectAll() {
    this.credentialTargets.filter(card => !card.hidden).forEach(card => { card.querySelector("input").checked = true; });
    this.sync();
  }

  clearSelection() {
    this.credentialTargets.forEach(card => { card.querySelector("input").checked = false; });
    this.sync();
  }

  sync() {
    const name = this.nameTarget.value.trim() || this.messagesValue.empty_name;
    this.previewNameTargets.forEach(target => { target.textContent = name; });
    const initials = name.split(/\s+/).slice(0, 2).map(word => Array.from(word)[0]).join("").toUpperCase();
    this.initialsTargets.forEach(target => { target.textContent = initials; });
    this.memberCountTargets.forEach(target => { target.textContent = this.memberRowTargets.length; });
    this.totalMembersTarget.textContent = this.memberRowTargets.length + this.automaticMembersValue.length;
    this.emptyMembersTarget.hidden = this.memberRowTargets.length > 0;
    this.reviewMembersTarget.replaceChildren();
    this.memberRowTargets.forEach(row => {
      const email = row.querySelector('input[type="email"]');
      const role = row.querySelector('[name$="[role]"]');
      const item = document.createElement("li");
      const label = document.createElement("span");
      label.textContent = email.value;
      const badge = document.createElement("small");
      badge.textContent = role.selectedOptions[0].textContent;
      item.append(label, badge);
      this.reviewMembersTarget.append(item);
      row.querySelector(".tc-icon-button").setAttribute("aria-label", `${this.messagesValue.remove} ${email.value}`);
    });
    if (!this.memberRowTargets.length) this.reviewMembersTarget.textContent = this.messagesValue.no_members;

    const selected = this.credentialTargets.filter(card => card.querySelector("input").checked);
    this.credentialTargets.forEach(card => { card.classList.toggle("is-selected", card.querySelector("input").checked); });
    this.credentialCountTargets.forEach(target => { target.textContent = selected.length; });
    if (this.hasSelectionCountTarget) {
      this.selectionCountTarget.textContent = `${selected.length} / ${this.credentialTargets.length}`;
      this.selectionCountTarget.setAttribute("aria-label", this.messagesValue.selected_count.replace("%count%", selected.length).replace("%total%", this.credentialTargets.length));
    }
    this.reviewCredentialsTarget.replaceChildren();
    selected.forEach(card => {
      const chip = document.createElement("span");
      chip.className = "tc-chip";
      chip.textContent = card.dataset.name;
      this.reviewCredentialsTarget.append(chip);
    });
    if (!selected.length) this.reviewCredentialsTarget.textContent = this.messagesValue.no_credentials;
    this.permissionTextTargets.forEach(target => { target.textContent = this.messagesValue[this.revealTarget.checked ? "reveal_title" : "restricted_title"]; });
  }
}
