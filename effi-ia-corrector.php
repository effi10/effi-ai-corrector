<?php
/**
 * Plugin Name:       effi AI Corrector
 * Description:       Nettoie et corrige les anomalies de contenu générées par IA (artefacts de code, formatage Markdown, etc.).
 * Version:           1.0.0
 * Author:            Cédric GIRARD
 * Author URI:        https://www.effi10.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       effi-ai-corrector
 * Domain Path:       /languages
 */

// Sécurité : empêche l'accès direct au fichier
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe principale du plugin effi AI Corrector
 */
class Effi_AI_Corrector {

    private static $cron_hook = 'effi_ai_corrector_daily_cron';
    private static $option_name = 'eac_selected_post_types';

    /**
     * Constructeur
     */
    public function __construct() {
        // Ajout de la page au menu d'administration
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

        // Ajout des scripts et styles pour la page d'admin
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // Enregistrement des actions AJAX
        add_action( 'wp_ajax_eac_analyze_content', [ $this, 'ajax_analyze_content' ] );
        add_action( 'wp_ajax_eac_correct_content', [ $this, 'ajax_correct_content' ] );
        add_action( 'wp_ajax_eac_toggle_cron', [ $this, 'ajax_toggle_cron' ] );
        
        // Action pour la tâche CRON
        add_action( self::$cron_hook, [ $this, 'run_cron_correction' ] );
        
        // Nettoyage lors de la désactivation du plugin
        register_deactivation_hook( __FILE__, [ $this, 'plugin_deactivation' ] );
    }

