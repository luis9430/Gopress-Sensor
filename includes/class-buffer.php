<?php
/**
 * Tabla propia del plugin: una fila por request medido, hasta que el
 * reportero la agrega y la vacía tras un POST exitoso a GoPress. Ver
 * PLAN_PLUGIN_SENSOR.md sección 3 — el buffer existe para no perder datos
 * entre reportes, no para acumular historial permanente (eso vive en
 * GoPress, en eventos_sitio).
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Buffer {

	public static function nombre_tabla() {
		global $wpdb;
		return $wpdb->prefix . 'gopress_agente_buffer';
	}

	/**
	 * Crea la tabla del buffer si no existe. Se llama en la activación del
	 * plugin (no en cada request) — dbDelta es idempotente, así que también
	 * es seguro llamarla de nuevo si el plugin se reactiva.
	 */
	public static function crear_tabla() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabla           = self::nombre_tabla();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabla} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			memoria_pico_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tiempo_ejecucion_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queries_lentas TEXT NULL,
			errores TEXT NULL,
			PRIMARY KEY (id),
			KEY creado_en (creado_en)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Inserta una fila con lo medido en un request. queries_lentas y errores
	 * llegan ya como JSON (arrays serializados por el medidor) — el buffer
	 * no interpreta su contenido, solo lo guarda y lo devuelve tal cual al
	 * reportero.
	 */
	public static function registrar( $memoria_pico_bytes, $tiempo_ejecucion_ms, $queries_lentas_json, $errores_json ) {
		global $wpdb;
		$wpdb->insert(
			self::nombre_tabla(),
			array(
				'memoria_pico_bytes'  => $memoria_pico_bytes,
				'tiempo_ejecucion_ms' => $tiempo_ejecucion_ms,
				'queries_lentas'      => $queries_lentas_json,
				'errores'             => $errores_json,
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Devuelve todas las filas del buffer, más viejas primero — el
	 * reportero las agrega y, si el POST tiene éxito, borra por id (ver
	 * borrar_hasta) para no reenviar datos ya confirmados.
	 */
	public static function obtener_todas() {
		global $wpdb;
		$tabla = self::nombre_tabla();
		return $wpdb->get_results( "SELECT * FROM {$tabla} ORDER BY id ASC" );
	}

	/**
	 * Borra solo las filas hasta el id dado (inclusive), no toda la tabla —
	 * si entre "leer" y "confirmar" llegó una fila nueva (un request que
	 * terminó mientras el reportero armaba el POST), esa fila no se pierde:
	 * queda para el próximo tick.
	 */
	public static function borrar_hasta( $id_maximo ) {
		global $wpdb;
		$tabla = self::nombre_tabla();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tabla} WHERE id <= %d", $id_maximo ) );
	}
}
