import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static values = { characterId: Number }


  async launch() {
    const res = await fetch(`/app/battle/faceoff/${this.characterIdValue}`, {
        credentials: 'include'
      })
      
    console.log(res)

    if (!res.ok) {
      alert("❌ You are not connected !");
      window.location.href = '/login';
      return;
    }

    window.location.href = `/app/battle/faceoff/${this.characterIdValue}`;
  }
}
