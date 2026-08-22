<?php
/**
 * Configuración del plugin: de dónde reportar y con qué token, y el estado
 * (efímero) del modo diagnóstico profundo.
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Config {

	const OPCION_MODO_PROFUNDO_HASTA = 'gopress_agente_modo_profundo_hasta';

	/**
	 * URL COMPLETA del endpoint de reporte de este sitio en GoPress (ej.
	 * http://host.docker.internal:8090/sites/mi-sitio/agente/reportar).
	 * GoPress ya conoce el nombre del sitio al aprovisionar, así que inyecta
	 * la URL final armada como constante en wp-config.php (ver
	 * PLAN_PLUGIN_SENSOR.md sección 4.4) — el plugin no necesita saber su
	 * propio nombre de sitio ni construir nada.
	 */
	public static function url_reportar() {
		if ( ! defined( 'GOPRESS_AGENTE_URL' ) || '' === GOPRESS_AGENTE_URL ) {
			return '';
		}
		return GOPRESS_AGENTE_URL;
	}

	public static function token() {
		if ( ! defined( 'GOPRESS_AGENTE_TOKEN' ) ) {
			return '';
		}
		return GOPRESS_AGENTE_TOKEN;
	}

	/**
	 * Umbral (ms) a partir del cual una query se considera "lenta" y se
	 * guarda en el buffer. Configurable por constante para no necesitar un
	 * panel de administración en el MVP (ver PLAN_PLUGIN_SENSOR.md sección 5).
	 */
	public static function umbral_query_lenta_ms() {
		if ( defined( 'GOPRESS_AGENTE_UMBRAL_QUERY_MS' ) ) {
			return (int) GOPRESS_AGENTE_UMBRAL_QUERY_MS;
		}
		return 200;
	}

	/**
	 * ¿Está activo el modo diagnóstico profundo ahora mismo? Compara contra
	 * un timestamp guardado en wp_options (autoload=no), nunca contra un
	 * interruptor manual que alguien pueda olvidar prendido — ver
	 * PLAN_PLUGIN_SENSOR.md sección 9. Vuelve a "no" solo con que pase el
	 * tiempo, sin que nadie tenga que desactivarlo explícitamente.
	 */
	public static function modo_profundo_activo() {
		$hasta = get_option( self::OPCION_MODO_PROFUNDO_HASTA, '' );
		if ( '' === $hasta ) {
			return false;
		}
		$timestamp_hasta = strtotime( $hasta );
		if ( false === $timestamp_hasta ) {
			return false;
		}
		return time() < $timestamp_hasta;
	}

	/**
	 * Guarda el timestamp de expiración del modo profundo, tal como llega en
	 * el campo "modo_profundo_hasta" de la respuesta del POST de reporte.
	 * autoload=no porque este valor solo se lee en el momento de ejecutar
	 * una query lenta, no en cada carga de página.
	 */
	public static function guardar_modo_profundo_hasta( $iso8601 ) {
		update_option( self::OPCION_MODO_PROFUNDO_HASTA, $iso8601, false );
	}
}
