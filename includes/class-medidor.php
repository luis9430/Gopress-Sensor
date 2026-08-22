<?php
/**
 * El sensor en sí: memoria pico, tiempo de ejecución, y errores/warnings de
 * PHP por request. Las queries lentas NO se miden acá — viven en el drop-in
 * db.php (ver PLAN_PLUGIN_SENSOR.md sección 2: se descartó SAVEQUERIES a
 * propósito por su overhead de debug_backtrace() en cada query) y llegan a
 * este medidor solo para juntarse con el resto antes de ir al buffer.
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Medidor {

	/** @var callable|null Handler de errores que ya estaba puesto antes que el nuestro. */
	private static $handler_previo = null;

	public static function iniciar() {
		// El handler previo se guarda para encadenarlo siempre: nunca hay
		// que asumir que WordPress core (o ningún otro plugin) no puso ya
		// un set_error_handler propio (ver PLAN_PLUGIN_SENSOR.md sección 2).
		self::$handler_previo = set_error_handler( array( __CLASS__, 'al_capturar_error' ) );

		register_shutdown_function( array( __CLASS__, 'al_terminar_request' ) );
	}

	/**
	 * Callback de set_error_handler. SIEMPRE devuelve false (nunca suprime
	 * el comportamiento normal de logging de PHP) e invoca el handler
	 * previo si había uno — nunca "reemplaza" el error handling existente,
	 * solo se engancha además de él.
	 */
	public static function al_capturar_error( $nivel, $mensaje, $archivo = '', $linea = 0 ) {
		// error_reporting() en 0 significa que el operador "@" silenció esta
		// línea específica — se respeta esa intención, igual que hace PHP
		// nativamente, en vez de reportarlo de todas formas.
		if ( 0 === error_reporting() ) {
			return false;
		}

		self::acumular_error( self::nombre_nivel( $nivel ), $mensaje, $archivo, $linea );

		if ( null !== self::$handler_previo ) {
			call_user_func( self::$handler_previo, $nivel, $mensaje, $archivo, $linea );
		}

		return false;
	}

	private static function nombre_nivel( $nivel ) {
		$mapa = array(
			E_ERROR             => 'ERROR',
			E_WARNING           => 'WARNING',
			E_NOTICE            => 'NOTICE',
			E_DEPRECATED        => 'DEPRECATED',
			E_USER_ERROR        => 'ERROR',
			E_USER_WARNING      => 'WARNING',
			E_USER_NOTICE       => 'NOTICE',
			E_USER_DEPRECATED   => 'DEPRECATED',
			E_STRICT            => 'STRICT',
		);
		return isset( $mapa[ $nivel ] ) ? $mapa[ $nivel ] : 'DESCONOCIDO';
	}

	private static function acumular_error( $nivel, $mensaje, $archivo, $linea ) {
		if ( ! isset( $GLOBALS['gopress_agente_errores'] ) ) {
			$GLOBALS['gopress_agente_errores'] = array();
		}
		// Los primeros N caracteres alcanzan para identificar el problema
		// sin inflar el reporte con mensajes larguísimos (ej. stack traces
		// que algunos plugins meten en el mensaje de error).
		$GLOBALS['gopress_agente_errores'][] = array(
			'nivel'   => $nivel,
			'mensaje' => mb_substr( (string) $mensaje, 0, 500 ),
			'archivo' => (string) $archivo,
			'linea'   => (int) $linea,
		);
	}

	/**
	 * register_shutdown_function: corre al final de cada request, después
	 * de que la respuesta ya se mandó al visitante — no afecta el tiempo de
	 * respuesta percibido. Deliberadamente mínimo y defensivo (ver
	 * PLAN_PLUGIN_SENSOR.md sección 2): si el shutdown fue disparado por un
	 * fatal de memoria agotada, cualquier trabajo extra acá puede fallar por
	 * falta de memoria, así que no se hace nada costoso.
	 */
	public static function al_terminar_request() {
		if ( ! defined( 'GOPRESS_AGENTE_ACTIVO' ) || ! GOPRESS_AGENTE_ACTIVO ) {
			return;
		}

		$memoria_pico_bytes = memory_get_peak_usage( true );

		$tiempo_ejecucion_ms = 0;
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$tiempo_ejecucion_ms = (int) round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 );
		}

		$queries_lentas = isset( $GLOBALS['gopress_agente_queries_lentas'] ) ? $GLOBALS['gopress_agente_queries_lentas'] : array();
		$errores        = isset( $GLOBALS['gopress_agente_errores'] ) ? $GLOBALS['gopress_agente_errores'] : array();

		// set_error_handler() nunca captura fatales (E_ERROR, E_PARSE,
		// E_COMPILE_ERROR) — esos terminan el script sin pasar por el
		// handler de usuario. error_get_last() es la única forma de verlos,
		// y es intencionalmente lo último que se consulta acá: si el fatal
		// fue por memoria agotada, cualquier trabajo antes de esto ya pudo
		// fallar, así que esta lectura debe quedar lo más simple posible.
		$ultimo_error = error_get_last();
		if ( null !== $ultimo_error && in_array( $ultimo_error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR ), true ) ) {
			$errores[] = array(
				'nivel'   => self::nombre_nivel( $ultimo_error['type'] ),
				'mensaje' => mb_substr( (string) $ultimo_error['message'], 0, 500 ),
				'archivo' => (string) $ultimo_error['file'],
				'linea'   => (int) $ultimo_error['line'],
			);
		}

		GoPress_Agente_Buffer::registrar(
			$memoria_pico_bytes,
			$tiempo_ejecucion_ms,
			wp_json_encode( $queries_lentas ),
			wp_json_encode( $errores )
		);
	}
}
