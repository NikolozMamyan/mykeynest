import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'container', 'progressBar', 'feedback'];

    connect() {
        this.check();
    }

    check() {
        const password = this.inputTarget.value;
        
        if (password.length === 0) {
            this.containerTarget.style.display = 'none';
            return;
        }

        this.containerTarget.style.display = 'block';
        
        const strength = this.calculateStrength(password);
        this.updateUI(strength);
    }

    calculateStrength(password) {
        let score = 0;
        
        // Longueur
        if (password.length >= 8) score++;
        if (password.length >= 12) score++;
        if (password.length >= 16) score++;
        
        // Complexité
        if (/[a-z]/.test(password)) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;
        
        // Déterminer le niveau
        if (score <= 3) return { level: 'weak', text: 'Faible', color: 'var(--color-danger-start)' };
        if (score <= 5) return { level: 'medium', text: 'Moyen', color: 'var(--color-warning)' };
        return { level: 'strong', text: 'Fort', color: 'var(--color-success)' };
    }

    updateUI(strength) {
        this.progressBarTarget.setAttribute('data-strength', strength.level);
        this.progressBarTarget.style.backgroundColor = strength.color;
        this.feedbackTarget.textContent = `Force du mot de passe : ${strength.text}`;
        this.feedbackTarget.style.color = strength.color;
    }
}