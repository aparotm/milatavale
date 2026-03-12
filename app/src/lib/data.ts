import { demoMovements, demoUsers } from "@/lib/demo-data";
import { hasSupabaseEnv } from "@/lib/env";
import { getSupabaseServerClient } from "@/lib/supabase";
import { AppUser, LedgerMovement, UserRole } from "@/lib/types";

const DEFAULT_VALUE_PER_CAN = 10;

function mapDemoUsers(): AppUser[] {
  return demoUsers.map((user) => ({
    id: user.id,
    email: user.email,
    password: user.password,
    role: user.role,
    fullName: user.fullName,
    rut: user.rut,
    localCode: user.localCode,
    localName: user.localName,
  }));
}

function formatMovementType(value: string): LedgerMovement["type"] {
  if (value === "gasto" || value === "incentivo" || value === "ajuste") {
    return value;
  }

  return "ingreso";
}

function pickRelation<T>(value: T | T[] | null | undefined): T | null {
  if (Array.isArray(value)) {
    return value[0] ?? null;
  }

  return value ?? null;
}

function normalizeRut(value: string) {
  return value.replace(/[^0-9kK]/g, "").toUpperCase();
}

async function getLocalByCode(localCode: string) {
  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return null;
  }

  const { data, error } = await supabase
    .from("locales")
    .select("id, code, name")
    .eq("code", localCode)
    .maybeSingle();

  if (error || !data) {
    return null;
  }

  return data;
}

export async function getUsers(): Promise<AppUser[]> {
  if (!hasSupabaseEnv()) {
    return mapDemoUsers();
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return mapDemoUsers();
  }

  const { data, error } = await supabase
    .from("profiles")
    .select("id, email, role, full_name, rut, local_code, local_name")
    .eq("active", true)
    .order("created_at", { ascending: true });

  if (error || !data) {
    return mapDemoUsers();
  }

  return data.map((row) => ({
    id: row.id,
    email: row.email,
    role: row.role as UserRole,
    fullName: row.full_name,
    rut: row.rut,
    localCode: row.local_code ?? undefined,
    localName: row.local_name ?? undefined,
  }));
}

export async function getUserById(id: string): Promise<AppUser | null> {
  const users = await getUsers();
  return users.find((user) => user.id === id) ?? null;
}

export async function getUserByCredentials(
  email: string,
  password: string,
): Promise<AppUser | null> {
  if (!hasSupabaseEnv()) {
    const user = mapDemoUsers().find(
      (item) =>
        item.email.toLowerCase() === email.toLowerCase() &&
        item.password === password,
    );
    return user ?? null;
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return null;
  }

  const { data, error } = await supabase
    .from("profiles")
    .select("id, email, role, full_name, rut, local_code, local_name")
    .eq("email", email)
    .eq("active", true)
    .limit(1)
    .maybeSingle();

  if (error || !data) {
    return null;
  }

  if (password !== "demo123") {
    return null;
  }

  return {
    id: data.id,
    email: data.email,
    role: data.role as UserRole,
    fullName: data.full_name,
    rut: data.rut,
    localCode: data.local_code ?? undefined,
    localName: data.local_name ?? undefined,
  };
}

export async function getMovements(): Promise<LedgerMovement[]> {
  if (!hasSupabaseEnv()) {
    return demoMovements;
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return demoMovements;
  }

  const { data, error } = await supabase
    .from("movements")
    .select(
      `
        id,
        type,
        can_count,
        amount,
        logistic_status,
        created_at,
        detail,
        profiles!movements_client_profile_id_fkey (
          id,
          full_name,
          rut
        ),
        locales (
          code
        )
      `,
    )
    .is("deleted_at", null)
    .order("created_at", { ascending: false });

  if (error || !data) {
    return demoMovements;
  }

  return data.map((row) => {
    const profile = pickRelation(row.profiles);
    const local = pickRelation(row.locales);

    return {
      id: row.id,
      type: formatMovementType(row.type),
      clientId: profile?.id ?? "",
      localCode: local?.code ?? "",
      clientName: profile?.full_name ?? "Cliente",
      clientRut: profile?.rut ?? "",
      canCount: row.can_count ?? 0,
      amount: row.amount ?? 0,
      status: row.logistic_status ?? "retirado",
      createdAt: row.created_at,
      note:
        typeof row.detail === "object" &&
        row.detail !== null &&
        "note" in row.detail
          ? String(row.detail.note)
          : undefined,
    };
  });
}

export async function getMovementsForClient(clientId: string) {
  const movements = await getMovements();
  return movements.filter((movement) => movement.clientId === clientId);
}

export async function getMovementsForLocal(localCode: string) {
  const movements = await getMovements();
  return movements.filter((movement) => movement.localCode === localCode);
}

