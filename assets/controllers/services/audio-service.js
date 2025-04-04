// Modification du audio-service.js
export default class AudioService {
    static instance = null;
    
    static getInstance() {
        if (!this.instance) {
            this.instance = new AudioService();
        }
        return this.instance;
    }
    
    constructor() {
        this.audio = new Audio('/sounds/welcome.mp3');
        this.isPlaying = false;
        
        // Récupérer la position et l'état sauvegardés
        this.restoreState();
        
        // Sauvegarder la position régulièrement
        setInterval(() => {
            if (this.isPlaying) {
                localStorage.setItem('audioCurrentTime', this.audio.currentTime);
            }
        }, 1000);
        
        // Ajouter des écouteurs pour les événements de navigation
        window.addEventListener('beforeunload', () => this.saveState());
        window.addEventListener('popstate', () => this.restoreStateAfterDelay());
        
        // Écouter la fin de la piste
        this.audio.addEventListener('ended', () => {
            this.isPlaying = false;
            localStorage.setItem('audioIsPlaying', 'false');
        });
    }
    
    saveState() {
        localStorage.setItem('audioCurrentTime', this.audio.currentTime);
        localStorage.setItem('audioIsPlaying', this.isPlaying.toString());
    }
    
    restoreState() {
        const savedTime = localStorage.getItem('audioCurrentTime');
        if (savedTime) {
            this.audio.currentTime = parseFloat(savedTime);
        }
        
        const playingState = localStorage.getItem('audioIsPlaying');
        if (playingState === 'true') {
            this.play();
        }
    }
    
    // Attendre un court instant après la navigation avant de restaurer
    restoreStateAfterDelay() {
        setTimeout(() => {
            if (localStorage.getItem('audioIsPlaying') === 'true') {
                this.play();
            }
        }, 100);
    }
    
    play() {
        const playPromise = this.audio.play();
        
        // Gérer les erreurs potentielles (politique d'autoplay des navigateurs)
        if (playPromise !== undefined) {
            playPromise.catch(error => {
                console.log("Lecture automatique impossible:", error);
                this.isPlaying = false;
                localStorage.setItem('audioIsPlaying', 'false');
            });
        }
        
        this.isPlaying = true;
        localStorage.setItem('audioIsPlaying', 'true');
    }
    
    pause() {
        this.audio.pause();
        this.isPlaying = false;
        localStorage.setItem('audioIsPlaying', 'false');
    }
    
    toggle() {
        if (this.isPlaying) {
            this.pause();
        } else {
            this.play();
        }
        return this.isPlaying;
    }
}