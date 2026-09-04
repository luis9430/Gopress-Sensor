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
		'metform_after_store_form_data'               => array( __CLASS__, 'extraer_metform_after_store_form_data' ),
		'everest_forms_process_complete'              => array( __CLASS__, 'extraer_everest_forms_process_complete' ),
		'bp_core_activated_user'                      => array( __CLASS__, 'extraer_bp_core_activated_user' ),
		'groups_join_group'                           => array( __CLASS__, 'extraer_bp_groups_join_group' ),
		'groups_leave_group'                          => array( __CLASS__, 'extraer_bp_groups_leave_group' ),
		'bp_activity_add'                              => array( __CLASS__, 'extraer_bp_activity_add' ),
		'friends_friendship_accepted'                  => array( __CLASS__, 'extraer_bp_friends_friendship_accepted' ),
		'mailpoet_subscriber_created'                  => array( __CLASS__, 'extraer_mailpoet_subscriber_created' ),
		'tutor_after_enroll'                           => array( __CLASS__, 'extraer_tutor_after_enroll' ),
		'tutor_course_complete_after'                  => array( __CLASS__, 'extraer_tutor_course_complete_after' ),
		'tutor_quiz_finished'                          => array( __CLASS__, 'extraer_tutor_quiz_finished' ),
		'ninja_forms_after_submission'                 => array( __CLASS__, 'extraer_ninja_forms_after_submission' ),
		'frm_after_create_entry'                       => array( __CLASS__, 'extraer_frm_after_create_entry' ),
		'fluentform/submission_inserted'               => array( __CLASS__, 'extraer_fluentform_submission_inserted' ),
		'woocommerce_new_order'                        => array( __CLASS__, 'extraer_woocommerce_order' ),
		'woocommerce_order_status_completed'           => array( __CLASS__, 'extraer_woocommerce_order' ),
		'woocommerce_order_status_processing'          => array( __CLASS__, 'extraer_woocommerce_order' ),
		'woocommerce_order_status_cancelled'           => array( __CLASS__, 'extraer_woocommerce_order' ),
		'woocommerce_order_status_refunded'            => array( __CLASS__, 'extraer_woocommerce_order' ),
		'woocommerce_order_refunded'                   => array( __CLASS__, 'extraer_woocommerce_order_refunded' ),
		'wcfmmp_new_store_created'                     => array( __CLASS__, 'extraer_wcfmmp_new_store_created' ),
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
	 * metform_after_store_form_data: do_action(
	 *   'metform_after_store_form_data', $form_id, $form_data,
	 *   $form_settings, $attributes
	 * ) — CUATRO argumentos, confirmado contra el código fuente real de
	 * MetForm (core/entries/action.php) ejecutándose en costalegre.
	 * $form_data es el $_POST crudo (plano field_id => valor, SIN 'type'
	 * por campo) — no encaja con el patrón de tipo por campo de los otros
	 * extractores. MetForm no tiene un widget de "nombre" en absoluto (solo
	 * 'mf-text' genérico, ver widgets/manifest.php) — no hay forma
	 * confiable de identificar cuál campo de texto es el nombre de la
	 * persona, así que ese campo queda SIEMPRE null, a diferencia de los
	 * demás extractores de esta familia. El email sí es identificable de
	 * forma confiable: $attributes['email_field_name'] ya trae el nombre
	 * del campo cuyo widget es 'mf-email' (resuelto por MetForm mismo vía
	 * get_input_name_by_widget_type(), no hace falta reimplementar esa
	 * búsqueda acá).
	 */
	private static function extraer_metform_after_store_form_data( $argumentos ) {
		$form_id    = $argumentos[0] ?? null;
		$form_data  = $argumentos[1] ?? array();
		$attributes = $argumentos[3] ?? array();

		$campo_email = $attributes['email_field_name'] ?? null;
		return array(
			'form_id'  => $form_id,
			'entry_id' => null,
			'email'    => $campo_email ? ( $form_data[ $campo_email ] ?? null ) : null,
			'nombre'   => null,
		);
	}

	/**
	 * everest_forms_process_complete: do_action(
	 *   'everest_forms_process_complete', $fields, $entry, $form_data,
	 *   $entry_id
	 * ) — CUATRO argumentos, misma firma que wpforms_process_complete (no
	 * es casualidad: Everest Forms comparte linaje de código con WPForms).
	 * Confirmado contra el código fuente real (class-evf-form-task.php)
	 * ejecutándose en costalegre. Cada elemento de $fields trae 'type' y
	 * 'value' — mismo shape que WPForms — pero a diferencia de Gravity
	 * Forms/WPForms/Forminator, Everest Forms NO tiene un tipo 'name'
	 * único: el nombre se arma con DOS campos de tipo separado,
	 * 'first-name' y 'last-name' (ver includes/fields/class-evf-field-
	 * first-name.php y -last-name.php), de ahí el parámetro tipos_nombre.
	 */
	private static function extraer_everest_forms_process_complete( $argumentos ) {
		$fields    = $argumentos[0] ?? array();
		$form_data = $argumentos[2] ?? array();
		$entry_id  = $argumentos[3] ?? 0;

		$campos = self::extraer_formulario_por_tipo_de_campo(
			(array) $fields,
			array(
				'clave_tipo'   => 'type',
				'clave_valor'  => 'value',
				'tipos_nombre' => array( 'first-name', 'last-name' ),
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
	 * bp_core_activated_user: do_action('bp_core_activated_user', $user_id,
	 * $key, $user) — TRES argumentos, confirmado contra el código fuente
	 * real de BuddyPress (bp-members/bp-members-functions.php) ejecutándose
	 * en costalegre. $user es un ARRAY plano (no un objeto WP_User), con
	 * las claves 'user_login'/'user_email' entre otras (armado a mano en la
	 * misma función antes del do_action) — no confundir con el $user de
	 * wp_login (ese sí es WP_User). $key es la clave de activación de la
	 * cuenta, no se reenvía: es un secreto de un solo uso, no aporta valor
	 * a una automatización y exponerlo no tiene sentido.
	 */
	private static function extraer_bp_core_activated_user( $argumentos ) {
		$user_id = $argumentos[0] ?? null;
		$user    = $argumentos[2] ?? array();
		return array(
			'user_id'    => $user_id,
			'user_login' => is_array( $user ) ? ( $user['user_login'] ?? null ) : null,
			'user_email' => is_array( $user ) ? ( $user['user_email'] ?? null ) : null,
		);
	}

	/**
	 * groups_join_group: do_action('groups_join_group', $group_id,
	 * $user_id, $group) — TRES argumentos, confirmado contra el código
	 * fuente real (bp-groups/bp-groups-functions.php). $group es un objeto
	 * BP_Groups_Group con propiedades públicas 'id'/'name' (confirmado
	 * contra classes/class-bp-groups-group.php) — se reenvía solo el
	 * nombre, no la descripción completa del grupo (sin valor claro para
	 * una automatización, y puede contener HTML largo).
	 */
	private static function extraer_bp_groups_join_group( $argumentos ) {
		$group_id = $argumentos[0] ?? null;
		$user_id  = $argumentos[1] ?? null;
		$group    = $argumentos[2] ?? null;
		return array(
			'group_id'   => $group_id,
			'user_id'    => $user_id,
			'group_name' => ( is_object( $group ) && isset( $group->name ) ) ? $group->name : null,
		);
	}

	/**
	 * groups_leave_group: do_action('groups_leave_group', $group->id,
	 * $user_id, $group) — mismos TRES argumentos y misma forma de $group
	 * que groups_join_group (confirmado en la misma función,
	 * bp-groups-functions.php), reusa el mismo extractor de forma.
	 */
	private static function extraer_bp_groups_leave_group( $argumentos ) {
		return self::extraer_bp_groups_join_group( $argumentos );
	}

	/**
	 * bp_activity_add: do_action('bp_activity_add', $r, $activity_id) — DOS
	 * argumentos, confirmado contra el código fuente real
	 * (bp-activity/bp-activity-functions.php). $r es un array de
	 * argumentos YA nombrados (parsed por wp_parse_args antes de esta
	 * función) — 'user_id', 'component' (ej. 'groups', 'activity'),
	 * 'type' (ej. 'activity_update', 'joined_group'), 'content'. No hace
	 * falta ningún extractor de campos tipo formulario: la forma ya es
	 * estable de por sí. 'content' puede traer HTML sin escapar (se
	 * respeta tal cual, la decisión de sanitizar queda del lado de quien
	 * consuma la automatización).
	 */
	private static function extraer_bp_activity_add( $argumentos ) {
		$r           = $argumentos[0] ?? array();
		$activity_id = $argumentos[1] ?? null;
		return array(
			'activity_id' => $activity_id,
			'user_id'     => $r['user_id'] ?? null,
			'component'   => $r['component'] ?? null,
			'type'        => $r['type'] ?? null,
			'content'     => $r['content'] ?? null,
		);
	}

	/**
	 * friends_friendship_accepted: do_action('friends_friendship_accepted',
	 * $friendship_id, $initiator_user_id, $friend_user_id, $friendship) —
	 * CUATRO argumentos, confirmado contra el código fuente real
	 * (bp-friends/bp-friends-functions.php). Los primeros tres argumentos
	 * ya son los IDs que hacen falta — no hace falta tocar el objeto
	 * $friendship en absoluto.
	 */
	private static function extraer_bp_friends_friendship_accepted( $argumentos ) {
		return array(
			'friendship_id'      => $argumentos[0] ?? null,
			'initiator_user_id'  => $argumentos[1] ?? null,
			'friend_user_id'     => $argumentos[2] ?? null,
		);
	}

	/**
	 * mailpoet_subscriber_created: do_action('mailpoet_subscriber_created',
	 * $subscriberId) — UN solo argumento, un ENTERO (confirmado contra el
	 * código fuente real de MailPoet,
	 * lib/Config/SubscriberChangesNotifier.php — el hook se dispara desde
	 * ahí, no desde el punto donde se crea el suscriptor, y solo pasa el
	 * ID). MailPoet usa Doctrine ORM internamente (SubscriberEntity no es
	 * un array ni un objeto simple), así que no se accede a ese ORM
	 * directo: se relee el suscriptor completo vía la API PÚBLICA
	 * documentada de MailPoet ("API used by other plugins", ver
	 * lib/API/MP/v1/API.php), \MailPoet\API\API::MP('v1')->getSubscriber(),
	 * que sí acepta un ID entero (confirmado contra
	 * lib/API/MP/v1/Subscribers.php::findSubscriber) y devuelve un array ya
	 * armado y estable (id/email/first_name/last_name/status). Se envuelve
	 * en try/catch porque esa API lanza APIException si el suscriptor ya no
	 * existe (por ejemplo si se borró en el mismo request) — no debe tumbar
	 * el resto del reenvío de hooks de negocio por eso.
	 */
	private static function extraer_mailpoet_subscriber_created( $argumentos ) {
		$subscriber_id = $argumentos[0] ?? null;
		if ( ! $subscriber_id || ! class_exists( '\MailPoet\API\API' ) ) {
			return array( 'subscriber_id' => $subscriber_id );
		}
		try {
			$suscriptor = \MailPoet\API\API::MP( 'v1' )->getSubscriber( (int) $subscriber_id );
		} catch ( \Exception $e ) {
			return array( 'subscriber_id' => $subscriber_id );
		}
		return array(
			'subscriber_id' => $subscriber_id,
			'email'         => $suscriptor['email'] ?? null,
			'first_name'    => $suscriptor['first_name'] ?? null,
			'last_name'     => $suscriptor['last_name'] ?? null,
			'status'        => $suscriptor['status'] ?? null,
		);
	}

	/**
	 * tutor_after_enroll: do_action('tutor_after_enroll', $course_id,
	 * $is_enrolled) — DOS argumentos, confirmado contra el código fuente
	 * real de Tutor LMS (models/EnrollmentModel.php,
	 * EnrollmentModel::do_enroll()). Pese al nombre, $is_enrolled NO es un
	 * booleano: es el ID del POST de inscripción recién creado
	 * (wp_insert_post() devuelve un ID), con post_author = el usuario
	 * inscrito (documentado explícitamente en el docblock de do_enroll()).
	 * El user_id no viaja directo en los argumentos del hook — se relee del
	 * post de inscripción.
	 */
	private static function extraer_tutor_after_enroll( $argumentos ) {
		$course_id       = $argumentos[0] ?? null;
		$enrollment_id   = $argumentos[1] ?? null;
		$post_inscripcion = $enrollment_id ? get_post( $enrollment_id ) : null;
		return array(
			'course_id'     => $course_id,
			'enrollment_id' => $enrollment_id,
			'user_id'       => $post_inscripcion ? (int) $post_inscripcion->post_author : null,
		);
	}

	/**
	 * tutor_course_complete_after: do_action('tutor_course_complete_after',
	 * $course_id, $user_id) — DOS argumentos, confirmado contra el código
	 * fuente real (models/CourseModel.php). Firma directa, sin necesidad de
	 * releer nada — a diferencia de tutor_after_enroll, acá el user_id sí
	 * viaja tal cual en el hook.
	 */
	private static function extraer_tutor_course_complete_after( $argumentos ) {
		return array(
			'course_id' => $argumentos[0] ?? null,
			'user_id'   => $argumentos[1] ?? null,
		);
	}

	/**
	 * tutor_quiz_finished: do_action('tutor_quiz_finished', $attempt_id,
	 * $quiz_id, $user_id) — TRES argumentos, confirmado contra el código
	 * fuente real (classes/Quiz.php,
	 * Quiz::finishing_quiz_attempt()). El hook NO trae el resultado
	 * (aprobado/reprobado) ni el puntaje — hay que calcularlo. Se usa
	 * QuizModel::prepare_attempt_result($attempt_id) en vez de leer la
	 * columna 'result' de la tabla wp_tutor_quiz_attempts directo: esa
	 * columna la actualiza update_attempt_result(), que NO se llama desde
	 * finishing_quiz_attempt() (confirmado leyendo esa función completa) —
	 * podría no estar calculada todavía en este punto. prepare_attempt_result()
	 * en cambio es puro: recalcula el resultado desde las respuestas ya
	 * guardadas, sin depender de si esa columna fue actualizada. Valores
	 * posibles de 'result': 'pass'/'fail'/'pending' (este último si hay
	 * preguntas de revisión manual sin calificar todavía, ej. respuesta
	 * abierta) — confirmado contra las constantes reales de QuizModel.
	 */
	private static function extraer_tutor_quiz_finished( $argumentos ) {
		$attempt_id = $argumentos[0] ?? null;
		$quiz_id    = $argumentos[1] ?? null;
		$user_id    = $argumentos[2] ?? null;

		$resultado = null;
		if ( $attempt_id && class_exists( '\Tutor\Models\QuizModel' ) ) {
			$resultado = \Tutor\Models\QuizModel::prepare_attempt_result( $attempt_id );
		}

		return array(
			'attempt_id' => $attempt_id,
			'quiz_id'    => $quiz_id,
			'user_id'    => $user_id,
			'result'     => $resultado ?: null,
		);
	}

	/**
	 * ninja_forms_after_submission: do_action('ninja_forms_after_submission',
	 * $data) — UN solo argumento, confirmado contra el código fuente real de
	 * Ninja Forms (includes/AJAX/Controllers/Submission.php). $data['fields']
	 * es un array indexado por field_id, cada elemento con 'type' y 'value' —
	 * mismo shape reusable que Gravity Forms/WPForms/Forminator. A diferencia
	 * de esos tres, Ninja Forms NO tiene un tipo 'name' único: separa
	 * 'firstname'/'lastname' (confirmado en includes/Fields/FirstName.php y
	 * LastName.php) — mismo caso que Everest Forms, solo que sin guión en el
	 * nombre del tipo.
	 */
	private static function extraer_ninja_forms_after_submission( $argumentos ) {
		$data   = $argumentos[0] ?? array();
		$fields = $data['fields'] ?? array();

		$campos = self::extraer_formulario_por_tipo_de_campo(
			(array) $fields,
			array(
				'clave_tipo'   => 'type',
				'clave_valor'  => 'value',
				'tipos_nombre' => array( 'firstname', 'lastname' ),
			)
		);

		return array(
			'form_id'  => $data['form_id'] ?? null,
			'entry_id' => $data['id'] ?? null,
			'email'    => $campos['email'],
			'nombre'   => $campos['nombre'],
		);
	}

	/**
	 * frm_after_create_entry: do_action('frm_after_create_entry', $entry_id,
	 * $form_id, $args) — TRES argumentos, confirmado contra el código fuente
	 * real de Formidable Forms (classes/models/FrmEntry.php). A diferencia de
	 * los extractores de arriba, este hook NO trae ningún campo del
	 * formulario — solo IDs. Se relee la entrada completa vía la función
	 * pública FrmEntry::getOne($id, true), que arma $entry->metas (valores
	 * indexados por field_id, SIN el tipo de campo — confirmado en
	 * FrmEntry::get_meta(), la columna 'type' se usa solo para sanitizar el
	 * valor y se descarta después) — por eso hace falta una segunda consulta
	 * a FrmField::get_all_types_in_form() para encontrar el field_id de tipo
	 * 'email'/'name'. El campo 'name' de Formidable es compuesto
	 * (sub-claves 'first'/'last'/'middle', confirmado en
	 * classes/models/fields/FrmFieldName.php) — se concatena 'first' + 'last'
	 * con el mismo orden que usa el propio plugin para mostrarlo.
	 */
	private static function extraer_frm_after_create_entry( $argumentos ) {
		$entry_id = $argumentos[0] ?? null;
		$form_id  = $argumentos[1] ?? null;

		if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmField' ) || ! $entry_id ) {
			return array( 'form_id' => $form_id, 'entry_id' => $entry_id, 'email' => null, 'nombre' => null );
		}

		$entry = FrmEntry::getOne( $entry_id, true );
		$metas = ( $entry && isset( $entry->metas ) ) ? (array) $entry->metas : array();

		$campo_email = FrmField::get_all_types_in_form( $form_id, 'email', 1 );
		$campo_email_id = is_object( $campo_email ) ? $campo_email->id : null;
		$email = $campo_email_id ? ( $metas[ $campo_email_id ] ?? null ) : null;

		$campo_nombre = FrmField::get_all_types_in_form( $form_id, 'name', 1 );
		$campo_nombre_id = is_object( $campo_nombre ) ? $campo_nombre->id : null;
		$valor_nombre = $campo_nombre_id ? ( $metas[ $campo_nombre_id ] ?? null ) : null;
		$nombre = null;
		if ( is_array( $valor_nombre ) ) {
			$nombre = trim( ( $valor_nombre['first'] ?? '' ) . ' ' . ( $valor_nombre['last'] ?? '' ) );
			$nombre = $nombre !== '' ? $nombre : null;
		} elseif ( is_string( $valor_nombre ) && $valor_nombre !== '' ) {
			$nombre = $valor_nombre;
		}

		return array(
			'form_id'  => $form_id,
			'entry_id' => $entry_id,
			'email'    => is_scalar( $email ) ? $email : null,
			'nombre'   => $nombre,
		);
	}

	/**
	 * fluentform/submission_inserted: do_action('fluentform/submission_inserted',
	 * $insertId, $formData, $form) — TRES argumentos, confirmado contra el
	 * código fuente real de Fluent Forms
	 * (app/Services/Form/SubmissionHandlerService.php). Existe también la
	 * variante deprecada 'fluentform_submission_inserted' (con guión bajo, no
	 * slash) — no se engancha acá, el propio plugin la marca como legacy.
	 * $formData es un array PLANO 'nombre_de_input => valor' (el $_POST ya
	 * sanitizado y filtrado) — sin 'type' por campo, a diferencia de Gravity
	 * Forms/WPForms/Forminator/Ninja Forms. Se identifica qué input es
	 * email/nombre vía la API pública de parseo de formularios
	 * (FormFieldsParser::getInputsByElementTypes(), confirmado en
	 * app/Services/Parser/Form.php) filtrando por 'element' ('input_email'/
	 * 'input_name'). El campo input_name es compuesto (array de sub-valores,
	 * confirmado en PaymentHelper::getFormInput() usando el mismo patrón de
	 * array_filter + implode(' ', ...) que se reusa acá).
	 */
	private static function extraer_fluentform_submission_inserted( $argumentos ) {
		$insert_id = $argumentos[0] ?? null;
		$form_data = $argumentos[1] ?? array();
		$form      = $argumentos[2] ?? null;

		$form_id = is_object( $form ) ? ( $form->id ?? null ) : null;

		if ( ! class_exists( '\FluentForm\App\Modules\Form\FormFieldsParser' ) || ! $form ) {
			return array( 'form_id' => $form_id, 'entry_id' => $insert_id, 'email' => null, 'nombre' => null );
		}

		$parser = '\FluentForm\App\Modules\Form\FormFieldsParser';

		$campo_email = $parser::getInputsByElementTypes( $form, array( 'input_email' ) );
		$nombre_campo_email = $campo_email ? array_key_first( $campo_email ) : null;
		$email = $nombre_campo_email ? ( $form_data[ $nombre_campo_email ] ?? null ) : null;

		$campo_nombre = $parser::getInputsByElementTypes( $form, array( 'input_name' ) );
		$nombre_campo_nombre = $campo_nombre ? array_key_first( $campo_nombre ) : null;
		$valor_nombre = $nombre_campo_nombre ? ( $form_data[ $nombre_campo_nombre ] ?? null ) : null;
		$nombre = null;
		if ( is_array( $valor_nombre ) ) {
			$partes = array_filter( $valor_nombre, 'is_scalar' );
			$nombre = trim( implode( ' ', $partes ) ) ?: null;
		} elseif ( is_string( $valor_nombre ) && $valor_nombre !== '' ) {
			$nombre = $valor_nombre;
		}

		return array(
			'form_id'  => $form_id,
			'entry_id' => $insert_id,
			'email'    => is_scalar( $email ) ? $email : null,
			'nombre'   => $nombre,
		);
	}

	/**
	 * woocommerce_new_order: do_action('woocommerce_new_order', $order_id,
	 * $order) — DOS argumentos, confirmado contra el código fuente real de
	 * WooCommerce (includes/data-stores/class-wc-order-data-store-cpt.php).
	 *
	 * woocommerce_order_status_{estado}: do_action('woocommerce_order_status_'
	 * . $status_transition['to'], $order_id, $order, $status_transition) —
	 * TRES argumentos, confirmado contra includes/class-wc-order.php
	 * (WC_Order::status_transition(), con docblock oficial completo). El
	 * nombre del hook varía dinámicamente por estado — este extractor se
	 * reusa para 'completed'/'processing'/'cancelled'/'refunded' (ver
	 * $extractores_conocidos), cada uno registrado por separado porque
	 * WordPress no permite enganchar por patrón de nombre.
	 *
	 * Ambos hooks comparten la misma forma de $order (objeto WC_Order) — se
	 * usa la misma función para los dos, ignorando el tercer argumento
	 * ($status_transition) cuando no está presente (woocommerce_new_order
	 * solo trae 2). Todos los datos se leen vía métodos públicos
	 * documentados de WC_Order (get_id/get_status/get_total/get_currency/
	 * get_billing_email/get_customer_id) — nunca se accede a $order->data
	 * directo, que no es un contrato estable entre versiones (HPOS vs.
	 * posts, ver la propia documentación de WooCommerce sobre el Order
	 * object).
	 */
	private static function extraer_woocommerce_order( $argumentos ) {
		$order = $argumentos[1] ?? null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array( 'order_id' => $argumentos[0] ?? null );
		}
		return array(
			'order_id'     => $order->get_id(),
			'status'       => $order->get_status(),
			'total'        => $order->get_total(),
			'currency'     => $order->get_currency(),
			'email'        => $order->get_billing_email(),
			'customer_id'  => $order->get_customer_id(),
		);
	}

	/**
	 * woocommerce_order_refunded: do_action('woocommerce_order_refunded',
	 * $order_id, $refund_id) — DOS IDs planos, confirmado contra el código
	 * fuente real (includes/wc-order-functions.php). A diferencia de los
	 * hooks de arriba, no trae el objeto $order — solo IDs. Se relee el
	 * pedido completo vía la función pública wc_get_order() (API estable y
	 * documentada de WooCommerce, la misma que usa todo el ecosistema de
	 * plugins de terceros para obtener un WC_Order por ID) para poder
	 * reportar los mismos campos que los demás hooks de WooCommerce.
	 */
	private static function extraer_woocommerce_order_refunded( $argumentos ) {
		$order_id  = $argumentos[0] ?? null;
		$refund_id = $argumentos[1] ?? null;

		$datos_orden = array( 'order_id' => $order_id );
		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
				$datos_orden = self::extraer_woocommerce_order( array( null, $order ) );
			}
		}

		$datos_orden['refund_id'] = $refund_id;
		return $datos_orden;
	}

	/**
	 * wcfmmp_new_store_created: do_action('wcfmmp_new_store_created',
	 * $vendor_id, $wcfm_vendor_form_data) — DOS argumentos, confirmado
	 * contra el código fuente real de WCFM (WC Frontend Manager,
	 * controllers/vendors/wcfm-controller-vendors-new.php) — el hook vive
	 * en el plugin BASE, no en el add-on "WCFM Marketplace"
	 * (wc-multivendor-marketplace) como sugiere su prefijo 'wcfmmp'.
	 * $wcfm_vendor_form_data es el array crudo del formulario de alta de
	 * tienda tal como lo llenó el usuario — su forma varía según qué
	 * campos existan (dirección, geolocalización, etc.), así que no se lee
	 * directo: se relee 'store_name' vía get_user_meta() con la clave
	 * 'wcfmmp_store_name', que el propio código de WCFM ya guardó ahí
	 * (update_user_meta) en la línea inmediatamente anterior al
	 * do_action — mismo criterio que otros extractores del catálogo de
	 * preferir una fuente estable (user meta ya persistido) sobre parsear
	 * un array de formulario de forma variable.
	 */
	private static function extraer_wcfmmp_new_store_created( $argumentos ) {
		$vendor_id = $argumentos[0] ?? null;
		$usuario   = $vendor_id ? get_userdata( $vendor_id ) : false;
		return array(
			'vendor_id'  => $vendor_id,
			'store_name' => $vendor_id ? get_user_meta( $vendor_id, 'wcfmmp_store_name', true ) : null,
			'user_email' => $usuario ? $usuario->user_email : null,
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
	 * extractor de una función corta que llame a esta, igual que los cuatro
	 * de arriba. Nunca escribir un quinto bucle de filtrado desde cero.
	 *
	 * Everest Forms confirmó un caso nuevo, no cubierto por los primeros
	 * tres: no tiene un tipo de campo "name" único, separa el nombre en DOS
	 * campos con tipos distintos (first-name/last-name, ver
	 * includes/fields/class-evf-field-first-name.php y
	 * -last-name.php) — de ahí el parámetro tipos_nombre en vez de asumir
	 * siempre el literal 'name': cuando trae más de un tipo, se concatenan
	 * en el ORDEN dado, con espacio entre valores no vacíos.
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
	 *     @type string[]      $tipos_nombre   Tipos que arman el nombre, en orden (default: ['name']). Más de uno se concatena con espacio.
	 * }
	 * @return array{email: mixed, nombre: mixed}
	 */
	private static function extraer_formulario_por_tipo_de_campo( $campos, $opciones ) {
		$clave_tipo     = $opciones['clave_tipo'];
		$clave_id       = $opciones['clave_id'] ?? 'id';
		$clave_valor    = $opciones['clave_valor'] ?? 'value';
		$tipos_nombre   = $opciones['tipos_nombre'] ?? array( 'name' );
		$resolver_valor = $opciones['resolver_valor'] ?? function ( $campo ) use ( $clave_valor ) {
			return is_array( $campo ) ? ( $campo[ $clave_valor ] ?? null ) : null;
		};

		$email          = null;
		$partes_nombre  = array_fill_keys( $tipos_nombre, null );
		foreach ( $campos as $campo ) {
			$tipo = is_object( $campo ) ? ( $campo->{$clave_tipo} ?? null ) : ( $campo[ $clave_tipo ] ?? null );
			$id   = is_object( $campo ) ? ( $campo->{$clave_id} ?? null ) : ( $campo[ $clave_id ] ?? null );

			if ( $tipo === 'email' && $email === null ) {
				$email = $resolver_valor( $campo, $id );
			}
			if ( in_array( $tipo, $tipos_nombre, true ) && $partes_nombre[ $tipo ] === null ) {
				$partes_nombre[ $tipo ] = $resolver_valor( $campo, $id );
			}
		}
		$nombre = trim( implode( ' ', array_filter( $partes_nombre, 'is_scalar' ) ) );
		return array(
			'email'  => $email,
			'nombre' => $nombre !== '' ? $nombre : null,
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
