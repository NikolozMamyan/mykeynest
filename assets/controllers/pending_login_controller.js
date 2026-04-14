import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['status', 'message']

  connect() {
    this.interval = setInterval(() => this.checkStatus(), 3000)
    this.checkStatus()
  }

  disconnect() {
    if (this.interval) {
      clearInterval(this.interval)
    }
  }

  async checkStatus() {
    try {
      const response = await fetch('/api/login-challenge/status', {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      })

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        this.statusTarget.textContent = 'error'
        this.messageTarget.textContent = data.error || 'Tentative introuvable'
        return
      }

      this.statusTarget.textContent = data.status

      if (data.status === 'pending') {
        this.messageTarget.textContent = 'Validation en attente. Vérifiez votre e-mail.'
        return
      }

      if (data.status === 'approved') {
        this.messageTarget.textContent = 'Connexion approuvée. Finalisation en cours...'
        await this.completeLogin()
        return
      }

      if (data.status === 'rejected') {
        this.messageTarget.textContent = 'Connexion refusée. Cet appareil a été bloqué.'
        clearInterval(this.interval)
        return
      }

      if (data.status === 'expired') {
        this.messageTarget.textContent = 'La demande a expiré. Veuillez recommencer.'
        clearInterval(this.interval)
        return
      }

      if (data.status === 'completed') {
        window.location.href = '/app/credential'
      }
    } catch (error) {
      this.messageTarget.textContent = 'Erreur réseau.'
    }
  }

  async completeLogin() {
    const response = await fetch('/api/login-challenge/complete', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Accept': 'application/json'
      }
    })

    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
      this.messageTarget.textContent = data.error || 'Impossible de finaliser la connexion.'
      return
    }

    clearInterval(this.interval)
    window.location.href = data.redirectUrl || '/app/credential'
  }
}
