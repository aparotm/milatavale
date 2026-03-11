# Supabase

Aquí irán:

- esquema SQL
- migraciones
- seeds
- políticas RLS
- storage buckets

Primero se definirá el modelo equivalente al plugin WordPress:

- perfiles
- locales
- relación cliente-local
- movimientos
- alertas
- auditoría

## Archivos iniciales

- `001_initial_schema.sql`
  Modelo base inspirado en el plugin actual.
- `002_demo_seed.sql`
  Datos demo para la primera réplica funcional.
