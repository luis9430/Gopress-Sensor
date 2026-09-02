<?php
/**
 * Reenvía hooks de negocio de WordPress/plugins (formulario enviado, pedido
 * nuevo, usuario registrado) como webhook hacia Activepieces. Qué hooks
 * escuchar y hacia dónde llega vía el mecanismo PULL ya existente (respuesta
 * del POST periódico de class-reportero.php) — nunca hardcodeado acá ni
 * configurado por push desde GoPress. Ver el plan de integración con
 * Activepieces (repo GoPress).
 *
 * A diferencia de class-reportero.php (agrega en un buffer y reporta cada 5
 * minutos), esto es fire-and-forget INMEDIATO sin buffer: un formulario
 * enviado o un pedido nuevo es un evento de negocio con valor decreciente si
 * se demora minutos — la automatización típica es "notificar YA". Si se
 * pierde un envío puntual porque Activepieces estaba caído en ese instante
 * exacto, es aceptable (mismo criterio best-effort que ya rige el resto del
 * plugin fuera de la telemetría agregada). wp_remote_post con
 * blocking=false evita que una automatización lenta o caída añada latencia
 * perceptible al visitante real que disparó el hook.
 *
 * @package GoPress_Agente
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GoPress_Agente_Hooks_Negocio {

	const OPCION_CONFIG = 'gopress_agente_hooks_negocio_config';

	/**
	 * Engancha dinámicamente los hooks configurados — si no hay config
	 * guardada (sitio recién instalado, o instrucciones nunca llegaron
	 * todavía), no engancha nada, es un no-op seguro. Prioridad 10, hasta 99
	 * argumentos: cada hook nativo de WordPress o de un plugin trae una
	 * cantidad distinta de argumentos, no vale la pena mantener un mapa de
	 * hook a cantidad de argumentos acá.
	 */
	public static function iniciar() {
		foreach ( self::config_actual() as $entrada ) {
			if ( empty( $entrada['hook'] ) || empty( $entrada['url_destino'] ) ) {
				continue;
			}
			// $entrada se captura por valor en el closure (PHP copia arrays
			// por valor por defecto) — cada hook activado tiene su propia
			// URL de destino sin pisarse entre sí, aunque varios hooks
			// reenvíen a la misma URL de Activepieces.
			add_action(
				$entrada['hook'],
				function ( ...$argumentos ) use ( $entrada ) {
					self::reenviar( $entrada, $argumentos );
				},
				10,
				99
			);
		}
	}

	/**
	 * Hace el POST fire-and-forget hacia la URL de destino del hook — sin
	 * reintento, sin registro local de éxito o fallo (ver el comentario de
	 * cabecera del archivo sobre por qué esto difiere de class-reportero.php).
	 */
	private static function reenviar( $entrada, $argumentos ) {
		wp_remote_post(
			$entrada['url_destino'],
			array(
				'timeout'   => 5,
				'blocking'  => false,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => wp_json_encode(
					array(
						'hook'         => $entrada['hook'],
						'argumentos'   => self::armar_payload( $entrada['hook'], $argumentos ),
						'disparado_en' => gmdate( 'c' ),
					)
				),
			)
		);
	}

	/**
	 * Arma el payload que se manda como "argumentos" — para los hooks
	 * conocidos en $extractores_conocidos, un objeto NOMBRADO Y ESTABLE
	 * (mismas claves siempre, documentadas en el catálogo de triggers de
	 * GoPress, ver internal/server/catalogo_hooks_negocio.go); para
	 * cualquier otro hook, cae al serializado genérico de siempre (array
	 * posicional best-effort, sin garantía de forma).
	 *
	 * Por qué hacía falta esto: serializar_argumentos_generico() reenvía
	 * los argumentos de do_action() tal cual, en el orden posicional que
	 * WordPress/el plugin de turno decida — para "user_register" eso es
	 * "[user_id, userdata]", nunca un objeto {email: "..."}. El usuario
	 * del builder de automatizaciones de GoPress no puede escribir una
	 * condición como "order.email" contra eso; necesita nombres de campo
	 * reales y estables, no un array por posición.
	 */
	private static function armar_payload( $hook, $argumentos ) {
		$extractor = self::$extractores_conocidos[ $hook ] ?? null;
		if ( $extractor ) {
			return call_user_func( $extractor, $argumentos );
		}
		return self::serializar_argumentos_generico( $argumentos );
	}

	/**
	 * Extractores nombrados por hook — cada uno documenta con qué versión
	 * confirmé la firma real de do_action() (developer.wordpress.org y
	 * código fuente de cada plugin) y qué tan estable es cada campo. Solo
	 * cubre los hooks que GoPress ya expone con campos documentados en su
	 * catálogo (internal/server/catalogo_hooks_negocio.go); el resto
	 * (WooCommerce, Gravity Forms, WPForms) sigue con el catálogo
	 * genérico hasta que se confirme su firma igual de a fondo.
	 */
	private static $extractores_conocidos = array(
		'user_register'                 => array( __CLASS__, 'extraer_user_register' ),
		'wp_login'                       => array( __CLASS__, 'extraer_wp_login' ),
		'wpcf7_mail_sent'                => array( __CLASS__, 'extraer_wpcf7_mail_sent' ),
		'elementor_pro/forms/new_record' => array( __CLASS__, 'extraer_elementor_pro_form' ),
	);

	/**
	 * user_register: do_action('user_register', $user_id, $userdata) —
	 * $user_id existe desde WP 1.5.0; $userdata (el array crudo pasado a
	 * wp_insert_user()) recién se agregó en WP 5.8.0, así que NO se puede
	 * asumir presente (self::iniciar() engancha con num_args=99, así que
	 * en un WP viejo simplemente no llega — $argumentos[1] queda unset,
	 * por eso ni se usa acá). Se relee con get_userdata($user_id) en vez
	 * de confiar en $userdata: siempre completo y consistente sin
	 * importar qué campos haya pasado el código que creó el usuario, y
	 * evita el riesgo real de que $userdata traiga user_pass EN CLARO —
	 * nunca se lee ni se reenvía ese campo.
	 */
	private static function extraer_user_register( $argumentos ) {
		$user_id = $argumentos[0] ?? null;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return array( 'user_id' => $user_id );
		}
		return array(
			'user_id'    => $user->ID,
			'user_login' => $user->user_login,
			'user_email' => $user->user_email,
			'role'       => $user->roles[0] ?? '',
		);
	}

	/**
	 * wp_login: do_action('wp_login', $user_login, $user) — $user (objeto
	 * WP_User) es parte de la firma estable documentada en
	 * developer.wordpress.org. No se reenvía el objeto $user completo:
	 * WP_User->data incluye user_pass (hasheado, pero igual no debe
	 * viajar fuera del sitio) — se extraen solo los campos puntuales que
	 * hacen falta.
	 */
	private static function extraer_wp_login( $argumentos ) {
		$user_login = $argumentos[0] ?? '';
		$user       = $argumentos[1] ?? null;
		return array(
			'user_login' => $user_login,
			'user_id'    => ( $user instanceof WP_User ) ? $user->ID : null,
		);
	}

	/**
	 * wpcf7_mail_sent: do_action('wpcf7_mail_sent', $contact_form) — UN
	 * solo argumento (confirmado contra el código fuente de Contact Form
	 * 7, submission.php), se dispara solo si el mail salió bien. Los
	 * datos que el visitante escribió NO están en $contact_form: se leen
	 * del singleton de la request actual, WPCF7_Submission::get_instance()
	 * (API pública del plugin). get_posted_data() devuelve un array cuyas
	 * CLAVES son los nombres de campo que el administrador del sitio
	 * definió al armar el formulario (ej. "your-name"/"your-email" son
	 * solo los nombres del template por defecto — un formulario real
	 * puede tener cualquier otro nombre) — variable por sitio, a
	 * diferencia de user_id/user_email de los hooks nativos de WordPress.
	 * Un campo multi-valor (checkbox, select múltiple) llega como array,
	 * no string, y json_encode lo serializa igual sin problema.
	 */
	private static function extraer_wpcf7_mail_sent( $argumentos ) {
		$contact_form = $argumentos[0] ?? null;
		$submission   = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		return array(
			'form_id'    => ( $contact_form instanceof WPCF7_ContactForm ) ? $contact_form->id() : null,
			'form_title' => ( $contact_form instanceof WPCF7_ContactForm ) ? $contact_form->title() : null,
			'campos'     => $submission ? $submission->get_posted_data() : array(),
		);
	}

	/**
	 * elementor_pro/forms/new_record: do_action(
	 *   'elementor_pro/forms/new_record', $record, $ajax_handler
	 * ) — DOS argumentos (confirmado: omitir el segundo en add_action()
	 * causa "Too few arguments to function", issue público de Elementor).
	 * $record->get('fields') es la extracción recomendada por la
	 * documentación oficial de Elementor Developers: array indexado por
	 * el FIELD ID (estable — "name", "email", o un id custom tipo
	 * "field_a1b2c3"), cada entrada trae al menos id/value/title. Se
	 * evita a propósito get_formatted_data() (no confirmado contra fuente
	 * primaria en esta investigación, y hay evidencia de que indexa por
	 * la ETIQUETA visible del campo — frágil, cambia si el administrador
	 * del sitio renombra el campo en el editor).
	 */
	private static function extraer_elementor_pro_form( $argumentos ) {
		$record = $argumentos[0] ?? null;
		$campos = array();
		if ( is_object( $record ) && method_exists( $record, 'get' ) ) {
			foreach ( (array) $record->get( 'fields' ) as $campo ) {
				$id            = $campo['id'] ?? null;
				$campos[ $id ] = $campo['value'] ?? null;
			}
		}
		return array(
			'form_name' => ( is_object( $record ) && method_exists( $record, 'get' ) ) ? $record->get( 'form_name' ) : null,
			'campos'    => $campos,
		);
	}

	/**
	 * Serializado genérico best-effort para cualquier hook SIN extractor
	 * nombrado (ver armar_payload) — mismo comportamiento que existía
	 * antes de esta clase de extractores: un array posicional, sin
	 * garantía de forma ni de nombres de campo estables.
	 */
	private static function serializar_argumentos_generico( $argumentos ) {
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
	 * Lee la config guardada — array vacío si no hay nada guardado o el
	 * valor no es un array (ej. primera vez que corre el plugin).
	 */
	public static function config_actual() {
		$config = get_option( self::OPCION_CONFIG, array() );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Guarda la config nueva recibida vía el mecanismo PULL (ver
	 * procesar_instrucciones en class-reportero.php). autoload=false: no se
	 * necesita en cada carga de página normal, solo cuando el hook
	 * correspondiente se dispara. No reengancha hooks en caliente dentro del
	 * mismo request (iniciar() ya corrió al bootstrap) — la config nueva
	 * toma efecto recién en el PRÓXIMO request de un visitante, aceptable:
	 * no es una config que deba aplicar con latencia de milisegundos.
	 */
	public static function guardar_config( $config ) {
		update_option( self::OPCION_CONFIG, is_array( $config ) ? $config : array(), false );
	}
}
