# Mi Lata Vale Migration Workspace

Este directorio queda organizado para migrar `Mi Lata Vale Ledger V2` desde WordPress hacia una app propia.

## Estructura

- `legacy/wordpress/plugin/`
  Plugin WordPress actual. Es la referencia funcional y de negocio.
- `legacy/wordpress/*.zip`
  Entregables ZIP históricos.
- `docs/`
  Documentación de arquitectura, roadmap y paridad funcional.
- `app/`
  Nueva app a desarrollar.
- `supabase/`
  Esquema, migraciones, seeds y políticas de la nueva base.

## Objetivo

Replicar el sistema actual como una app moderna con:

- frontend/app propia
- deploy en Vercel
- base y auth en Supabase
- paneles separados por rol
- auditoría, ledger y trazabilidad equivalentes al plugin

## Regla de trabajo

La app nueva no se diseña desde cero en términos de negocio.
Se construye respetando la lógica del plugin legado y mejorando estructura, seguridad y mantenibilidad.

## Siguiente paso recomendado

1. Definir esquema Supabase.
2. Crear app con usuarios de prueba.
3. Replicar panel cliente.
4. Replicar panel almacén.
5. Replicar panel admin.
6. Recién después abrir registro/login público y migrar datos reales.
