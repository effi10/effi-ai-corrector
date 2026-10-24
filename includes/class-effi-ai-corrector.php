<?php
/**
 * Administration, traitements AJAX par lots et correction automatique (CRON).
 *
 * @package effi-ai-corrector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principale du plugin.
 */
final class Effi_AI_Corrector {

	const OPTION_POST_TYPES = 'eac_selected_post_types';
	const OPTION_RULES      = 'eac_correction_rules';
	const OPTION_EXCLUDED   = 'eac_excluded_ids';
	const OPTION_CRON_STATE = 'eac_cron_state';
	const OPTION_CRON_LOG   = 'eac_cron_log';
	const OPTION_VERSION    = 'eac_version';
	const OPTION_NOTICE     = 'eac_upgrade_notice';

	/** Toutes les options du plugin (utilisé par uninstall.php). */
	const OPTIONS = array(
		self::OPTION_POST_TYPES,
		self::OPTION_RULES,
		self::OPTION_EXCLUDED,
		self::OPTION_CRON_STATE,
		self::OPTION_CRON_LOG,
		self::OPTION_VERSION,
		self::OPTION_NOTICE,
	);

	const CRON_HOOK        = 'eac_daily_correction';
	const LEGACY_CRON_HOOK = 'effi_ai_corrector_daily_cron';
	const LOCK_TRANSIENT   = 'eac_cron_lock';
	const NONCE_ACTION     = 'eac_ajax';
	const PAGE_SLUG        = 'effi-ai-corrector';
	const BATCH_SIZE       = 50;
	const PREVIEW_MAX      = 5;
	const PREVIEW_LINES    = 20;
	const LOG_MAX          = 10;

	/** @var Effi_AI_Corrector|null */
	private static $instance = null;

	/** @var string */
	private $page_hook = '';

	/**
	 * Valeurs soumises refusées (réaffichées dans le formulaire), ou null.
	 *
	 * @var array|null
	 */
	private $submitted = null;

	/** @var string[] */
	private $form_errors = array();

	/**
	 * @return Effi_AI_Corrector
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_upgrade_notice' ) );
		add_action( 'wp_ajax_eac_analyze', array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_eac_correct', array( $this, 'ajax_correct' ) );
		add_action( 'wp_ajax_eac_toggle_cron', array( $this, 'ajax_toggle_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Installation, mise à jour, désactivation
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Mise à niveau depuis 1.0.x : désactive l'ancien CRON (il appliquait des
	 * règles dangereuses à tout le contenu publié) et remplace les anciennes
	 * règles par défaut enregistrées par leurs versions corrigées.
	 */
	public function maybe_upgrade() {
		$installed = get_option( self::OPTION_VERSION, '' );
		if ( EAC_VERSION === $installed ) {
			return;
		}

		if ( '' === $installed || version_compare( $installed, '1.1.0', '<' ) ) {
			$flags = array();
			if ( wp_next_scheduled( self::LEGACY_CRON_HOOK ) ) {
				wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
				$flags[] = 'cron_disabled';
			}
			if ( $this->migrate_legacy_rules() ) {
				$flags[] = 'rules_migrated';
			}
			// Option toujours présente et chargée d'office : pas de requête SQL par écran.
			update_option( self::OPTION_NOTICE, $flags, true );
		}

		update_option( self::OPTION_VERSION, EAC_VERSION );
	}

	/**
	 * Remplace, dans les règles enregistrées, les règles par défaut de la 1.0.2
	 * restées inchangées. Les règles personnalisées ne sont pas touchées.
	 *
	 * @return bool Vrai si les règles ont été modifiées.
	 */
	private function migrate_legacy_rules() {
		$json = get_option( self::OPTION_RULES, null );
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return false;
		}
		$rules = json_decode( $json, true );
		if ( ! is_array( $rules ) ) {
			return false;
		}

		$defaults = array();
		foreach ( Effi_AI_Corrector_Engine::default_rules() as $rule ) {
			$defaults[ $rule['id'] ] = $rule;
		}
		$legacy_fences = array(
			'remove_code_block_1'    => '<p>```</p>',
			'remove_code_block_html' => '<p>```html</p>',
		);

