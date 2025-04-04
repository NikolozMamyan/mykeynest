import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['content']

    connect() {
        this.loadUser()
    }

    async loadUser() {
        try {
            const res = await fetch('/api/me', { credentials: 'include' })

            if (!res.ok) throw new Error()

            const user = await res.json()

            this.contentTarget.innerHTML = `
            <div class="auth-bar">
                <div class="auth-info">
                    <span>👾 <strong>${user.email}</strong></span>
                    <span class="user-id-display">
                        <i class="fas fa-id-badge"></i> ID: <strong>${user.id}</strong>
                        <button class="copy-id-btn" title="Copy ID" data-id="${user.id}">
                            <i class="fas fa-copy"></i>
                        </button>
                    </span>
                </div>
                <button class="logout-btn" data-action="click->auth-navbar#logout" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        `
        

        } catch {
            // non connecté = on affiche rien
            this.element.remove()
        }
    }

    async logout() {
        const res = await fetch('/api/logout', {
            method: 'POST',
            credentials: 'include'
        })

        if (res.ok) {
            window.location.href = '/login'
        }
    }
}
