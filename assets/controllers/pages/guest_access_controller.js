import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['password', 'confirmation', 'strength', 'strengthLabel', 'match', 'choice', 'credential', 'select', 'empty', 'toast'];
    static values = { translations: Object };

    connect() {
        this.element.classList.add('is-enhanced');
        if (this.hasPasswordTarget) this.checkPassword();
        if (this.hasChoiceTarget) this.showCredential(this.choiceTargets[0].dataset.index);
    }

    disconnect() {
        window.clearTimeout(this.toastTimeout);
    }

    checkPassword() {
        const password = this.passwordTarget.value;
        const confirmation = this.confirmationTarget.value;
        const matches = password === confirmation;
        this.confirmationTarget.setCustomValidity(confirmation && !matches ? this.translationsValue.mismatch : '');
        this.confirmationTarget.setAttribute('aria-invalid', confirmation && !matches ? 'true' : 'false');
        this.matchTarget.textContent = confirmation ? (matches ? this.translationsValue.match : this.translationsValue.mismatch) : '';
        this.matchTarget.classList.toggle('is-error', Boolean(confirmation && !matches));
        let score = 0;
        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;
        const level = score < 3 ? 'weak' : score < 5 ? 'medium' : 'strong';
        this.strengthTarget.style.width = password ? `${Math.max(1, score) * 20}%` : '0%';
        this.strengthTarget.dataset.level = level;
        this.strengthLabelTarget.textContent = password.length < 8 ? this.translationsValue.min : this.translationsValue[level];
    }

    validateRegistration(event) {
        this.checkPassword();
        if (!event.currentTarget.reportValidity()) event.preventDefault();
    }

    toggleInput(event) {
        const button = event.currentTarget;
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.textContent = visible ? button.dataset.hide : button.dataset.show;
        button.setAttribute('aria-pressed', String(visible));
    }

    selectCredential(event) {
        this.showCredential(event.currentTarget.dataset.index || event.currentTarget.value);
    }

    showCredential(index) {
        this.choiceTargets.forEach(choice => {
            const selected = choice.dataset.index === index;
            choice.setAttribute('aria-selected', String(selected));
            choice.tabIndex = selected ? 0 : -1;
        });
        this.credentialTargets.forEach(credential => {
            credential.hidden = credential.dataset.index !== index;
            credential.querySelectorAll('[data-password-visible]').forEach(secret => { secret.hidden = true; });
            credential.querySelectorAll('[data-password-hidden]').forEach(secret => { secret.hidden = false; });
            const toggle = credential.querySelector('[data-action="guest-access#toggleSecret"]');
            if (toggle) {
                toggle.textContent = this.translationsValue.show;
                toggle.setAttribute('aria-pressed', 'false');
            }
        });
        if (this.hasSelectTarget) this.selectTarget.value = index;
    }

    choiceKeydown(event) {
        const choices = this.choiceTargets.filter(choice => !choice.hidden);
        const current = choices.indexOf(event.currentTarget);
        let next;
        if (event.key === 'ArrowDown') next = (current + 1) % choices.length;
        else if (event.key === 'ArrowUp') next = (current - 1 + choices.length) % choices.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = choices.length - 1;
        else return;
        event.preventDefault();
        this.showCredential(choices[next].dataset.index);
        choices[next].focus();
    }

    filterCredentials(event) {
        const query = event.currentTarget.value.trim().toLocaleLowerCase();
        this.choiceTargets.forEach(choice => { choice.hidden = !choice.dataset.search.includes(query); });
        const visible = this.choiceTargets.filter(choice => !choice.hidden);
        this.emptyTarget.hidden = visible.length > 0;
        if (visible.length && !visible.some(choice => choice.getAttribute('aria-selected') === 'true')) {
            this.showCredential(visible[0].dataset.index);
        }
    }

    toggleSecret(event) {
        const button = event.currentTarget;
        const field = button.closest('dd');
        const secret = field.querySelector('[data-password-visible]');
        const visible = secret.hidden;
        secret.hidden = !visible;
        field.querySelector('[data-password-hidden]').hidden = visible;
        button.textContent = this.translationsValue[visible ? 'hide' : 'show'];
        button.setAttribute('aria-pressed', String(visible));
    }

    async copy(event) {
        let message;
        try {
            await navigator.clipboard.writeText(event.currentTarget.dataset.copy);
            message = this.translationsValue.copied;
        } catch {
            message = this.translationsValue.copyError;
        }
        this.toastTarget.textContent = message;
        this.toastTarget.hidden = false;
        window.clearTimeout(this.toastTimeout);
        this.toastTimeout = window.setTimeout(() => { this.toastTarget.hidden = true; }, 2200);
    }
}
