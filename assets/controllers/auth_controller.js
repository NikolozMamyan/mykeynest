// assets/controllers/auth_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['email', 'company', 'password', 'result']

  connect() {
    console.log("Stimulus auth controller is active ✅");
  }

  getNextUrl() {
    const params = new URLSearchParams(window.location.search)
    const next = params.get('next')

    // Sécurité: on n'accepte que des chemins internes
    if (!next) return null
    if (!next.startsWith('/')) return null
    if (next.startsWith('//')) return null
    return next
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
        <div style="color: var(--color-primary); font-weight: 500;">
          ✔ Login successful... redirecting...
          <span class="spinner"></span>
        </div>
      `

      const nextUrl = this.getNextUrl() || '/app/credential'

      setTimeout(() => {
        window.location.href = nextUrl
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
      body: JSON.stringify({ company, email, password }),
      credentials: 'include'
    })

    if (response.ok) {
      this.resultTarget.innerHTML = `
        <div style="color: var(--color-primary); font-weight: 500;">
          ✔ Account created... redirecting...
          <span class="spinner"></span>
        </div>
      `

      const nextUrl = this.getNextUrl() || '/app/credential'

      setTimeout(() => {
        window.location.href = nextUrl
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

  async logout(event) {
    event.preventDefault()

    try {
      const response = await fetch("/api/logout", {
        method: "POST",
        credentials: "include",
        headers: { "X-Requested-With": "XMLHttpRequest" },
      })

      if (!response.ok) {
        window.location.href = "/login"
        return
      }

      window.location.href = "/login"
    } catch (err) {
      console.error("Erreur réseau :", err)
    }
  }
}
