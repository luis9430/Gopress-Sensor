<?php
/**
 * WP-Cron: agrega el buffer y hace el POST periódico a GoPress. Ver
 * PLAN_PLUGIN_SENSOR.md sección 3 para el contrato completo del endpoint.
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Reportero {

	const HOOK_CRON = 'gopress_agente_reportar';

	/**
	 * Tope de detalle reportado por período — ver PLAN_PLUGIN_SENSOR.md.
	 * No es "sin límite": un plugin roto disparando el mismo error en cada
	 * request bajo tráfico moderado podría generar miles de líneas en 5
	 * minutos, justo el escenario donde más importa que el propio mecanismo
	 * de observación no se vuelva el problema (payload de varios MB). 200 ya
	 * cubre con margen el caso real de diagnóstico (antes eran solo 5), y
	 * los errores además se deduplican (ver agrupar_errores) antes de
	 * aplicar este tope, así que en la práctica cuesta llegar a 200 salvo
	 * con decenas de errores realmente distintos en el mismo período.
	 */
	const TOPE_DETALLE_POR_PERIODO = 200;

	public static function iniciar() {
		add_action( self::HOOK_CRON, array( __CLASS__, 'reportar' ) );
	}

	/**
	 * Corrida del evento de cron: lee el buffer completo, arma el resumen,
	 * lo manda a GoPress, y solo borra el buffer si el POST tuvo éxito. Si
	 * el buffer está vacío no manda nada — no tiene sentido un POST con
	 * requests_medidos=0 cada 5 minutos en un sitio sin tráfico.
	 *
	 * El reporte de hooks investigados (ver class-investigacion-hooks.php)
	 * es un mecanismo INDEPENDIENTE del buffer de telemetría — corre
	 * siempre en el mismo tick, antes del "return" de buffer vacío: un
	 * sitio con poco tráfico técnico (buffer vacío) puede perfectamente
	 * tener hooks de negocio capturados durante una sesión de
	 * investigación activa, y viceversa.
	 */
	public static function reportar() {
		GoPress_Agente_Investigacion_Hooks::reportar_si_corresponde();

		$filas = GoPress_Agente_Buffer::obtener_todas();
		if ( empty( $filas ) ) {
			return;
		}

		$url   = GoPress_Agente_Config::url_reportar();
		$token = GoPress_Agente_Config::token();
		if ( '' === $url || '' === $token ) {
			return; // plugin instalado pero todavía no configurado — no hay a quién reportarle.
		}

		$resumen   = self::agregar( $filas );
		$respuesta = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-GoPress-Token'  => $token,
				),
				'body'    => wp_json_encode( $resumen ),
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			return; // sin conectividad; el buffer queda intacto para el próximo tick.
		}

		$codigo = wp_remote_retrieve_response_code( $respuesta );
		if ( 204 === $codigo ) {
			GoPress_Agente_Buffer::borrar_hasta( end( $filas )->id );
			return;
		}

		if ( 200 === $codigo ) {
			self::procesar_instrucciones( wp_remote_retrieve_body( $respuesta ) );
			GoPress_Agente_Buffer::borrar_hasta( end( $filas )->id );
			return;
		}

		// 401/404/5xx: no se borra el buffer — se reintenta en el próximo
		// tick (ver PLAN_PLUGIN_SENSOR.md sección 3, punto 4: nunca perder
		// datos por un GoPress momentáneamente caído).
	}

	/**
	 * Body 200 con instrucciones pendientes: modo diagnóstico profundo (ver
	 * sección 9), la lista vigente de hooks de negocio a escuchar (ver
	 * class-hooks-negocio.php), y la ventana de investigación de hooks (ver
	 * class-investigacion-hooks.php). Un JSON inválido no rompe el flujo
	 * (json_decode devuelve null y se ignora).
	 *
	 * hooks_negocio se guarda si la CLAVE está presente, incluso con un
	 * array VACÍO — eso es lo que permite desactivar todos los hooks desde
	 * GoPress sin que el sitio quede con la última config no vacía para
	 * siempre. Si la clave no está presente del todo (respuesta de un
	 * GoPress más viejo sin esta funcionalidad, o el body vino vacío por el
	 * caso legado de 204, ver reportar()), no se toca la config guardada:
	 * se reintenta sincronizar en el próximo tick de 5 minutos.
	 */
	private static function procesar_instrucciones( $body_json ) {
		$datos = json_decode( $body_json, true );
		if ( ! is_array( $datos ) ) {
			return;
		}
		if ( ! empty( $datos['modo_profundo_hasta'] ) ) {
			GoPress_Agente_Config::guardar_modo_profundo_hasta( $datos['modo_profundo_hasta'] );
		}
		if ( array_key_exists( 'hooks_negocio', $datos ) && is_array( $datos['hooks_negocio'] ) ) {
			GoPress_Agente_Hooks_Negocio::guardar_config( $datos['hooks_negocio'] );
		}
		if ( ! empty( $datos['investigacion_hooks_hasta'] ) ) {
			GoPress_Agente_Investigacion_Hooks::guardar_hasta( $datos['investigacion_hooks_hasta'] );
		}
	}

	/**
	 * Agrega las filas del buffer en el resumen que espera el endpoint:
	 * memoria pico máxima del período, tiempo promedio/máximo, conteo real
	 * de queries lentas y errores (sin tope), y el DETALLE de cada uno hasta
	 * TOPE_DETALLE_POR_PERIODO — antes solo se mandaban "las 5 peores"/
	 * "los últimos 5", perdiendo el resto de la evidencia real del período.
	 * Se agrega en PHP, no en SQL, porque queries_lentas/errores están
	 * serializados como JSON por fila — más simple que un JSON_TABLE de
	 * MySQL para el volumen que maneja este buffer (unas pocas filas cada
	 * 5 minutos).
	 */
	private static function agregar( $filas ) {
		$memoria_pico_max      = 0;
		$suma_tiempo_ms        = 0;
		$tiempo_max_ms         = 0;
		$todas_queries_lentas  = array();
		$todos_errores         = array();

		foreach ( $filas as $fila ) {
			$memoria_pico_max = max( $memoria_pico_max, (int) $fila->memoria_pico_bytes );
			$suma_tiempo_ms  += (int) $fila->tiempo_ejecucion_ms;
			$tiempo_max_ms    = max( $tiempo_max_ms, (int) $fila->tiempo_ejecucion_ms );

			$queries = json_decode( $fila->queries_lentas, true );
			if ( is_array( $queries ) ) {
				$todas_queries_lentas = array_merge( $todas_queries_lentas, $queries );
			}
			$errores = json_decode( $fila->errores, true );
			if ( is_array( $errores ) ) {
				$todos_errores = array_merge( $todos_errores, $errores );
			}
		}

		$total_requests    = count( $filas );
		$errores_agrupados = self::agrupar_errores( $todos_errores );

		// Ordenadas por duración descendente: si hay más de
		// TOPE_DETALLE_POR_PERIODO, las que se cortan son las MENOS lentas,
		// no las primeras que aparecieron — es lo que de verdad interesa ver.
		usort(
			$todas_queries_lentas,
			function ( $a, $b ) {
				return $b['duracion_ms'] <=> $a['duracion_ms'];
			}
		);
		// Los errores agrupados se ordenan por cantidad de repeticiones
		// descendente — el más frecuente es casi siempre el más relevante
		// para diagnosticar (un warning que se dispara en cada request pesa
		// más que uno que ocurrió una sola vez).
		usort(
			$errores_agrupados,
			function ( $a, $b ) {
				return $b['repeticiones'] <=> $a['repeticiones'];
			}
		);

		$primera = reset( $filas );
		$ultima  = end( $filas );

		return array(
			'periodo_desde'            => gmdate( 'c', strtotime( $primera->creado_en ) ),
			'periodo_hasta'            => gmdate( 'c', strtotime( $ultima->creado_en ) ),
			'requests_medidos'         => $total_requests,
			'memoria_pico_bytes'       => $memoria_pico_max,
			'tiempo_ejecucion_prom_ms' => $total_requests > 0 ? (int) round( $suma_tiempo_ms / $total_requests ) : 0,
			'tiempo_ejecucion_max_ms'  => $tiempo_max_ms,
			'queries_lentas_count'     => count( $todas_queries_lentas ),
			'queries_lentas_muestra'   => array_slice( $todas_queries_lentas, 0, self::TOPE_DETALLE_POR_PERIODO ),
			'errores_count'            => count( $todos_errores ),
			'errores_muestra'          => array_slice( $errores_agrupados, 0, self::TOPE_DETALLE_POR_PERIODO ),
		);
	}

	/**
	 * Deduplica errores idénticos (mismo mensaje+archivo+línea) dentro del
	 * período, sumando un contador de repeticiones — más útil y mucho más
	 * liviano que mandar 800 copias literales del mismo warning. Conserva
	 * nivel/mensaje/archivo/línea del primero encontrado (son idénticos por
	 * definición de la clave de agrupación) y agrega 'repeticiones'.
	 */
	private static function agrupar_errores( $errores ) {
		$agrupados = array();
		foreach ( $errores as $error ) {
			$clave = ( isset( $error['archivo'] ) ? $error['archivo'] : '' ) . ':' .
				( isset( $error['linea'] ) ? $error['linea'] : '' ) . ':' .
				( isset( $error['mensaje'] ) ? $error['mensaje'] : '' );

			if ( ! isset( $agrupados[ $clave ] ) ) {
				$agrupados[ $clave ] = $error;
				$agrupados[ $clave ]['repeticiones'] = 0;
			}
			++$agrupados[ $clave ]['repeticiones'];
		}
		return array_values( $agrupados );
	}
}
