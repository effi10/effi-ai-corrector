<?php
/**
 * Désinstallation : supprime les options eac_*, le verrou et les tâches
 * planifiées du plugin, sur chaque site en multisite.
 *
 * Les contenus corrigés et leurs révisions ne sont pas touchés.
 *
 * @package effi-ai-corrector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Définit seulement la classe (aucun hook n'est enregistré).
require_once __DIR__ . '/includes/class-effi-ai-corrector.php';

$eac_cleanup = static function () {
	foreach ( Effi_AI_Corrector::OPTIONS as $eac_option ) {
		delete_option( $eac_option );
	}
	Effi_AI_Corrector::clear_site_schedule(); // Tâches 1.1.0 et 1.0.x, verrou.
};

if ( is_multisite() ) {
	$eac_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $eac_site_ids as $eac_site_id ) {
		switch_to_blog( (int) $eac_site_id );
		$eac_cleanup();
		restore_current_blog();
	}
} else {
	$eac_cleanup();
}
