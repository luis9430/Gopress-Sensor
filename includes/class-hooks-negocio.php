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
		'user_register'                              => array( __CLASS__, 'extraer_user_register' ),
		'wp_login'                                    => array( __CLASS__, 'extraer_wp_login' ),
		'wpcf7_mail_sent'                             => array( __CLASS__, 'extraer_wpcf7_mail_sent' ),
		'elementor_pro/forms/new_record'              => array( __CLASS__, 'extraer_elementor_pro_form' ),
		'gform_after_submission'                      => array( __CLASS__, 'extraer_gform_after_submission' ),
		'wpforms_process_complete'                    => array( __CLASS__, 'extraer_wpforms_process_complete' ),
		'forminator_custom_form_submit_before_set_fields' => array( __CLASS__, 'extraer_forminator_form_submit' ),
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
	 * gform_after_submission: do_action('gform_after_submission', $entry,
	 * $form) — DOS argumentos, confirmado contra docs.gravityforms.com
	 * (Entry Object / Form Object / Field Object). $entry es un array
	 * asociativo donde los VALORES de campo están indexados por el ID
	 * numérico que ESE formulario le asignó al campo (ej. $entry['7']) —
	 * no hay clave fija tipo $entry['email'], varía por formulario. La
	 * única forma estable de encontrar "el" email/nombre es recorrer
	 * $form['fields'] y filtrar por la propiedad type ("email"/"name"),
	 * documentada en field-object — nunca por ID fijo. El campo "name" es
	 * compuesto (sub-inputs $field->inputs, sufijos .3=First/.6=Last según
	 * field-object), se reconstruye concatenando ambos si existen.
	 *
	 * Limitación real: si el formulario tiene más de un campo del mismo
	 * type (dos campos email, por ejemplo), esto se queda con el PRIMERO
	 * que encuentra — no hay forma genérica de saber cuál es "el bueno".
	 * Si el formulario no tiene ningún campo de ese type, la clave queda
	 * null, nunca se inventa un valor.
	 */
	private static function extraer_gform_after_submission( $argumentos ) {
		$entry = $argumentos[0] ?? array();
		$form  = $argumentos[1] ?? array();

		$resolver_valor = function ( $campo, $id ) use ( $entry ) {
			$primero  = rgar( $entry, $id . '.3' );
			$apellido = rgar( $entry, $id . '.6' );
			$nombre   = trim( $primero . ' ' . $apellido );
			if ( $nombre !== '' ) {
				return $nombre;
			}
			// Campo "name" configurado como un solo input simple (sin
			// dividir first/last), o campo "email" — su valor vive directo
			// en el ID base.
			return rgar( $entry, (string) $id ) ?: null;
		};

		$campos = self::extraer_formulario_por_tipo_de_campo(
			(array) ( $form['fields'] ?? array() ),
			array(
				'clave_tipo'     => 'type',
				'clave_id'       => 'id',
				'resolver_valor' => $resolver_valor,
			)
		);

		return array(
			'form_id'  => $entry['form_id'] ?? null,
			'entry_id' => $entry['id'] ?? null,
			'email'    => $campos['email'],
			'nombre'   => $campos['nombre'],
		);
	}

	/**
	 * wpforms_process_complete: do_action('wpforms_process_complete',
	 * $fields, $entry, $form_data, $entry_id) — CUATRO argumentos,
	 * confirmado contra wpforms.com/developers/ Y contra el código fuente
	 * real de wpforms-lite (includes/class-process.php,
	 * includes/fields/class-base.php). Se usa $fields (NO $entry, que es
	 * el $_POST crudo sin normalizar) — cada elemento ya trae 'type' y
	 * 'value' listos (class-base.php: format()), así que el mismo criterio
	 * "filtrar por type" de Gravity Forms aplica acá, más simple: WPForms
	 * ya concatena first/last del campo "name" en 'value' antes de que
	 * esto se ejecute, no hace falta reconstruirlo a mano.
	 *
	 * entry_id puede ser 0 (WPForms Lite, o guardado de entradas
	 * desactivado) — documentado explícitamente por WPForms, se reenvía
	 * tal cual sin tratarlo como error.
	 */
	private static function extraer_wpforms_process_complete( $argumentos ) {
		$fields    = $argumentos[0] ?? array();
		$form_data = $argumentos[2] ?? array();
		$entry_id  = $argumentos[3] ?? 0;

		$campos = self::extraer_formulario_por_tipo_de_campo(
			(array) $fields,
			array(
				'clave_tipo'  => 'type',
				'clave_valor' => 'value',
			)
		);

		return array(
			'form_id'  => $form_data['id'] ?? null,
			'entry_id' => $entry_id,
			'email'    => $campos['email'],
			'nombre'   => $campos['nombre'],
		);
	}

	/**
	 * forminator_custom_form_submit_before_set_fields: do_action(
	 *   'forminator_custom_form_submit_before_set_fields', $entry,
	 *   $module_id, $field_data_array
	 * ) — TRES argumentos, confirmado contra el código fuente real de
	 * Forminator (front-action.php, set_field_data() y
	 * set_field_data_array()) ejecutándose en costalegre. Cada elemento de
	 * $field_data_array trae 'name' (el field id), 'value' y 'field_type'
	 * (NO 'type' como Gravity Forms/WPForms — clave distinta, mismo shape)
	 * — 'field_type' toma los valores 'email'/'name' declarados en
	 * library/fields/email.php y name.php. El campo "name" puede llegar
	 * como string simple o como array con sub-claves (first/last) según
	 * cómo esté configurado en el editor — se concatena si es array.
	 */
	private static function extraer_forminator_form_submit( $argumentos ) {
		$field_data_array = $argumentos[2] ?? array();

		$resolver_valor = function ( $campo ) {
			$valor = $campo['value'] ?? null;
			if ( is_array( $valor ) ) {
				return trim( implode( ' ', array_filter( $valor, 'is_scalar' ) ) ) ?: null;
			}
			return $valor;
		};

		$campos = self::extraer_formulario_por_tipo_de_campo(
			(array) $field_data_array,
			array(
				'clave_tipo'     => 'field_type',
				'resolver_valor' => $resolver_valor,
			)
		);

		return array(
			'form_id'  => $argumentos[1] ?? null,
			'entry_id' => null,
			'email'    => $campos['email'],
			'nombre'   => $campos['nombre'],
		);
	}

	/**
	 * Extractor genérico reusado por las 3 familias de formularios de
	 * arriba (Gravity Forms, WPForms, Forminator) — el patrón que se repite
	 * en los tres es idéntico: una lista de "campos", cada uno con una
	 * clave que indica su TIPO ("email"/"name") y alguna forma de resolver
	 * su valor real. Lo único que cambia entre plugins es el NOMBRE de esas
	 * claves y, en el caso de Gravity Forms, que el valor no viene directo
	 * en el campo sino que hay que ir a buscarlo a $entry por id — de ahí
	 * el parámetro resolver_valor en vez de asumir siempre 'clave_valor'.
	 *
	 * Agregar un plugin nuevo de esta familia (Ninja Forms, Formidable,
	 * Fluent Forms, etc.) es: confirmar contra su código/documentación (a)
	 * el nombre del hook y la posición del array de campos en sus
	 * argumentos, (b) qué clave marca el tipo de campo y qué valores toma
	 * para email/nombre, (c) cómo se resuelve el valor real — y escribir un
	 * extractor de una función corta que llame a esta, igual que los tres
	 * de arriba. Nunca escribir un cuarto bucle de filtrado desde cero.
	 *
	 * Limitación heredada de los extractores originales, no nueva: si el
	 * formulario tiene más de un campo del mismo tipo, se queda con el
	 * PRIMERO; si no tiene ninguno, la clave queda null.
	 *
	 * @param array $campos Lista de campos del formulario.
	 * @param array $opciones {
	 *     @type string        $clave_tipo     Clave que indica el tipo de campo (ej. 'type', 'field_type'). Obligatorio.
	 *     @type string        $clave_id       Clave del identificador del campo, para pasarlo a resolver_valor. Opcional.
	 *     @type string        $clave_valor    Clave del valor ya resuelto dentro del propio campo (ej. 'value'). Ignorado si se pasa resolver_valor.
	 *     @type callable|null $resolver_valor function($campo, $id) => valor real. Si no se pasa, se usa $campo[$clave_valor].
	 * }
	 * @return array{email: mixed, nombre: mixed}
	 */
	private static function extraer_formulario_por_tipo_de_campo( $campos, $opciones ) {
		$clave_tipo     = $opciones['clave_tipo'];
		$clave_id       = $opciones['clave_id'] ?? 'id';
		$clave_valor    = $opciones['clave_valor'] ?? 'value';
		$resolver_valor = $opciones['resolver_valor'] ?? function ( $campo ) use ( $clave_valor ) {
			return is_array( $campo ) ? ( $campo[ $clave_valor ] ?? null ) : null;
		};

		$email  = null;
		$nombre = null;
		foreach ( $campos as $campo ) {
			$tipo = is_object( $campo ) ? ( $campo->{$clave_tipo} ?? null ) : ( $campo[ $clave_tipo ] ?? null );
			$id   = is_object( $campo ) ? ( $campo->{$clave_id} ?? null ) : ( $campo[ $clave_id ] ?? null );

			if ( $tipo === 'email' && $email === null ) {
				$email = $resolver_valor( $campo, $id );
			}
			if ( $tipo === 'name' && $nombre === null ) {
				$nombre = $resolver_valor( $campo, $id );
			}
		}
		return array(
			'email'  => $email,
			'nombre' => $nombre,
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