		$fence_enabled = null;
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && isset( $rule['id'], $rule['search'] ) && isset( $legacy_fences[ $rule['id'] ] ) && $legacy_fences[ $rule['id'] ] === $rule['search'] ) {
				$fence_enabled = ( true === $fence_enabled ) || ( isset( $rule['enabled'] ) && true === $rule['enabled'] );
			}
		}

		$changed = false;
		$out     = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['id'] ) ) {
				$out[] = $rule;
				continue;
			}
			if ( isset( $rule['search'], $legacy_fences[ $rule['id'] ] ) && $legacy_fences[ $rule['id'] ] === $rule['search'] ) {
				// Les deux anciennes règles sont remplacées par une seule, à la place de la première.
				if ( null !== $fence_enabled ) {
					$new            = $defaults['remove_code_fence'];
					$new['enabled'] = $fence_enabled;
					$out[]          = $new;
					$fence_enabled  = null;
				}
				$changed = true;
				continue;
			}
			$legacy_patterns = array(
				'bold_markdown_to_strong' => Effi_AI_Corrector_Engine::LEGACY_BOLD_PATTERN,
				'italic_markdown_to_em'   => Effi_AI_Corrector_Engine::LEGACY_ITALIC_PATTERN,
			);
			if ( isset( $rule['pattern'], $legacy_patterns[ $rule['id'] ] ) && $legacy_patterns[ $rule['id'] ] === $rule['pattern'] ) {
				$rule['pattern'] = $defaults[ $rule['id'] ]['pattern'];
				$changed         = true;
			}
			$out[] = $rule;
		}

		if ( $changed ) {
			update_option( self::OPTION_RULES, (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		return $changed;
	}

	/**
	 * Désactivation : supprime les tâches planifiées (sur chaque site si le
	 * plugin était activé pour tout le réseau).
	 *
	 * @param bool $network_wide Désactivation réseau.
	 */
	public static function deactivate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::clear_site_schedule();
				restore_current_blog();
			}
			return;
		}
		self::clear_site_schedule();
	}

	/**
	 * Supprime les tâches planifiées et le verrou du site courant.
	 */
	public static function clear_site_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Réglages
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Types de contenu proposés : types publics, sauf les médias.
	 *
	 * @return WP_Post_Type[] Indexés par nom.
	 */
	private function get_allowed_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Types sélectionnés, revalidés contre les types publics enregistrés.
	 *
	 * @return string[]
	 */
	private function get_selected_post_types() {
		$saved = get_option( self::OPTION_POST_TYPES, array() );
		if ( ! is_array( $saved ) ) {
			return array();
		}
		return array_values( array_intersect( array_map( 'strval', $saved ), array_keys( $this->get_allowed_post_types() ) ) );
	}

	/**
	 * Identifiants exclus en permanence.
	 *
	 * @return int[]
	 */
	private function get_excluded_ids() {
		$ids = get_option( self::OPTION_EXCLUDED, array() );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Règles enregistrées (ou par défaut) et erreurs de validation.
	 *
	 * @return array{json:string,rules:array,errors:string[]}
	 */
	private function get_rules() {
		$json = get_option( self::OPTION_RULES, null );
		if ( null === $json || false === $json ) {
			$json = Effi_AI_Corrector_Engine::default_rules_json();
		}
		return $this->parse_rules( is_string( $json ) ? $json : '' );
	}

	/**
	 * Décode et valide un JSON de règles.
	 *
	 * @param string $json JSON.
	 * @return array{json:string,rules:array,errors:string[]}
	 */
	private function parse_rules( $json ) {
		$out = array(
			'json'   => $json,
			'rules'  => array(),
			'errors' => array(),
		);
		if ( '' === trim( $json ) ) {
			return $out;
		}
		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			/* translators: %s: JSON error message. */
			$out['errors'][] = sprintf( __( 'JSON invalide : %s.', 'effi-ai-corrector' ), json_last_error_msg() );
			return $out;
		}
		$out['errors'] = Effi_AI_Corrector_Engine::validate_rules( $decoded, 'wp_kses_post' );
		if ( ! $out['errors'] ) {
			$out['rules'] = $decoded;
		}
		return $out;
	}

	/**
	 * Moteur configuré pour le site.
	 *
	 * @param array $rules Règles validées.
	 * @return Effi_AI_Corrector_Engine
	 */
	private function make_engine( array $rules ) {
		global $shortcode_tags;

		return new Effi_AI_Corrector_Engine(
			$rules,
			array(
				/**
				 * Blocs dont le texte peut être corrigé.
				 *
				 * @param string[] $blocks Noms de blocs.
				 */
				'text_blocks'    => (array) apply_filters( 'eac_text_blocks', Effi_AI_Corrector_Engine::DEFAULT_TEXT_BLOCKS ),
				/**
				 * Balises dont le contenu n'est jamais modifié.
				 *
				 * @param string[] $tags Noms de balises.
				 */
				'protected_tags' => (array) apply_filters( 'eac_protected_tags', Effi_AI_Corrector_Engine::DEFAULT_PROTECTED_TAGS ),
				'shortcode_tags' => is_array( $shortcode_tags ) ? array_keys( $shortcode_tags ) : array(),
				'kses'           => 'wp_kses_post',
			)
		);
	}

	/**
	 * Traite le formulaire de réglages (avant tout affichage).
	 */
	public function handle_settings_form() {
		if ( ! isset( $_POST['eac_save_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'effi-ai-corrector' ), 403 );
		}
		check_admin_referer( 'eac_save_settings', 'eac_save_settings_nonce' );

		$allowed = array_keys( $this->get_allowed_post_types() );
		$posted  = isset( $_POST['eac_post_types'] ) ? (array) wp_unslash( $_POST['eac_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key ci-dessous.
		$types   = array_values( array_intersect( array_map( 'sanitize_key', $posted ), $allowed ) );

		$excluded_raw = isset( $_POST['eac_excluded_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['eac_excluded_ids'] ) ) : '';
		$excluded     = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $excluded_raw ) ) ) ) );

		// Le JSON est conservé tel quel : il est validé, jamais affiché sans échappement.
		$json   = isset( $_POST['eac_rules_json'] ) ? (string) wp_unslash( $_POST['eac_rules_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$parsed = $this->parse_rules( $json );

		if ( $parsed['errors'] ) {
			$this->form_errors = $parsed['errors'];
			$this->submitted   = array(
				'types'    => $types,
				'excluded' => $excluded_raw,
				'json'     => $json,
			);
			return;
		}

		update_option( self::OPTION_POST_TYPES, $types );
		update_option( self::OPTION_EXCLUDED, $excluded, false );
		update_option( self::OPTION_RULES, $json );

		wp_safe_redirect( add_query_arg( 'eac-saved', '1', menu_page_url( self::PAGE_SLUG, false ) ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Administration
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Page dans le menu Outils.
	 */
	public function add_admin_menu() {
		$this->page_hook = (string) add_management_page(
			__( 'effi AI Corrector', 'effi-ai-corrector' ),
			__( 'effi AI Corrector', 'effi-ai-corrector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
		if ( '' !== $this->page_hook ) {
			add_action( 'load-' . $this->page_hook, array( $this, 'handle_settings_form' ) );
		}
	}

	/**
	 * Script et style de la page.
	 *
	 * @param string $hook Écran courant.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( '' === $this->page_hook || $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'eac-admin', EAC_URL . 'assets/admin.css', array(), EAC_VERSION );
		wp_enqueue_script( 'eac-admin', EAC_URL . 'assets/admin.js', array( 'jquery' ), EAC_VERSION, true );
		wp_localize_script(
			'eac-admin',
			'eacAdmin',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
				'batchSize'    => self::BATCH_SIZE,
				'previewMax'   => self::PREVIEW_MAX,
				'defaultRules' => Effi_AI_Corrector_Engine::default_rules_json(),
				'i18n'         => array(
					'analyzing'       => __( 'Analyse en cours : %1$d / %2$d contenus examinés…', 'effi-ai-corrector' ),
					'analyzeDone'     => __( 'Analyse terminée : %1$d contenu(s) examiné(s), %2$d concerné(s) par au moins une correction, %3$d en erreur. Vérifiez l\'aperçu et décochez les contenus à ne pas modifier.', 'effi-ai-corrector' ),
					'nothingToFix'    => __( 'Analyse terminée : %1$d contenu(s) examiné(s), aucun à corriger avec les règles actuelles (%2$d en erreur).', 'effi-ai-corrector' ),
					'correcting'      => __( 'Correction en cours : %1$d / %2$d contenus traités…', 'effi-ai-corrector' ),
					'correctDone'     => __( 'Correction terminée : %1$d contenu(s) modifié(s), %2$d inchangé(s) ou ignoré(s), %3$d en erreur.', 'effi-ai-corrector' ),
					'analyzeFailed'   => __( 'Analyse interrompue après %1$d contenu(s) examiné(s) : %2$s.', 'effi-ai-corrector' ),
					'unsavedSettings' => __( 'Vos modifications des réglages ne sont pas enregistrées : l\'analyse utilisera les réglages enregistrés. Continuer ?', 'effi-ai-corrector' ),
					'interrupted'     => __( 'Traitement interrompu après %1$d contenu(s) : %2$s. Les contenus déjà corrigés le restent ; relancez l\'analyse avant de reprendre.', 'effi-ai-corrector' ),
					'confirmCorrect'  => __( "⚠️ Corriger %d contenu(s) maintenant ?\n\nLeur contenu va être modifié dans la base de données. Vérifiez que vous disposez d'une sauvegarde récente et que l'aperçu vous convient.", 'effi-ai-corrector' ),
					'confirmCron'     => __( "⚠️ Activer la correction automatique ?\n\nChaque nuit vers 3 h (heure du site), les règles actuelles seront appliquées SANS confirmation ni aperçu aux contenus publiés ou modifiés depuis l'exécution précédente.\n\nAssurez-vous que vos sauvegardes sont automatiques.", 'effi-ai-corrector' ),
					'confirmDefaults' => __( 'Remplacer le contenu de la zone de règles par les règles par défaut ? Rien n\'est enregistré tant que vous ne cliquez pas sur « Enregistrer les réglages ».', 'effi-ai-corrector' ),
					'noSelection'     => __( 'Aucun contenu sélectionné.', 'effi-ai-corrector' ),
					'httpError'       => __( 'erreur du serveur (HTTP %s)', 'effi-ai-corrector' ),
					'timeout'         => __( 'délai d\'attente dépassé', 'effi-ai-corrector' ),
					'invalidResponse' => __( 'réponse invalide du serveur', 'effi-ai-corrector' ),
					'preview'         => __( 'Aperçu avant / après', 'effi-ai-corrector' ),
					'titleChange'     => __( 'Titre : espaces retirés en début ou en fin', 'effi-ai-corrector' ),
					'statusUpdated'   => __( 'Corrigé', 'effi-ai-corrector' ),
					'statusUnchanged' => __( 'Inchangé', 'effi-ai-corrector' ),
					'statusSkipped'   => __( 'Ignoré', 'effi-ai-corrector' ),
					'statusError'     => __( 'Erreur', 'effi-ai-corrector' ),
					'statusExcluded'  => __( 'Exclu', 'effi-ai-corrector' ),
					'leaveWarning'    => __( 'Un traitement est en cours : quitter la page l\'interrompra.', 'effi-ai-corrector' ),
					'cronEnable'      => __( 'Activer la correction automatique', 'effi-ai-corrector' ),
					'cronDisable'     => __( 'Désactiver la correction automatique', 'effi-ai-corrector' ),
				),
			)
		);
	}

	/**
	 * Avis affiché après une mise à jour depuis la 1.0.x.
	 */
	public function render_upgrade_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $screen->id === $this->page_hook ) {
			return; // Affiché dans la page elle-même.
		}
		$flags = get_option( self::OPTION_NOTICE, array() );
		if ( ! is_array( $flags ) || ! $flags ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'effi AI Corrector 1.1.0 :', 'effi-ai-corrector' ),
			esc_html( $this->upgrade_message( $flags ) ),
			esc_url( menu_page_url( self::PAGE_SLUG, false ) ),
			esc_html__( 'Ouvrir l\'outil', 'effi-ai-corrector' )
		);
	}

	/**
	 * Texte de l'avis de mise à jour.
	 *
	 * @param string[] $flags Changements effectués.
	 * @return string
	 */
	private function upgrade_message( array $flags ) {
		$parts = array();
		if ( in_array( 'cron_disabled', $flags, true ) ) {
			$parts[] = __( 'la correction automatique (CRON) de la version précédente a été désactivée par sécurité ; vérifiez vos règles avec l\'analyse avant de la réactiver.', 'effi-ai-corrector' );
		}
		if ( in_array( 'rules_migrated', $flags, true ) ) {
			$parts[] = __( 'les règles par défaut enregistrées ont été remplacées par leurs versions corrigées (Markdown et blocs de code).', 'effi-ai-corrector' );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Page de l'outil.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$allowed_types = $this->get_allowed_post_types();
		$stored_rules  = $this->get_rules();

		if ( null !== $this->submitted ) {
			$selected_types = $this->submitted['types'];
			$excluded_text  = $this->submitted['excluded'];
			$rules_json     = $this->submitted['json'];
		} else {
			$selected_types = $this->get_selected_post_types();
			$excluded_text  = implode( ', ', $this->get_excluded_ids() );
			$rules_json     = $stored_rules['json'];
		}

		$cron_next = wp_next_scheduled( self::CRON_HOOK );
		$flags     = get_option( self::OPTION_NOTICE, array() );
		?>
		<div class="wrap eac-wrap">
			<h1><?php esc_html_e( 'effi AI Corrector', 'effi-ai-corrector' ); ?></h1>
			<hr class="wp-header-end">

			<div class="notice notice-warning inline eac-warning">
				<p><strong><?php esc_html_e( '⚠️ Avertissement : ce plugin modifie le contenu de vos publications en masse.', 'effi-ai-corrector' ); ?></strong></p>
				<ul>
					<li><?php esc_html_e( 'Faites une sauvegarde complète de la base de données avant toute correction.', 'effi-ai-corrector' ); ?></li>
					<li><?php esc_html_e( 'Lancez toujours l\'analyse et vérifiez l\'aperçu avant de corriger.', 'effi-ai-corrector' ); ?></li>
					<li><?php esc_html_e( 'La correction automatique (CRON) applique les règles sans confirmation ni aperçu.', 'effi-ai-corrector' ); ?></li>
				</ul>
			</div>

			<?php
			if ( is_array( $flags ) && $flags ) {
				printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( $this->upgrade_message( $flags ) ) );
				update_option( self::OPTION_NOTICE, array(), true );
			}

			if ( isset( $_GET['eac-saved'] ) && null === $this->submitted ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple affichage.
				$message = __( 'Réglages enregistrés.', 'effi-ai-corrector' );
				if ( $cron_next ) {
					$message .= ' ' . __( 'La correction automatique utilisera ces réglages dès sa prochaine exécution.', 'effi-ai-corrector' );
				}
				printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			}

			if ( $this->form_errors ) {
				$this->render_error_list( __( 'Les réglages n\'ont pas été enregistrés : les règles contiennent des erreurs.', 'effi-ai-corrector' ), $this->form_errors );
			} elseif ( $stored_rules['errors'] ) {
				$this->render_error_list( __( 'Les règles enregistrées sont invalides : analyse, correction et exécution automatique sont bloquées tant qu\'elles ne sont pas corrigées.', 'effi-ai-corrector' ), $stored_rules['errors'] );
			}
			?>

			<p><?php esc_html_e( 'Cet outil nettoie les restes de formatage laissés par les IA (Markdown, balises de code isolées, espaces autour des titres) dans les contenus publiés.', 'effi-ai-corrector' ); ?></p>

			<form method="post" action="" id="eac-settings">
				<?php wp_nonce_field( 'eac_save_settings', 'eac_save_settings_nonce' ); ?>

				<h2 class="title"><?php esc_html_e( '1. Contenus à traiter', 'effi-ai-corrector' ); ?></h2>
				<p><?php esc_html_e( 'Seuls les contenus publiés des types cochés sont analysés et corrigés.', 'effi-ai-corrector' ); ?></p>
				<fieldset class="eac-types">
					<legend class="screen-reader-text"><?php esc_html_e( 'Types de contenu', 'effi-ai-corrector' ); ?></legend>
					<?php foreach ( $allowed_types as $type ) : ?>
						<label>
							<input type="checkbox" name="eac_post_types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $selected_types, true ) ); ?>>
							<?php echo esc_html( $type->labels->name ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<p>
					<label for="eac-excluded-ids"><strong><?php esc_html_e( 'Contenus exclus en permanence', 'effi-ai-corrector' ); ?></strong></label><br>
					<input type="text" id="eac-excluded-ids" name="eac_excluded_ids" class="regular-text" value="<?php echo esc_attr( $excluded_text ); ?>" placeholder="12, 345, 6789">
				</p>
				<p class="description"><?php esc_html_e( 'Identifiants séparés par des virgules. Ces contenus ne sont jamais analysés ni modifiés, y compris par la correction automatique.', 'effi-ai-corrector' ); ?></p>

				<h2 class="title"><?php esc_html_e( '2. Règles de correction (JSON)', 'effi-ai-corrector' ); ?></h2>
				<p><?php esc_html_e( 'Les règles sont appliquées dans l\'ordre, uniquement au texte situé entre les balises : jamais aux balises ni à leurs attributs, aux commentaires de blocs, au contenu des balises pre, code, script, style, textarea, iframe, ni aux blocs autres que paragraphe, titre, liste, citation, exergue, tableau et détails (le contenu classique est traité). Laissez la zone vide pour ne corriger que les titres.', 'effi-ai-corrector' ); ?></p>
				<p><?php esc_html_e( 'Chaque règle est vérifiée à l\'enregistrement : champs requis, type connu, motif valide, remplacement accepté par le filtrage HTML de WordPress. En cas d\'erreur, rien n\'est enregistré.', 'effi-ai-corrector' ); ?></p>
				<textarea name="eac_rules_json" id="eac-rules-json" rows="18" class="large-text code" spellcheck="false"><?php echo esc_textarea( $rules_json ); ?></textarea>
				<p>
					<button type="button" class="button" id="eac-default-rules"><?php esc_html_e( 'Insérer les règles par défaut', 'effi-ai-corrector' ); ?></button>
				</p>

				<p><button type="submit" name="eac_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Enregistrer les réglages', 'effi-ai-corrector' ); ?></button></p>
			</form>

			<hr>

			<h2 class="title"><?php esc_html_e( '3. Analyse et correction', 'effi-ai-corrector' ); ?></h2>
			<div id="eac-manual">
				<p><?php esc_html_e( 'L\'analyse ne modifie rien : elle liste les contenus concernés avec un aperçu avant / après pour les premiers. La correction ne porte que sur les contenus cochés dans cette liste, par lots de 50.', 'effi-ai-corrector' ); ?></p>
				<p><?php esc_html_e( 'Avant chaque modification, l\'état actuel du contenu est enregistré dans ses révisions (si le type de contenu les gère et qu\'elles ne sont pas désactivées) : vous pouvez le restaurer depuis l\'écran Révisions de chaque contenu. Cela ne remplace pas une sauvegarde de la base.', 'effi-ai-corrector' ); ?></p>
				<p>
					<button type="button" id="eac-analyze" class="button button-secondary"><?php esc_html_e( 'Analyser', 'effi-ai-corrector' ); ?></button>
					<button type="button" id="eac-correct" class="button button-primary" disabled><?php esc_html_e( 'Corriger les contenus cochés', 'effi-ai-corrector' ); ?></button>
				</p>
				<div id="eac-progress" class="eac-progress" hidden>
					<div class="eac-progress-track"><div class="eac-progress-bar"></div></div>
					<p class="eac-progress-label" aria-live="polite"></p>
				</div>
				<div id="eac-message" class="notice inline" hidden><p></p></div>
				<div id="eac-results" hidden>
					<table class="widefat striped eac-results-table">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" id="eac-check-all" checked aria-label="<?php esc_attr_e( 'Tout cocher', 'effi-ai-corrector' ); ?>"></td>
								<th scope="col"><?php esc_html_e( 'Contenu', 'effi-ai-corrector' ); ?></th>
								<th scope="col" class="eac-col-type"><?php esc_html_e( 'Type', 'effi-ai-corrector' ); ?></th>
								<th scope="col" class="eac-col-changes"><?php esc_html_e( 'Corrections prévues', 'effi-ai-corrector' ); ?></th>
								<th scope="col" class="eac-col-status"><?php esc_html_e( 'Statut', 'effi-ai-corrector' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
				<div id="eac-errors" hidden>
					<h3><?php esc_html_e( 'Contenus non traités', 'effi-ai-corrector' ); ?></h3>
					<ul></ul>
				</div>
			</div>

			<hr>

			<h2 class="title"><?php esc_html_e( '4. Correction automatique (CRON)', 'effi-ai-corrector' ); ?></h2>
			<div id="eac-cron">
				<p><?php esc_html_e( 'Une fois activée, la correction s\'exécute chaque nuit vers 3 h (heure du site, au premier passage d\'un visiteur ensuite si le site utilise le WP-Cron standard). Elle ne traite que les contenus publiés ou modifiés depuis l\'exécution précédente (depuis l\'activation pour la première), et applique les règles enregistrées sans confirmation ni aperçu.', 'effi-ai-corrector' ); ?></p>
				<p id="eac-cron-status"><?php echo wp_kses_post( $this->cron_status_html() ); ?></p>
				<div id="eac-cron-message" class="notice inline" hidden><p></p></div>
				<p>
					<button type="button" id="eac-cron-toggle" class="button" data-active="<?php echo $cron_next ? '1' : '0'; ?>">
						<?php echo $cron_next ? esc_html__( 'Désactiver la correction automatique', 'effi-ai-corrector' ) : esc_html__( 'Activer la correction automatique', 'effi-ai-corrector' ); ?>
					</button>
				</p>
				<h3><?php esc_html_e( 'Journal des dernières exécutions', 'effi-ai-corrector' ); ?></h3>
				<?php $this->render_cron_log(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Liste d'erreurs dans un avis.
	 *
	 * @param string   $title  Titre.
	 * @param string[] $errors Messages.
	 */
	private function render_error_list( $title, array $errors ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $title ) . '</strong></p><ul class="eac-error-list">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Date et heure dans le fuseau et les formats du site.
	 *
	 * @param int $timestamp Horodatage Unix.
	 * @return string
	 */
	private function format_datetime( $timestamp ) {
		/* translators: 1: date, 2: time. */
		return sprintf( __( '%1$s à %2$s', 'effi-ai-corrector' ), wp_date( get_option( 'date_format' ), $timestamp ), wp_date( get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * État du CRON (HTML simple, échappé).
	 *
	 * @return string
	 */
	private function cron_status_html() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $next ) {
			return esc_html__( 'La correction automatique est', 'effi-ai-corrector' ) . ' <strong>' . esc_html__( 'inactive', 'effi-ai-corrector' ) . '</strong>.';
		}

		$html  = esc_html__( 'La correction automatique est', 'effi-ai-corrector' ) . ' <strong>' . esc_html__( 'active', 'effi-ai-corrector' ) . '</strong>. ';
		/* translators: %s: date and time. */
		$html .= esc_html( sprintf( __( 'Prochaine exécution : %s (heure du site).', 'effi-ai-corrector' ), $this->format_datetime( $next ) ) );

		$state = $this->get_cron_state();
		if ( '' !== $state['since'] ) {
			$since = strtotime( $state['since'] . ' +0000' );
			if ( $since ) {
				/* translators: %s: date and time. */
				$html .= ' ' . esc_html( sprintf( __( 'Elle traitera les contenus publiés ou modifiés depuis le %s.', 'effi-ai-corrector' ), $this->format_datetime( $since ) ) );
			}
		}
		if ( $state['queue'] ) {
			/* translators: %d: number of contents. */
			$html .= ' ' . esc_html( sprintf( __( '%d contenu(s) restent à traiter de l\'exécution précédente.', 'effi-ai-corrector' ), count( $state['queue'] ) ) );
		}
		return $html;
	}

	/**
	 * Journal des exécutions automatiques.
	 */
	private function render_cron_log() {
		$log = get_option( self::OPTION_CRON_LOG, array() );
		if ( ! is_array( $log ) || ! $log ) {
			echo '<p>' . esc_html__( 'Aucune exécution enregistrée.', 'effi-ai-corrector' ) . '</p>';
			return;
		}

		$statuses = array(
			'ok'      => __( 'Terminée', 'effi-ai-corrector' ),
			'errors'  => __( 'Terminée avec erreurs', 'effi-ai-corrector' ),
			'partial' => __( 'Partielle (suite planifiée)', 'effi-ai-corrector' ),
			'skipped' => __( 'Ignorée', 'effi-ai-corrector' ),
			'failed'  => __( 'Échec', 'effi-ai-corrector' ),
		);
		?>
		<table class="widefat striped eac-log-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'effi-ai-corrector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Statut', 'effi-ai-corrector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Examinés', 'effi-ai-corrector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Contenus modifiés', 'effi-ai-corrector' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Erreurs et remarques', 'effi-ai-corrector' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $log as $entry ) : ?>
				<?php
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$status   = isset( $entry['status'], $statuses[ $entry['status'] ] ) ? $statuses[ $entry['status'] ] : '';
				$modified = isset( $entry['modified'] ) ? array_map( 'absint', (array) $entry['modified'] ) : array();
				$total    = isset( $entry['modified_count'] ) ? (int) $entry['modified_count'] : count( $modified );
				?>
				<tr>
					<td><?php echo esc_html( isset( $entry['time'] ) ? $this->format_datetime( (int) $entry['time'] ) : '' ); ?></td>
					<td><?php echo esc_html( $status ); ?></td>
					<td><?php echo esc_html( isset( $entry['checked'] ) ? (string) (int) $entry['checked'] : '0' ); ?></td>
					<td>
						<?php
						if ( ! $modified ) {
							echo '—';
						} else {
							$links = array();
							foreach ( $modified as $id ) {
								$url     = get_edit_post_link( $id );
								$links[] = $url ? '<a href="' . esc_url( $url ) . '">#' . esc_html( (string) $id ) . '</a>' : '#' . esc_html( (string) $id );
							}
							echo wp_kses_post( implode( ', ', $links ) );
							if ( $total > count( $modified ) ) {
								/* translators: %d: number of other contents. */
								echo ' ' . esc_html( sprintf( __( 'et %d autre(s)', 'effi-ai-corrector' ), $total - count( $modified ) ) );
							}
						}
						?>
					</td>
					<td>
						<?php
						$lines = array();
						if ( ! empty( $entry['message'] ) ) {
							$lines[] = (string) $entry['message'];
						}
						foreach ( isset( $entry['errors'] ) ? (array) $entry['errors'] : array() as $error ) {
							if ( is_array( $error ) && isset( $error['id'], $error['message'] ) ) {
								$lines[] = '#' . absint( $error['id'] ) . ' : ' . $error['message'];
							}
						}
						if ( ! $lines ) {
							echo '—';
						} else {
							echo '<ul class="eac-log-errors">';
							foreach ( $lines as $line ) {
								echo '<li>' . esc_html( $line ) . '</li>';
							}
							echo '</ul>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Sélection et traitement des contenus
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Identifiants des contenus à traiter, par ordre croissant.
	 *
	 * @param string[] $types    Types sélectionnés.
	 * @param int      $after_id Ne renvoie que les ID supérieurs.
	 * @param int      $limit    Nombre maximum (-1 : tous).
	 * @param array    $extra    Arguments WP_Query supplémentaires.
	 * @return WP_Query|null Null si aucun type n'est sélectionné.
	 */
	private function query_ids( array $types, $after_id, $limit, array $extra = array() ) {
		if ( ! $types ) {
			return null;
		}

		$args = array_merge(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => (int) $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
				'post__not_in'           => $this->get_excluded_ids(),
				'eac_after_id'           => (int) $after_id,
			),
			$extra
		);

		add_filter( 'posts_where', array( $this, 'filter_after_id' ), 10, 2 );
		$query = new WP_Query( $args );
		remove_filter( 'posts_where', array( $this, 'filter_after_id' ), 10 );

		return $query;
	}

	/**
	 * Pagination par curseur (ID > n) pour nos seules requêtes.
	 *
	 * @param string   $where Clause WHERE.
	 * @param WP_Query $query Requête.
	 * @return string
	 */
	public function filter_after_id( $where, $query ) {
		global $wpdb;

		$after = $query instanceof WP_Query ? (int) $query->get( 'eac_after_id' ) : 0;
		if ( $after > 0 ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		}
		return $where;
	}

	/**
	 * Analyse ou corrige un contenu.
	 *
	 * @param int                      $post_id ID.
	 * @param Effi_AI_Corrector_Engine $engine  Moteur.
	 * @param string[]                 $types   Types autorisés.
	 * @param bool                     $write   Enregistrer la correction.
	 * @param bool                     $preview Joindre un aperçu avant / après.
	 * @return array Résultat : status = changed|updated|unchanged|skipped|error.
	 */
	private function process_post( $post_id, Effi_AI_Corrector_Engine $engine, array $types, $write, $preview ) {
		$result = array(
			'id'      => (int) $post_id,
			'status'  => 'unchanged',
			'message' => '',
		);

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			$result['status']  = 'skipped';
			$result['message'] = __( 'contenu introuvable', 'effi-ai-corrector' );
			return $result;
		}

		$result['title']    = $this->plain_title( $post );
		$result['edit_url'] = (string) get_edit_post_link( $post->ID, 'raw' );
		$type_object        = get_post_type_object( $post->post_type );
		$result['type']     = $type_object ? $type_object->labels->singular_name : $post->post_type;

		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, $types, true ) || in_array( (int) $post->ID, $this->get_excluded_ids(), true ) ) {
			$result['status']  = 'skipped';
			$result['message'] = __( 'contenu non publié, exclu ou d\'un type non sélectionné', 'effi-ai-corrector' );
			return $result;
		}

		// Contenu et titre bruts, tels qu'enregistrés (jamais get_the_content()).
		$content = (string) get_post_field( 'post_content', $post->ID, 'raw' );
		$title   = (string) get_post_field( 'post_title', $post->ID, 'raw' );

		$content_result = $engine->apply( $content );
		if ( '' !== $content_result['error'] ) {
			$result['status']  = 'error';
			$result['message'] = $content_result['error'];
			return $result;
		}
		$title_result = Effi_AI_Corrector_Engine::fix_title( $title );
		if ( '' !== $title_result['error'] ) {
			$result['status']  = 'error';
			$result['message'] = $title_result['error'];
			return $result;
		}

		if ( ! $content_result['changed'] && ! $title_result['changed'] ) {
			return $result;
		}

		$changes = array();
		foreach ( $content_result['counts'] as $index => $count ) {
			/* translators: 1: rule name, 2: number of replacements. */
			$changes[] = sprintf( __( '%1$s : %2$d', 'effi-ai-corrector' ), $engine->get_rule_name( $index ), $count );
		}
		$result['changes']       = $changes;
		$result['title_changed'] = $title_result['changed'];
		$result['status']        = 'changed';

		if ( $preview && $content_result['changed'] ) {
			$result['preview'] = $this->build_preview( $content, $content_result['content'] );
		}

		if ( ! $write ) {
			return $result;
		}

		$data = array( 'ID' => $post->ID );
		if ( $content_result['changed'] ) {
			$data['post_content'] = $content_result['content'];
		}
		if ( $title_result['changed'] ) {
			$data['post_title'] = $title_result['title'];
		}

		$saved = $this->update_post( $data );
		if ( is_wp_error( $saved ) ) {
			$result['status']  = 'error';
			$result['message'] = $saved->get_error_message();
			return $result;
		}

		$result['status'] = 'updated';
		if ( isset( $data['post_content'] ) && get_post_field( 'post_content', $post->ID, 'raw' ) !== $data['post_content'] ) {
			$result['message'] = __( 'enregistré, mais le contenu en base diffère de la correction calculée (filtre d\'une autre extension ?) : vérifiez-le.', 'effi-ai-corrector' );
		}
		return $result;
	}

	/**
	 * Enregistre la correction.
	 *
	 * - wp_slash() : wp_insert_post() retire un niveau d'antislash ; sans lui,
	 *   les « \ » des attributs de blocs (JSON), du code, etc. disparaissent.
	 * - Révision préalable : le cœur enregistre une révision de la version
	 *   corrigée ; l'état actuel n'est conservé que s'il existe déjà en
	 *   révision. wp_save_post_revision() le sauvegarde si besoin (sans
	 *   doublon si la dernière révision est identique).
	 * - kses : sans le droit unfiltered_html (CRON, multisite,
	 *   DISALLOW_UNFILTERED_HTML), WordPress repasserait TOUT le contenu dans
	 *   wp_kses_post() et supprimerait iframes, scripts, formulaires… déjà
	 *   présents. Le moteur garantit que les règles n'ajoutent que du HTML
	 *   accepté par wp_kses_post() (remplacement vérifié à l'enregistrement de
	 *   la règle, chaque balise produite vérifiée à l'exécution, sinon le
	 *   contenu est refusé). Tout HTML non filtré présent après correction
	 *   l'était donc déjà en base : désactiver kses le temps de cette mise à
	 *   jour ne permet pas d'en ajouter. La révision préalable est créée dans
	 *   la même fenêtre, sinon sa copie de l'original serait elle-même filtrée.
	 *
	 * @param array $data Champs à mettre à jour (non slashés).
	 * @return int|WP_Error
	 */
	private function update_post( array $data ) {
		$kses_active = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses_active ) {
			kses_remove_filters();
		}
		try {
			wp_save_post_revision( $data['ID'] );
			$saved = wp_update_post( wp_slash( $data ), true );
		} finally {
			if ( $kses_active ) {
				kses_init_filters();
			}
		}

		if ( ! is_wp_error( $saved ) && ! $saved ) {
			$saved = new WP_Error( 'eac_update_failed', __( 'la mise à jour a échoué', 'effi-ai-corrector' ) );
		}
		return $saved;
	}

	/**
	 * Aperçu avant / après limité aux lignes modifiées.
	 *
	 * @param string $before Contenu d'origine.
	 * @param string $after  Contenu corrigé.
	 * @return string HTML (passé par wp_kses_post).
	 */
	private function build_preview( $before, $after ) {
		$left  = explode( "\n", normalize_whitespace( $before ) );
		$right = explode( "\n", normalize_whitespace( $after ) );
		$note  = '';

		if ( count( $left ) === count( $right ) ) {
			$kept_left  = array();
			$kept_right = array();
			foreach ( $left as $i => $line ) {
				if ( $line !== $right[ $i ] ) {
					$kept_left[]  = $line;
					$kept_right[] = $right[ $i ];
				}
			}
			$hidden = count( $kept_left ) - self::PREVIEW_LINES;
			if ( $hidden > 0 ) {
				/* translators: %d: number of lines. */
				$note = sprintf( __( '… et %d autre(s) ligne(s) modifiée(s).', 'effi-ai-corrector' ), $hidden );
			}
			$left  = array_slice( $kept_left, 0, self::PREVIEW_LINES );
			$right = array_slice( $kept_right, 0, self::PREVIEW_LINES );
		}

		$diff = wp_text_diff(
			implode( "\n", $left ),
			implode( "\n", $right ),
			array(
				'title_left'  => __( 'Avant', 'effi-ai-corrector' ),
				'title_right' => __( 'Après', 'effi-ai-corrector' ),
			)
		);
		if ( '' !== $note ) {
			$diff .= '<p class="description">' . esc_html( $note ) . '</p>';
		}
		return wp_kses_post( $diff );
	}

	/**
	 * Titre en texte brut pour l'affichage.
	 *
	 * @param WP_Post $post Contenu.
	 * @return string
	 */
	private function plain_title( WP_Post $post ) {
		$title = trim( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		return '' !== $title ? $title : __( '(sans titre)', 'effi-ai-corrector' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Vérifie le jeton et les droits.
	 */
	private function verify_ajax() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expirée : rechargez la page.', 'effi-ai-corrector' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'effi-ai-corrector' ) ), 403 );
		}
	}

	/**
	 * Types et moteur pour un traitement, ou réponse d'erreur.
	 *
	 * @return array{0:string[],1:Effi_AI_Corrector_Engine}
	 */
	private function prepare_run() {
		$types = $this->get_selected_post_types();
		if ( ! $types ) {
			wp_send_json_error( array( 'message' => __( 'Aucun type de contenu n\'est sélectionné : cochez au moins un type dans la section 1, puis enregistrez les réglages.', 'effi-ai-corrector' ) ) );
		}
		$rules = $this->get_rules();
		if ( $rules['errors'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'Les règles enregistrées sont invalides : corrigez-les puis enregistrez les réglages.', 'effi-ai-corrector' ),
					'details' => $rules['errors'],
				)
			);
		}
		return array( $types, $this->make_engine( $rules['rules'] ) );
	}

	/**
	 * Analyse d'un lot de 50 contenus (aucune écriture).
	 */
	public function ajax_analyze() {
		$this->verify_ajax();
		list( $types, $engine ) = $this->prepare_run();

		$after         = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;
		$previews_left = isset( $_POST['previews_left'] ) ? min( self::PREVIEW_MAX, absint( wp_unslash( $_POST['previews_left'] ) ) ) : self::PREVIEW_MAX;

		$query = $this->query_ids( $types, $after, self::BATCH_SIZE );
		if ( ! $query instanceof WP_Query ) {
			wp_send_json_error( array( 'message' => __( 'Aucun type de contenu n\'est sélectionné.', 'effi-ai-corrector' ) ) );
		}
		$ids = array_map( 'intval', $query->posts );
		$this->prime_posts( $ids );

		$items  = array();
		$errors = array();
		foreach ( $ids as $id ) {
			$result = $this->process_post( $id, $engine, $types, false, $previews_left > 0 );
			if ( 'changed' === $result['status'] ) {
				if ( isset( $result['preview'] ) ) {
					--$previews_left;
				}
				$items[] = $result;
			} elseif ( 'error' === $result['status'] ) {
				$errors[] = $result;
			}
		}

		$data = array(
			'checked' => count( $ids ),
			'last_id' => $ids ? (int) end( $ids ) : $after,
			'done'    => count( $ids ) < self::BATCH_SIZE,
			'items'   => $items,
			'errors'  => $errors,
		);
		if ( 0 === $after ) {
			$data['total'] = $this->count_candidates( $types );
		}
		wp_send_json_success( $data );
	}

	/**
	 * Correction d'un lot (50 ID au plus) choisis après l'analyse.
	 */
	public function ajax_correct() {
		$this->verify_ajax();
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			wp_send_json_error( array( 'message' => __( 'Une correction automatique est en cours : réessayez dans quelques minutes.', 'effi-ai-corrector' ) ) );
		}
		list( $types, $engine ) = $this->prepare_run();

		$raw = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint ci-dessous.
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) ), 0, self::BATCH_SIZE );
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Aucun contenu sélectionné.', 'effi-ai-corrector' ) ) );
		}
		$this->prime_posts( $ids );

		$results = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$results[] = array(
					'id'      => $id,
					'status'  => 'skipped',
					'message' => __( 'droits insuffisants', 'effi-ai-corrector' ),
				);
				continue;
			}
			$result    = $this->process_post( $id, $engine, $types, true, false );
			$results[] = array_intersect_key( $result, array_flip( array( 'id', 'status', 'message', 'title', 'edit_url' ) ) );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Active ou désactive la correction automatique.
	 */
	public function ajax_toggle_cron() {
		$this->verify_ajax();

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$this->save_cron_state( $this->get_cron_state( true ) );
			wp_send_json_success(
				array(
					'active'      => false,
					'message'     => __( 'Correction automatique désactivée.', 'effi-ai-corrector' ),
					'status_html' => wp_kses_post( $this->cron_status_html() ),
				)
			);
		}

		// Mêmes prérequis qu'un traitement manuel.
		$this->prepare_run();

		$first = new DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );
		$done  = wp_schedule_event( $first->getTimestamp(), 'daily', self::CRON_HOOK, array(), true );
		if ( is_wp_error( $done ) || ! $done ) {
			wp_send_json_error( array( 'message' => is_wp_error( $done ) ? $done->get_error_message() : __( 'Planification impossible.', 'effi-ai-corrector' ) ) );
		}

		$state          = $this->get_cron_state( true );
		$state['since'] = gmdate( 'Y-m-d H:i:s' );
		$this->save_cron_state( $state );

		wp_send_json_success(
			array(
				'active'      => true,
				'message'     => __( 'Correction automatique activée : seuls les contenus publiés ou modifiés à partir de maintenant seront traités.', 'effi-ai-corrector' ),
				'status_html' => wp_kses_post( $this->cron_status_html() ),
			)
		);
	}

	/**
	 * Charge les contenus d'un lot en une requête.
	 *
	 * @param int[] $ids ID.
	 */
	private function prime_posts( array $ids ) {
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}
	}

	/**
	 * Nombre de contenus publiés des types choisis (pour la barre de progression).
	 *
	 * @param string[] $types Types.
	 * @return int
	 */
	private function count_candidates( array $types ) {
		$total = 0;
		foreach ( $types as $type ) {
			$counts = wp_count_posts( $type );
			$total += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}
		return $total;
	}

	/*
	 * ---------------------------------------------------------------------
	 * CRON
	 * ---------------------------------------------------------------------
	 */

	/**
	 * État du traitement automatique.
	 *
	 * @param bool $reset Renvoyer un état vierge.
	 * @return array{since:string,run_start:string,queue:int[]}
	 */
	private function get_cron_state( $reset = false ) {
		$state = $reset ? array() : get_option( self::OPTION_CRON_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'since'     => isset( $state['since'] ) && is_string( $state['since'] ) ? $state['since'] : '',
			'run_start' => isset( $state['run_start'] ) && is_string( $state['run_start'] ) ? $state['run_start'] : '',
			'queue'     => isset( $state['queue'] ) ? array_values( array_filter( array_map( 'absint', (array) $state['queue'] ) ) ) : array(),
		);
	}

	/**
	 * @param array $state État.
	 */
	private function save_cron_state( array $state ) {
		update_option( self::OPTION_CRON_STATE, $state, false );
	}

	/**
	 * Correction automatique : contenus publiés ou modifiés depuis la dernière
	 * exécution, avec verrou, limite de durée (reprise deux minutes plus tard)
	 * et journal.
	 */
	public function run_cron() {
		$start = microtime( true );
		$entry = array(
			'time'           => time(),
			'status'         => 'ok',
			'checked'        => 0,
			'modified'       => array(),
			'modified_count' => 0,
			'errors'         => array(),
			'message'        => '',
		);

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			$entry['status']  = 'skipped';
			$entry['message'] = __( 'Une autre exécution était en cours (verrou actif).', 'effi-ai-corrector' );
			$this->add_log( $entry );
			return;
		}
		set_transient( self::LOCK_TRANSIENT, time(), 10 * MINUTE_IN_SECONDS );

		try {
			$entry = $this->run_cron_batch( $entry, $start );
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
			$this->add_log( $entry );
		}
	}

	/**
	 * Corps du traitement automatique.
	 *
	 * @param array $entry Entrée du journal.
	 * @param float $start Début (microtime).
	 * @return array Entrée complétée.
	 */
	private function run_cron_batch( array $entry, $start ) {
		$types = $this->get_selected_post_types();
		if ( ! $types ) {
			$entry['status']  = 'failed';
			$entry['message'] = __( 'Aucun type de contenu sélectionné : rien n\'a été traité.', 'effi-ai-corrector' );
			return $entry;
		}
		$rules = $this->get_rules();
		if ( $rules['errors'] ) {
			$entry['status']  = 'failed';
			$entry['message'] = __( 'Règles invalides : rien n\'a été traité.', 'effi-ai-corrector' ) . ' ' . implode( ' ', $rules['errors'] );
			return $entry;
		}

		$state = $this->get_cron_state();
		if ( '' === $state['since'] ) {
			// État absent (option supprimée…) : on repart de maintenant, sans rattrapage.
			$state['since'] = gmdate( 'Y-m-d H:i:s' );
			$this->save_cron_state( $state );
			$entry['message'] = __( 'Point de départ initialisé : les contenus publiés ou modifiés à partir de maintenant seront traités.', 'effi-ai-corrector' );
			return $entry;
		}

		if ( ! $state['queue'] ) {
			$state['run_start'] = gmdate( 'Y-m-d H:i:s' );
			$query              = $this->query_ids(
				$types,
				0,
				-1,
				array(
					'date_query' => array(
						'relation' => 'OR',
						array(
							'column'    => 'post_modified_gmt',
							'after'     => $state['since'],
							'inclusive' => true,
						),
						// Contenus planifiés publiés depuis : leur date de modification peut être antérieure.
						array(
							'column'    => 'post_date_gmt',
							'after'     => $state['since'],
							'inclusive' => true,
						),
					),
				)
			);
			if ( ! $query instanceof WP_Query ) {
				$entry['status']  = 'failed';
				$entry['message'] = __( 'Aucun type de contenu sélectionné : rien n\'a été traité.', 'effi-ai-corrector' );
				return $entry;
			}
			$state['queue'] = array_map( 'intval', $query->posts );
		}

		$engine = $this->make_engine( $rules['rules'] );
		/**
		 * Durée maximale (secondes) d'une exécution automatique avant reprise.
		 *
		 * @param int $seconds Durée.
		 */
		$limit = max( 5, (int) apply_filters( 'eac_cron_time_limit', 20 ) );

		while ( $state['queue'] && ( microtime( true ) - $start ) < $limit ) {
			$id     = array_shift( $state['queue'] );
			$result = $this->process_post( $id, $engine, $types, true, false );
			++$entry['checked'];
			if ( 'updated' === $result['status'] ) {
				++$entry['modified_count'];
				if ( count( $entry['modified'] ) < 100 ) {
					$entry['modified'][] = $id;
				}
			}
			if ( ( 'error' === $result['status'] || '' !== $result['message'] ) && 'skipped' !== $result['status'] && count( $entry['errors'] ) < 50 ) {
				$entry['errors'][] = array(
					'id'      => $id,
					'message' => $result['message'],
				);
			}
		}

		if ( $state['queue'] ) {
			$entry['status'] = 'partial';
			/* translators: %d: number of contents. */
			$entry['message'] = sprintf( __( '%d contenu(s) restant(s) : suite planifiée dans deux minutes.', 'effi-ai-corrector' ), count( $state['queue'] ) );
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON_HOOK );
		} else {
			$state['since']     = $state['run_start'] ? $state['run_start'] : gmdate( 'Y-m-d H:i:s' );
			$state['run_start'] = '';
			if ( $entry['errors'] ) {
				$entry['status'] = 'errors';
			}
		}
		$this->save_cron_state( $state );

		return $entry;
	}

	/**
	 * Ajoute une entrée au journal (10 dernières exécutions).
	 *
	 * @param array $entry Entrée.
	 */
	private function add_log( array $entry ) {
		$log = get_option( self::OPTION_CRON_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_option( self::OPTION_CRON_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}
}
