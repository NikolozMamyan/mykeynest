import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'icon', 'showIcon', 'hideIcon'];

    toggle() {
        const type = this.inputTarget.type === 'password' ? 'text' : 'password';
        this.inputTarget.type = type;
        
        // Change l'icône
        if (this.hasIconTarget) {
            if (type === 'text') {
                this.iconTarget.classList.remove('fa-eye');
                this.iconTarget.classList.add('fa-eye-slash');
            } else {
                this.iconTarget.classList.remove('fa-eye-slash');
                this.iconTarget.classList.add('fa-eye');
            }
        }

        if (this.hasShowIconTarget && this.hasHideIconTarget) {
            this.showIconTarget.hidden = type === 'text';
            this.hideIconTarget.hidden = type !== 'text';
        }
    }
}
