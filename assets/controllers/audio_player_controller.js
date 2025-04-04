import { Controller } from "@hotwired/stimulus";
import AudioService from "./services/audio-service.js";

export default class extends Controller {
    static targets = ["player", "miniButton", "playIcon", "pauseIcon"]
    
    connect() {
        // Obtenir l'instance du service audio
        this.audioService = AudioService.getInstance();
        
        // Mise à jour de l'UI selon l'état actuel
        this.updatePlaybackUI();
        
        // Ajouter un écouteur pour les mises à jour d'état
        document.addEventListener('audio-state-changed', this.updatePlaybackUI.bind(this));
        
        // Contrôler l'affichage initial
        if (this.hasPlayerTarget && this.hasMiniButtonTarget) {
            if (sessionStorage.getItem('playerVisible') === 'true') {
                this.showPlayer();
            } else {
                this.hidePlayerShowButton();
            }
        } else {
            // Comportement par défaut : afficher puis cacher
            setTimeout(() => {
                this.hidePlayerShowButton();
            }, 5000);
        }
    }
    
    showPlayer() {
        this.playerTarget.classList.remove('hidden');
        this.miniButtonTarget.classList.remove('visible');
        sessionStorage.setItem('playerVisible', 'true');
        
        this.autoHideTimer = setTimeout(() => {
            this.hidePlayerShowButton();
        }, 5000);
    }
    
    hidePlayerShowButton() {
        this.playerTarget.classList.add('hidden');
        sessionStorage.setItem('playerVisible', 'false');
        
        setTimeout(() => {
            this.miniButtonTarget.classList.add('visible');
        }, 300);
    }
    
    toggleAudio() {
        const isPlaying = this.audioService.toggle();
        
        // Mettre à jour l'UI
        this.updatePlaybackUI();
        
        // Réinitialiser le timer d'auto-hide
        clearTimeout(this.autoHideTimer);
        this.autoHideTimer = setTimeout(() => {
            this.hidePlayerShowButton();
        }, 5000);
        
        // Émettre un événement pour synchroniser d'autres instances potentielles
        document.dispatchEvent(new CustomEvent('audio-state-changed'));
    }
    
    updatePlaybackUI() {
        if (this.audioService.isPlaying) {
            if (this.hasPlayIconTarget) this.playIconTarget.classList.add('hidden');
            if (this.hasPauseIconTarget) this.pauseIconTarget.classList.remove('hidden');
        } else {
            if (this.hasPlayIconTarget) this.playIconTarget.classList.remove('hidden');
            if (this.hasPauseIconTarget) this.pauseIconTarget.classList.add('hidden');
        }
    }
    
    disconnect() {
        clearTimeout(this.autoHideTimer);
        document.removeEventListener('audio-state-changed', this.updatePlaybackUI.bind(this));
    }
}