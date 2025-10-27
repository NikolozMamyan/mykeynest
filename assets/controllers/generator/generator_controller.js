import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = [
    "output", "outputContainer", "lengthInput", "lengthValue",
    "uppercase", "lowercase", "numbers", "symbols", "meter",
    "toastWrap", "list", "saveName", "credName", "credDomain", "credUsername"
  ];

  connect() {
    this.recentPasswords = this.loadRecentPasswords();
    this.currentDraftId = null;
    this.pendingSavePassword = null;
    this.updateLength();
    this.renderRecent();
    this.updateMeter("", 0);
  }

  // === Génération
  generatePassword() {
    const len = +this.lengthInputTarget.value;
    const pools = this.getCharPools();
    const opts = {
      upper: this.uppercaseTarget.checked,
      lower: this.lowercaseTarget.checked,
      nums: this.numbersTarget.checked,
      syms: this.symbolsTarget.checked
    };

    let chars = "";
    Object.entries(opts).forEach(([k,v]) => { if(v) chars += pools[k]; });
    if(!chars) return this.toast("error","Options","Choisissez au moins un type de caractère.");

    const arr = [];
    Object.entries(opts).forEach(([k,v]) => { if(v) arr.push(pools[k][this.rand(pools[k].length)]); });
    while(arr.length < len) arr.push(chars[this.rand(chars.length)]);
    for(let i=arr.length-1;i>0;i--){ const j=this.rand(i+1); [arr[i],arr[j]]=[arr[j],arr[i]]; }
    const pwd = arr.join("");

    this.outputTarget.textContent = pwd;
    this.outputContainerTarget.classList.add("generated");
    setTimeout(()=>this.outputContainerTarget.classList.remove("generated"),500);
    this.addRecentPassword(pwd);
    this.renderRecent();
    this.updateMeter(pwd, chars.length);
  }

  async copyPassword() {
    const p = this.outputTarget.textContent.trim();
    if (!p || p.startsWith("•")) return;
    await navigator.clipboard.writeText(p);
    this.toast("success", "Copié", "Mot de passe copié !");
  }

  updateLength() {
    this.lengthValueTarget.textContent = this.lengthInputTarget.value;
  }

  setActiveTab(button) {
  // Enlève "active" sur tous les boutons du switcher
  button.closest(".switcher").querySelectorAll("button").forEach(b => b.classList.remove("active"));
  // Active le bouton cliqué
  button.classList.add("active");
}


  // === Onglets
  showRecents(e) { this.setActiveTab(e.currentTarget); this.renderRecent(); }
