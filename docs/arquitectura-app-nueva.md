# Arquitectura Propuesta

## Stack

- `Next.js`
- `TypeScript`
- `Vercel`
- `Supabase Postgres`
- `Supabase Auth`
- `Supabase Storage`

## Principio central

La nueva app debe seguir siendo un sistema `ledger-first`.

Eso significa:

- el saldo no se edita manualmente como fuente de verdad
- el saldo se deriva de movimientos
- cada corrección debe dejar trazabilidad
- los estados logísticos no deben confundirse con impacto contable

## Módulos principales

### 1. Auth y perfiles

- login inicial con usuarios de prueba
- roles: `admin`, `almacen`, `cliente`, `gestor`
- futura etapa: registro público por formularios separados por tipo de usuario

### 2. Ledger

Tabla central de movimientos:

- ingresos por latas
- gastos/canjes
- incentivos
- regularizaciones históricas
- ajustes contables
- reversas

Campos mínimos:

- `id`
- `tipo`
- `cliente_id`
- `almacen_id`
- `local_codigo`
- `cantidad_latas`
- `valor_por_lata`
- `monto_calculado`
- `origen_saldo`
- `clasificacion_mov`
- `estado_logistico`
- `mov_ref_id`
- `is_system_adjustment`
- `detalle_json`
- `deleted_at`
- `created_at`
- `updated_at`

### 3. Usuarios y relación con locales

Mantener modelo N-N entre clientes y locales.

Entidades separadas:

- `profiles`
- `locales`
- `clientes_locales`

No volver al modelo legacy de un solo local por meta.

### 4. Paneles

#### Cliente

- saldo disponible
- historial de movimientos
- filtro por local
- alertas

#### Almacén

- registro de latas
- registro de gasto
- listado de clientes del local
- KPIs del local
- historial del local
- marcar retiro logístico

#### Gestor

- retiros pendientes por local
- vista operativa sin impacto contable

#### Admin

- listado global de movimientos
- reversas
- incentivos
- regularizaciones
- ajustes
- diagnóstico
- exportables
- gestión de conflictos y duplicados

### 5. Alertas

Persistidas y dismissibles por usuario.

### 6. Auditoría

Tabla append-only de eventos administrativos y sistémicos.

## Diferencias deseables respecto al plugin

- separar UI, dominio y acceso a datos
- sacar lógica de negocio del archivo bootstrap
- evitar mezclar compat legacy con dominio nuevo
- usar transacciones en operaciones sensibles
- tipar explícitamente entradas y salidas

## Estrategia de implementación

### Fase 1

- usuarios de prueba
- seed inicial
- auth simple
- paneles básicos
- datos mock realistas

### Fase 2

- paridad completa con flujos de WordPress
- adjuntos reales
- auditoría
- exportables

### Fase 3

- registro público
- login definitivo
- migración de datos históricos
