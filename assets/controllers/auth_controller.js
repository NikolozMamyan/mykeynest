// assets/controllers/auth_controller.js

import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['email', 'company', 'password', 'result']


        connect() {
            console.log("Stimulus auth controller is active ✅");
        }

    async login(event) {
        event.preventDefault()

        const email = this.emailTarget.value
        const password = this.passwordTarget.value

        const response = await fetch('/api/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password }),
            credentials: 'include'
        })

        if (response.ok) {
            this.resultTarget.innerHTML = `
                <div style="color: var(--health-color);">✔ Login successful... redirecting...</div>
                <span class="spinner"></span>
            `
            setTimeout(() => {
                window.location.href = '/app/credential'
            }, 1200)
        } else {
            const error = await response.json()
            this.resultTarget.innerHTML = `
                <div style="color: var(--damage-color);">
                    ⚠ ${error.error || 'Login failed'}
                </div>
            `
        }
    }

    async register(event) {
        event.preventDefault()

        const company = this.companyTarget.value
        const email = this.emailTarget.value
        const password = this.passwordTarget.value

        const response = await fetch('/api/register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ company,email, password })
        })

        if (response.ok) {
            this.resultTarget.innerHTML = `
                <div style="color: var(--health-color);">
                    ✔ Account created! You can now log in.
                </div>
                 <span class="spinner"></span>
            `
            setTimeout(() => {
                window.location.href = '/app/credential'
            }, 1500)
        } else {
            const errorData = await response.json()
            this.resultTarget.innerHTML = `
                <div style="color: var(--damage-color);">
                    ⚠ ${errorData.error || 'Something went wrong.'}
                </div>
            `
        }
    }
}