async showDrafts(e) {
  if (e && e.currentTarget) this.setActiveTab(e.currentTarget);
  this.listTarget.innerHTML = "<p>Chargement...</p>";
  try {
    const res = await fetch("/api/generator/list-drafts");
    const data = await res.json();
    this.listTarget.innerHTML = "";
    if (!data.drafts?.length) {
      this.listTarget.innerHTML = "<p>Aucun brouillon</p>";
      return;
    }
    data.drafts.forEach(d => {
      const el = document.createElement("div");
      el.className = "password-item";
      el.innerHTML = `
        <div class="mono">${this.escape(d.name)}</div>
        <div class="item-actions">
          <button class="btn btn-icon" data-action="click->generator#openConvertModal"
                  data-id="${d.id}" data-name="${this.escape(d.name)}"
                  data-pass="${this.escape(d.password)}"><i class="fa-solid fa-right-long"></i></button>
          <button class="btn btn-icon" data-action="click->generator#copyItem"
                  data-password="${this.escape(d.password)}"><i class="fa-solid fa-copy"></i></button>
        </div>`;
      this.listTarget.appendChild(el);
    });
  } catch {
    this.listTarget.innerHTML = "<p style='color:red'>Erreur de chargement.</p>";
  }
}


  // === Modales
  openModal(id) {
    document.getElementById(id).hidden = false;
  }
  closeModal() {
    this.element.querySelectorAll(".modal-backdrop").forEach(m=>m.hidden = true);
  }
  confirmClearAll() { this.openModal("confirmModal"); }
  openConvertModal(e) {
    this.currentDraftId = e.currentTarget.dataset.id;
    this.credNameTarget.value = e.currentTarget.dataset.name || "";
    this.credDomainTarget.value = "";
    this.credUsernameTarget.value = "";
    this.openModal("convertModal");
  }

  // === Draft
  async saveDraft() {
    const name = this.saveNameTarget.value.trim();
    if(!name) return this.toast("error","Nom requis","Merci d'indiquer un nom.");
    try {
      await fetch("/api/generator/save-draft", {
        method:"POST",
        headers:{ "Content-Type":"application/json" },
        body: JSON.stringify({ name, password: this.pendingSavePassword })
      });
      this.closeModal();
      this.toast("success","Brouillon","Enregistré !");
      this.showDrafts();
    } catch {
      this.toast("error","Erreur","Impossible d'enregistrer.");
    }
  }

  async convertDraft() {
    const payload = {
      draftId: this.currentDraftId,
      name: this.credNameTarget.value,
      domain: this.credDomainTarget.value,
      username: this.credUsernameTarget.value
    };
    try {
      const res = await fetch("/api/generator/convert-draft", {
        method:"POST",
        headers:{ "Content-Type":"application/json" },
        body: JSON.stringify(payload)
      });
      const j = await res.json();
      if (j.success) {
        this.toast("success","Converti","Brouillon transformé !");
        this.closeModal();
        this.showDrafts();
      } else this.toast("error","Erreur", j.error || "Échec.");
    } catch {
      this.toast("error","Erreur réseau","Vérifiez la connexion.");
    }
  }

  // === LocalStorage
  loadRecentPasswords() {
    const data = localStorage.getItem("recentPasswords");
    if (!data) return [];
    try {
      const parsed = JSON.parse(data);
      const now = Date.now();
      const valid = parsed.filter(p => now - p.createdAt < 48*3600*1000);
      if (valid.length !== parsed.length) localStorage.setItem("recentPasswords", JSON.stringify(valid));
      return valid;
    } catch { return []; }
  }
  saveRecentPasswords() {
    localStorage.setItem("recentPasswords", JSON.stringify(this.recentPasswords));
  }
  addRecentPassword(password) {
    const now = Date.now();
    this.recentPasswords.unshift({ password, createdAt: now });
    if (this.recentPasswords.length > 12) this.recentPasswords.pop();
    this.saveRecentPasswords();
  }

  clearAllRecents() {
    this.recentPasswords = [];
    this.saveRecentPasswords();
    this.renderRecent();
    this.toast("success","Nettoyé","Tous les récents ont été supprimés.");
    this.closeModal();
  }

  // === Liste
  renderRecent() {
    const list = this.listTarget;
    list.innerHTML = "";
    if (!this.recentPasswords.length) {
      list.innerHTML = "<p class='text-center muted'><i class='fa-solid fa-inbox'></i><br><br>Aucun mot de passe</p>";
      return;
    }
    this.recentPasswords.forEach(obj=>{
      const el=document.createElement("div");
      el.className="password-item";
      el.innerHTML=`
        <div class="mono">${this.escape(obj.password)}</div>
        <div class="item-actions">
          <button class="btn btn-icon" data-action="click->generator#copyItem" data-password="${this.escape(obj.password)}"><i class="fa-solid fa-copy"></i></button>
          <button class="btn btn-icon" data-action="click->generator#openSaveModal" data-password="${this.escape(obj.password)}"><i class="fa-solid fa-floppy-disk"></i></button>
          <button class="btn btn-icon-danger" data-action="click->generator#deleteItem" data-ts="${obj.createdAt}"><i class="fa-solid fa-xmark"></i></button>
        </div>`;
      list.appendChild(el);
    });
  }

  async copyItem(e) {
    await navigator.clipboard.writeText(e.currentTarget.dataset.password);
    this.toast("success","Copié","Mot de passe copié !");
  }

  openSaveModal(e) {
    this.pendingSavePassword = e.currentTarget.dataset.password;
    this.saveNameTarget.value = "";
    this.openModal("saveModal");
  }

  deleteItem(e) {
    const ts = +e.currentTarget.dataset.ts;
    this.recentPasswords = this.recentPasswords.filter(p => p.createdAt !== ts);
    this.saveRecentPasswords();
    this.renderRecent();
    this.toast("success","Supprimé","Élément retiré.");
  }

  // === Utils
  toast(type,title,msg){
    const el=document.createElement("div");
    el.className="toast"+(type==="error"?" error":"");
    el.innerHTML=`<div><div class="title">${title}</div><div class="msg">${msg}</div></div>`;
    this.toastWrapTarget.appendChild(el);
    setTimeout(()=>el.style.opacity=".6",2500);
    setTimeout(()=>el.remove(),4200);
  }
  getCharPools(){ return {
    upper:"ABCDEFGHIJKLMNOPQRSTUVWXYZ",lower:"abcdefghijklmnopqrstuvwxyz",
    nums:"0123456789",syms:"!@#$%^&*()_+-=[]{};:,.<>/?"};}
  rand(max){const b=new Uint32Array(1);crypto.getRandomValues(b);return b[0]%max;}
  entropy(bits){return Math.round(bits*10)/10;}
  updateMeter(pwd,pool){
    const bar=this.meterTarget.querySelector("div");
    const eLabel=this.meterTarget.parentElement.querySelector("#entropyLabel");
    const sLabel=this.meterTarget.parentElement.querySelector("#strengthLabel");
    if(!pwd){bar.style.width="0%";eLabel.innerHTML="Entropie : —";sLabel.textContent="Force : —";return;}
    const H=Math.log2(pool)*pwd.length;const e=this.entropy(H);
    eLabel.innerHTML=`<i class="fa-solid fa-shield-halved"></i> Entropie : ${e} bits`;
    let s="weak",pct=25;if(e>=60){s="strong";pct=100;}else if(e>=45){s="medium";pct=65;}else if(e>=30){s="medium";pct=45;}
    this.meterTarget.className=`meter ${s}`;bar.style.width=`${pct}%`;
    sLabel.textContent="Force : "+(s==="strong"?"Forte":s==="medium"?"Moyenne":"Faible");
  }
  escape(str){return str?String(str).replace(/[&<>"']/g,t=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[t])):"";}
}
