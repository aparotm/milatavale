# Mi Lata Vale Ledger V2 (MLV2)

Plugin de WordPress para gestionar **saldo interno** de clientes a partir de **reciclaje de latas**, con registro operacional por locales (almacenes) y trazabilidad mediante un **ledger** (libro de movimientos).

> Este README documenta tanto la **arquitectura del plugin** como las **decisiones y cambios** aplicados durante la iteración.  
> Última actualización: 2026-01-15

---

## Propósito y objetivos

### Problema que resuelve
- Permite que un local (almacén) registre **latas** de un cliente y eso se traduzca en **saldo**.
- Permite registrar **gastos** que descuentan saldo.
- Permite seguimiento operativo de **retiros físicos** (cuando el gestor pasa por el local), sin afectar saldo.

### Objetivos del diseño
- **Crédito y descuento inmediatos**: saldo actualizado en el momento del registro.
- **Simplicidad**: evitar doble suma y evitar flujos de “aprobación”.
- **Auditabilidad**: movimientos con metadata/historial.
- **Permisos por rol** claros.

---

## Arquitectura y construcción

### Núcleo: ledger + saldo en usermeta
- **Tabla de movimientos**: `*_mlv_movimientos` (ingresos, gastos y estados logísticos).
- **Saldo por cliente**: `wp_usermeta.meta_key = mlv_saldo` (snapshot del saldo actual).

**Regla:** el saldo se modifica solo al registrar **latas** (positivo) o **gastos** (negativo).  
Cambiar estado `pendiente_retiro ↔ retirado` es **logístico** y no cambia saldo.

### Capas del plugin
- `includes/core/`: reglas de ledger, helpers (RUT), escritura de movimientos, estados.
- `includes/frontend/`: paneles, formularios, KPIs, shortcodes.
- `includes/admin/`: tablas admin, export CSV, settings (ej. precio por lata).

---

## Estados del movimiento (decisión final)

Únicos estados globales:
- `pendiente_retiro`
- `retirado`

Se eliminó todo rastro de:
- validación/aprobación admin
- consolidar/rechazar/anular/reabrir
- estados intermedios (validado_admin, etc.)

---

## Funcionalidades por rol

### Cliente (`um_cliente`)
- Ve KPIs en este orden:
  1) Saldo  2) Latas  3) Monto  4) Gastos
- Ve movimientos (historial).
- Recibe aviso cuando el almacén registra **latas** o **gasto** para ese cliente.

### Almacén (`um_almacen`)
- Registra latas (saldo +, estado `pendiente_retiro`).
- Registra gastos (saldo -, estado `retirado`).
- Marca retiros (cambia estado a `retirado`, sin impacto en saldo).

#### Registro asistido de clientes (nuevo)
- Shortcode: `[mlv_registro_cliente_almacen]`
- **Email obligatorio** (para recuperación de contraseña).
- Evita duplicados por RUT/email.
- Crea usuario con rol `um_cliente` y password generado (cliente usa “Recuperar contraseña”).

### Gestor (`um_gestor`)
- Vista de retiros disponibles por local (movimientos `pendiente_retiro`).
- Shortcode restituido: `[mlv_gestor_almacenes_disponibles]`
- No registra/valida/suma/resta saldo.

### Administrador
- Tablas y export CSV.
- Ajustes de configuración.
- No valida ni aprueba movimientos.

---

## KPIs: definición final

- **Saldo**: `mlv_saldo`.
- **Latas**: suma de latas en movimientos positivos (según estructura del movimiento).
- **Monto**: suma de montos positivos.
- **Gastos**: suma de **ABS(montos negativos)** (nunca debe quedar en 0 si hay gastos).

---

## Alertas (decisión final)

Se eliminaron alertas “ruidosas” relacionadas a movimientos para gestor/operación.  
Se mantienen:
1) **Flash alerts por redirección** (solo una vez).
2) Avisos relevantes para **cliente** cuando el almacén registra latas o gasto.

Requisito: si se recarga la página, no reaparecen. Si se cierran con ✕, desaparecen permanentemente.

---

## Eliminación de datos bancarios (decisión final)
Se removió del plugin todo lo relacionado a datos bancarios:
- campos en formularios
- metas/labels/lógica residual

---

## Formato uniforme de cliente (UX)

En tablas y dropdowns, mostrar siempre:
**`Nombre Apellido – 12.345.678-9`**

---

## Shortcodes relevantes
- `[mlv_registro_cliente_almacen]`
- `[mlv_gestor_almacenes_disponibles]`

---

## Reset de datos (testing)
Para partir de cero desde BD:
- `TRUNCATE *_mlv_movimientos`
- `DELETE usermeta mlv_saldo`
- (Opcional) `TRUNCATE *_mlv_alerts`

