# effi AI Corrector

Un plugin WordPress pour nettoyer et corriger les anomalies de contenu générées par IA.

## Description

Ce plugin a été conçu pour les utilisateurs de WordPress qui intègrent du contenu généré par des intelligences artificielles. Il cible et corrige les problèmes de formatage les plus courants, tels que les artefacts de code Markdown ou les balises HTML superflues, afin de garantir un affichage propre et professionnel sur votre site.

## Fonctionnalités principales

*   **Nettoyage du Markdown** : Supprime les balises de blocs de code vides comme `<p>```</p>` ou `<p>```html</p>`.
*   **Correction sémantique** : Convertit automatiquement la syntaxe Markdown pour le gras (`**mot**`) et l'italique (`*mot*`) en balises HTML standard (`<strong>mot</strong>` et `<em>mot</em>`).
*   **Interface d'administration simple** : Accédez à toutes les fonctionnalités depuis une page unique dans le menu `Outils` de WordPress.
*   **Contrôle total** :
    *   **Sélection des contenus** : Choisissez les types de publication à traiter (articles, pages, etc.).
    *   **Analyse sans risque** : Lancez une analyse pour savoir combien de publications sont concernées avant d'appliquer la moindre modification.
    *   **Correction manuelle** : Exécutez la correction quand vous le souhaitez en un seul clic.
*   **Automatisation intelligente** : Activez une tâche CRON pour que le plugin corrige automatiquement et quotidiennement les nouveaux contenus, sans que vous ayez à y penser.

## Installation

1.  Téléchargez le fichier `effi-ia-corrector.zip`.
2.  Depuis votre tableau de bord WordPress, allez dans `Extensions` > `Ajouter`.
3.  Cliquez sur le bouton `Téléverser une extension` en haut de la page.
4.  Sélectionnez le fichier `.zip` que vous venez de télécharger et cliquez sur `Installer maintenant`.
5.  Une fois l'installation terminée, cliquez sur `Activer l'extension`.

## Comment utiliser le plugin ?

L'interface est simple et rapide à prendre en main.

<img width="635" height="479" alt="image" src="https://github.com/user-attachments/assets/fbf8a620-c8de-49f6-b804-19c5a212e710" />


1.  Une fois le plugin activé, rendez-vous dans le menu `Outils` → `effi AI Corrector`.
2.  **Étape 1 : Configurer les types de contenu**
    *   Cochez les cases correspondant aux types de contenu que vous souhaitez que le plugin analyse et corrige (par exemple, "Articles", "Pages").
    *   Cliquez sur `Sauvegarder la sélection`. Cette étape est nécessaire pour que les actions manuelles et automatiques fonctionnent.
3.  **Étape 2 : Lancer une action manuelle**
    *   **Pour analyser** : Cliquez sur `Analyser les anomalies`. Le plugin comptera les publications concernées sans rien modifier et affichera le résultat.
    *   **Pour corriger** : Cliquez sur `Lancer la correction maintenant`. Une confirmation vous sera demandée. **⚠️ Attention, cette action est irréversible. Il est vivement recommandé de faire une sauvegarde de votre base de données avant de continuer.**
4.  **Étape 3 : Gérer l'automatisation**
    *   Cliquez sur `Planifier la correction automatique` pour activer un nettoyage quotidien. Le statut passera à "active".
    *   Si la tâche est déjà active, le bouton `Désactiver la correction automatique` vous permettra de l'arrêter.

## Auteur

*   **Cédric GIRARD**
*   Site web : [effi10.com](https://www.effi10.com)

## Licence

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html) 
