<?php
/**
 * Plugin Name:       effi AI Corrector
 * Plugin URI:        https://github.com/effi10/effi-ai-corrector
 * Description:       Corrige en masse les restes de formatage laissés par les IA (Markdown, balises de code isolées, espaces autour des titres) dans les contenus publiés, avec analyse, aperçu avant / après et correction par lots.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cédric Girard
 * Author URI:        https://www.effi10.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       effi-ai-corrector
 * Update URI:        https://github.com/effi10/effi-ai-corrector
 *
 * Le nom de ce fichier (effi-ia-corrector.php) est conservé tel quel : le
 * renommer désactiverait le plugin sur les sites où il est installé.
 *
 * @package effi-ai-corrector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Effi_AI_Corrector', false ) ) {
	return; // Une autre copie du plugin est déjà chargée.
}

define( 'EAC_VERSION', '1.1.0' );
define( 'EAC_FILE', __FILE__ );
define( 'EAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'EAC_URL', plugin_dir_url( __FILE__ ) );

require_once EAC_DIR . 'includes/class-effi-ai-corrector-engine.php';
require_once EAC_DIR . 'includes/class-effi-ai-corrector.php';

register_deactivation_hook( __FILE__, array( 'Effi_AI_Corrector', 'deactivate' ) );

Effi_AI_Corrector::instance();
