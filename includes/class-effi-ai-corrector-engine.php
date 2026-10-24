<?php
/**
 * Moteur de règles d'effi AI Corrector.
 *
 * Cette classe ne dépend pas de WordPress (hors fonction de traduction `__()`)
 * afin de pouvoir être testée en PHP pur.
 *
 * Principe : le contenu brut (`post_content`) est découpé en jetons par une
 * expression régulière (commentaires, balises avec leurs attributs, éléments
 * « texte brut » comme script/style/textarea, et texte entre les balises). Les
 * règles ne sont appliquées qu'aux jetons de texte modifiables, puis le contenu
 * est réassemblé par simple concaténation : tout ce qui n'est pas du texte
 * modifiable est restitué octet pour octet (aucune resérialisation des blocs).
 *
 * Un segment de texte n'est modifiable que s'il :
 * - n'est pas à l'intérieur d'une balise protégée (pre, code, kbd, samp, svg,
 *   math, object, template) ni d'un élément texte brut (script, style,
 *   textarea, iframe, noscript…) ;
 * - se trouve hors de tout bloc (contenu classique) ou directement dans un
 *   bloc de texte autorisé (paragraphe, titre, liste, élément de liste,
 *   citation, exergue, tableau, détails) ;
 * - n'est pas une balise de shortcode enregistré (`[gallery …]`).
 *
 * @package effi-ai-corrector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applique les règles de correction à un contenu et valide les règles.
 */
final class Effi_AI_Corrector_Engine {

	/** Gras Markdown : **texte** (motif durci, voir l'audit). */
	const BOLD_PATTERN = '/(?<![\w*])\*\*(?=\S)([^*<>\n]+?)(?<=\S)\*\*(?![\w*])/u';

	/** Italique Markdown : *texte* (motif durci, voir l'audit). */
	const ITALIC_PATTERN = '/(?<![\w*\/])\*(?=[^\s*])([^*<>\n]+?)(?<=[^\s*])\*(?![\w*\/])/u';

	/** Segment de texte réduit à une balise de code Markdown : ``` ou ```html. */
	const FENCE_PATTERN = '/^\s*```[a-z0-9_+#.-]*\s*$/iu';

	/** Motifs par défaut de la version 1.0.2 (remplacés lors de la mise à jour). */
	const LEGACY_BOLD_PATTERN   = '/\*\*(?!\s)(.*?)(?!\s)\*\*/u';
	const LEGACY_ITALIC_PATTERN = '/\*(?!\s)(.*?)(?!\s)\*/u';

	/** Espaces (y compris insécables) en début et en fin de titre uniquement. */
	const TITLE_TRIM_PATTERN = '/^(?:\s|\x{00A0}|\x{202F}|&nbsp;)+|(?:\s|\x{00A0}|\x{202F}|&nbsp;)+$/u';

