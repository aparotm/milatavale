# Changelog

## 2.3.18 - 2026-02-24

### Shortcode clientes con acciones
- Se elimina el buscador superior duplicado para dejar un solo buscador visible.
- Se corrige versionado interno del plugin (`Version` y `MLV2_VERSION`) para consistencia.

## 2.3.17 - 2026-02-24

### UI tabla clientes
- Botones de acciones actualizados a `+ Gasto` y `+ Latas`.

## 2.3.16 - 2026-02-24

### UI botones tabla clientes
- Se aplican estilos compactos solicitados para botones en `[mlv_almacen_clientes_acciones]` (tamaño/fuente/padding).

## 2.3.15 - 2026-02-24

### Ajuste visual botones en tabla de clientes (almacén)
- Se corrige truncamiento/corte de texto en acciones de `[mlv_almacen_clientes_acciones]`.
- Botones ahora tienen:
  - `width:auto !important`
  - ancho mínimo estable por acción
  - labels cortos (`Gasto`, `Latas`) para mantener filas limpias.

## 2.3.14 - 2026-02-24

### Shortcode clientes con acciones: mejoras UI/columnas
- `[mlv_almacen_clientes_acciones]` agrega columna **Canjeado** por cliente (gastos operacionales del local).
- Se compactan botones de acciones por fila:
  - layout horizontal en contenedor flexible
  - texto sin salto de línea
  - menor alto de fila en la tabla.

## 2.3.13 - 2026-02-24

### Nuevo shortcode: tabla de clientes con acciones (almacén)
- Se agrega `[mlv_almacen_clientes_acciones]` para usuarios `um_almacen`/admin:
  - tabla con columnas: nombre, RUT, teléfono, ingresos reciclaje, ingresos incentivos, saldo, latas
  - buscador en vivo por nombre/RUT/teléfono
  - botones por fila: **Registrar gasto** y **Registrar latas**
- El shortcode acepta atributos opcionales:
  - `gasto_url` (default: `/registro-gasto/`)
  - `latas_url` (default: `/registro-latas/`)

### Prefill de cliente en formularios existentes
- `[mlv_registro_gasto]` y `[mlv_registro_latas]` ahora aceptan `?mlv2_cliente_id=ID` y dejan el cliente preseleccionado en el desplegable.
- Se valida que el cliente preseleccionado pertenezca al local del almacenero.

## 2.3.12 - 2026-02-24

### Hotfix KPIs cliente (reversa incentivo)
- Ajuste de cálculo en KPIs front para que **incentivos** se calcule neto por `origen_saldo='incentivo'` (incluye reversas/correcciones).
- Con esto, al reversar un incentivo en admin, el panel del cliente refleja el cambio en KPI de incentivos y saldo de forma consistente.

## 2.3.11 - 2026-02-24

### Reversa grupal de incentivos (nuevo)
- Se agrega `incentivo_batch_id` en `wp_mlv_movimientos` (columna + indice) para agrupar incentivos repartidos por lote.
- En `Mi Lata Vale > Movimientos`, los incentivos de lote ahora muestran accion **Reversar lote**.
- Nueva pantalla interna `mlv2_reverse_incentivo_batch` con confirmacion y motivo.
- Nueva accion `admin_post_mlv2_reverse_incentivo_batch`:
  - revierte todos los movimientos del lote creando asientos espejo (`clasificacion_mov='correccion'`)
  - evita doble reversa si ya existe una reversa activa para algun movimiento del lote
  - recalcula saldo por cliente afectado y deja trazabilidad en auditoria (`movimiento_reverse_batch`).

### Incentivos por local
- Al registrar incentivos en modo **Repartir por local(es)** se genera un `batch_id` unico y se guarda en:
  - columna `incentivo_batch_id`
  - `detalle.incentivo.batch_id`.

### Reversa individual
- Las reversas individuales ahora preservan `incentivo_batch_id` cuando el movimiento original pertenece a un lote.

## 2.3.10 - 2026-02-24

### Hotfix critico
- Corregido `Parse error` por parentesis faltante en expresiones ternarias de:
  - `mi-lata-vale-ledger-v2.php` (registro de gasto)
  - `includes/core/validation.php` (registro de latas)
- Sin cambios funcionales de negocio; solo correccion sintactica.

## 2.3.9 - 2026-02-24

### Diagnostico: exportable
- Se agregan exports de diagnostico en:
  - CSV (`admin_post_mlv2_export_diagnostico_csv`)
  - JSON (`admin_post_mlv2_export_diagnostico_json`)
- Botones de descarga en `Mi Lata Vale > Diagnostico`.
- Ambos exports usan nonce + permiso `manage_options`.

