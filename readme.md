# effi AI Corrector

Extension WordPress qui nettoie les restes de formatage laissés par les IA dans les contenus publiés : Markdown non converti (`**gras**`, `*italique*`), balises de code isolées (```` ``` ````, ```` ```html ````) et espaces superflus autour des titres.

## ⚠️ Avertissement

**Cette extension modifie le contenu de vos publications en masse, directement dans la base de données.**

- **Faites une sauvegarde complète de la base de données avant toute correction** (et prévoyez des sauvegardes automatiques si vous activez la correction automatique).
- **Lancez toujours l'analyse et vérifiez l'aperçu avant de corriger.** L'analyse ne modifie rien ; la correction ne porte que sur les contenus cochés après l'analyse.
- **La correction automatique (CRON) applique les règles sans confirmation ni aperçu**, chaque nuit, aux contenus publiés ou modifiés depuis l'exécution précédente.
- Testez de préférence vos règles sur une copie du site (préproduction) avant de les appliquer en production.

Le même avertissement est affiché en tête de la page de l'outil.

## Fonctionnement

L'outil se trouve dans **Outils → effi AI Corrector** (droit `manage_options`). Il traite les contenus **publiés** des types de contenu cochés.

1. **Contenus à traiter** : cochez les types (articles, pages, types personnalisés publics). Vous pouvez aussi exclure en permanence des contenus par leur identifiant ; ils ne sont alors jamais analysés ni modifiés, y compris par la correction automatique.
2. **Règles de correction (JSON)** : modifiables, appliquées dans l'ordre (voir plus bas). Elles sont vérifiées à l'enregistrement ; en cas d'erreur, rien n'est enregistré et les erreurs sont listées.
3. **Analyse et correction** :
   - **Analyser** parcourt les contenus par lots de 50 (barre de progression) sans rien modifier. Elle liste les contenus concernés (titre, lien vers l'éditeur, corrections prévues par règle) et affiche un aperçu avant / après (lignes modifiées) pour les 5 premiers. Les contenus en erreur sont listés à part, avec la raison.
   - Décochez les contenus à ne pas modifier, puis **Corriger les contenus cochés** (confirmation demandée). La correction se fait par lots de 50 ; un compteur final indique les contenus modifiés, inchangés ou en erreur. En cas d'interruption (erreur serveur, délai dépassé), le traitement s'arrête et indique où il en était.
4. **Correction automatique (CRON)** : facultative, voir plus bas.

Avant chaque modification, l'état actuel du contenu est enregistré dans ses **révisions** (si le type de contenu les gère et qu'elles ne sont pas désactivées) : vous pouvez le restaurer depuis l'écran Révisions du contenu. Sans révisions (type de contenu qui ne les gère pas, `WP_POST_REVISIONS` à `false` ou `0`), seule votre sauvegarde permet de revenir en arrière.

### Où les règles s'appliquent

Les règles ne s'appliquent **qu'au texte situé entre les balises**. Le contenu brut (`post_content`) est découpé en segments : commentaires (dont les délimiteurs de blocs `<!-- wp:… -->` et leurs attributs JSON), balises avec leurs attributs, éléments entiers comme `<script>…</script>`, et texte. Seuls les segments de texte autorisés sont modifiés, puis le contenu est réassemblé tel quel : tout le reste est restitué **octet pour octet** (aucune resérialisation des blocs, antislashs et JSON intacts).

Ne sont **jamais** modifiés :

- les balises et leurs attributs (`title="*Nouveau*"`, `alt="**x**"`, `class`, `style`…) ;
- les commentaires HTML, dont les délimiteurs de blocs et leurs attributs ;
- le contenu des balises `pre`, `code`, `kbd`, `samp`, `svg`, `math`, `object`, `template` et des éléments `script`, `style`, `textarea`, `iframe`, `noscript`, `noembed`, `noframes`, `xmp` ;
- le contenu des blocs autres que : paragraphe, titre, liste, élément de liste, citation, exergue, tableau, détails (`core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/quote`, `core/pullquote`, `core/table`, `core/details`). Les blocs HTML personnalisé, code, préformaté, shortcode et les blocs d'extensions (dont le texte est souvent aussi stocké dans leurs attributs) ne sont pas touchés ;
- les balises des shortcodes enregistrés (`[gallery ids="…"]`, `[/caption]`) ; le texte entre l'ouverture et la fermeture reste traité.

Le contenu classique (hors blocs) est traité, avec les mêmes protections. Un bloc de texte placé dans un groupe, des colonnes, etc. est traité normalement.

