import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['sessionsList', 'toast', 'toastMessage', 'logoutAllBtn']

  connect() {
    console.log('Session Manager controller connected')
    this.toastTimeout = null
    this.loadSessions()
  }

  async loadSessions() {
    try {
      const response = await fetch('/api/sessions', {
        method: 'GET',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'Cache-Control': 'no-cache'
        },
        cache: 'no-store'
      })

      if (!response.ok) {
        throw new Error(`Erreur lors du chargement des sessions (${response.status})`)
      }

      const sessions = await response.json()
      console.log('Sessions reçues:', sessions)
      this.renderSessions(sessions)
    } catch (error) {
      console.error('Erreur loadSessions:', error)
      this.showToast('Erreur lors du chargement des sessions', 'error')
      this.sessionsListTarget.innerHTML = this.getEmptyState()
    }
  }

  renderSessions(sessions) {
    if (!Array.isArray(sessions) || sessions.length === 0) {
      this.sessionsListTarget.innerHTML = this.getEmptyState()
      return
    }

    this.sessionsListTarget.innerHTML = sessions
      .map(session => this.getSessionCard(session))
      .join('')
  }

  getSessionCard(session) {
    const deviceIcon = this.getDeviceIcon(session.userAgent)
    const deviceName = session.deviceName || this.getDeviceName(session.userAgent)
    const isBlocked = session.isBlocked
    const isCurrent = session.isCurrent
    const cardClass = `session-card ${isCurrent ? 'current' : ''} ${isBlocked ? 'blocked' : ''}`

    return `
      <div class="${cardClass}">
        <div class="session-header">
          <div class="session-info">
            <div class="session-device">
              <div class="device-icon">
                <i class="${deviceIcon}"></i>
              </div>
              <div class="device-details">
                <h3>${this.escapeHtml(deviceName)}</h3>
                <p>${this.escapeHtml(session.ipAddress || 'IP inconnue')}</p>
              </div>
            </div>
          </div>
          ${isCurrent ? '<span class="session-badge badge-current">Session actuelle</span>' : ''}
          ${isBlocked ? '<span class="session-badge badge-blocked">Bloqué</span>' : ''}
        </div>

        <div class="session-meta">
          <div class="meta-item">
            <i class="fas fa-clock"></i>
            <span>Dernière activité: ${this.formatDate(session.lastActivityAt)}</span>
          </div>
          <div class="meta-item">
            <i class="fas fa-calendar-plus"></i>
            <span>Créé: ${this.formatDate(session.createdAt)}</span>
          </div>
        </div>

        ${isBlocked ? `
          <div class="blocked-info">
            <i class="fas fa-shield-alt"></i>
            <div>
              <strong>Session bloquée</strong>
              <p>${this.escapeHtml(session.blockedReason || 'Raison non spécifiée')}</p>
            </div>
          </div>
        ` : ''}

        <div class="session-actions">
          ${!isCurrent && !session.isRevoked ? `
            <button 
              class="btn-session btn-revoke"
              data-action="click->session-manager#revokeSession"
              data-session-id="${session.id}"
            >
              <i class="fas fa-sign-out-alt"></i>
              Déconnecter
            </button>
          ` : ''}
          
          ${!isCurrent && !isBlocked ? `
            <button 
              class="btn-session btn-block"
              data-action="click->session-manager#blockSession"
              data-session-id="${session.id}"
            >
              <i class="fas fa-ban"></i>
              Bloquer cet appareil
            </button>
          ` : ''}

          ${isBlocked ? `
            <button 
              class="btn-session btn-unblock"
              data-action="click->session-manager#unblockSession"
              data-session-id="${session.id}"
            >
              <i class="fas fa-unlock"></i>
              Débloquer
            </button>
          ` : ''}
        </div>
      </div>
    `
  }

  async revokeSession(event) {
    const sessionId = event.currentTarget.dataset.sessionId

    if (!confirm('Voulez-vous vraiment déconnecter cette session ?')) {
      return
    }

    try {
      const response = await fetch(`/api/sessions/${sessionId}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error(`Erreur lors de la révocation (${response.status})`)
      }

      await this.loadSessions()
      this.showToast('Session déconnectée avec succès', 'success')
    } catch (error) {
      console.error('Erreur revokeSession:', error)
      this.showToast('Erreur lors de la déconnexion', 'error')
    }
  }

  async blockSession(event) {
    const sessionId = event.currentTarget.dataset.sessionId

    const reason = prompt(
      'Pourquoi voulez-vous bloquer cet appareil ?\n(Cette action empêchera toute future connexion depuis cet appareil)',
      'Appareil suspect'
    )

    if (!reason) return

    try {
      const response = await fetch(`/api/sessions/${sessionId}/block`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ reason })
      })

      if (!response.ok) {
        throw new Error(`Erreur lors du blocage (${response.status})`)
      }

      await this.loadSessions()
      this.showToast('Appareil bloqué avec succès', 'success')
    } catch (error) {
      console.error('Erreur blockSession:', error)
      this.showToast('Erreur lors du blocage', 'error')
    }
  }

  async unblockSession(event) {
    const sessionId = event.currentTarget.dataset.sessionId

    if (!confirm('Voulez-vous vraiment débloquer cet appareil ?')) {
      return
    }

    try {
      const response = await fetch(`/api/sessions/${sessionId}/unblock`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error(`Erreur lors du déblocage (${response.status})`)
      }

      await this.loadSessions()
      this.showToast('Appareil débloqué avec succès', 'success')
    } catch (error) {
      console.error('Erreur unblockSession:', error)
      this.showToast('Erreur lors du déblocage', 'error')
    }
  }

  async logoutAll() {
    if (!confirm('Voulez-vous vraiment déconnecter tous les autres appareils ?')) {
      return
    }

    if (this.hasLogoutAllBtnTarget) {
      this.logoutAllBtnTarget.disabled = true
    }

    try {
      const response = await fetch('/api/sessions/logout-all', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      })

      if (!response.ok) {
        throw new Error(`Erreur lors de la déconnexion (${response.status})`)
      }

      const data = await response.json()

      await this.loadSessions()
      this.showToast(`${data.revokedCount ?? 0} session(s) déconnectée(s)`, 'success')
    } catch (error) {
      console.error('Erreur logoutAll:', error)
      this.showToast('Erreur lors de la déconnexion', 'error')
    } finally {
      if (this.hasLogoutAllBtnTarget) {
        this.logoutAllBtnTarget.disabled = false
      }
    }
  }

  getDeviceIcon(userAgent) {
    if (!userAgent) return 'fas fa-laptop'

    const ua = userAgent.toLowerCase()
    if (ua.includes('mobile') || ua.includes('android') || ua.includes('iphone')) {
      return 'fas fa-mobile-alt'
    }
    if (ua.includes('tablet') || ua.includes('ipad')) {
      return 'fas fa-tablet-alt'
    }
    return 'fas fa-laptop'
  }

  getDeviceName(userAgent) {
    if (!userAgent) return 'Appareil inconnu'

    const ua = userAgent.toLowerCase()
    if (ua.includes('edg')) return 'Edge'
    if (ua.includes('chrome')) return 'Chrome'
    if (ua.includes('firefox')) return 'Firefox'
    if (ua.includes('safari')) return 'Safari'
    return 'Navigateur inconnu'
  }

  formatDate(dateString) {
    if (!dateString) return 'Date inconnue'

    const date = new Date(dateString)
    if (isNaN(date.getTime())) return 'Date inconnue'

    const now = new Date()
    const diffMs = now - date
    const diffMins = Math.floor(diffMs / 60000)
    const diffHours = Math.floor(diffMs / 3600000)
    const diffDays = Math.floor(diffMs / 86400000)

    if (diffMins < 1) return 'À l’instant'
    if (diffMins < 60) return `Il y a ${diffMins} min`
    if (diffHours < 24) return `Il y a ${diffHours}h`
    if (diffDays < 7) return `Il y a ${diffDays}j`

    return date.toLocaleDateString('fr-FR', {
      day: 'numeric',
      month: 'short',
      year: 'numeric'
    })
  }

showToast(message, type = 'success') {

  if (!this.hasToastTarget) {
    console.warn('Toast target introuvable — création automatique')

    const toast = document.createElement('div')
    toast.className = 'toast'

    toast.innerHTML = `
      <div class="toast-content">
        <i class="toast-icon"></i>
        <span class="toast-message"></span>
      </div>
    `

    document.body.appendChild(toast)

    this.toastElement = toast
    this.toastMessageElement = toast.querySelector('.toast-message')
  }

  const toast = this.toastElement || this.toastTarget
  const messageEl = this.toastMessageElement || this.toastMessageTarget

  messageEl.textContent = message

  toast.className = `toast ${type}`
  toast.classList.add('show')

  clearTimeout(this.toastTimeout)

  this.toastTimeout = setTimeout(() => {
    toast.classList.remove('show')
  }, 3000)
}

  getEmptyState() {
    return `
      <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <p>Aucune session active</p>
      </div>
    `
  }

  escapeHtml(text) {
    const div = document.createElement('div')
    div.textContent = text
    return div.innerHTML
  }
}