## 2.3.8 - 2026-02-24

### Diagnostico: boton "Reparar ahora"
- Nueva accion admin `admin_post_mlv2_repair_now`.
- Desde `Mi Lata Vale > Diagnostico` ahora se puede ejecutar reparacion en un clic:
  - corre `MLV2_DB::maybe_install()` (tablas/migraciones/indices)
  - limpia cache de health checks
  - reevalua estado y muestra resultado (`repair_ok` / `repair_warn`)

### Health cache
- `MLV2_Health::clear_cache()` para invalidar transient de criticidad.

## 2.3.7 - 2026-02-24

### Modo estricto (nuevo)
- Nuevo ajuste `mlv2_strict_mode_enabled` en pantalla de Ajustes.
- Cuando está activo, el servicio de movimientos bloquea nuevas altas de latas/gastos si `MLV2_Health` detecta fallas estructurales criticas (tablas/columnas/indices).
- Frontend muestra mensaje especifico `strict_mode_block` para guiar a revisar Diagnostico.

### Health checks
- `MLV2_Health` agrega:
  - `has_critical_issues()`
  - `has_critical_issues_cached()` (cache con transient)

### Hardening servicio
- `MLV2_Movement_Service` ahora expone `get_last_error()` y codigos internos (`strict_mode_block`, `invalid_input`, `db_insert_failed`) para manejo de errores controlado.

## 2.3.6 - 2026-02-24

### Anti-regresion (diagnostico)
- Nuevo `MLV2_Health` en `includes/core/health.php`.
- Nueva pagina admin **Diagnostico** (`Mi Lata Vale > Diagnostico`) con chequeos de:
  - existencia de tablas criticas
  - columnas e indices requeridos en `wp_mlv_movimientos`
  - anomalias basicas de datos (signo de montos, local/cliente faltante)

### Hardening de servicio de movimientos
- `MLV2_Movement_Service::insert_ingreso()` y `insert_gasto()` ahora validan:
  - `local_codigo` no vacio
  - `cliente_user_id > 0`
  - `created_by_user_id > 0`
  - `monto > 0` en gastos
- Si falla validacion, no insertan y retornan `0`.

## 2.3.5 - 2026-02-24

### Servicio de movimientos (refactor mayor)
- Nuevo archivo `includes/core/movement-service.php` con `MLV2_Movement_Service`.
- Centraliza:
  - obtención de local por almacén
  - validación de conflicto doble-rol
  - inserción de ingresos/gastos
  - recálculo de saldo cliente

### Integración de flujos operativos
- `handle_registro_latas_post` ahora usa `MLV2_Movement_Service::insert_ingreso()`.
- `mlv2_handle_registro_gasto_autonomo` ahora usa `MLV2_Movement_Service::insert_gasto()`.
- Ambos flujos ahora comparten validaciones y recálculo por servicio.

## 2.3.4 - 2026-02-24

### Refactor operativo (menos duplicación)
- Se agregan helpers compartidos:
  - `mlv2_get_local_codigo_for_user()`
  - `mlv2_is_doble_rol_conflict()`
- Registro de latas y gastos reutilizan esos helpers para validar local configurado y bloqueo de doble rol.

### Hardening admin
- Reversa contable (`admin_post_mlv2_reverse_movimiento`) ahora bloquea:
  - movimientos en papelera
  - movimientos de ajuste del sistema (`is_system_adjustment=1`)
- Edición de monto (`admin_post_mlv2_update_movimiento_monto`):
  - exige monto `> 0`
  - bloquea edición de movimientos en papelera
  - input HTML ajustado a `min=1`

## 2.3.3 - 2026-02-24

### Rendimiento de base de datos
- Se agregan índices para consultas frecuentes de panel/admin:
  - `idx_created_by (created_by_user_id)`
  - `idx_cliente_deleted (cliente_user_id, deleted_at)`
  - `idx_local_deleted_created (local_codigo, deleted_at, created_at)`
- La migración crea estos índices automáticamente en instalaciones existentes.

### Rendimiento frontend (almacén)
- `get_clientes_by_local()` ahora usa cache estático por request.
- Corrección de `meta_query` para búsqueda por local en usuarios cliente.
- Al cargar clientes por tabla N-N, se fuerza rol `um_cliente` y se amplía límite según cantidad real asociada.

## 2.3.2 - 2026-02-24

### Robustez operativa
- Se bloquea registro de latas/gastos cuando el almacén no tiene `mlv_local_codigo` configurado.
- Nuevos mensajes front para guiar al almacenero a completar perfil/local.

### Consistencia contable
- Registro de latas ahora recalcula `mlv_saldo` desde ledger tras insertar movimiento (evita drift por concurrencia o históricos).

