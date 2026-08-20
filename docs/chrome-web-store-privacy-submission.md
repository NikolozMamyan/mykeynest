# Nouvelle soumission Chrome Web Store — confidentialité

Élément : **MyKeyNest**  
ID : `llckfoodkfccmibgmpfiodjkpincnfid`  
Version corrigée : **1.2.3**

## Ordre de déploiement

1. Déployer d’abord la web app contenant la route `/privacy`.
2. Ouvrir `https://key-nest.com/privacy` dans une fenêtre privée, sans être connecté.
3. Vérifier que la page répond directement en `200`, sans redirection vers la connexion, sans erreur et sans consentement requis pour la lire.
4. Vérifier aussi `https://key-nest.com/privacy?lang=en`.
5. Mettre ensuite à jour les informations de confidentialité du Chrome Web Store.
6. Importer le ZIP de l’extension **1.2.3**, puis envoyer cette nouvelle version en examen.

## URL à renseigner

Dans le tableau de bord Chrome Web Store, ouvrir l’élément, puis **Confidentialité / Privacy practices** et renseigner exactement :

`https://key-nest.com/privacy`

Ne pas renseigner la page d’accueil, `/cgu`, une page nécessitant une connexion ou une URL qui redirige vers une autre rubrique.

## Finalité unique proposée

> MYKEYNEST permet à l’utilisateur authentifié d’accéder à son coffre de mots de passe et de remplir, à sa demande, les formulaires de connexion sur les sites qu’il consulte. L’extension identifie localement le domaine et la présence d’un formulaire de connexion afin de proposer les identifiants autorisés correspondant à ce site.

## Justifications des permissions

### `storage`

> Utilisée pour conserver localement le jeton de connexion, l’identifiant technique d’installation et les préférences nécessaires au fonctionnement de l’extension. Les mots de passe ne sont pas conservés durablement par l’extension.

### `tabs`

> Utilisée pour identifier l’onglet actif et son domaine afin de rechercher les identifiants correspondants, envoyer la commande de remplissage à cet onglet et ouvrir les pages MYKEYNEST demandées par l’utilisateur.

### `notifications`

> Utilisée uniquement comme solution de secours pour avertir l’utilisateur lorsqu’une étape d’association nécessite son attention et que la fenêtre de l’extension ne peut pas être ouverte automatiquement.

### Accès aux sites (`<all_urls>`)

> Nécessaire pour détecter localement les formulaires de connexion et remplir les identifiants sur les sites où l’utilisateur choisit d’utiliser MYKEYNEST. L’extension ne lit pas les valeurs saisies par l’utilisateur et n’enregistre pas son historique de navigation.

### Accès hôte `https://key-nest.com/*`

> Nécessaire pour communiquer avec l’API officielle MYKEYNEST : association du compte, authentification, récupération des entrées autorisées, contrôle des droits et remplissage demandé par l’utilisateur.

Les accès `127.0.0.1` et `localhost` servent uniquement au développement local. Si Google demande pourquoi ils figurent dans le paquet Store, créer idéalement un manifeste de production qui ne les contient pas.

## Catégories de données à déclarer

Déclarer au minimum les catégories réellement traitées par l’extension, y compris quand le traitement est uniquement local :

- **Informations personnellement identifiables** : adresse e-mail, nom et informations des membres/partages affichés dans l’extension.
- **Informations d’authentification** : noms d’utilisateur, mots de passe récupérés sur action explicite, jeton d’accès, code d’association et identifiant d’installation.
- **Historique Web / activité de navigation** : domaine ou URL de l’onglet actif utilisé pour faire correspondre les identifiants. Préciser que cette donnée n’est pas utilisée pour créer un historique ni à des fins publicitaires.
- **Contenu de sites Web** : structure des formulaires de connexion détectée localement afin d’effectuer l’auto-remplissage.

Ne pas cocher les données financières, de santé, de localisation, les communications personnelles ou l’activité utilisateur de type frappes/clics si aucune nouvelle fonctionnalité ne les traite.

Pour chaque catégorie cochée, indiquer que l’usage sert uniquement la fonctionnalité principale, la sécurité du compte et la prévention des abus. Certifier l’absence de vente, de publicité personnalisée, de transfert à des courtiers et d’usage pour le crédit.

## Déclaration Limited Use

La politique publique contient la déclaration demandée :

> The use of information received from Google APIs will adhere to the Chrome Web Store User Data Policy, including the Limited Use requirements.

Dans le tableau de bord, accepter les certifications Limited Use uniquement après avoir vérifié que toutes les déclarations correspondent aux comportements actuels du paquet envoyé.

## Texte d’accompagnement pour le nouvel examen

> Bonjour, nous avons corrigé le motif Purple Nickel. Le lien « Confidentialité » renvoyait par erreur vers nos CGU. Une politique de confidentialité autonome, publique et directement accessible est désormais disponible à l’adresse https://key-nest.com/privacy. Elle décrit les données traitées par le service et l’extension, la finalité unique, les permissions Chrome, le stockage, les prestataires, la conservation, les droits des utilisateurs et le respect des exigences Limited Use. La version 1.2.3 ajoute également un lien vers cette politique avant l’association du compte et dans les paramètres de l’extension, et supprime la permission `activeTab` devenue redondante ainsi que les ressources publiquement exposées devenues inutiles.

## Contrôle final avant envoi

- La page `/privacy` fonctionne hors connexion et sur mobile.
- Le lien du Store est exactement celui de la politique, sans redirection.
- Les déclarations de données correspondent au comportement du code.
- Toutes les permissions du manifeste ont une justification.
- Les captures et la description du Store décrivent clairement le coffre et l’auto-remplissage.
- Le paquet importé affiche la version `1.2.3`.
- Aucune clé, aucun token et aucun fichier `.env` ne sont inclus dans le ZIP.

## Documentation officielle

- https://developer.chrome.com/docs/webstore/program-policies/privacy
- https://developer.chrome.com/docs/webstore/cws-dashboard-privacy
- https://developer.chrome.com/docs/webstore/program-policies/user-data-faq
- https://developer.chrome.com/docs/webstore/troubleshooting
