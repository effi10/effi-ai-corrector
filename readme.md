# effi AI Corrector

Un plugin WordPress pour nettoyer et corriger les anomalies de contenu générées par IA.

## Description

Ce plugin a été conçu pour les utilisateurs de WordPress qui intègrent du contenu généré par des intelligences artificielles. Il cible et corrige les problèmes de formatage les plus courants, tels que les artefacts de code Markdown ou les balises HTML superflues, afin de garantir un affichage propre et professionnel sur votre site.

## Fonctionnalités principales

*   **Nettoyage du Markdown** : Supprime les balises de blocs de code vides comme `<p>```</p>` ou `<p>```html</p>`.
*   **Correction sémantique** : Convertit automatiquement la syntaxe Markdown pour le gras (`**mot**`) et l'italique (`*mot*`) en balises HTML standard (`<strong>mot</strong>` et `<em>mot</em>`).
*   **Règles de correction personnalisables** : Modifiez directement les règles de nettoyage via un éditeur JSON intégré pour ajouter ou adapter les corrections à vos besoins spécifiques.
*   **Correction des titres** : Supprime les espaces superflus (y compris les espaces insécables) en début et fin de titre.
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

1.  Une fois le plugin activé, rendez-vous dans le menu `Outils` → `effi AI Corrector`.
2.  **Étape 1 : Configurer les types de contenu**
    *   Cochez les cases correspondant aux types de contenu que vous souhaitez que le plugin analyse et corrige (par exemple, "Articles", "Pages").
3.  **Étape 2 : Personnaliser les règles de correction (JSON)**
    *   Modifiez directement le JSON dans la zone de texte "Règles de correction du contenu" pour adapter le nettoyage à vos besoins.
    *   Vous pouvez ajouter, supprimer ou désactiver des règles. Voir la syntaxe détaillée ci-dessous.
    *   Cliquez sur `Sauvegarder les réglages` pour enregistrer à la fois la sélection des contenus et vos règles personnalisées. Cette sauvegarde est nécessaire pour que les actions s'exécutent avec les bons paramètres.
4.  **Étape 3 : Lancer une action manuelle**
    *   **Pour analyser** : Cliquez sur `Analyser les anomalies`. Le plugin comptera les publications concernées (en se basant sur vos règles) sans rien modifier et affichera le résultat.
    *   **Pour corriger** : Cliquez sur `Lancer la correction maintenant`. Une confirmation vous sera demandée. **⚠️ Attention, cette action est irréversible. Il est vivement recommandé de faire une sauvegarde de votre base de données avant de continuer.**
5.  **Étape 4 : Gérer l'automatisation**
    *   Cliquez sur `Planifier la correction automatique` pour activer un nettoyage quotidien basé sur vos règles actuelles. Le statut passera à "active".
    *   Si la tâche est déjà active, le bouton `Désactiver la correction automatique` vous permettra de l'arrêter.

## Syntaxe des règles de correction (JSON)

Le cœur du plugin réside dans sa capacité à interpréter un ensemble de règles définies en JSON. Voici la structure détaillée que chaque règle doit respecter :

*   `id` (string) : Un identifiant unique pour la règle. Non utilisé par la logique du plugin, mais pratique pour vous y retrouver.
*   `label` (string) : Une description lisible du rôle de la règle.
*   `type` (string) : Le type de remplacement à effectuer. Valeurs possibles :
    *   `str_replace` : Pour un simple remplacement de chaîne de caractères.
    *   `preg_replace` : Pour un remplacement basé sur une expression régulière (PCRE).
*   `enabled` (boolean) : `true` pour que la règle soit appliquée, `false` pour l'ignorer.
*   **Champs pour `str_replace`** :
    *   `search` (string) : La chaîne de caractères à rechercher.
    *   `replace` (string) : La chaîne de caractères qui la remplacera.
*   **Champs pour `preg_replace`** :
    *   `pattern` (string) : L'expression régulière. **Important** : les antislashs `\` doivent être échappés. Par exemple, `/\*\*/` devient `"/\\*\\*/"`.
    *   `replacement` (string) : La chaîne de remplacement, qui peut utiliser des captures comme `$1`, `$2`, etc.

### Exemple de règles par défaut

Voici le JSON utilisé par défaut par le plugin. Vous pouvez vous en inspirer pour créer vos propres règles.

```json
[
  {
    "id": "remove_code_block_1",
    "label": "Suppression de <p>```</p>",
    "type": "str_replace",
    "enabled": true,
    "search": "<p>```</p>",
    "replace": ""
  },
  {
    "id": "remove_code_block_html",
    "label": "Suppression de <p>```html</p>",
    "type": "str_replace",
    "enabled": true,
    "search": "<p>```html</p>",
    "replace": ""
  },
  {
    "id": "bold_markdown_to_strong",
    "label": "Conversion de **texte** en <strong>",
    "type": "preg_replace",
    "enabled": true,
    "pattern": "/\\*\\*(?!\\s)(.*?)(?!\\s)\\*\\*/u",
    "replacement": "<strong>$1</strong>"
  },
  {
    "id": "italic_markdown_to_em",
    "label": "Conversion de *texte* en <em>",
    "type": "preg_replace",
    "enabled": true,
    "pattern": "/\\*(?!\\s)(.*?)(?!\\s)\\*/u",
    "replacement": "<em>$1</em>"
  }
]
```

## Auteur

*   **Cédric GIRARD**
*   Site web : [effi10.com](https://www.effi10.com)

## Licence

GPL v2 or later - [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html) 