	/** Blocs dont le texte peut être corrigé (leur texte est lu depuis le HTML par l'éditeur). */
	const DEFAULT_TEXT_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/pullquote',
		'core/table',
		'core/details',
	);

	/** Balises dont tout le contenu est protégé. */
	const DEFAULT_PROTECTED_TAGS = array( 'pre', 'code', 'kbd', 'samp', 'svg', 'math', 'object', 'template' );

	/** Éléments « texte brut » : capturés d'un seul tenant, jamais modifiés. */
	const RAW_TEXT_TAGS = array( 'script', 'style', 'textarea', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext' );

	/** Éléments vides (sans balise fermante). */
	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' );

	/** Délimiteur de bloc Gutenberg, même syntaxe que WP_Block_Parser. */
	const BLOCK_DELIMITER_PATTERN = '~^<!--\s+(?P<closer>/)?wp:(?P<ns>[a-z][a-z0-9_-]*/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?:\{.*\}\s+)?(?P<void>/)?-->$~s';

	/** Nom d'une balise ouvrante ou fermante. */
	const TAG_NAME_PATTERN = '~^<(/?)([a-zA-Z][^\s/>]*)~';

	/** Types de règles acceptés. */
	const RULE_TYPES = array( 'str_replace', 'preg_replace' );

	/**
	 * Règles actives, indexées par leur position dans la liste d'origine.
	 *
	 * @var array<int,array>
	 */
	private $rules = array();

	/** @var string[] */
	private $text_blocks;

	/** @var string[] */
	private $protected_tags;

	/**
	 * Fonction de filtrage HTML (wp_kses_post dans WordPress) ou null.
	 *
	 * @var callable|null
	 */
	private $kses;

	/**
	 * Expression qui isole les balises de shortcodes enregistrés, ou chaîne vide.
	 *
	 * @var string
	 */
	private $shortcode_pattern = '';

	/**
	 * @param array $rules   Règles déjà validées par validate_rules() ; seules celles dont `enabled` vaut true sont appliquées.
	 * @param array $options {
	 *     @type string[]      $text_blocks    Blocs dont le texte peut être corrigé.
	 *     @type string[]      $protected_tags Balises dont le contenu est protégé.
	 *     @type string[]      $shortcode_tags Noms des shortcodes enregistrés (leurs balises sont protégées).
	 *     @type callable|null $kses           Filtre HTML appliqué à chaque balise produite (wp_kses_post).
	 * }
	 */
	public function __construct( array $rules, array $options = array() ) {
		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) || true !== ( isset( $rule['enabled'] ) ? $rule['enabled'] : false ) ) {
				continue;
			}
			if ( 'str_replace' === $rule['type'] && isset( $rule['search'] ) && is_string( $rule['search'] ) && '' !== $rule['search'] ) {
				$rule['replace'] = isset( $rule['replace'] ) ? (string) $rule['replace'] : '';
				$this->rules[ (int) $index ] = $rule;
			} elseif ( 'preg_replace' === $rule['type'] && isset( $rule['pattern'] ) && is_string( $rule['pattern'] ) && '' !== $rule['pattern'] ) {
				$rule['replacement'] = isset( $rule['replacement'] ) ? (string) $rule['replacement'] : '';
				$this->rules[ (int) $index ] = $rule;
			}
		}

		$this->text_blocks    = array_values( array_map( 'strval', isset( $options['text_blocks'] ) ? (array) $options['text_blocks'] : self::DEFAULT_TEXT_BLOCKS ) );
		$this->protected_tags = array_values( array_map( 'strtolower', isset( $options['protected_tags'] ) ? (array) $options['protected_tags'] : self::DEFAULT_PROTECTED_TAGS ) );
		$this->kses           = ( isset( $options['kses'] ) && is_callable( $options['kses'] ) ) ? $options['kses'] : null;

		$tags = array();
		foreach ( isset( $options['shortcode_tags'] ) ? (array) $options['shortcode_tags'] : array() as $tag ) {
			if ( is_string( $tag ) && '' !== $tag ) {
				$tags[] = preg_quote( $tag, '~' );
			}
		}
		if ( $tags ) {
			$this->shortcode_pattern = '~(\[\[?/?(?:' . implode( '|', $tags ) . ')(?![\w-])[^\]]*+\]\]?)~';
		}
	}

	/**
	 * Règles par défaut.
	 *
	 * @return array[]
	 */
	public static function default_rules() {
		return array(
			array(
				'id'          => 'remove_code_fence',
				'label'       => 'Suppression des balises de code Markdown isolées (```, ```html…)',
				'type'        => 'preg_replace',
				'enabled'     => true,
				'pattern'     => self::FENCE_PATTERN,
				'replacement' => '',
			),
			array(
				'id'          => 'bold_markdown_to_strong',
				'label'       => 'Conversion de **texte** en <strong>',
				'type'        => 'preg_replace',
				'enabled'     => true,
				'pattern'     => self::BOLD_PATTERN,
				'replacement' => '<strong>$1</strong>',
			),
			array(
				'id'          => 'italic_markdown_to_em',
				'label'       => 'Conversion de *texte* en <em>',
				'type'        => 'preg_replace',
				'enabled'     => true,
				'pattern'     => self::ITALIC_PATTERN,
				'replacement' => '<em>$1</em>',
			),
		);
	}

	/**
	 * Règles par défaut au format JSON (tel qu'affiché dans l'administration).
	 *
	 * @return string
	 */
	public static function default_rules_json() {
		return (string) json_encode( self::default_rules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Applique les règles actives au contenu.
	 *
	 * En cas d'erreur (regex en échec, HTML produit non sûr…), `error` est
	 * renseigné et `content` contient le contenu d'origine : l'appelant ne doit
	 * rien enregistrer.
	 *
	 * @param string $content Contenu brut (post_content).
	 * @return array{content:string,changed:bool,counts:array<int,int>,error:string}
	 */
	public function apply( $content ) {
		$content = (string) $content;
		$result  = array(
			'content' => $content,
			'changed' => false,
			'counts'  => array(),
			'error'   => '',
		);
		$current = $content;

		foreach ( $this->rules as $index => $rule ) {
			// Une chaîne absente du contenu ne peut pas se trouver dans un de ses segments.
			if ( 'str_replace' === $rule['type'] && false === strpos( $current, $rule['search'] ) ) {
				continue;
			}

			$segments = $this->segments( $current );
			if ( false === $segments ) {
				/* translators: %s: PCRE error message. */
				$result['error'] = sprintf( __( 'Découpage du contenu impossible (%s) : contenu laissé intact.', 'effi-ai-corrector' ), self::preg_error() );
				return $result;
			}

			$rule_count = 0;
			$parts      = array();
			foreach ( $segments as $segment ) {
				list( $text, $editable ) = $segment;
				if ( ! $editable || '' === $text ) {
					$parts[] = $text;
					continue;
				}

				$count = 0;
				if ( 'str_replace' === $rule['type'] ) {
					$new = str_replace( $rule['search'], $rule['replace'], $text, $count );
				} else {
					$new = preg_replace( $rule['pattern'], $rule['replacement'], $text, -1, $count );
					if ( null === $new ) {
						$result['error'] = sprintf(
							/* translators: 1: rule name, 2: PCRE error message. */
							__( 'La règle « %1$s » a échoué (%2$s) : contenu laissé intact.', 'effi-ai-corrector' ),
							self::rule_name( $rule, $index ),
							self::preg_error()
						);
						return $result;
					}
				}

				if ( $count > 0 && $new !== $text ) {
					$problem = self::check_fragment( $new, $this->kses );
					if ( '' !== $problem ) {
						$result['error'] = sprintf(
							/* translators: 1: rule name, 2: description of the problem. */
							__( 'La règle « %1$s » produirait du HTML refusé ou mal formé (%2$s) : contenu laissé intact.', 'effi-ai-corrector' ),
							self::rule_name( $rule, $index ),
							$problem
						);
						return $result;
					}
					$rule_count += $count;
				}
				$parts[] = $new;
			}

			if ( $rule_count > 0 ) {
				$current                   = implode( '', $parts );
				$result['counts'][ $index ] = $rule_count;
			}
		}

		$result['content'] = $current;
		$result['changed'] = $current !== $content;
		return $result;
	}

	/**
	 * Nom lisible d'une règle active.
	 *
	 * @param int $index Position de la règle.
	 * @return string
	 */
	public function get_rule_name( $index ) {
		return isset( $this->rules[ $index ] ) ? self::rule_name( $this->rules[ $index ], $index ) : '#' . ( (int) $index + 1 );
	}

	/**
	 * Retire les espaces (normaux, insécables, fines insécables, &nbsp;) en début
	 * et en fin de titre. Ceux du milieu (« Pourquoi ? », « Note : ») sont conservés.
	 *
	 * @param string $title Titre brut.
	 * @return array{title:string,changed:bool,error:string}
	 */
	public static function fix_title( $title ) {
		$title = (string) $title;
		$new   = preg_replace( self::TITLE_TRIM_PATTERN, '', $title );
		if ( null === $new ) {
			return array(
				'title'   => $title,
				'changed' => false,
				/* translators: %s: PCRE error message. */
				'error'   => sprintf( __( 'Nettoyage du titre impossible (%s) : titre laissé intact.', 'effi-ai-corrector' ), self::preg_error() ),
			);
		}
		// Un titre réduit à des espaces n'est pas vidé.
		if ( '' === $new ) {
			$new = $title;
		}
		return array(
			'title'   => $new,
			'changed' => $new !== $title,
			'error'   => '',
		);
	}

	/**
	 * Valide une liste de règles décodée depuis le JSON.
	 *
	 * @param mixed         $rules Valeur décodée (json_decode( $json, true )).
	 * @param callable|null $kses  Filtre HTML (wp_kses_post) : le remplacement doit en sortir inchangé.
	 * @return string[] Messages d'erreur (vide si tout est valide).
	 */
	public static function validate_rules( $rules, $kses = null ) {
		if ( ! is_array( $rules ) || ! self::is_list( $rules ) ) {
			return array( __( 'Le JSON doit être une liste de règles : [ { … }, { … } ].', 'effi-ai-corrector' ) );
		}

		$errors = array();
		foreach ( $rules as $index => $rule ) {
			if ( ! is_array( $rule ) || ( $rule && self::is_list( $rule ) ) ) {
				/* translators: %d: rule number. */
				$errors[] = sprintf( __( 'Règle n° %d : chaque règle doit être un objet { … }.', 'effi-ai-corrector' ), $index + 1 );
				continue;
			}
			$name = self::rule_name( $rule, $index );
			foreach ( self::validate_rule( $rule, $kses ) as $message ) {
				/* translators: 1: rule number, 2: rule name, 3: error message. */
				$errors[] = sprintf( __( 'Règle n° %1$d (« %2$s ») : %3$s', 'effi-ai-corrector' ), $index + 1, $name, $message );
			}
		}
		return $errors;
	}

	/**
	 * Valide une règle.
	 *
	 * @param array         $rule Règle.
	 * @param callable|null $kses Filtre HTML.
	 * @return string[]
	 */
	private static function validate_rule( array $rule, $kses ) {
		$errors = array();

		foreach ( array( 'id', 'label' ) as $key ) {
			if ( isset( $rule[ $key ] ) && ! is_string( $rule[ $key ] ) ) {
				/* translators: %s: field name. */
				$errors[] = sprintf( __( 'le champ « %s » doit être une chaîne.', 'effi-ai-corrector' ), $key );
			}
		}

		if ( ! array_key_exists( 'enabled', $rule ) || ! is_bool( $rule['enabled'] ) ) {
			$errors[] = __( 'le champ « enabled » est requis et doit valoir true ou false.', 'effi-ai-corrector' );
		}

		$type = isset( $rule['type'] ) ? $rule['type'] : null;
		if ( ! is_string( $type ) || ! in_array( $type, self::RULE_TYPES, true ) ) {
			$errors[] = __( 'le champ « type » est requis et doit valoir "str_replace" ou "preg_replace".', 'effi-ai-corrector' );
			return $errors;
		}

		if ( 'str_replace' === $type ) {
			$search_key  = 'search';
			$replace_key = 'replace';
		} else {
			$search_key  = 'pattern';
			$replace_key = 'replacement';
		}

		if ( ! isset( $rule[ $search_key ] ) || ! is_string( $rule[ $search_key ] ) || '' === $rule[ $search_key ] ) {
			/* translators: %s: field name. */
			$errors[] = sprintf( __( 'le champ « %s » est requis (chaîne non vide).', 'effi-ai-corrector' ), $search_key );
		}
		if ( ! array_key_exists( $replace_key, $rule ) || ! is_string( $rule[ $replace_key ] ) ) {
			/* translators: %s: field name. */
			$errors[] = sprintf( __( 'le champ « %s » est requis (chaîne, éventuellement vide).', 'effi-ai-corrector' ), $replace_key );
		}
		if ( $errors ) {
			return $errors;
		}

		$search      = $rule[ $search_key ];
		$replacement = $rule[ $replace_key ];

		if ( 'str_replace' === $type ) {
			if ( preg_match( '~<[a-zA-Z/!?]~', $search ) ) {
				$errors[] = __( 'la chaîne recherchée contient une balise HTML ; les règles ne s\'appliquent qu\'au texte situé entre les balises, elle ne trouverait jamais rien.', 'effi-ai-corrector' );
			}
		} else {
			error_clear_last();
			$compiled = @preg_match( $search, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- on récupère le message ci-dessous.
			if ( false === $compiled ) {
				$last     = error_get_last();
				$detail   = $last ? preg_replace( '/^preg_match\(\):\s*/', '', $last['message'] ) : self::preg_error();
				/* translators: %s: PCRE compilation error. */
				$errors[] = sprintf( __( 'motif invalide (%s).', 'effi-ai-corrector' ), $detail );
				return $errors;
			}
			if ( 1 === $compiled ) {
				$errors[] = __( 'le motif correspond à une chaîne vide : il insérerait le remplacement partout.', 'effi-ai-corrector' );
			}
			if ( null === @preg_replace( $search, $replacement, 'Test **gras** *italique* ```html 2*3' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				/* translators: %s: PCRE error message. */
				$errors[] = sprintf( __( 'le remplacement échoue sur un texte de test (%s).', 'effi-ai-corrector' ), self::preg_error() );
			}
		}

		if ( null !== $kses && call_user_func( $kses, $replacement ) !== $replacement ) {
			$errors[] = __( 'le remplacement est modifié par le filtrage HTML de WordPress (wp_kses_post) : balise ou attribut non autorisé, ou caractère « & » isolé (écrivez &amp;).', 'effi-ai-corrector' );
		}

		$problem = self::check_fragment( $replacement, $kses );
		if ( '' !== $problem ) {
			/* translators: %s: description of the problem. */
			$errors[] = sprintf( __( 'remplacement refusé (%s).', 'effi-ai-corrector' ), $problem );
		}

		return $errors;
	}

	/**
	 * Vérifie un fragment de HTML produit par une règle : balises autorisées
	 * par $kses et équilibrées, ni commentaire, ni script, ni chevron isolé.
	 *
	 * @param string        $html Fragment.
	 * @param callable|null $kses Filtre HTML.
	 * @return string Description du problème, ou chaîne vide.
	 */
	public static function check_fragment( $html, $kses = null ) {
		$tokens = self::split( (string) $html );
		if ( false === $tokens ) {
			/* translators: %s: PCRE error message. */
			return sprintf( __( 'découpage impossible : %s', 'effi-ai-corrector' ), self::preg_error() );
		}

		$stack = array();
		foreach ( $tokens as $token ) {
			list( $kind, $text ) = $token;
			if ( 'text' === $kind ) {
				if ( preg_match( '~<[a-zA-Z/!?]~', $text ) ) {
					/* translators: %s: excerpt. */
					return sprintf( __( 'chevron « < » isolé dans « %s »', 'effi-ai-corrector' ), self::excerpt( $text ) );
				}
				continue;
			}
			if ( ! preg_match( self::TAG_NAME_PATTERN, $text, $match ) ) {
				return __( 'commentaire HTML, CDATA ou instruction interdits', 'effi-ai-corrector' );
			}
			$tag = strtolower( $match[2] );
			if ( in_array( $tag, self::RAW_TEXT_TAGS, true ) ) {
				/* translators: %s: tag name. */
				return sprintf( __( 'balise <%s> interdite', 'effi-ai-corrector' ), $tag );
			}
			if ( null !== $kses && call_user_func( $kses, $text ) !== $text ) {
				/* translators: %s: HTML tag. */
				return sprintf( __( 'balise refusée par le filtrage HTML de WordPress : %s', 'effi-ai-corrector' ), self::excerpt( $text ) );
			}
			if ( '/' === $match[1] ) {
				if ( end( $stack ) !== $tag ) {
					/* translators: %s: tag name. */
					return sprintf( __( 'balise fermante </%s> sans balise ouvrante', 'effi-ai-corrector' ), $tag );
				}
				array_pop( $stack );
			} elseif ( ! in_array( $tag, self::VOID_TAGS, true ) && ! preg_match( '~/\s*>$~', $text ) ) {
				$stack[] = $tag;
			}
		}
		if ( $stack ) {
			/* translators: %s: tag name. */
			return sprintf( __( 'balise <%s> non refermée', 'effi-ai-corrector' ), end( $stack ) );
		}
		return '';
	}

	/**
	 * Découpe le contenu en segments [ texte, modifiable ].
	 *
	 * La concaténation des segments redonne exactement le contenu d'origine.
	 *
	 * @param string $html Contenu.
	 * @return array|false Liste de [ string, bool ], ou false si la regex échoue.
	 */
	public function segments( $html ) {
		$tokens = self::split( (string) $html );
		if ( false === $tokens ) {
			return false;
		}

		$segments = array();
		$blocks   = array(); // Pile des blocs ouverts.
		$protect  = null;    // Balise protégée en cours.
		$depth    = 0;

		foreach ( $tokens as $token ) {
			list( $kind, $text ) = $token;

			if ( 'text' === $kind ) {
				$editable = null === $protect && ( ! $blocks || in_array( end( $blocks ), $this->text_blocks, true ) );
				if ( $editable && '' !== $this->shortcode_pattern && false !== strpos( $text, '[' ) ) {
					$pieces = preg_split( $this->shortcode_pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
					if ( false === $pieces ) {
						$segments[] = array( $text, false );
						continue;
					}
					foreach ( $pieces as $i => $piece ) {
						if ( '' !== $piece ) {
							$segments[] = array( $piece, 0 === $i % 2 );
						}
					}
					continue;
				}
				$segments[] = array( $text, $editable );
				continue;
			}

			// Tout ce qui n'est pas du texte est restitué tel quel.
			$segments[] = array( $text, false );

			if ( 0 === strpos( $text, '<!--' ) ) {
				if ( preg_match( self::BLOCK_DELIMITER_PATTERN, $text, $match ) ) {
					$name = ( ! empty( $match['ns'] ) ? $match['ns'] : 'core/' ) . $match['name'];
					if ( ! empty( $match['closer'] ) ) {
						for ( $i = count( $blocks ) - 1; $i >= 0; $i-- ) {
							if ( $blocks[ $i ] === $name ) {
								array_splice( $blocks, $i );
								break;
							}
						}
					} elseif ( empty( $match['void'] ) ) {
						$blocks[] = $name;
					}
				}
				continue;
			}

			if ( ! preg_match( self::TAG_NAME_PATTERN, $text, $match ) ) {
				continue;
			}
			$tag     = strtolower( $match[2] );
			$closing = '/' === $match[1];
			if ( in_array( $tag, self::RAW_TEXT_TAGS, true ) || preg_match( '~/\s*>$~', $text ) ) {
				continue;
			}
			if ( null === $protect ) {
				if ( ! $closing && in_array( $tag, $this->protected_tags, true ) ) {
					$protect = $tag;
					$depth   = 1;
				}
			} elseif ( $tag === $protect ) {
				$depth += $closing ? -1 : 1;
				if ( $depth <= 0 ) {
					$protect = null;
					$depth   = 0;
				}
			}
		}

		return $segments;
	}

	/**
	 * Découpe du HTML en jetons [ 'text'|'markup', chaîne ].
	 *
	 * @param string $html HTML.
	 * @return array|false
	 */
	public static function split( $html ) {
		$count = preg_match_all( self::token_pattern(), $html, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $count ) {
			return false;
		}

		$tokens = array();
		$pos    = 0;
		foreach ( $matches[0] as $match ) {
			list( $markup, $offset ) = $match;
			if ( $offset > $pos ) {
				$tokens[] = array( 'text', substr( $html, $pos, $offset - $pos ) );
			}
			$tokens[] = array( 'markup', $markup );
			$pos      = $offset + strlen( $markup );
		}
		if ( $pos < strlen( $html ) ) {
			$tokens[] = array( 'text', substr( $html, $pos ) );
		}
		return $tokens;
	}

	/**
	 * Expression de découpage : commentaires (dont délimiteurs de blocs), CDATA,
	 * éléments texte brut entiers, balises (les guillemets des attributs sont
	 * respectés, un « > » dans title="a > b" ne ferme pas la balise), et
	 * déclarations. Quantificateurs possessifs : pas de retour arrière explosif.
	 * Sans modificateur /u : fonctionne aussi sur un contenu en UTF-8 invalide.
	 *
	 * @return string
	 */
	private static function token_pattern() {
		static $pattern = null;
		if ( null === $pattern ) {
			$attrs = '(?:[^>"\'=]++|=\s*+(?:"[^"]*+"|\'[^\']*+\')|[="\'])*+';
			$raw   = array();
			foreach ( self::RAW_TEXT_TAGS as $tag ) {
				$raw[] = '<' . $tag . '(?![\w:-])' . $attrs . '>(?:.*?</' . $tag . '\s*+>|.*+$)';
			}
			$pattern = '~<!--(?:.*?-->|.*+$)'
				. '|<!\[CDATA\[(?:.*?\]\]>|.*+$)'
				. '|' . implode( '|', $raw )
				. '|</?[a-zA-Z][^\s/>]*+' . $attrs . '>'
				. '|<[!?][^>]*+>'
				. '~si';
		}
		return $pattern;
	}

	/**
	 * Vrai si le tableau est une liste (clés 0..n-1).
	 *
	 * @param array $value Tableau.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Nom lisible d'une règle.
	 *
	 * @param array $rule  Règle.
	 * @param int   $index Position.
	 * @return string
	 */
	private static function rule_name( array $rule, $index ) {
		foreach ( array( 'label', 'id' ) as $key ) {
			if ( isset( $rule[ $key ] ) && is_string( $rule[ $key ] ) && '' !== trim( $rule[ $key ] ) ) {
				return $rule[ $key ];
			}
		}
		return '#' . ( (int) $index + 1 );
	}

	/**
	 * Dernière erreur PCRE en clair.
	 *
	 * @return string
	 */
	public static function preg_error() {
		if ( function_exists( 'preg_last_error_msg' ) ) {
			return preg_last_error_msg();
		}
		$names = array(
			PREG_NO_ERROR              => 'No error',
			PREG_INTERNAL_ERROR        => 'Internal error',
			PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit exhausted',
			PREG_RECURSION_LIMIT_ERROR => 'Recursion limit exhausted',
			PREG_BAD_UTF8_ERROR        => 'Malformed UTF-8 characters, possibly incorrectly encoded',
			PREG_BAD_UTF8_OFFSET_ERROR => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
		);
		$code  = preg_last_error();
		return isset( $names[ $code ] ) ? $names[ $code ] : 'PCRE error ' . $code;
	}

	/**
	 * Extrait court pour les messages d'erreur.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function excerpt( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( preg_match( '/^.{0,60}/us', $text, $match ) ) {
			return strlen( $match[0] ) < strlen( $text ) ? $match[0] . '…' : $text;
		}
		return substr( $text, 0, 60 );
	}
}
