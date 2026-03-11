# Investigacion Funcional y Tecnica - Plugin Mi Lata Vale Ledger V2

## 1) Idea general del sistema

`Mi Lata Vale Ledger V2` es un plugin de WordPress que implementa un sistema de monedero cerrado para campañas de reciclaje.
La logica central es:

- El almacen registra latas de un cliente.
- Ese registro genera saldo monetario para el cliente.
- Luego el almacen puede registrar gastos/canjes, que descuentan saldo.
- El retiro fisico de latas (por parte de gestor) es un estado logistico y no modifica saldo.

En terminos de concepto, el plugin combina:

- Operacion territorial (locales/almacenes, gestores, clientes).
- Contabilidad simplificada por movimientos (ledger).
- Trazabilidad (alertas, historial y auditoria).

---

## 2) Proposito y objetivo de negocio

### Proposito principal

Digitalizar la operacion de reciclaje en almacenes para que cada accion de reciclaje tenga reflejo inmediato en saldo del cliente, con trazabilidad y control por roles.

### Objetivos funcionales

- Credito inmediato al cliente al registrar latas.
- Descuento inmediato al registrar gasto.
- Evitar aprobaciones manuales complejas para la operacion diaria.
- Mantener capacidad de auditoria y correccion administrativa.
- Permitir gestion por multiples locales (modelo cliente-local).

### Objetivos tecnicos

- Fuente de verdad contable en una tabla de movimientos.
- Snapshot de saldo por usuario (`mlv_saldo`) para compatibilidad y rendimiento de UI.
- Integracion con roles de Ultimate Member (`um_cliente`, `um_almacen`, `um_gestor`).
- Seguridad de entrada (nonce, roles, sanitizacion, validaciones de archivos).

---

## 3) Arquitectura de programacion (alto nivel)

## 3.1 Capas principales

- `core/`: reglas de negocio, seguridad, ledger, validacion y servicios.
- `frontend/`: shortcodes, paneles por rol, formularios y endpoints AJAX.
- `admin/`: vistas de gestion, tablas, exportaciones, ajustes, diagnostico y correcciones.

## 3.2 Archivo de bootstrap

El archivo principal carga assets, inicializa clases, registra hooks y handlers de negocio.
Tambien implementa integraciones de normalizacion de metadatos para Ultimate Member.

## 3.3 Patron de datos contables

- Tabla de movimientos = fuente de verdad.
- `monto_calculado`:
  - positivo en ingresos,
  - negativo en gastos.
- El saldo real se calcula por suma de movimientos vigentes (`deleted_at IS NULL`).

---

## 4) Modelo de datos relevante

## 4.1 Tablas custom

- `*_mlv_movimientos`: ledger operativo/contable.
- `*_mlv_clientes_almacenes`: relacion N-N cliente-local.
- `*_mlv_alerts`: notificaciones por usuario.
- `*_mlv_audit_log`: bitacora de auditoria.

## 4.2 Metadatos de usuario

- `mlv_saldo` (snapshot/cache de saldo).
- `mlv_rut`, `mlv_rut_norm`.
- `mlv_local_codigo`, `mlv_local_nombre`, `mlv_local_comuna`, `mlv_local_direccion`, `mlv_local_hours`.
- `mlv_telefono`, `mlv_phone_e164`.

---

## 5) Roles y responsabilidades

## 5.1 Cliente (`um_cliente`)

- Consulta saldo, KPIs y movimientos.
- Puede filtrar por local cuando tiene asociacion multiple.
- Recibe alertas cuando se registran latas o gastos a su nombre.

## 5.2 Almacen (`um_almacen`)

- Registra latas (ingreso, estado inicial `pendiente_retiro`).
- Registra gasto/canje (egreso, estado `retirado`).
- Marca/desmarca retirado de movimientos (logistico).
- Registra clientes nuevos o asocia clientes existentes por RUT.

## 5.3 Gestor (`um_gestor`)

- Visualiza retiros pendientes por local.
- No impacta saldo contable.

## 5.4 Administrador

- Administra movimientos, usuarios y filtros globales.
- Ejecuta incentivos, regularizaciones y ajustes contables.
- Revierte movimientos/lotes, usa papelera logica y exporta datos.
- Configura precio por lata y seguridad (Turnstile / modo estricto).

---

## 6) Flujos funcionales criticos

## 6.1 Registro de latas

1. Almacen completa formulario (2 pasos con confirmacion).
2. Se valida rol, nonce, cliente, local, evidencia y reglas anti-doble-rol.
3. Se inserta movimiento de ingreso.
4. Se recalcula `mlv_saldo` desde ledger.
5. Se genera alerta al cliente.

## 6.2 Registro de gasto

1. Almacen completa formulario (2 pasos).
2. Se valida saldo suficiente y reglas de seguridad.
3. Se inserta gasto como monto negativo.
4. Se recalcula `mlv_saldo`.
5. Se generan alertas operativas.

## 6.3 Retiro logistico

- Cambio de estado `pendiente_retiro` <-> `retirado` via AJAX.
- No altera monto ni saldo.

## 6.4 Incentivos y ajustes (admin)

- El sistema permite abonos por incentivo sin pesaje de latas.
- Tambien permite regularizaciones historicas y correcciones contables.
- Esto extiende el ledger mas alla del flujo operativo diario.

---

## 7) Seguridad y controles implementados

- Validacion por rol (`require_role`) y capacidades admin (`manage_options`).
- Nonces en formularios y endpoints AJAX.
- Sanitizacion sistematica de entradas.
- Restricciones de carga de archivos (tipo y tamano).
- Rate limit + honeypot + Turnstile opcional para login por RUT.
- Bloqueo de conflictos de doble rol por comparacion de RUT normalizado.
- Modo estricto que puede bloquear escrituras ante fallas estructurales criticas.

---

## 8) Observaciones tecnicas de madurez

- El plugin esta funcionalmente bien orientado al modelo ledger.
- Existe mezcla de logica moderna con remanentes legacy (comentarios/compatibilidad).
- El archivo principal esta sobrecargado de responsabilidades.
- Hay duplicacion de logica entre formularios de latas y gasto (preview/evidencia/compresion).
- No toda la operacion contable usa transacciones DB completas.
- El sistema prioriza pragmatismo operativo, con buen nivel de trazabilidad.

---

## 9) Sintesis conceptual final

Este plugin implementa una plataforma de economia circular a escala local:

- Convierte reciclaje en saldo utilizable.
- Controla operacion por roles.
- Mantiene evidencia y trazabilidad.
- Permite capa administrativa para incentivos, regularizaciones y correcciones.

En terminos de programacion, la idea central es correcta:
`ledger transaccional + snapshot de saldo + UI por shortcode + control por rol`.
Ese diseno permite evolucionar el sistema por entregas incrementales (ZIPs) sin romper la operacion base.