### AJAX / rendimiento defensivo
- `mlv2_load_more_movimientos` ahora limita `per_page` a rango seguro (5-100).
- Búsqueda de clientes por RUT agrega fallback a `mlv_rut` cuando `mlv_rut_norm` no está disponible.

## 2.3.1 - 2026-02-23

### Seguridad
- Se eliminaron endpoints `admin_post_nopriv` para registro de latas y gastos.

### Consistencia contable
- KPIs de cliente y local usan saldo calculado desde ledger para evitar divergencias con snapshot en `usermeta`.

### Mantenibilidad
- Nueva utilidad central `MLV2_Movimientos` para unificar detección de gasto/ingreso, evidencia y monto efectivo.
- Frontend (tablas), admin y export CSV ahora comparten la misma lógica de interpretación de movimientos.

## 2.3.0 - 2026-02-23

### KPIs (Shortcodes)
- SubKPIs de cliente y almacenero ahora muestran 6 bloques (incluye Movimientos).

## 2.2.9 - 2026-02-23

### UI
- KPIs en shortcodes ahora se muestran en 2 filas de 3 (desktop).

## 2.2.8 - 2026-02-23

### KPIs
- KPIs alineados a: Saldo disponible, Reciclaje, Incentivos, Canjeado, Latas, Movimientos.
- Movimientos excluye incentivos y ajustes/registros administrativos.

## 2.2.7 - 2026-02-23

### Panel almacenero
- Incentivos por local se agregan en una sola fila cuando se ven “Todos los clientes”.
- Columna “Tipo” con salto de línea y detalle de incentivo.

### Tablas
- Encabezado “Ingreso/Gasto” renombrado a “Tipo”.

## 2.2.6 - 2026-02-23

### Movimientos (shortcodes)
- Panel almacenero ahora lista movimientos por `local_codigo`, incluyendo incentivos y ajustes creados por admin.
- AJAX de “Cargar más” usa el mismo filtro por local.

## 2.2.5 - 2026-02-23

### Incentivos / Regularización / Ajustes
- Formularios con modo cliente o reparto por local(es).
- Incentivos sin fecha ni local relacionado.
- Ajustes y regularizaciones pueden repartirse entre clientes de locales.

### Movimientos
- Columna de detalle en Movimientos (admin) con info de incentivo/ajuste/regularización.
- En tablas de cliente/almacén se muestra “Incentivo” con detalle.

### KPIs
- Cliente y almacén con 6 KPIs (incluye Movimientos).

## 2.2.4 - 2026-02-23

### Fix
- Incentivos: corregido error de parseo en el JS inline del formulario.

## 2.2.3 - 2026-02-23

### Front-end
- Se removió el KPI de regularizaciones en los shortcodes/totales del front.

## 2.2.2 - 2026-02-23

### Admin/UX
- Después de reversar, editar monto o regularizar se redirige a Movimientos con aviso visible.
- Incentivos: formulario dinámico según modo (cliente o local) para evitar confusión.
- Incentivos: tipo/categoría ahora es desplegable estandarizado.

## 2.2.1 - 2026-02-23

### Incentivos
- Incentivos por cliente o por local(es).
- Reparto automático del pozo entre clientes del local.
- En selector de local se muestra cantidad de clientes.

### Seguridad/UX
- Mensaje claro cuando un almacenero intenta registrarse con su propio RUT.

### Admin
- Se removieron del menú: RUT duplicados y Conflictos de doble rol.

## 2.2.0 - 2026-02-23

### Nuevas funciones
- Incentivos desde WP-Admin con registro contable separado.
- Regularización histórica para latas/saldos preexistentes.
- Ajustes contables manuales con trazabilidad.
- Reversa de movimientos para correcciones sin borrar historial.
- Detección de RUT duplicados con herramienta de fusión.
- Reporte de conflictos de doble rol.

### Contabilidad y datos
- Nuevas columnas en `wp_mlv_movimientos`:
  - `origen_saldo`, `mov_ref_id`, `is_system_adjustment`, `clasificacion_mov`.
- Backfill seguro con defaults y clasificación opcional por texto.

### Seguridad y reglas de negocio
- Bloqueo de auto-registro del dueño/administrador como cliente en su propio local.
- Validación real de DV del RUT.

### KPIs y reportes
- KPIs separados por reciclaje e incentivos.
- Regularizaciones históricas visibles sin inflar la operación normal.
- Export CSV extendido con nuevos campos contables.

### Compatibilidad
- Mantiene tabla y metadatos existentes.
- No rompe flujo actual de registro de latas/gastos.
