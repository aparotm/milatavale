# Roadmap de Migración

## Fase 0. Base de proyecto

- ordenar carpeta
- congelar plugin legacy como referencia
- documentar módulos y paridad

## Fase 1. Prototipo funcional con usuarios de prueba

- crear app Next.js
- configurar Supabase
- definir esquema inicial
- crear seed con usuarios de prueba
- login simple
- routing por rol
- panel cliente demo
- panel almacén demo
- panel admin demo

Objetivo:
ver la app completa antes de cargar datos reales.

## Fase 2. Paridad operativa

- registro de latas
- registro de gastos
- historial de movimientos
- alertas
- gestión de clientes por local
- gestor con retiros pendientes
- KPIs equivalentes

Objetivo:
igualar el uso diario del plugin.

## Fase 3. Paridad administrativa

- incentivos
- reversas
- regularizaciones
- ajustes contables
- exportables
- auditoría
- diagnóstico

Objetivo:
igualar las herramientas de operación y corrección.

## Fase 4. Auth y registro público definitivo

- formularios públicos por tipo de usuario
- flujos de alta
- validación de RUT
- aprobación o activación según reglas
- login definitivo

Objetivo:
reemplazar acceso manual/temporal por onboarding real.

## Fase 5. Migración real

- importar usuarios
- importar movimientos
- importar relaciones cliente-local
- validar saldos
- ejecutar QA de paridad
- pasar a producción

## Orden recomendado de construcción

1. esquema de datos
2. seed demo
3. auth demo
4. panel cliente
5. panel almacén
6. panel gestor
7. panel admin
8. adjuntos
9. auditoría
10. registro público
