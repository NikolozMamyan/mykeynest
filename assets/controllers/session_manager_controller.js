import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['sessionsList', 'toast', 'toastMessage', 'logoutAllBtn']

  connect() {
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

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(data.error || `Erreur lors du chargement des appareils (${response.status})`)
      }

      this.renderSessions(data)
    } catch (error) {
      console.error('Erreur loadSessions:', error)
      this.showToast(error.message || 'Erreur lors du chargement des appareils', 'error')
      this.sessionsListTarget.innerHTML = this.getEmptyState()
    }
  }

  renderSessions(devices) {
    if (!Array.isArray(devices) || devices.length === 0) {
      this.sessionsListTarget.innerHTML = this.getEmptyState()
      return
    }

    this.sessionsListTarget.innerHTML = devices
      .map(device => this.getSessionCard(device))
      .join('')
  }

  getSessionCard(device) {
    const deviceIcon = this.getDeviceIcon(device.userAgent)
    const deviceName = device.deviceName || this.getDeviceName(device.userAgent)
    const deviceTypeLabel = device.deviceType === 'mobile' ? 'Mobile' : 'Desktop'
    const isBlocked = device.isBlocked
    const isCurrent = device.isCurrent
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
                <p>${this.escapeHtml(device.ipAddress || 'IP inconnue')}</p>
                <div class="device-type-row">
                  <span class="session-badge badge-device badge-device--${device.deviceType === 'mobile' ? 'mobile' : 'desktop'}">${deviceTypeLabel}</span>
                  <span class="device-expiry">Cookie: ${this.escapeHtml(device.sessionLifetimeLabel || '30 jours')}</span>
                </div>
              </div>
            </div>
          </div>
          ${isCurrent ? '<span class="session-badge badge-current">Appareil actuel</span>' : ''}
          ${isBlocked ? '<span class="session-badge badge-blocked">Bloqué</span>' : ''}
        </div>

        <div class="session-meta">
          <div class="meta-item">
            <i class="fas fa-clock"></i>
            <span>Dernière activité: ${this.formatDate(device.lastActivityAt)}</span>
          </div>
          <div class="meta-item">
            <i class="fas fa-calendar-plus"></i>
            <span>Première session: ${this.formatDate(device.createdAt)}</span>
          </div>
          <div class="meta-item">
            <i class="fas fa-layer-group"></i>
            <span>${device.sessionCount || 0} session(s)</span>
          </div>
          <div class="meta-item">
            <i class="fas fa-cookie-bite"></i>
            <span>Expire: ${this.formatDate(device.expiresAt)}</span>
          </div>
        </div>

        ${isBlocked ? `
          <div class="blocked-info">
            <i class="fas fa-shield-alt"></i>
            <div>
              <strong>Appareil bloqué</strong>
              <p>${this.escapeHtml(device.blockedReason || 'Raison non spécifiée')}</p>
            </div>
          </div>
        ` : ''}

        <div class="session-actions">
          ${!isCurrent && !isBlocked ? `
            <button
              class="btn-session btn-block"
              data-action="click->session-manager#blockDevice"
              data-device-id="${this.escapeHtml(device.deviceId)}"
            >
              <i class="fas fa-ban"></i>
              Bloquer cet appareil
            </button>
          ` : ''}

          ${isBlocked ? `
            <button
              class="btn-session btn-unblock"
              data-action="click->session-manager#unblockDevice"
              data-device-id="${this.escapeHtml(device.deviceId)}"
            >
              <i class="fas fa-unlock"></i>
              Débloquer
            </button>
          ` : ''}

          ${!isCurrent && !isBlocked && device.sessions?.length ? `
            <button
              class="btn-session btn-revoke"
              data-action="click->session-manager#revokeLatestSession"
              data-session-id="${device.sessions[0].id}"
            >
              <i class="fas fa-sign-out-alt"></i>
              Déconnecter la dernière session
            </button>
          ` : ''}
        </div>
      </div>
    `
  }

  async revokeLatestSession(event) {
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

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(data.error || `Erreur lors de la révocation (${response.status})`)
      }

      await this.loadSessions()
      this.showToast(data.message || 'Session déconnectée avec succès', 'success')
    } catch (error) {
      console.error('Erreur revokeLatestSession:', error)
      this.showToast(error.message || 'Erreur lors de la déconnexion', 'error')
    }
  }

  async blockDevice(event) {
    const deviceId = event.currentTarget.dataset.deviceId

    const reason = prompt(
      'Pourquoi voulez-vous bloquer cet appareil ?\n(Cette action empêchera toute future connexion depuis cet appareil)',
      'Appareil suspect'
    )

    if (!reason) return

    try {
      const response = await fetch(`/api/sessions/devices/${encodeURIComponent(deviceId)}/block`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ reason })
      })

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(data.error || `Erreur lors du blocage (${response.status})`)
      }

      await this.loadSessions()
      this.showToast(data.message || 'Appareil bloqué avec succès', 'success')
    } catch (error) {
      console.error('Erreur blockDevice:', error)
      this.showToast(error.message || 'Erreur lors du blocage', 'error')
    }
  }

  async unblockDevice(event) {
    const deviceId = event.currentTarget.dataset.deviceId

    if (!confirm('Voulez-vous vraiment débloquer cet appareil ?')) {
      return
    }

    try {
      const response = await fetch(`/api/sessions/devices/${encodeURIComponent(deviceId)}/unblock`, {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json'
        }
      })

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(data.error || `Erreur lors du déblocage (${response.status})`)
      }

      await this.loadSessions()
      this.showToast(data.message || 'Appareil débloqué avec succès', 'success')
    } catch (error) {
      console.error('Erreur unblockDevice:', error)
      this.showToast(error.message || 'Erreur lors du déblocage', 'error')
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

      const data = await response.json().catch(() => ({}))

      if (!response.ok) {
        throw new Error(data.error || `Erreur lors de la déconnexion (${response.status})`)
      }

      await this.loadSessions()
      this.showToast(`${data.revokedCount ?? 0} session(s) déconnectée(s)`, 'success')
    } catch (error) {
      console.error('Erreur logoutAll:', error)
      this.showToast(error.message || 'Erreur lors de la déconnexion', 'error')
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
    if (Number.isNaN(date.getTime())) return 'Date inconnue'

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
        <p>Aucun appareil connu</p>
      </div>
    `
  }

  escapeHtml(text) {
    const div = document.createElement('div')
    div.textContent = text ?? ''
    return div.innerHTML
  }
}
