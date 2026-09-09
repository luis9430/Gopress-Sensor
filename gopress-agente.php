<?php
/**
 * Plugin Name: GoPress Agente
 * Description: Sensor de telemetría técnica para sitios administrados por GoPress. Observa y reporta memoria, tiempo de ejecución, queries lentas y errores PHP — nunca decide ni diagnostica (eso vive en GoPress). Sin SEO, sin analytics, sin llamadas a terceros.
 * Version: 0.1.0
 * Author: GoPress
 * License: GPL-2.0-or-later
 *
 * Ver PLAN_PLUGIN_SENSOR.md (repo GoPress) para el diseño completo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GOPRESS_AGENTE_VERSION', '0.1.0' );
define( 'GOPRESS_AGENTE_DIR', plugin_dir_path( __FILE__ ) );

// GOPRESS_AGENTE_ACTIVO existe para poder apagar el medidor sin desactivar
// el plugin entero (ej. si alguna vez hace falta diagnosticar si el propio
// sensor es la causa de un problema) — por defecto, activo.
if ( ! defined( 'GOPRESS_AGENTE_ACTIVO' ) ) {
	define( 'GOPRESS_AGENTE_ACTIVO', true );
}

require_once GOPRESS_AGENTE_DIR . 'includes/class-config.php';
require_once GOPRESS_AGENTE_DIR . 'includes/class-buffer.php';
require_once GOPRESS_AGENTE_DIR . 'includes/class-medidor.php';
require_once GOPRESS_AGENTE_DIR . 'includes/class-investigacion-hooks.php';
require_once GOPRESS_AGENTE_DIR . 'includes/class-reportero.php';
require_once GOPRESS_AGENTE_DIR . 'includes/class-hooks-negocio.php';

/**
 * Activación: crea la tabla del buffer, copia el drop-in de queries lentas a
 * wp-content/db.php, y programa el cron.
 *
 * Solo puede existir un db.php a la vez y WordPress no detecta el conflicto
 * si dos orígenes lo disputan (ver PLAN_PLUGIN_SENSOR.md sección 2) — antes
 * de copiar se distingue: (a) no existe ninguno → se copia sin más; (b) ya
 * existe y es NUESTRO (tiene la marca del drop-in, de una instalación previa
 * o una reactivación) → se sobreescribe igual, por si esta versión trae
 * cambios; (c) existe y es de otro origen (ej. HyperDB, un object cache que
 * también reemplaza $wpdb) → NO se toca, y las queries lentas simplemente no
 * se van a medir — se deja constancia en el log de PHP para que quien
 * administre el servidor lo note, en vez de fallar en silencio o romper el
 * drop-in ajeno.
 */
function gopress_agente_activar() {
	GoPress_Agente_Buffer::crear_tabla();

	$destino_dropin = WP_CONTENT_DIR . '/db.php';
	$fuente_dropin  = GOPRESS_AGENTE_DIR . 'includes/class-db-dropin.php';

	$es_ajeno = file_exists( $destino_dropin )
		&& false === strpos( (string) file_get_contents( $destino_dropin ), 'GOPRESS_AGENTE_DB_DROPIN_MARCA' );

	if ( $es_ajeno ) {
		error_log( 'GoPress Agente: wp-content/db.php ya existe y no es de este plugin — no se instaló el sensor de queries lentas para no pisar el drop-in existente.' );
	} else {
		copy( $fuente_dropin, $destino_dropin );
	}

	if ( ! wp_next_scheduled( GoPress_Agente_Reportero::HOOK_CRON ) ) {
		wp_schedule_event( time(), 'gopress_agente_cada_5_min', GoPress_Agente_Reportero::HOOK_CRON );
	}
}
register_activation_hook( __FILE__, 'gopress_agente_activar' );

/**
 * Desactivación: limpia el cron programado. Deliberadamente NO borra la
 * tabla del buffer (por si se reactiva) ni el drop-in db.php — db.php es
 * standalone (WordPress lo carga directo, no depende de que el plugin esté
 * activo), así que seguir midiendo tiempo por query es inofensivo aunque el
 * resto del plugin esté apagado: sin el medidor ni el reportero corriendo,
 * esas mediciones simplemente no se leen ni se envían a ningún lado.
 */
function gopress_agente_desactivar() {
	$timestamp = wp_next_scheduled( GoPress_Agente_Reportero::HOOK_CRON );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, GoPress_Agente_Reportero::HOOK_CRON );
	}
}
register_deactivation_hook( __FILE__, 'gopress_agente_desactivar' );

/**
 * Intervalo de cron propio de 5 minutos — WordPress no trae uno nativo entre
 * "hourly" y "twicedaily" tan corto.
 */
add_filter(
	'cron_schedules',
	function ( $horarios ) {
		$horarios['gopress_agente_cada_5_min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 5 minutos (GoPress Agente)', 'gopress-agente' ),
		);
		return $horarios;
	}
);

GoPress_Agente_Medidor::iniciar();
GoPress_Agente_Investigacion_Hooks::iniciar();
GoPress_Agente_Reportero::iniciar();
GoPress_Agente_Hooks_Negocio::iniciar();
