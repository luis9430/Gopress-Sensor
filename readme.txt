=== GoPress Agente ===
Contributors: gopress
Tags: monitoring, performance, telemetry
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sensor de telemetría técnica para sitios administrados por GoPress.

== Description ==

Este plugin es exclusivamente un sensor: mide memoria pico, tiempo de
ejecución, queries lentas de base de datos y errores/warnings de PHP por
request, y reporta un resumen agregado cada 5 minutos a GoPress vía WP-Cron.

**Nunca decide ni diagnostica nada.** Toda interpretación de estos datos
vive en GoPress, no en este plugin. No incluye SEO, no incluye analytics, no
hace ninguna llamada de red a servicios de terceros — solo reporta al
servidor GoPress configurado.

Requiere configuración vía las constantes `GOPRESS_AGENTE_URL` y
`GOPRESS_AGENTE_TOKEN` en `wp-config.php` (GoPress las inyecta
automáticamente al aprovisionar el sitio). Sin panel de administración en
esta versión.

== Changelog ==

= 0.1.0 =
* Primera versión: medidor (memoria, tiempo, errores), drop-in de queries
  lentas, buffer propio y reportero vía WP-Cron.
