insert into public.profiles (
  id,
  role,
  email,
  full_name,
  rut,
  rut_norm,
  phone,
  local_code,
  local_name
)
values
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e001',
    'admin',
    'admin@milatavale.app',
    'Admin Mi Lata Vale',
    '11.111.111-1',
    '111111111',
    '+56 9 1111 1111',
    null,
    null
  ),
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002',
    'almacen',
    'almacen.centro@milatavale.app',
    'Carla Mendoza',
    '12.345.678-5',
    '123456785',
    '+56 9 2222 2222',
    'LOC-000101',
    'Almacen Centro'
  ),
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e003',
    'gestor',
    'gestor.centro@milatavale.app',
    'Diego Soto',
    '15.555.444-3',
    '155554443',
    '+56 9 3333 3333',
    'LOC-000101',
    'Almacen Centro'
  ),
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e004',
    'cliente',
    'cliente.ana@milatavale.app',
    'Ana Torres',
    '16.403.938-8',
    '164039388',
    '+56 9 4444 4444',
    'LOC-000101',
    'Almacen Centro'
  ),
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e005',
    'cliente',
    'cliente.pedro@milatavale.app',
    'Pedro Rojas',
    '17.654.321-K',
    '17654321K',
    '+56 9 5555 5555',
    'LOC-000101',
    'Almacen Centro'
  )
on conflict (id) do nothing;

insert into public.locales (
  id,
  code,
  name,
  comuna,
  address,
  admin_profile_id
)
values (
  '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
  'LOC-000101',
  'Almacen Centro',
  'Santiago',
  'Av. Demo 123',
  '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002'
)
on conflict (code) do nothing;

insert into public.cliente_locales (
  cliente_profile_id,
  local_id,
  created_by_profile_id
)
values
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e004',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002'
  ),
  (
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e005',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002'
  )
on conflict (cliente_profile_id, local_id) do nothing;

insert into public.movements (
  id,
  type,
  client_profile_id,
  local_id,
  created_by_profile_id,
  can_count,
  value_per_can,
  amount,
  balance_origin,
  movement_classification,
  logistic_status,
  detail,
  created_at,
  updated_at
)
values
  (
    '5cc3278c-6f0b-47a7-9d48-7b7902fe1001',
    'ingreso',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e004',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002',
    42,
    10,
    420,
    'reciclaje',
    'operacion',
    'pendiente_retiro',
    '{"note":"Registro de latas del almacén"}'::jsonb,
    '2026-03-08 10:30:00+00',
    '2026-03-08 10:30:00+00'
  ),
  (
    '5cc3278c-6f0b-47a7-9d48-7b7902fe1002',
    'gasto',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e004',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002',
    0,
    0,
    -150,
    'reciclaje',
    'operacion',
    'retirado',
    '{"note":"Canje de saldo"}'::jsonb,
    '2026-03-09 12:10:00+00',
    '2026-03-09 12:10:00+00'
  ),
  (
    '5cc3278c-6f0b-47a7-9d48-7b7902fe1003',
    'ingreso',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e005',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e002',
    70,
    10,
    700,
    'reciclaje',
    'operacion',
    'retirado',
    '{"note":"Carga de latas"}'::jsonb,
    '2026-03-10 09:15:00+00',
    '2026-03-10 09:15:00+00'
  ),
  (
    '5cc3278c-6f0b-47a7-9d48-7b7902fe1004',
    'incentivo',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e005',
    '6aa00b1a-4442-46ce-a02c-90d63d6ab001',
    '7bb0dc2f-3332-4d18-a2f2-f4df7f65e001',
    0,
    0,
    200,
    'incentivo',
    'operacion',
    'retirado',
    '{"note":"Incentivo por campaña"}'::jsonb,
    '2026-03-10 17:40:00+00',
    '2026-03-10 17:40:00+00'
  )
on conflict (id) do nothing;
