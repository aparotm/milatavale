import { demoMovements, demoUsers } from "@/lib/demo-data";
import { hasSupabaseEnv } from "@/lib/env";
import { getSupabaseServerClient } from "@/lib/supabase";
import { AppUser, AuditEntry, LedgerMovement, UserRole } from "@/lib/types";

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

async function addAuditLog(input: {
  actorProfileId: string;
  action: string;
  objectType: string;
  objectId: string;
  afterData?: Record<string, unknown>;
  metadata?: Record<string, unknown>;
}) {
  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return;
  }

  await supabase.from("audit_log").insert({
    actor_profile_id: input.actorProfileId,
    action: input.action,
    object_type: input.objectType,
    object_id: input.objectId,
    after_data: input.afterData ?? null,
    metadata: input.metadata ?? {},
  });
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
  emailOrRut: string,
  password: string,
): Promise<AppUser | null> {
  if (!hasSupabaseEnv()) {
    const user = mapDemoUsers().find(
      (item) =>
        (item.email.toLowerCase() === emailOrRut.toLowerCase() ||
          normalizeRut(item.rut) === normalizeRut(emailOrRut)) &&
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
    .eq("active", true)
    .or(`email.eq.${emailOrRut},rut_norm.eq.${normalizeRut(emailOrRut)}`)
    .limit(1);

  if (error || !data?.length) {
    return null;
  }

  const row = data[0];

  if (password !== "demo123") {
    return null;
  }

  return {
    id: row.id,
    email: row.email,
    role: row.role as UserRole,
    fullName: row.full_name,
    rut: row.rut,
    localCode: row.local_code ?? undefined,
    localName: row.local_name ?? undefined,
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
        value_per_can,
        logistic_status,
        created_at,
        detail,
        profiles!movements_client_profile_id_fkey (
          id,
          full_name,
          rut
        ),
        locales (
          code,
          name
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
    const detail =
      typeof row.detail === "object" && row.detail !== null ? row.detail : null;

    return {
      id: row.id,
      type: formatMovementType(row.type),
      clientId: profile?.id ?? "",
      localCode: local?.code ?? "",
      localName: local?.name ?? undefined,
      clientName: profile?.full_name ?? "Cliente",
      clientRut: profile?.rut ?? "",
      canCount: row.can_count ?? 0,
      amount: row.amount ?? 0,
      status: row.logistic_status ?? "retirado",
      createdAt: row.created_at,
      note: detail && "note" in detail ? String(detail.note) : undefined,
      evidenceUrl:
        detail && "evidence_url" in detail
          ? String(detail.evidence_url)
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

export async function getMovementById(id: string) {
  const movements = await getMovements();
  return movements.find((movement) => movement.id === id) ?? null;
}

export async function getClientBalance(clientId: string) {
  const movements = await getMovementsForClient(clientId);
  return movements.reduce((sum, movement) => sum + movement.amount, 0);
}

export async function getAuditEntries(limit = 20): Promise<AuditEntry[]> {
  if (!hasSupabaseEnv()) {
    return [];
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return [];
  }

  const { data, error } = await supabase
    .from("audit_log")
    .select(
      `
        id,
        action,
        object_type,
        object_id,
        created_at,
        metadata,
        profiles (
          full_name
        )
      `,
    )
    .order("created_at", { ascending: false })
    .limit(limit);

  if (error || !data) {
    return [];
  }

  return data.map((row) => ({
    id: row.id,
    action: row.action,
    objectType: row.object_type,
    objectId: row.object_id,
    actorName: pickRelation(row.profiles)?.full_name ?? "Sistema",
    createdAt: row.created_at,
    metadata:
      typeof row.metadata === "object" && row.metadata !== null
        ? (row.metadata as Record<string, unknown>)
        : undefined,
  }));
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

  if (!clientProfileId) {
    throw new Error("No se pudo resolver el cliente.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "cliente_upsert",
    objectType: "profile",
    objectId: clientProfileId,
    afterData: {
      localCode: local.code,
      rut,
      fullName,
    },
  });

  return clientProfileId;
}

export async function createIngresoMovement(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  canCount: number;
  note?: string;
  evidenceUrl?: string;
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
  const { data, error } = await supabase
    .from("movements")
    .insert({
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
        evidence_url: input.evidenceUrl?.trim() || null,
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar el ingreso.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "movimiento_ingreso_create",
    objectType: "movement",
    objectId: data.id,
    afterData: {
      canCount: input.canCount,
      amount,
      localCode: local.code,
    },
  });
}

export async function createGastoMovement(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  amount: number;
  note?: string;
  evidenceUrl?: string;
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

  const { data, error } = await supabase
    .from("movements")
    .insert({
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
        evidence_url: input.evidenceUrl?.trim() || null,
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar el gasto.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "movimiento_gasto_create",
    objectType: "movement",
    objectId: data.id,
    afterData: {
      amount: input.amount,
      localCode: local.code,
    },
  });
}

export async function setMovementRetirado(input: {
  movementId: string;
  actorProfileId: string;
  retirado: boolean;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const movement = await getMovementById(input.movementId);
  if (!movement) {
    throw new Error("No se encontró el movimiento.");
  }

  const nextStatus = input.retirado ? "retirado" : "pendiente_retiro";
  const { error } = await supabase
    .from("movements")
    .update({
      logistic_status: nextStatus,
      updated_at: new Date().toISOString(),
    })
    .eq("id", input.movementId);

  if (error) {
    throw new Error("No se pudo actualizar el retiro.");
  }

  await addAuditLog({
    actorProfileId: input.actorProfileId,
    action: input.retirado ? "marcar_retirado" : "desmarcar_retirado",
    objectType: "movement",
    objectId: input.movementId,
    afterData: {
      status: nextStatus,
    },
  });
}
