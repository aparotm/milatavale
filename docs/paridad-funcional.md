# Paridad Funcional a Respetar

## Núcleo de negocio

- Registrar latas suma saldo inmediatamente.
- Registrar gasto descuenta saldo inmediatamente.
- Marcar `pendiente_retiro` y `retirado` es logístico, no contable.
- `mlv_saldo` en WordPress equivale a un cache derivado, no a la fuente de verdad.

## Roles

### Cliente

- ver saldo
- ver KPIs
- ver movimientos
- filtrar por local
- recibir alertas

### Almacén

- registrar latas
- registrar gastos
- ver clientes asociados
- ver KPIs del local
- marcar retiros
- registrar clientes para su local

### Gestor

- ver retiros pendientes
- no alterar saldo

### Admin

- ver todo
- exportar
- editar monto cuando corresponda
- reversar
- crear incentivos
- registrar regularizaciones
- hacer ajustes contables
- detectar duplicados/conflictos
- ejecutar diagnóstico

## Funcionalidades especiales existentes

- RUT como identidad operativa principal
- normalización de RUT
- bloqueo de doble rol por mismo RUT
- relación cliente-local N-N
- alertas persistidas
- evidencia con imagen
- incentivos individuales y por lote/local
- reversa individual y reversa de lote
- regularización histórica
- ajustes manuales y masivos
- papelera lógica
- auditoría
- diagnóstico estructural
- modo estricto

## Lo que puede cambiar sin romper el negocio

- forma visual del panel
- framework frontend
- auth provider
- estructura de carpetas
- implementación del admin

## Lo que no conviene cambiar

- semántica del ledger
- separación entre estado logístico e impacto contable
- trazabilidad de correcciones
- segmentación por rol
- modelo cliente-local
