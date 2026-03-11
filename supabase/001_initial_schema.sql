create extension if not exists "pgcrypto";

create type public.app_role as enum ('admin', 'almacen', 'cliente', 'gestor');
create type public.movement_type as enum ('ingreso', 'gasto', 'incentivo', 'ajuste');
create type public.logistic_status as enum ('pendiente_retiro', 'retirado');

create table if not exists public.profiles (
  id uuid primary key default gen_random_uuid(),
  auth_user_id uuid unique,
  role public.app_role not null,
  email text not null unique,
  full_name text not null,
  rut text not null,
  rut_norm text not null unique,
  phone text,
  local_code text,
  local_name text,
  active boolean not null default true,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.locales (
  id uuid primary key default gen_random_uuid(),
  code text not null unique,
  name text not null,
  comuna text,
  address text,
  hours_json jsonb not null default '{}'::jsonb,
  admin_profile_id uuid references public.profiles(id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.cliente_locales (
  id uuid primary key default gen_random_uuid(),
  cliente_profile_id uuid not null references public.profiles(id) on delete cascade,
  local_id uuid not null references public.locales(id) on delete cascade,
  created_by_profile_id uuid references public.profiles(id) on delete set null,
  created_at timestamptz not null default now(),
  unique (cliente_profile_id, local_id)
);

create table if not exists public.movements (
  id uuid primary key default gen_random_uuid(),
  type public.movement_type not null,
  client_profile_id uuid not null references public.profiles(id) on delete restrict,
  local_id uuid references public.locales(id) on delete set null,
  created_by_profile_id uuid not null references public.profiles(id) on delete restrict,
  validated_by_profile_id uuid references public.profiles(id) on delete set null,
  movement_ref_id uuid references public.movements(id) on delete set null,
  can_count integer not null default 0,
  value_per_can integer not null default 0,
  amount integer not null default 0,
  balance_origin text not null default 'reciclaje',
  movement_classification text not null default 'operacion',
  logistic_status public.logistic_status not null default 'retirado',
  is_system_adjustment boolean not null default false,
  incentive_batch_id text,
  detail jsonb not null default '{}'::jsonb,
  deleted_at timestamptz,
  deleted_by_profile_id uuid references public.profiles(id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists movements_client_idx
  on public.movements (client_profile_id, deleted_at);

create index if not exists movements_local_idx
  on public.movements (local_id, deleted_at, created_at desc);

create index if not exists movements_batch_idx
  on public.movements (incentive_batch_id);

create table if not exists public.alerts (
  id uuid primary key default gen_random_uuid(),
  profile_id uuid not null references public.profiles(id) on delete cascade,
  level text not null,
  title text not null,
  body text not null,
  ref_type text,
  ref_id uuid,
  dismissed_at timestamptz,
  created_at timestamptz not null default now()
);

create index if not exists alerts_profile_idx
  on public.alerts (profile_id, dismissed_at, created_at desc);

create table if not exists public.audit_log (
  id uuid primary key default gen_random_uuid(),
  actor_profile_id uuid references public.profiles(id) on delete set null,
  action text not null,
  object_type text not null,
  object_id text not null,
  before_data jsonb,
  after_data jsonb,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default now()
);

create or replace function public.profile_balance(profile_uuid uuid)
returns integer
language sql
stable
as $$
  select coalesce(sum(amount), 0)::integer
  from public.movements
  where client_profile_id = profile_uuid
    and deleted_at is null;
$$;