    /**
     * Ajoute la page d'options au menu "Outils"
     */
    public function add_admin_menu() {
        add_management_page(
            'effi AI Corrector',
            'effi AI Corrector',
            'manage_options',
            'effi-ai-corrector',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Affiche le contenu de la page d'administration
     */
    public function render_admin_page() {
        // Sauvegarde des types de contenu sélectionnés si le formulaire est soumis
        if ( isset( $_POST['eac_save_settings_nonce'] ) && wp_verify_nonce( $_POST['eac_save_settings_nonce'], 'eac_save_settings' ) ) {
            $selected_post_types = isset( $_POST['eac_post_types'] ) ? array_map( 'sanitize_text_field', $_POST['eac_post_types'] ) : [];
            update_option( self::$option_name, $selected_post_types );
            echo '<div class="notice notice-success is-dismissible"><p><strong>Réglages sauvegardés.</strong></p></div>';
        }

        $saved_post_types = get_option( self::$option_name, [] );
        ?>
        <div class="wrap">
            <h1><?php _e( 'effi AI Corrector - Outil de Correction', 'effi-ai-corrector' ); ?></h1>
            <p><?php _e( "Cet outil vous aide à nettoyer les anomalies courantes trouvées dans les contenus générés par IA.", 'effi-ai-corrector' ); ?></p>

            <form method="POST" action="">
                <?php wp_nonce_field( 'eac_save_settings', 'eac_save_settings_nonce' ); ?>
                
                <h2 class="title"><?php _e( '1. Sélectionner les types de contenu à traiter', 'effi-ai-corrector' ); ?></h2>
                <p><?php _e( 'Cochez les types de contenu sur lesquels les actions doivent s\'appliquer.', 'effi-ai-corrector' ); ?></p>
                
                <?php
                $post_types = get_post_types( [ 'public' => true ], 'objects' );
                foreach ( $post_types as $post_type ) {
                    echo '<label style="padding-right: 20px;">';
                    echo '<input type="checkbox" name="eac_post_types[]" value="' . esc_attr( $post_type->name ) . '" ' . checked( in_array( $post_type->name, $saved_post_types ), true, false ) . '>';
                    echo esc_html( $post_type->labels->name );
                    echo '</label>';
                }
                ?>
                <p><button type="submit" class="button button-secondary"><?php _e( 'Sauvegarder la sélection', 'effi-ai-corrector' ); ?></button></p>
            </form>
            
            <hr>

            <h2 class="title"><?php _e( '2. Actions Manuelles', 'effi-ai-corrector' ); ?></h2>
            <div id="eac-manual-actions">
                <button id="eac-analyze-btn" class="button button-secondary">
                    <span class="dashicons dashicons-search" style="vertical-align: middle;"></span> <?php _e( 'Analyser les anomalies', 'effi-ai-corrector' ); ?>
                </button>
                <button id="eac-correct-btn" class="button button-primary">
                    <span class="dashicons dashicons-admin-tools" style="vertical-align: middle;"></span> <?php _e( 'Lancer la correction maintenant', 'effi-ai-corrector' ); ?>
                </button>
                <span class="spinner"></span>
                <p class="description">
                    <strong><?php _e( 'Analyse :', 'effi-ai-corrector' ); ?></strong> <?php _e( 'Compte les publications concernées (sans rien modifier).', 'effi-ai-corrector' ); ?><br>
                    <strong><?php _e( 'Correction :', 'effi-ai-corrector' ); ?></strong> <?php _e( 'Applique les corrections. <strong>⚠️ Action irréversible. Sauvegardez votre base de données avant.</strong>', 'effi-ai-corrector' ); ?>
                </p>
            </div>
            <div id="eac-results" style="display:none; margin-top:15px;" class="notice notice-info inline"></div>
            
            <hr>

            <h2 class="title"><?php _e( '3. Action Automatisée (Tâche CRON)', 'effi-ai-corrector' ); ?></h2>
            <div id="eac-cron-action">
                <?php
                $cron_active = wp_next_scheduled( self::$cron_hook );
                $cron_button_text = $cron_active ? __( 'Désactiver la correction automatique', 'effi-ai-corrector' ) : __( 'Planifier la correction automatique', 'effi-ai-corrector' );
                $cron_status_text = $cron_active ? sprintf( __( 'La tâche est <strong>active</strong> et s\'exécutera quotidiennement. Prochaine exécution : %s.', 'effi-ai-corrector' ), get_date_from_gmt( date( 'Y-m-d H:i:s', $cron_active ), 'd/m/Y à H:i' ) ) : __( 'La tâche automatique est <strong>inactive</strong>.', 'effi-ai-corrector' );
                ?>
                <button id="eac-cron-btn" class="button button-secondary"><?php echo esc_html( $cron_button_text ); ?></button>
                 <span class="spinner"></span>
                <p id="eac-cron-status" class="description"><?php echo $cron_status_text; ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Charge le script JS pour la page d'administration
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'tools_page_effi-ai-corrector' !== $hook ) {
            return;
        }
        // Le script est écrit directement dans le HTML pour la simplicité de ce plugin
        add_action( 'admin_footer', [ $this, 'render_admin_js' ] );
    }

    /**
     * Contient le JavaScript pour gérer les actions AJAX
     */
    public function render_admin_js() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {

                function showSpinner(container) {
                    container.find('.spinner').addClass('is-active');
                    container.find('button').prop('disabled', true);
                }

                function hideSpinner(container) {
                    container.find('.spinner').removeClass('is-active');
                    container.find('button').prop('disabled', false);
                }

                function showResult(message, type = 'info') {
                    $('#eac-results').removeClass('notice-info notice-success notice-error').addClass('notice-' + type).html('<p>' + message + '</p>').show();
                }

                // Action pour le bouton "Analyser"
                $('#eac-analyze-btn').on('click', function() {
                    const container = $('#eac-manual-actions');
                    showSpinner(container);
                    
                    $.post(ajaxurl, {
                        action: 'eac_analyze_content',
                        nonce: '<?php echo wp_create_nonce( "eac_ajax_nonce" ); ?>'
                    }, function(response) {
                        hideSpinner(container);
                        if (response.success) {
                            showResult(response.data.message, 'info');
                        } else {
                            showResult(response.data.message, 'error');
                        }
                    });
                });

                // Action pour le bouton "Corriger"
                $('#eac-correct-btn').on('click', function() {
                    if (!confirm("⚠️ Êtes-vous sûr de vouloir lancer la correction ?\n\nCette action est irréversible et modifiera votre base de données. Assurez-vous d'avoir fait une sauvegarde.")) {
                        return;
                    }
                    const container = $('#eac-manual-actions');
                    showSpinner(container);

                    $.post(ajaxurl, {
                        action: 'eac_correct_content',
                        nonce: '<?php echo wp_create_nonce( "eac_ajax_nonce" ); ?>'
                    }, function(response) {
                        hideSpinner(container);
                        if (response.success) {
                            showResult(response.data.message, 'success');
                        } else {
                            showResult(response.data.message, 'error');
                        }
                    });
                });
                
                // Action pour le bouton CRON
                $('#eac-cron-btn').on('click', function() {
                    const container = $('#eac-cron-action');
                    showSpinner(container);
                    
                    $.post(ajaxurl, {
                        action: 'eac_toggle_cron',
                        nonce: '<?php echo wp_create_nonce( "eac_ajax_nonce" ); ?>'
                    }, function(response) {
                        hideSpinner(container);
                        if (response.success) {
                            $('#eac-cron-btn').text(response.data.button_text);
                            $('#eac-cron-status').html(response.data.status_text);
                             showResult(response.data.message, 'success');
                        } else {
                            showResult(response.data.message, 'error');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * Logique de base pour trouver les posts
     */
    private function get_posts_to_process($return_fields = 'all') {
        $post_types = get_option(self::$option_name, []);
        if (empty($post_types)) {
            return [];
        }

        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Traiter tous les posts
            'fields'         => ($return_fields === 'ids') ? 'ids' : '',
        ];

        return new WP_Query($args);
    }
    
    /**
     * Fonction principale de correction
     */
    private function perform_correction($content) {
        $corrections = 0;
        $original_content = $content;

        // Règle 1: Suppression de <p>```</p>
        $content = str_replace('<p>```</p>', '', $content);

        // Règle 2: Suppression de <p>```html</p>
        $content = str_replace('<p>```html</p>', '', $content);
        
        // Règle 3: Correction de **mot** en <strong>mot</strong>
        $pattern = '/\*\*(?!\s)(.*?)(?!\s)\*\*/u';
        $content = preg_replace($pattern, '<strong>$1</strong>', $content);
        
		// Règle 3: Correction de *mot* en <em>mot</em>
        $pattern = '/\*(?!\s)(.*?)(?!\s)\*/u';
        $content = preg_replace($pattern, '<em>$1</em>', $content);
		
        if($original_content !== $content) {
            $corrections = 1; // Au moins une correction a été faite
        }
        
        return ['content' => $content, 'corrected' => $corrections];
    }
    
    /**
     * Gère la requête AJAX pour l'analyse
     */
    public function ajax_analyze_content() {
        check_ajax_referer( 'eac_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission non accordée.' ] );
        }
        
        $query = $this->get_posts_to_process();
        $affected_posts = 0;

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_content = get_the_content();
                $result = $this->perform_correction($post_content);
                if ($result['corrected']) {
                    $affected_posts++;
                }
            }
            wp_reset_postdata();
        } else {
             wp_send_json_error( [ 'message' => 'Aucun type de contenu n\'a été sélectionné ou sauvegardé.' ] );
        }

        wp_send_json_success( [ 'message' => sprintf( 'Analyse terminée : <strong>%d publication(s)</strong> sont concernées par au moins une correction.', $affected_posts ) ] );
    }

    /**
     * Gère la requête AJAX pour la correction
     */
    public function ajax_correct_content() {
        check_ajax_referer( 'eac_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission non accordée.' ] );
        }
        
        $query = $this->get_posts_to_process();
        $corrected_count = 0;
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_content = get_the_content();
                
                $result = $this->perform_correction($post_content);
                
                if ($result['corrected']) {
                    // Supprimer les hooks qui pourraient interférer pour éviter les boucles infinies
                    remove_action( 'save_post', 'wp_save_post_revision' );
                    
                    wp_update_post( [
                        'ID'           => $post_id,
                        'post_content' => $result['content'],
                    ] );
                    
                    // Rétablir le hook
                    add_action( 'save_post', 'wp_save_post_revision' );
                    
                    $corrected_count++;
                }
            }
            wp_reset_postdata();
        } else {
             wp_send_json_error( [ 'message' => 'Aucun type de contenu n\'a été sélectionné ou sauvegardé.' ] );
        }

        wp_send_json_success( [ 'message' => sprintf( 'Correction terminée. <strong>%d publication(s)</strong> ont été mises à jour.', $corrected_count ) ] );
    }
    
    /**
     * Gère la requête AJAX pour la tâche CRON
     */
    public function ajax_toggle_cron() {
        check_ajax_referer('eac_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission non accordée.']);
        }
        
        if (wp_next_scheduled(self::$cron_hook)) {
            // Désactiver le CRON
            wp_clear_scheduled_hook(self::$cron_hook);
            $message = 'Tâche automatique désactivée avec succès.';
            $button_text = 'Planifier la correction automatique';
            $status_text = 'La tâche automatique est <strong>inactive</strong>.';
        } else {
            // Activer le CRON
            wp_schedule_event(strtotime('today 18:00'), 'daily', self::$cron_hook);
            $next_run = get_date_from_gmt(date('Y-m-d H:i:s', wp_next_scheduled(self::$cron_hook)), 'd/m/Y à H:i');
            $message = 'Tâche automatique planifiée avec succès.';
            $button_text = 'Désactiver la correction automatique';
            $status_text = sprintf('La tâche est <strong>active</strong> et s\'exécutera quotidiennement. Prochaine exécution : %s.', $next_run);
        }
        
        wp_send_json_success([
            'message'     => $message,
            'button_text' => $button_text,
            'status_text' => $status_text,
        ]);
    }
    
    /**
     * Exécute la correction via la tâche CRON
     */
    public function run_cron_correction() {
        $query = $this->get_posts_to_process();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_content = get_the_content();
                
                $result = $this->perform_correction($post_content);
                
                if ($result['corrected']) {
                    remove_action( 'save_post', 'wp_save_post_revision' );
                    wp_update_post( [
                        'ID'           => $post_id,
                        'post_content' => $result['content'],
                    ] );
                    add_action( 'save_post', 'wp_save_post_revision' );
                }
            }
            wp_reset_postdata();
        }
    }
    
    /**
     * Action lors de la désactivation du plugin
     */
    public function plugin_deactivation() {
        // Supprime la tâche CRON pour éviter les erreurs
        wp_clear_scheduled_hook( self::$cron_hook );
    }
}

// Initialisation du plugin
new Effi_AI_Corrector();