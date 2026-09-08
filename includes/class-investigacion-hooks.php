<?php
/**
 * Sensor temporal de investigación de hooks: durante una ventana activa
 * (ver internal/server/investigacion_hooks.go, 5 minutos), engancha
 * add_action('all', ...) para capturar el NOMBRE y los ARGUMENTOS
 * completos de cada hook de un plugin de terceros que se dispare — sin
 * necesitar saber de antemano cuál es el hook relevante. Reemplaza tener
 * que armar un script ad-hoc de captura (como se hizo investigando
 * WPForms) por evidencia real recolectada por el propio plugin-sensor.
 *
 * A diferencia de class-hooks-negocio.php (reenvía hooks YA CONOCIDOS y
 * configurados, fire-and-forget inmediato por hook), esto es exploratorio:
 * escucha TODOS los hooks sin filtrar por nombre, acumula en memoria
 * durante el request, y reporta el lote completo en el mismo tick de
 * WP-Cron que el reporte periódico normal (ver reportar_si_corresponde,
 * llamado desde class-reportero.php::reportar()) — nunca hace su propio
 * POST inmediato, porque el volumen de hooks disparados en una ventana de
 * 5 minutos de uso real puede ser alto y no tiene sentido de negocio
 * reportarlo con latencia de milisegundos como sí la tiene un pedido
 * nuevo o un formulario enviado.
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Investigacion_Hooks {

	const OPCION_HASTA = 'gopress_agente_investigacion_hooks_hasta';

	/**
	 * Prefijos de hooks internos de WordPress core que disparan en
	 * CUALQUIER request, sin importar qué haga el visitante — "ruido" para
	 * el propósito de esta herramienta (encontrar el hook de NEGOCIO de un
	 * plugin nuevo, ej. "se envió un formulario"). Basado en evidencia
	 * REAL, no en suposición: se capturó un request completo sin ningún
	 * filtro (488 hooks únicos, miles de disparos totales — ej. "gettext"
	 * solo, 986 veces) y se derivó esta lista de los prefijos que
	 * concentraban el volumen: sistema de i18n (gettext), opciones
	 * (pre_option_, option_, default_option_), sanitización/escape
	 * (sanitize_, esc_, clean_, kses_), registro de tipos de contenido
	 * (register_..._args, registered_...), y bloques de Gutenberg
	 * (block_type_metadata). Deliberadamente por PREFIJO (no nombre
	 * exacto): la variante real de WordPress es "pre_option_siteurl",
	 * "pre_option_stylesheet", etc. — una por cada opción que se lea en el
	 * request, imposible de enumerar por nombre exacto de antemano.
	 *
	 * Hooks de negocio reales quedan afuera de estos prefijos a propósito
	 * (ej. "wp_login", "woocommerce_new_order" no empiezan con ninguno de
	 * estos) — el riesgo real es dejar pasar ALGO de ruido ocasional, no
	 * excluir por accidente lo que el usuario busca.
	 */
	private static $prefijos_ruido = array(
		'gettext', 'ngettext', 'load_textdomain', 'unload_textdomain', 'override_load_textdomain',
		'override_unload_textdomain', 'pre_load_textdomain', 'translation_file_format',
		'lang_dir_for_domain', 'pre_get_language_files_from_path', 'determine_locale',
		'pre_determine_locale', 'get_available_languages',
		'pre_option', 'option_', 'default_option_', 'pre_update_option', 'update_option_',
		'pre_wp_load_alloptions', 'alloptions',
		'sanitize_', 'esc_', 'clean_', 'kses_', 'wp_kses',
		'register_post_type_args', 'registered_post_type', 'register_taxonomy_args',
		'registered_taxonomy', 'register_meta_args', 'is_post_type_viewable',
		'register_', 'registered_', 'post_type_labels_',
		'block_type_metadata', 'should_load_separate_core_block_assets',
		'map_meta_cap', 'user_has_cap', 'is_protected_meta', 'nonce_',
		'pre_get_scheduled_event', 'wp_next_scheduled', 'get_schedule', 'plugin_loaded', 'extra_plugin_headers',
		'set_url_scheme', 'site_url', 'home_url', 'admin_url', 'includes_url',
		'plugins_url', 'theme_root', 'theme_file_path', 'stylesheet', 'template',
		'extra_theme_headers', 'locale', 'salt', 'redis_object_cache', 'redis_cache_',
		'wp_cache_', 'wp_video_', 'wp_audio_', 'wp_roles_init', 'load_default_',
		'auth_cookie_', 'determine_current_user', 'wp_is_application_passwords_available',
		'wp_doing_ajax', 'parse_tax_query',
	);

	/**
	 * Hooks nativos de nombre EXACTO (no siguen un prefijo reconocible,
	 * pero disparan siempre) — lista más chica, complementa a
	 * $prefijos_ruido para los casos que no encajan en un patrón.
	 */
	private static $hooks_nativos_ruido = array(
		'plugins_loaded', 'muplugins_loaded', 'setup_theme', 'after_setup_theme',
		'init', 'wp_loaded', 'admin_init', 'admin_menu', 'widgets_init',
		'parse_request', 'send_headers', 'parse_query', 'pre_get_posts',
		'wp', 'template_redirect', 'template_include', 'wp_head', 'wp_footer',
		'wp_enqueue_scripts', 'admin_enqueue_scripts', 'wp_print_styles',
		'wp_print_scripts', 'wp_default_scripts', 'wp_default_styles',
		'shutdown', 'in_admin_header', 'in_admin_footer',
		'admin_head', 'admin_footer', 'admin_notices', 'all_admin_notices',
		'current_screen', 'load-index.php', 'the_post', 'loop_start', 'loop_end',
		'wp_ajax_heartbeat', 'heartbeat_tick', 'rest_api_init',
		'sanitize_comment_cookies', 'set_current_user',
		'wp_before_admin_bar_render', 'wp_after_admin_bar_render',
		'get_header', 'get_footer', 'get_sidebar',
	);

	/**
	 * Buffer en memoria de esta corrida de PHP — nunca persiste entre
	 * requests (a diferencia de GoPress_Agente_Buffer, que sí usa una
	 * tabla propia): cada request que dispare hooks durante la ventana
	 * activa reporta su propio lote por separado en el siguiente tick de
	 * WP-Cron, sin necesidad de una tabla nueva ni de coordinar entre
	 * requests concurrentes.
	 */
	private static $capturados = array();

	/**
	 * Tope duro de seguridad — el filtro por prefijo (ver $prefijos_ruido)
	 * ya elimina la enorme mayoría del ruido real (confirmado contra
	 * WordPress real: de más de 150 000 disparos en un request de prueba,
	 * quedaron unas pocas decenas relevantes), pero un plugin roto
	 * disparando su PROPIO hook de negocio en un loop, o un prefijo de
	 * ruido todavía no identificado, no debe poder crecer sin límite y
	 * convertir el propio sensor de investigación en el problema (payload
	 * de varios MB, mismo criterio que TOPE_DETALLE_POR_PERIODO en
	 * class-reportero.php).
	 */
	const TOPE_CAPTURAS_POR_REQUEST = 500;

	/**
	 * Engancha add_action('all', ...) SOLO si la ventana de investigación
	 * sigue vigente — no-op de costo cero en cualquier request normal
	 * fuera de la ventana de 5 minutos. Prioridad por defecto, un solo
	 * argumento variádico: 'all' es el único hook de WordPress que se
	 * dispara para CUALQUIER otro hook/filtro, con current_filter()
	 * devolviendo el nombre real y func_get_args() los argumentos
	 * originales tal cual se dispararon.
	 */
	public static function iniciar() {
		if ( ! self::activa() ) {
			return;
		}
		add_action( 'all', array( __CLASS__, 'capturar' ) );
	}

	public static function activa() {
		$hasta = get_option( self::OPCION_HASTA, '' );
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
	 * Guarda el timestamp de expiración — mismo mecanismo PULL que
	 * modo_profundo_hasta (ver class-config.php), leído desde
	 * procesar_instrucciones() en class-reportero.php.
	 */
	public static function guardar_hasta( $iso8601 ) {
		update_option( self::OPCION_HASTA, $iso8601, false );
	}

	/**
	 * Callback de 'all' — filtra ruido nativo y hooks propios del sensor
	 * (para no capturarse a sí mismo reportando), y acumula el resto con
	 * sus argumentos ya serializados. Nunca lanza si algo no es
	 * serializable: json_encode con un valor no soportado (ej. un recurso
	 * abierto) devuelve false más abajo al reportar, no acá — este método
	 * solo acumula datos crudos en memoria PHP, no hace ninguna conversión
	 * costosa por cada hook disparado (podrían ser cientos en un request
	 * con muchos plugins activos).
	 */
	public static function capturar() {
		if ( count( self::$capturados ) >= self::TOPE_CAPTURAS_POR_REQUEST ) {
			return;
		}

		$hook = current_filter();
		if ( 'all' === $hook || in_array( $hook, self::$hooks_nativos_ruido, true ) ) {
			return;
		}
		if ( 0 === strpos( $hook, 'gopress_agente_' ) ) {
			return; // nunca capturar los propios hooks/cron del plugin-sensor
		}
		foreach ( self::$prefijos_ruido as $prefijo ) {
			if ( 0 === strpos( $hook, $prefijo ) ) {
				return;
			}
		}

		self::$capturados[] = array(
			'hook'         => $hook,
			'argumentos'   => self::serializar_argumentos( func_get_args() ),
			'disparado_en' => gmdate( 'c' ),
		);
	}

	/**
	 * Mismo criterio best-effort de serialización que
	 * class-hooks-negocio.php::serializar_argumentos_generico() — acá no
	 * hay extractores nombrados por hook (el propósito de esta
	 * herramienta es justo DESCUBRIR hooks que todavía no se conocen, así
	 * que nunca puede haber un extractor curado de antemano). Un objeto
	 * sin get_data()/to_array() cae a get_object_vars(), que puede
	 * devolver un array vacío para objetos con propiedades privadas —
	 * aceptable: sigue siendo mejor evidencia que nada, y el nombre del
	 * hook y la CANTIDAD de argumentos ya son la señal principal que
	 * busca quien investiga.
	 */
	private static function serializar_argumentos( $argumentos ) {
		return array_map(
			function ( $argumento ) {
				if ( is_object( $argumento ) ) {
					if ( method_exists( $argumento, 'get_data' ) ) {
						return $argumento->get_data();
					}
					if ( method_exists( $argumento, 'to_array' ) ) {
						return $argumento->to_array();
					}
					return get_object_vars( $argumento );
				}
				return $argumento;
			},
			$argumentos
		);
	}

	/**
	 * Llamado desde class-reportero.php::reportar(), en el mismo tick que
	 * el reporte periódico normal — POST SEPARADO (no se mezcla con el
	 * body del reporte agregado: volumen y forma distintos, ver el
	 * comentario de reportar_si_corresponde). Vacía el buffer en memoria
	 * apenas arma el request, sin importar si el POST tiene éxito — un
	 * reintento perdiendo hooks de una corrida de investigación puntual es
	 * aceptable (mismo criterio best-effort que rige el resto del plugin
	 * fuera del buffer de telemetría agregada, que sí tiene reintento
	 * real). Un lote vacío no genera ningún POST.
	 */
	public static function reportar_si_corresponde() {
		if ( empty( self::$capturados ) ) {
			return;
		}
		$lote               = self::$capturados;
		self::$capturados   = array();

		$url   = GoPress_Agente_Config::url_reportar();
		$token = GoPress_Agente_Config::token();
		if ( '' === $url || '' === $token ) {
			return;
		}

		// La URL de reporte normal termina en ".../agente/reportar" — el
		// endpoint de hooks investigados es un sibling bajo el mismo
		// sitio (ver internal/server/server.go,
		// "POST /sites/{nombre}/agente/hooks-investigados"), así que se
		// deriva reemplazando el último segmento en vez de necesitar una
		// constante nueva inyectada en wp-config.php.
		$url_hooks = preg_replace( '#/agente/reportar$#', '/agente/hooks-investigados', $url );
		if ( $url_hooks === $url ) {
			return; // la URL no tenía la forma esperada; no adivinar un endpoint incorrecto.
		}

		wp_remote_post(
			$url_hooks,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-GoPress-Token' => $token,
				),
				'body'    => wp_json_encode( array( 'hooks' => $lote ) ),
			)
		);
	}
}
