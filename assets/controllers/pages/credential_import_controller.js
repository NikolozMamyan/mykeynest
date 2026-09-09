import { Controller } from "@hotwired/stimulus"

const REQUIRED_COLUMNS = {
  site: ["domain", "url", "uri", "website", "login_uri", "login_url", "origin", "hostname", "domaine", "site", "adresse_web"],
  username: ["username", "login_username", "user", "email", "login", "identifiant", "utilisateur", "nom_utilisateur", "nom_d_utilisateur"],
  password: ["password", "login_password", "pass", "mot_de_passe", "motdepasse"],
}

export default class extends Controller {
  static targets = [
    "input", "dropzone", "emptyState", "selectedState", "fileName", "fileSize",
    "feedback", "submitButton", "submitLabel",
  ]

  static values = {
    maxSize: Number,
    invalidType: String,
    tooLarge: String,
    invalidColumns: String,
    fileReady: String,
  }

  fileChanged() {
    this.inspect(this.inputTarget.files?.[0] ?? null)
  }

  dragEnter(event) {
    event.preventDefault()
    this.dropzoneTarget.classList.add("is-dragging")
  }

  dragOver(event) {
    event.preventDefault()
    if (event.dataTransfer) event.dataTransfer.dropEffect = "copy"
  }

  dragLeave(event) {
    if (!this.dropzoneTarget.contains(event.relatedTarget)) {
      this.dropzoneTarget.classList.remove("is-dragging")
    }
  }

  drop(event) {
    event.preventDefault()
    this.dropzoneTarget.classList.remove("is-dragging")
    const file = event.dataTransfer?.files?.[0]
    if (!file) return

    const transfer = new DataTransfer()
    transfer.items.add(file)
    this.inputTarget.files = transfer.files
    this.inspect(file)
  }

  clear(event) {
    event?.preventDefault()
    this.inputTarget.value = ""
    this.emptyStateTarget.hidden = false
    this.selectedStateTarget.hidden = true
    this.submitButtonTarget.disabled = true
    this.setFeedback("")
  }

  submit() {
    if (this.submitButtonTarget.disabled) return
    this.submitButtonTarget.disabled = true
    this.submitButtonTarget.classList.add("is-loading")
    this.submitButtonTarget.querySelector("i")?.classList.replace("fa-file-import", "fa-spinner")
    this.submitLabelTarget.textContent = this.submitLabelTarget.dataset.loadingLabel
  }

  async inspect(file) {
    if (!file) {
      this.clear()
      return
    }

    this.emptyStateTarget.hidden = true
    this.selectedStateTarget.hidden = false
    this.fileNameTarget.textContent = file.name
    this.fileSizeTarget.textContent = this.formatBytes(file.size)
    this.submitButtonTarget.disabled = true

    if (!file.name.toLowerCase().endsWith(".csv")) {
      this.setFeedback(this.invalidTypeValue, "error")
      return
    }

    if (file.size > this.maxSizeValue) {
      this.setFeedback(this.tooLargeValue, "error")
      return
    }

    try {
      const text = await file.slice(0, 65536).text()
      if (this.inputTarget.files?.[0] !== file) return
      if (!this.hasRequiredColumns(text)) {
        this.setFeedback(this.invalidColumnsValue, "error")
        return
      }
    } catch {
      this.setFeedback(this.invalidColumnsValue, "error")
      return
    }

    this.submitButtonTarget.disabled = false
    this.setFeedback(this.fileReadyValue, "success")
  }

  hasRequiredColumns(text) {
    const lines = text.replace(/^\uFEFF/, "").split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
    if (!lines.length) return false

    let separator = null
    if (/^sep=.$/i.test(lines[0])) separator = lines.shift().slice(-1)
    if (!lines.length) return false

    separator ??= this.detectSeparator(lines[0])
    const headers = this.parseLine(lines[0], separator).map((header) => this.normalizeHeader(header))

    return Object.values(REQUIRED_COLUMNS).every((aliases) => aliases.some((alias) => headers.includes(alias)))
  }

  detectSeparator(line) {
    return [",", ";", "\t"].reduce((best, separator) => {
      const count = this.parseLine(line, separator).length
      return count > best.count ? { separator, count } : best
    }, { separator: ",", count: 0 }).separator
  }

  parseLine(line, separator) {
    const values = []
    let value = ""
    let quoted = false

    for (let index = 0; index < line.length; index += 1) {
      const character = line[index]
      if (character === '"' && quoted && line[index + 1] === '"') {
        value += '"'
        index += 1
      } else if (character === '"') {
        quoted = !quoted
      } else if (character === separator && !quoted) {
        values.push(value)
        value = ""
      } else {
        value += character
      }
    }

    values.push(value)
    return values
  }

  normalizeHeader(value) {
    return value.trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "")
  }

  setFeedback(message, status = "") {
    this.feedbackTarget.textContent = message
    this.feedbackTarget.classList.toggle("is-success", status === "success")
    this.feedbackTarget.classList.toggle("is-error", status === "error")
  }

  formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }
}
