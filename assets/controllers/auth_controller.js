import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['email', 'company', 'password', 'result', 'accountType', 'companyField']
  static values = { locale: String }

  get messages() {
    return this.localeValue === 'en'
      ? { login: 'Signing in…', verify: 'Check your email to approve this sign-in.', loggedIn: 'Signed in. Redirecting…', loginError: 'Unable to sign in.', network: 'Network error. Please try again.', register: 'Creating your account…', registered: 'Account created. Redirecting…', registerError: 'Unable to create your account.' }
      : { login: 'Connexion en cours…', verify: 'Vérifiez votre e-mail pour valider cette connexion.', loggedIn: 'Connexion réussie. Redirection…', loginError: 'Connexion impossible.', network: 'Erreur réseau. Veuillez réessayer.', register: 'Création du compte en cours…', registered: 'Compte créé. Redirection…', registerError: 'Création du compte impossible.' }
  }

  showFeedback(message, error = false) {
    const notice = document.createElement('div')
    notice.className = error ? 'flash-error' : 'auth-feedback'
    notice.textContent = message
    this.resultTarget.replaceChildren(notice)
  }

  connect() {
    this.changeAccountType()
  }

  changeAccountType() {
    if (!this.hasCompanyFieldTarget) return
    const professional = this.accountTypeTargets.some(input => input.checked && input.value === 'professional')
    this.companyFieldTarget.hidden = !professional
    this.companyTarget.disabled = !professional
    this.companyTarget.required = professional
  }

  google(event) {
    if (!this.validateCompany()) {
      event.preventDefault()
      return
    }
    const form = event.currentTarget
    form.elements.accountType.value = this.accountTypeTargets.find(input => input.checked)?.value || 'personal'
    form.elements.company.value = this.hasCompanyTarget && !this.companyTarget.disabled ? this.companyTarget.value.trim() : ''
  }

  validateCompany() {
    if (!this.hasCompanyTarget || this.companyTarget.disabled) return true
    this.companyTarget.value = this.companyTarget.value.trim()
    return this.companyTarget.reportValidity()
  }

  getNextUrl() {
    const params = new URLSearchParams(window.location.search)
    const next = params.get('next')

    if (!next) return null
    if (!next.startsWith('/')) return null
    if (next.startsWith('//')) return null
    if (/[\\\x00-\x20]/.test(next)) return null

    return next
  }

  async login(event) {
    event.preventDefault()

    const submitButton = event.currentTarget.querySelector('button[type="submit"]')
    submitButton.disabled = true

    const email = this.emailTarget.value.trim()
    const password = this.passwordTarget.value

    this.showFeedback(this.messages.login)

    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ email, password }),
        credentials: 'include'
      })

      const data = await response.json().catch(() => ({}))

      if (response.status === 202 && data.status === 'email_verification_required') {
        this.showFeedback(this.messages.verify)

        setTimeout(() => {
          window.location.href = '/app/security/pending-login'
        }, 900)

        return
      }

      if (response.ok) {
        this.showFeedback(this.messages.loggedIn)

        const nextUrl = this.getNextUrl() || data.redirectUrl || '/app/credential'

        setTimeout(() => {
          window.location.href = nextUrl
        }, 1200)

        return
      }

      this.showFeedback(data.message || data.error || this.messages.loginError, true)
    } catch (error) {
      console.error('Erreur login:', error)

      this.showFeedback(this.messages.network, true)
    } finally {
      submitButton.disabled = false
    }
  }

  async register(event) {
    event.preventDefault()
    if (!this.validateCompany()) return

    const submitButton = event.currentTarget.querySelector('button[type="submit"]')
    submitButton.disabled = true

    const company = this.companyTarget.disabled ? '' : this.companyTarget.value.trim()
    const accountType = this.accountTypeTargets.find(input => input.checked)?.value || 'personal'
    const email = this.emailTarget.value.trim()
    const password = this.passwordTarget.value

    this.showFeedback(this.messages.register)

    try {
      const response = await fetch('/api/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ company, accountType, email, password }),
        credentials: 'include'
      })

      const data = await response.json().catch(() => ({}))

      if (response.ok) {
        this.showFeedback(this.messages.registered)

        const nextUrl = this.getNextUrl() || data.redirectUrl || '/app/credential'

        setTimeout(() => {
          window.location.href = nextUrl
        }, 1500)

        return
      }

      this.showFeedback(data.error || this.messages.registerError, true)
    } catch (error) {
      console.error('Erreur register:', error)

      this.showFeedback(this.messages.network, true)
    } finally {
      submitButton.disabled = false
    }
  }

  async logout(event) {
    event.preventDefault()
    this.clearInterfaceCache()

    try {
      const response = await fetch('/api/logout', {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })

      if (!response.ok) {
        window.location.href = '/login'
        return
      }

      window.location.href = '/login'
    } catch (err) {
      console.error('Erreur reseau :', err)
    }
  }

  clearInterfaceCache() {
    try {
      Object.keys(sessionStorage)
        .filter((key) => key.startsWith('mykeynest:notifications:') || key.startsWith('mykeynest:credential-count:'))
        .forEach((key) => sessionStorage.removeItem(key))
    } catch {}
  }
}