*Pourquoi ce découpage plutôt que `parse_blocks()` ?* `parse_blocks()` puis `serialize_blocks()` réécrit les délimiteurs et le JSON des attributs (un objet vide `{}` devient `[]`, les caractères spéciaux sont réencodés), ce qui modifie des contenus qu'aucune règle ne concerne et peut invalider des blocs. Le découpage par expression régulière garde tout le reste intact, traite de la même façon le contenu classique et les blocs imbriqués, tout en suivant les délimiteurs de blocs pour n'agir que dans les blocs de texte listés ci-dessus.

Limites : une mise en forme Markdown qui chevauche une balise (`**gras <a href="…">lien</a>**`) n'est pas convertie ; une règle ne peut pas viser du HTML (une recherche contenant une balise est refusée, elle ne trouverait jamais rien).

### Titres

Les espaces, espaces insécables (U+00A0), espaces fines insécables (U+202F) et entités `&nbsp;` sont retirés **en début et en fin** de titre uniquement. Ceux du milieu sont conservés (typographie française : « Pourquoi ? », « Note : », « 10 € »). Un titre composé uniquement d'espaces n'est pas vidé.

## Règles par défaut

````json
[
    {
        "id": "remove_code_fence",
        "label": "Suppression des balises de code Markdown isolées (```, ```html…)",
        "type": "preg_replace",
        "enabled": true,
        "pattern": "/^\\s*```[a-z0-9_+#.-]*\\s*$/iu",
        "replacement": ""
    },
    {
        "id": "bold_markdown_to_strong",
        "label": "Conversion de **texte** en <strong>",
        "type": "preg_replace",
        "enabled": true,
        "pattern": "/(?<![\\w*])\\*\\*(?=\\S)([^*<>\\n]+?)(?<=\\S)\\*\\*(?![\\w*])/u",
        "replacement": "<strong>$1</strong>"
    },
    {
        "id": "italic_markdown_to_em",
        "label": "Conversion de *texte* en <em>",
        "type": "preg_replace",
        "enabled": true,
        "pattern": "/(?<![\\w*\\/])\\*(?=[^\\s*])([^*<>\\n]+?)(?<=[^\\s*])\\*(?![\\w*\\/])/u",
        "replacement": "<em>$1</em>"
    }
]
````

