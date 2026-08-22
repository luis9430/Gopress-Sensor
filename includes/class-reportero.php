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

	public static function iniciar() {
		add_action( self::HOOK_CRON, array( __CLASS__, 'reportar' ) );
	}

	/**
	 * Corrida del evento de cron: lee el buffer completo, arma el resumen,
	 * lo manda a GoPress, y solo borra el buffer si el POST tuvo éxito. Si
	 * el buffer está vacío no manda nada — no tiene sentido un POST con
	 * requests_medidos=0 cada 5 minutos en un sitio sin tráfico.
	 */
	public static function reportar() {
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
	 * Body 200 con instrucciones pendientes — hoy la única instrucción es
	 * activar el modo diagnóstico profundo (ver sección 9). Si el body no
	 * trae ese campo, no se hace nada; un JSON inválido tampoco rompe el
	 * flujo (json_decode devuelve null y se ignora).
	 */
	private static function procesar_instrucciones( $body_json ) {
		$datos = json_decode( $body_json, true );
		if ( is_array( $datos ) && ! empty( $datos['modo_profundo_hasta'] ) ) {
			GoPress_Agente_Config::guardar_modo_profundo_hasta( $datos['modo_profundo_hasta'] );
		}
	}

	/**
	 * Agrega las filas del buffer en el resumen que espera el endpoint:
	 * memoria pico máxima del período, tiempo promedio/máximo, conteo de
	 * queries lentas y errores con sus 5 peores/últimas muestras. Se agrega
	 * en PHP, no en SQL, porque queries_lentas/errores están serializados
	 * como JSON por fila — más simple que un JSON_TABLE de MySQL para el
	 * volumen que maneja este buffer (unas pocas filas cada 5 minutos).
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

		$total_requests = count( $filas );

		// Las muestras se ordenan para quedarse con "las 5 peores" (más
		// lentas) y "los últimos 5" errores, no las primeras 5 que
		// aparecieron — es lo que de verdad interesa ver.
		usort(
			$todas_queries_lentas,
			function ( $a, $b ) {
				return $b['duracion_ms'] <=> $a['duracion_ms'];
			}
		);
		$errores_recientes = array_slice( $todos_errores, -5 );

		$primera = reset( $filas );
		$ultima  = end( $filas );

		return array(
			'periodo_desde'          => gmdate( 'c', strtotime( $primera->creado_en ) ),
			'periodo_hasta'          => gmdate( 'c', strtotime( $ultima->creado_en ) ),
			'requests_medidos'       => $total_requests,
			'memoria_pico_bytes'     => $memoria_pico_max,
			'tiempo_ejecucion_prom_ms' => $total_requests > 0 ? (int) round( $suma_tiempo_ms / $total_requests ) : 0,
			'tiempo_ejecucion_max_ms' => $tiempo_max_ms,
			'queries_lentas_count'   => count( $todas_queries_lentas ),
			'queries_lentas_muestra' => array_slice( $todas_queries_lentas, 0, 5 ),
			'errores_count'          => count( $todos_errores ),
			'errores_muestra'        => $errores_recientes,
		);
	}
}