export async function getClientBalance(clientId: string) {
  const movements = await getMovementsForClient(clientId);
  return movements.reduce((sum, movement) => sum + movement.amount, 0);
}

export async function createClientForLocal(input: {
  fullName: string;
  rut: string;
  email?: string;
  phone?: string;
  localCode: string;
  createdByProfileId: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const fullName = input.fullName.trim();
  const rut = input.rut.trim();
  const email = input.email?.trim().toLowerCase();
  const phone = input.phone?.trim();
  const rutNorm = normalizeRut(rut);

  if (!fullName || !rutNorm) {
    throw new Error("Nombre y RUT son obligatorios.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local asociado.");
  }

  let existingProfile = null as {
    id: string;
    role: UserRole;
    email: string;
    full_name: string;
    rut: string;
  } | null;

  const { data: profileByRut } = await supabase
    .from("profiles")
    .select("id, role, email, full_name, rut")
    .eq("rut_norm", rutNorm)
    .maybeSingle();

  existingProfile = profileByRut ?? null;

  if (!existingProfile && email) {
    const { data: profileByEmail } = await supabase
      .from("profiles")
      .select("id, role, email, full_name, rut")
      .eq("email", email)
      .maybeSingle();

    existingProfile = profileByEmail ?? null;
  }

  if (existingProfile && existingProfile.role !== "cliente") {
    throw new Error("El RUT o email ya pertenece a un usuario de otro rol.");
  }

  let clientProfileId = existingProfile?.id ?? null;

  if (!clientProfileId) {
    const safeSlug = normalizeRut(rut).toLowerCase();
    const fallbackEmail = email || `cliente.${safeSlug}@demo.milatavale.app`;
    const { data: insertedProfile, error: insertError } = await supabase
      .from("profiles")
      .insert({
        role: "cliente",
        email: fallbackEmail,
        full_name: fullName,
        rut,
        rut_norm: rutNorm,
        phone: phone || null,
        local_code: local.code,
        local_name: local.name,
        active: true,
        metadata: {
          source: "almacen_form",
        },
      })
      .select("id")
      .single();

    if (insertError || !insertedProfile) {
      throw new Error("No se pudo crear el cliente.");
    }

    clientProfileId = insertedProfile.id;
  } else {
    const { error: updateError } = await supabase
      .from("profiles")
      .update({
        full_name: fullName,
        rut,
        rut_norm: rutNorm,
        phone: phone || null,
        local_code: local.code,
        local_name: local.name,
        active: true,
      })
      .eq("id", clientProfileId);

    if (updateError) {
      throw new Error("No se pudo actualizar el cliente existente.");
    }
  }

  const { error: relationError } = await supabase.from("cliente_locales").upsert(
    {
      cliente_profile_id: clientProfileId,
      local_id: local.id,
      created_by_profile_id: input.createdByProfileId,
    },
    {
      onConflict: "cliente_profile_id,local_id",
    },
  );

  if (relationError) {
    throw new Error("No se pudo asociar el cliente al local.");
  }

  return clientProfileId;
}

export async function createIngresoMovement(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  canCount: number;
  note?: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  if (!Number.isFinite(input.canCount) || input.canCount <= 0) {
    throw new Error("La cantidad de latas debe ser mayor a cero.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const amount = input.canCount * DEFAULT_VALUE_PER_CAN;
  const { error } = await supabase.from("movements").insert({
    type: "ingreso",
    client_profile_id: input.clientProfileId,
    local_id: local.id,
    created_by_profile_id: input.createdByProfileId,
    can_count: input.canCount,
    value_per_can: DEFAULT_VALUE_PER_CAN,
    amount,
    balance_origin: "reciclaje",
    movement_classification: "operacion",
    logistic_status: "pendiente_retiro",
    detail: {
      note: input.note?.trim() || "Registro de latas",
      source: "almacen_form",
    },
  });

  if (error) {
    throw new Error("No se pudo registrar el ingreso.");
  }
}

export async function createGastoMovement(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  amount: number;
  note?: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  if (!Number.isFinite(input.amount) || input.amount <= 0) {
    throw new Error("El monto del gasto debe ser mayor a cero.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const balance = await getClientBalance(input.clientProfileId);
  if (balance < input.amount) {
    throw new Error("El cliente no tiene saldo suficiente.");
  }

  const { error } = await supabase.from("movements").insert({
    type: "gasto",
    client_profile_id: input.clientProfileId,
    local_id: local.id,
    created_by_profile_id: input.createdByProfileId,
    can_count: 0,
    value_per_can: 0,
    amount: -input.amount,
    balance_origin: "reciclaje",
    movement_classification: "operacion",
    logistic_status: "retirado",
    detail: {
      note: input.note?.trim() || "Canje de saldo",
      source: "almacen_form",
    },
  });

  if (error) {
    throw new Error("No se pudo registrar el gasto.");
  }
}