- **Balises de code isolées** : un segment de texte réduit à ```` ``` ```` ou ```` ```html ```` (```` ```php ````…) est vidé. `<p>```</p>` devient `<p></p>`, ce qui garde le bloc paragraphe valide dans l'éditeur (la version 1.0.2 supprimait aussi les balises `<p>`, ce qui cassait le bloc).
- **Gras et italique** : les motifs exigent un caractère non blanc juste après l'ouverture et juste avant la fermeture, et refusent une lettre, un chiffre ou un astérisque collé à l'extérieur. Ils ne touchent donc pas `2*3*4`, `*.php et *.js`, `***x***`, `****`, `/* commentaire */`, ni un astérisque ouvert dans un élément de liste et fermé dans le suivant.

Le bouton **Insérer les règles par défaut** remplit la zone de texte avec ces règles (rien n'est enregistré avant de cliquer sur « Enregistrer les réglages »). Laissez la zone vide pour ne corriger que les titres.

## Syntaxe des règles

Le JSON est une liste d'objets. Chaque règle comprend :

| Champ | Requis | Description |
|---|---|---|
| `id` | non | Identifiant libre (chaîne). |
| `label` | non | Description affichée dans l'analyse et le journal (chaîne). |
| `type` | oui | `str_replace` (remplacement de chaîne) ou `preg_replace` (expression régulière PCRE). |
| `enabled` | oui | `true` pour appliquer la règle, `false` pour l'ignorer. |
| `search` / `replace` | oui pour `str_replace` | Chaîne recherchée (non vide, sans balise HTML) et chaîne de remplacement (éventuellement vide). |
| `pattern` / `replacement` | oui pour `preg_replace` | Motif avec délimiteurs (`/…/u`) et remplacement (captures `$1`, `$2`…). |

Dans le JSON, chaque antislash du motif est doublé : `/\*\*/` s'écrit `"/\\*\\*/"`.

À l'enregistrement, chaque règle est vérifiée ; la moindre erreur bloque l'enregistrement :

- champs requis présents et du bon type, `type` connu ;
- motif compilable (`preg_match( $motif, '' ) !== false`), qui ne correspond pas à une chaîne vide (sinon le remplacement serait inséré partout) ;
- remplacement **inchangé par `wp_kses_post()`** (pas de `<script>`, `<iframe>`, `onclick`, `javascript:`…) ; écrivez `&amp;` pour une esperluette ;
- remplacement sans commentaire HTML et aux balises équilibrées (`<strong>$1</strong>`, pas `<strong>$1`).

Les règles enregistrées sont revérifiées avant chaque analyse, correction ou exécution automatique : si elles sont invalides (par exemple des règles personnalisées d'une ancienne version), rien n'est traité et les erreurs sont affichées.

## Correction automatique (CRON)

- **Activation** : bouton « Activer la correction automatique », avec confirmation. Les mêmes prérequis qu'une correction manuelle s'appliquent (au moins un type coché, règles valides).
- **Horaire** : première exécution le lendemain à 3 h **dans le fuseau horaire du site** (Réglages → Général), puis toutes les 24 heures. Avec le WP-Cron standard, la tâche part au premier chargement de page qui suit cette heure. Après un changement d'heure, l'exécution peut glisser d'une heure.
- **Nouveaux contenus seulement** : chaque exécution ne traite que les contenus publiés ou modifiés depuis l'exécution précédente (depuis l'activation pour la première), d'après leur date de modification (`post_modified_gmt`) ou de publication (`post_date_gmt`, pour les contenus planifiés). Les contenus plus anciens ne sont jamais traités par le CRON : utilisez l'analyse manuelle.
- **Sans confirmation** : les règles enregistrées au moment de l'exécution sont appliquées directement, sans aperçu.
- **Verrou** : une exécution en cours empêche une deuxième exécution simultanée (et une correction manuelle) ; le verrou expire de lui-même au bout de 10 minutes si une exécution est interrompue.
- **Durée limitée** : une exécution s'arrête après 20 secondes (filtre `eac_cron_time_limit`) et la suite est planifiée deux minutes plus tard.
- **Journal** : les 10 dernières exécutions sont affichées sous le bouton (date, statut, nombre de contenus examinés, identifiants des contenus modifiés avec lien vers l'éditeur, erreurs).
- **Désactivation** : bouton « Désactiver la correction automatique », ou désactivation de l'extension (la tâche est supprimée sur chaque site d'un réseau multisite).

## Sécurité et intégrité des contenus

- Le contenu est lu **brut**, tel qu'enregistré (`get_post_field( 'post_content', $id, 'raw' )`) : jamais `get_the_content()`, qui renvoie seulement l'introduction avant `<!--more-->`, la première page d'un contenu paginé ou le formulaire d'un contenu protégé.
- L'enregistrement passe par `wp_update_post( wp_slash( $data ), true )` : les antislashs (attributs JSON des blocs, exemples de code, chemins Windows) sont conservés et les erreurs remontées.
- Si une expression régulière échoue (motif invalide, UTF-8 invalide avec `/u`, limite de retour arrière…), **rien n'est écrit** pour ce contenu : il est signalé en erreur avec le message PCRE (`preg_last_error_msg()`), y compris si des règles précédentes avaient réussi.
- Chaque balise produite par une règle est revérifiée avec `wp_kses_post()` au moment de la correction (une capture `$1` pourrait sinon injecter `javascript:` ou un attribut `onclick`) ; un contenu dont la correction produirait du HTML refusé, mal équilibré ou un « < » isolé n'est pas modifié.
- **Filtrage kses** : sans le droit `unfiltered_html` (exécution automatique, multisite, `DISALLOW_UNFILTERED_HTML`), WordPress repasse **tout** le contenu enregistré dans `wp_kses_post()` et supprime en silence les iframes, scripts, formulaires… déjà présents. Puisque les règles ne peuvent ajouter que du HTML accepté par `wp_kses_post()` (vérifié à l'enregistrement de la règle puis à chaque correction), tout HTML non filtré présent après correction l'était déjà en base. L'extension désactive donc kses (`kses_remove_filters()`) le temps de chaque mise à jour, puis le rétablit (`kses_init_filters()`), seulement s'il était actif. Elle ne permet pas d'ajouter du HTML non filtré ; elle évite seulement de détruire celui qui existe.
- La date de modification des contenus corrigés est mise à jour, comme pour toute modification (elle apparaît dans les sitemaps).
- Accès réservé aux administrateurs (`manage_options`), jeton de sécurité (nonce) sur chaque action, droit `edit_post` vérifié pour chaque contenu corrigé manuellement, sorties échappées.

## Installation

1. Téléchargez l'archive de l'extension et vérifiez que le dossier qu'elle contient s'appelle `effi-ai-corrector` (l'archive « Download ZIP » de GitHub crée `effi-ai-corrector-main` : renommez-le).
2. **Extensions → Ajouter → Téléverser une extension**, choisissez l'archive, puis **Activer**.
3. Rendez-vous dans **Outils → effi AI Corrector**.

Le fichier principal s'appelle `effi-ia-corrector.php` (nom historique). Il n'a pas été renommé : WordPress identifie une extension par son dossier et ce fichier, et un changement de nom la désactiverait sur les sites où elle est installée.

Configuration requise : WordPress 6.0 ou supérieur, PHP 7.4 ou supérieur.

### Mise à jour depuis la version 1.0.x

Lors de la mise à jour, automatiquement :

- la tâche CRON de la 1.0.x (qui retraitait chaque jour **tout** le contenu publié) est supprimée ; un avis vous le signale. Réactivez la correction automatique après avoir vérifié vos règles avec l'analyse ;
- si vous aviez enregistré les règles par défaut de la 1.0.2 sans les modifier, elles sont remplacées par les nouvelles (motifs gras et italique corrigés, deux règles ```` ``` ```` fusionnées en une). Vos règles personnalisées ne sont pas touchées : si l'une cherche du HTML (`<p>…</p>`), elle est désormais refusée et doit être réécrite pour le texte.

## Désinstallation

La suppression de l'extension (Extensions → Supprimer) efface ses options (`eac_*`), sa tâche planifiée et son verrou, sur chaque site d'un réseau multisite. Les contenus corrigés et leurs révisions ne sont pas modifiés.

## Pour les développeurs

| Filtre | Rôle | Valeur par défaut |
|---|---|---|
| `eac_text_blocks` | Blocs dont le texte peut être corrigé. | `core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/quote`, `core/pullquote`, `core/table`, `core/details` |
| `eac_protected_tags` | Balises dont le contenu n'est jamais modifié (les éléments `script`, `style`, `textarea`, `iframe`… le sont toujours). | `pre`, `code`, `kbd`, `samp`, `svg`, `math`, `object`, `template` |
| `eac_cron_time_limit` | Durée maximale d'une exécution automatique, en secondes (minimum 5). | `20` |

Le moteur de règles (`includes/class-effi-ai-corrector-engine.php`) ne dépend pas de WordPress et peut être testé en PHP seul.

## Journal des versions

### 1.1.0

Corrections issues d'un audit du code :

- **Antislashs** : enregistrement avec `wp_update_post( wp_slash( $data ), true )` ; la 1.0.2 supprimait tous les « \ » des contenus corrigés (attributs JSON des blocs, code).
- **Contenu brut** : lecture de `post_content` au lieu de `get_the_content()`, qui pouvait remplacer tout le contenu par son introduction (`<!--more-->`), sa première page ou le formulaire de mot de passe.
- **Échec d'une regex** : plus aucune écriture (la 1.0.2 vidait le contenu) ; erreur affichée avec le message PCRE. Validation complète des règles à l'enregistrement.
- **Zones protégées** : les règles ne s'appliquent plus qu'au texte (ni balises, ni attributs, ni commentaires de blocs, ni `pre`, `code`, `script`, `style`, `textarea`…, ni blocs autres que les blocs de texte). Motifs gras et italique durcis contre les faux positifs (`2*3*4`, `*.php et *.js`, listes, attributs, `***x***`).
- **CRON** : confirmation avant activation, première exécution à 3 h dans le fuseau du site, traitement des seuls contenus publiés ou modifiés depuis l'exécution précédente, verrou, durée limitée avec reprise, journal des 10 dernières exécutions. L'ancienne tâche est désactivée lors de la mise à jour.
- **kses** : les contenus corrigés sans le droit `unfiltered_html` (CRON, multisite) ne perdent plus leurs iframes, scripts ou formulaires ; les règles ne peuvent produire que du HTML accepté par `wp_kses_post()`.
- **Traitement par lots** : analyse et correction par lots de 50 avec barre de progression, gestion des erreurs réseau et compteur final ; plus de requête unique sur tout le site.
- **Analyse détaillée** : liste des contenus concernés, corrections prévues, aperçu avant / après, possibilité de décocher des contenus et d'en exclure en permanence.
- **Titres** : seuls les espaces de début et de fin sont retirés ; les insécables du milieu sont conservés.
- Plus d'erreur fatale quand aucun type de contenu n'est coché ; types revalidés contre les types publics enregistrés.
- Révision de l'état d'origine enregistrée avant chaque correction ; suppression du code de révisions inopérant et du message « action irréversible » trompeur.
- Sorties échappées, messages traduisibles (y compris AJAX), JavaScript dans un fichier séparé avec gestion des erreurs.
- Fichier `uninstall.php` (options, tâche planifiée, multisite), nettoyage des tâches à la désactivation réseau.
- En-tête complet (`Requires at least`, `Requires PHP`, `License`, `Update URI`…), constante de version, fichier `LICENSE` (GPL v2).

### 1.0.2

Version publiée initialement sur GitHub.

## Auteur

Cédric Girard, consultant SEO et développeur : [effi10.com](https://www.effi10.com/)

## Licence

GPL-2.0-or-later. Texte complet de la licence GNU GPL version 2 dans le fichier [LICENSE](LICENSE).
