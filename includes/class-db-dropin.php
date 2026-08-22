<?php
/**
 * Fuente del drop-in wp-content/db.php. WordPress carga automáticamente ese
 * archivo si existe, ANTES de que cargue cualquier plugin — por eso este
 * archivo se copia (no se hace un symlink ni un require) a wp-content/db.php
 * en la activación del plugin (ver GoPress_Agente_Activacion).
 *
 * Decisión (ver PLAN_PLUGIN_SENSOR.md sección 2): esto reemplaza a
 * SAVEQUERIES a propósito. SAVEQUERIES instrumenta TODAS las queries del
 * request y ejecuta debug_backtrace() en cada una dentro de log_query() —
 * el costo real no es medir el tiempo, es ese backtrace. Esta subclase mide
 * con microtime() y descarta en el momento cualquier query que no supere el
 * umbral configurado — nunca acumula un array completo, nunca hace
 * backtrace salvo que el modo diagnóstico profundo esté activo (ver sección 9).
 *
 * @package GoPress_Agente
 *
 * GOPRESS_AGENTE_DB_DROPIN_MARCA — no borrar este comentario: gopress_agente_activar()
 * lo busca en el db.php existente para distinguir "ya es nuestro, de una
 * instalación previa" de "es de otro origen, no pisar" (ver
 * PLAN_PLUGIN_SENSOR.md sección 2 — solo puede existir un db.php a la vez,
 * WordPress no detecta el conflicto si dos plugins lo disputan).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/wp-db.php';

class GoPress_Agente_WPDB extends wpdb {

	public function query( $query ) {
		$inicio    = microtime( true );
		$resultado = parent::query( $query );
		$duracion_ms = (int) round( ( microtime( true ) - $inicio ) * 1000 );

		$umbral_ms = class_exists( 'GoPress_Agente_Config' ) ? GoPress_Agente_Config::umbral_query_lenta_ms() : 200;
		if ( $duracion_ms >= $umbral_ms ) {
			$this->gopress_registrar_query_lenta( $query, $duracion_ms );
		}

		return $resultado;
	}

	private function gopress_registrar_query_lenta( $query, $duracion_ms ) {
		if ( ! isset( $GLOBALS['gopress_agente_queries_lentas'] ) ) {
			$GLOBALS['gopress_agente_queries_lentas'] = array();
		}

		// 300 caracteres alcanzan para ver tabla, columnas del WHERE y
		// meta_key sin necesitar backtrace (ver PLAN_PLUGIN_SENSOR.md
		// sección 9) — suficiente para saber "hacia dónde mirar" en la
		// mayoría de los casos.
		$muestra = array(
			'duracion_ms' => $duracion_ms,
			'sql'         => mb_substr( (string) $query, 0, 300 ),
		);

		// Modo diagnóstico profundo (sección 9): solo cuando GoPress lo
		// activó explícitamente para este sitio, y solo sobre las queries
		// que YA cruzaron el umbral — nunca sobre las rápidas, y nunca por
		// defecto. limit=8 frames: alcanza para ver qué plugin/tema llamó,
		// no hace falta el stack completo.
		if ( class_exists( 'GoPress_Agente_Config' ) && GoPress_Agente_Config::modo_profundo_activo() ) {
			$muestra['origen_probable'] = $this->gopress_origen_desde_backtrace();
		}

		$GLOBALS['gopress_agente_queries_lentas'][] = $muestra;
	}

	/**
	 * Deduce un nombre de plugin/tema legible a partir de la ruta de
	 * archivo del primer frame del backtrace que no pertenece a WordPress
	 * core ni al propio drop-in — no pretende certeza absoluta, es la mejor
	 * pista disponible sin cargar todo el stack.
	 */
	private function gopress_origen_desde_backtrace() {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 );
		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			if ( false !== strpos( $frame['file'], '/wp-content/plugins/' ) ) {
				preg_match( '#/wp-content/plugins/([^/]+)/#', $frame['file'], $m );
				return isset( $m[1] ) ? 'plugin:' . $m[1] : $frame['file'];
			}
			if ( false !== strpos( $frame['file'], '/wp-content/themes/' ) ) {
				preg_match( '#/wp-content/themes/([^/]+)/#', $frame['file'], $m );
				return isset( $m[1] ) ? 'tema:' . $m[1] : $frame['file'];
			}
		}
		return 'desconocido';
	}
}

global $wpdb;
$wpdb = new GoPress_Agente_WPDB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
