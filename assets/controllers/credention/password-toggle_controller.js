import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'icon'];

    toggle() {
        const type = this.inputTarget.type === 'password' ? 'text' : 'password';
        this.inputTarget.type = type;
        
        // Change l'icône
        if (type === 'text') {
            this.iconTarget.classList.remove('fa-eye');
            this.iconTarget.classList.add('fa-eye-slash');
        } else {
            this.iconTarget.classList.remove('fa-eye-slash');
            this.iconTarget.classList.add('fa-eye');
        }
    }
}