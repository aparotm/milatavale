# Mi Lata Vale App

Prototipo inicial en `Next.js` para reemplazar el plugin WordPress.

## Estado actual

- scaffold base de `Next.js`
- login demo con cookie local de sesión
- usuarios de prueba
- integración preparada con Supabase
- paneles iniciales por rol:
  - admin
  - almacén
  - cliente
  - gestor

## Usuarios demo

- `admin@milatavale.app` / `admin123`
- `almacen.centro@milatavale.app` / `almacen123`
- `gestor.centro@milatavale.app` / `gestor123`
- `cliente.ana@milatavale.app` / `cliente123`
- `cliente.pedro@milatavale.app` / `cliente123`

## Próximo paso

1. instalar dependencias
2. correr `npm run dev`
3. validar paneles demo
4. pasar a esquema Supabase y auth real

## Variables esperadas

```bash
NEXT_PUBLIC_SUPABASE_URL=
NEXT_PUBLIC_SUPABASE_PUBLISHABLE_KEY=
SUPABASE_SERVICE_ROLE_KEY=
```

## Nota sobre login actual

Mientras no implementemos auth real, la app usa una contraseña temporal:

- `demo123`

Si hay Supabase configurado, el login busca el email en `profiles` y valida esa contraseña temporal solo para esta etapa.
