import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['status', 'message', 'title', 'email', 'steps', 'hint', 'retry']
  static values = { poll: { type: Boolean, default: true }, messages: Object }

  connect() {
    this.active = true
    this.checking = false
    this.polling = this.pollValue
    if (this.polling) this.checkStatus()
  }

  disconnect() {
    this.active = false
    clearTimeout(this.timeout)
  }

  showState(state, message = null) {
    const labels = this.messagesValue[state]
    this.element.dataset.state = state
    this.titleTarget.textContent = labels.title
    this.statusTarget.textContent = labels.status
    this.messageTarget.textContent = message || labels.message
    this.stepsTarget.hidden = state !== 'pending'
    this.hintTarget.hidden = state !== 'pending'
    this.retryTarget.textContent = state === 'unavailable' ? this.messagesValue.retry : this.messagesValue.check
    if (['rejected', 'expired', 'missing', 'completed', 'different_device'].includes(state)) {
      this.polling = false
      this.retryTarget.hidden = true
    }
  }

  async checkStatus() {
    if (!this.active || this.checking || !this.polling) return
    clearTimeout(this.timeout)
    this.checking = true
    this.retryTarget.disabled = true
    try {
      const response = await fetch('/api/login-challenge/status', {
        credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' }
      })
      const data = await response.json()
      if (!this.active) return
      if (!response.ok) {
        this.showState(response.status === 404 ? 'missing' : 'unavailable')
        return
      }
      if (data.email) {
        this.emailTarget.textContent = data.email
        this.emailTarget.hidden = false
      }
      if (data.status === 'completed' && data.redirectUrl) {
        this.polling = false
        window.location.assign(data.redirectUrl)
        return
      }
      if (!['pending', 'approved', 'rejected', 'expired', 'completed'].includes(data.status)) {
        this.showState('unavailable')
        return
      }
      this.showState(data.status)
      if (data.status === 'approved') {
        this.messageTarget.textContent = this.messagesValue.finishing
        await this.completeLogin()
      }
    } catch {
      if (this.active) this.showState('unavailable')
    } finally {
      this.checking = false
      this.retryTarget.disabled = false
      if (this.active && this.polling) {
        this.timeout = setTimeout(() => this.checkStatus(), 3000)
      }
    }
  }

  async completeLogin() {
    const response = await fetch('/api/login-challenge/complete', {
      method: 'POST', credentials: 'include', cache: 'no-store', headers: { Accept: 'application/json' }
    })
    const data = await response.json()
    if (!this.active) return
    if (response.ok && data.redirectUrl) {
      this.polling = false
      window.location.assign(data.redirectUrl)
      return
    }
    if (response.status === 409 && data.status === 'completed') {
      // Another tab may have just received the session cookie; check it on the next poll.
      this.messageTarget.textContent = this.messagesValue.finishing
      return
    }
    const state = ['expired', 'rejected', 'different_device'].includes(data.status) ? data.status : 'unavailable'
    this.showState(state)
  }
}
