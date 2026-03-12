import { demoMovements, demoUsers } from "@/lib/demo-data";
import { hasSupabaseEnv } from "@/lib/env";
import { getSupabaseServerClient } from "@/lib/supabase";
import {
  AppUser,
  AuditEntry,
  DiagnosticsSummary,
  DuplicateRutGroup,
  LedgerMovement,
  LocalHours,
  LocalProfile,
  UserRole,
} from "@/lib/types";

const DEFAULT_VALUE_PER_CAN = 10;
const DEFAULT_HOURS: LocalHours[] = [
  { day: "Lunes", open: true, from: "08:30", to: "20:30" },
  { day: "Martes", open: true, from: "08:30", to: "20:30" },
  { day: "Miércoles", open: true, from: "08:30", to: "20:30" },
  { day: "Jueves", open: true, from: "08:30", to: "20:30" },
  { day: "Viernes", open: true, from: "08:30", to: "20:30" },
  { day: "Sábado", open: true, from: "09:00", to: "14:00" },
  { day: "Domingo", open: false, from: "09:00", to: "14:00" },
];

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

function splitCsvLine(line: string) {
  const cells: string[] = [];
  let current = "";
  let inQuotes = false;

  for (let i = 0; i < line.length; i += 1) {
    const char = line[i];
    const next = line[i + 1];

    if (char === '"' && inQuotes && next === '"') {
      current += '"';
      i += 1;
      continue;
    }

    if (char === '"') {
      inQuotes = !inQuotes;
      continue;
    }

    if (char === "," && !inQuotes) {
      cells.push(current.trim());
      current = "";
      continue;
    }

    current += char;
  }

  cells.push(current.trim());
  return cells;
}

function parseHours(value: unknown): LocalHours[] {
  if (!Array.isArray(value) || value.length === 0) {
    return DEFAULT_HOURS;
  }

  return value.map((row, index) => {
    const source =
      typeof row === "object" && row !== null ? (row as Record<string, unknown>) : {};
    return {
      day: String(source.day ?? DEFAULT_HOURS[index]?.day ?? `Día ${index + 1}`),
      open: Boolean(source.open ?? true),
      from: String(source.from ?? "08:30"),
      to: String(source.to ?? "20:30"),
    };
  });
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

export async function uploadEvidenceFile(file: File) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  if (!file || file.size === 0) {
    return "";
  }

  const bucket = "evidencias";
  await supabase.storage.createBucket(bucket, {
    public: true,
    fileSizeLimit: 5 * 1024 * 1024,
  });

  const extension = file.name.includes(".")
    ? file.name.split(".").pop()
    : "jpg";
  const path = `${new Date().toISOString().slice(0, 10)}/${crypto.randomUUID()}.${extension}`;
  const arrayBuffer = await file.arrayBuffer();

  const { error } = await supabase.storage
    .from(bucket)
    .upload(path, arrayBuffer, {
      contentType: file.type || "application/octet-stream",
      upsert: false,
    });

  if (error) {
    throw new Error("No se pudo subir la evidencia.");
  }

  const { data } = supabase.storage.from(bucket).getPublicUrl(path);
  return data.publicUrl;
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

export async function getDiagnosticsSummary(): Promise<DiagnosticsSummary> {
  const users = await getUsers();
  const movements = await getMovements();
  const rutSet = new Set<string>();
  let duplicateRutCount = 0;

  users.forEach((user) => {
    const norm = normalizeRut(user.rut);
    if (rutSet.has(norm)) {
      duplicateRutCount += 1;
    } else {
      rutSet.add(norm);
    }
  });

  return {
    duplicateRutCount,
    inactiveUsers: 0,
    usersWithoutLocal: users.filter(
      (user) => user.role !== "admin" && !user.localCode,
    ).length,
    pendingMovements: movements.filter(
      (movement) => movement.status === "pendiente_retiro",
    ).length,
  };
}

export async function getDuplicateRutGroups(): Promise<DuplicateRutGroup[]> {
  const users = await getUsers();
  const movements = await getMovements();
  const byRut = new Map<string, AppUser[]>();

  users
    .filter((user) => user.role === "cliente")
    .forEach((user) => {
      const key = normalizeRut(user.rut);
      const current = byRut.get(key) ?? [];
      current.push(user);
      byRut.set(key, current);
    });

  return Array.from(byRut.entries())
    .filter(([, groupedUsers]) => groupedUsers.length > 1)
    .map(([rut, groupedUsers]) => ({
      rut,
      users: groupedUsers,
      movementCountByUser: Object.fromEntries(
        groupedUsers.map((user) => [
          user.id,
          movements.filter((movement) => movement.clientId === user.id).length,
        ]),
      ),
    }));
}

export async function createPublicProfileRegistration(input: {
  role: "cliente" | "almacen" | "gestor";
  fullName: string;
  rut: string;
  email?: string;
  phone?: string;
  localCode?: string;
  localName?: string;
  comuna?: string;
  address?: string;
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
  const rutNorm = normalizeRut(rut);
  const email =
    input.email?.trim().toLowerCase() ||
    `${input.role}.${rutNorm.toLowerCase()}@registro.milatavale.app`;

  if (!fullName || !rutNorm) {
    throw new Error("Nombre y RUT son obligatorios.");
  }

  const { data: existing } = await supabase
    .from("profiles")
    .select("id")
    .or(`rut_norm.eq.${rutNorm},email.eq.${email}`)
    .maybeSingle();

  if (existing) {
    throw new Error("Ya existe un usuario con ese RUT o email.");
  }

  const { data, error } = await supabase
    .from("profiles")
    .insert({
      role: input.role,
      email,
      full_name: fullName,
      rut,
      rut_norm: rutNorm,
      phone: input.phone?.trim() || null,
      local_code: input.localCode?.trim() || null,
      local_name: input.localName?.trim() || null,
      active: true,
      metadata: {
        registration_source: "public_form",
        comuna: input.comuna?.trim() || null,
        address: input.address?.trim() || null,
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar el usuario.");
  }

  if (input.role === "almacen" && input.localCode && input.localName) {
    await supabase.from("locales").upsert(
      {
        code: input.localCode.trim(),
        name: input.localName.trim(),
        comuna: input.comuna?.trim() || null,
        address: input.address?.trim() || null,
        admin_profile_id: data.id,
      },
      {
        onConflict: "code",
      },
    );
  }

  return data.id;
}

export async function importMovementsCsv(input: {
  csvText: string;
  actorProfileId: string;
}) {
  const lines = input.csvText
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (lines.length < 2) {
    throw new Error("El CSV no tiene filas para importar.");
  }

  const header = splitCsvLine(lines[0]).map((cell) => cell.toLowerCase());
  const rows = lines.slice(1).map((line) => splitCsvLine(line));
  const col = (name: string) => header.indexOf(name);
  const results: string[] = [];

  for (const row of rows) {
    const mode = row[col("mode")] || "ajuste";
    const rut = row[col("rut")] || "";
    const localCode = row[col("local_code")] || "";
    const amount = Number.parseInt(row[col("amount")] || "0", 10) || 0;
    const note = row[col("note")] || "";
    const type = row[col("type")] || "abonar";
    const canCount = Number.parseInt(row[col("can_count")] || "0", 10) || 0;
    const valuePerCan = Number.parseInt(row[col("value_per_can")] || "10", 10) || 10;

    const users = await getUsers();
    const client = users.find(
      (user) => user.role === "cliente" && normalizeRut(user.rut) === normalizeRut(rut),
    );

    if (!client || !localCode) {
      results.push(`Saltado: ${rut}`);
      continue;
    }

    if (mode === "regularizacion") {
      await createHistoricalRegularization({
        clientProfileId: client.id,
        localCode,
        createdByProfileId: input.actorProfileId,
        amount,
        canCount,
        valuePerCan,
        note,
        type:
          type === "saldo_preexistente" || type === "ajuste_excepcional"
            ? type
            : "latas_preexistentes",
      });
      results.push(`Regularizado: ${rut}`);
      continue;
    }

    if (mode === "incentivo") {
      await createIncentiveMovement({
        clientProfileId: client.id,
        localCode,
        createdByProfileId: input.actorProfileId,
        amount,
        note,
      });
      results.push(`Incentivo: ${rut}`);
      continue;
    }

    await createAdjustmentMovement({
      clientProfileId: client.id,
      localCode,
      createdByProfileId: input.actorProfileId,
      amount,
      direction: type === "descontar" ? "descontar" : "abonar",
      note,
    });
    results.push(`Ajuste: ${rut}`);
  }

  return results;
}

export async function getLocalProfile(localCode: string): Promise<LocalProfile | null> {
  if (!hasSupabaseEnv()) {
    return {
      id: "demo-local",
      code: localCode,
      name: "Almacén Demo",
      comuna: "Santiago",
      address: "Av. Demo 123",
      phone: "+56 9 2222 2222",
      hours: DEFAULT_HOURS,
    };
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    return null;
  }

  const { data, error } = await supabase
    .from("locales")
    .select(
      `
        id,
        code,
        name,
        comuna,
        address,
        hours_json,
        profiles!locales_admin_profile_id_fkey (
          phone
        )
      `,
    )
    .eq("code", localCode)
    .maybeSingle();

  if (error || !data) {
    return null;
  }

  return {
    id: data.id,
    code: data.code,
    name: data.name,
    comuna: data.comuna ?? undefined,
    address: data.address ?? undefined,
    phone: pickRelation(data.profiles)?.phone ?? undefined,
    hours: parseHours(data.hours_json),
  };
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

export async function updateLocalProfile(input: {
  localCode: string;
  actorProfileId: string;
  name: string;
  comuna?: string;
  address?: string;
  phone?: string;
  hours: LocalHours[];
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const { error: localError } = await supabase
    .from("locales")
    .update({
      name: input.name.trim(),
      comuna: input.comuna?.trim() || null,
      address: input.address?.trim() || null,
      hours_json: input.hours,
      updated_at: new Date().toISOString(),
    })
    .eq("id", local.id);

  if (localError) {
    throw new Error("No se pudo actualizar el local.");
  }

  const { error: profileError } = await supabase
    .from("profiles")
    .update({
      local_name: input.name.trim(),
      phone: input.phone?.trim() || null,
      updated_at: new Date().toISOString(),
    })
    .eq("id", input.actorProfileId);

  if (profileError) {
    throw new Error("No se pudo actualizar el perfil del almacén.");
  }

  await addAuditLog({
    actorProfileId: input.actorProfileId,
    action: "local_update",
    objectType: "local",
    objectId: local.id,
    afterData: {
      code: input.localCode,
      name: input.name,
    },
  });
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

export async function createIncentiveMovement(input: {
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
    throw new Error("El incentivo debe ser mayor a cero.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const { data, error } = await supabase
    .from("movements")
    .insert({
      type: "incentivo",
      client_profile_id: input.clientProfileId,
      local_id: local.id,
      created_by_profile_id: input.createdByProfileId,
      can_count: 0,
      value_per_can: 0,
      amount: input.amount,
      balance_origin: "incentivo",
      movement_classification: "operacion",
      logistic_status: "retirado",
      detail: {
        note: input.note?.trim() || "Incentivo admin",
        source: "admin_incentivo",
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar el incentivo.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "movimiento_incentivo_create",
    objectType: "movement",
    objectId: data.id,
    afterData: {
      amount: input.amount,
    },
  });
}

export async function createAdjustmentMovement(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  amount: number;
  direction: "abonar" | "descontar";
  note: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  if (!Number.isFinite(input.amount) || input.amount <= 0) {
    throw new Error("El monto del ajuste debe ser mayor a cero.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const signedAmount = input.direction === "descontar" ? -input.amount : input.amount;
  if (input.direction === "descontar") {
    const balance = await getClientBalance(input.clientProfileId);
    if (balance < input.amount) {
      throw new Error("El cliente no tiene saldo suficiente para el ajuste.");
    }
  }

  const { data, error } = await supabase
    .from("movements")
    .insert({
      type: signedAmount >= 0 ? "ingreso" : "gasto",
      client_profile_id: input.clientProfileId,
      local_id: local.id,
      created_by_profile_id: input.createdByProfileId,
      can_count: 0,
      value_per_can: 0,
      amount: signedAmount,
      balance_origin: "ajuste",
      movement_classification: "correccion",
      logistic_status: "retirado",
      is_system_adjustment: true,
      detail: {
        note: input.note.trim() || "Ajuste manual",
        source: "admin_ajuste",
        adjustment_direction: input.direction,
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar el ajuste.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "movimiento_ajuste_create",
    objectType: "movement",
    objectId: data.id,
    afterData: {
      amount: signedAmount,
      direction: input.direction,
    },
  });
}

export async function createHistoricalRegularization(input: {
  clientProfileId: string;
  localCode: string;
  createdByProfileId: string;
  amount: number;
  canCount?: number;
  valuePerCan?: number;
  note: string;
  type: "latas_preexistentes" | "saldo_preexistente" | "ajuste_excepcional";
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const local = await getLocalByCode(input.localCode);
  if (!local) {
    throw new Error("No se encontró el local.");
  }

  const amount =
    input.type === "latas_preexistentes"
      ? (input.canCount ?? 0) * (input.valuePerCan ?? DEFAULT_VALUE_PER_CAN)
      : input.amount;

  if (!Number.isFinite(amount) || amount <= 0) {
    throw new Error("La regularización debe generar un monto positivo.");
  }

  const { data, error } = await supabase
    .from("movements")
    .insert({
      type: "ingreso",
      client_profile_id: input.clientProfileId,
      local_id: local.id,
      created_by_profile_id: input.createdByProfileId,
      can_count: input.type === "latas_preexistentes" ? input.canCount ?? 0 : 0,
      value_per_can:
        input.type === "latas_preexistentes"
          ? input.valuePerCan ?? DEFAULT_VALUE_PER_CAN
          : 0,
      amount,
      balance_origin: input.type === "saldo_preexistente" ? "incentivo" : "reciclaje",
      movement_classification: "regularizacion_historica",
      logistic_status: "retirado",
      is_system_adjustment: true,
      detail: {
        note: input.note.trim() || "Regularización histórica",
        source: "admin_regularizacion",
        regularization_type: input.type,
      },
    })
    .select("id")
    .single();

  if (error || !data) {
    throw new Error("No se pudo registrar la regularización.");
  }

  await addAuditLog({
    actorProfileId: input.createdByProfileId,
    action: "movimiento_regularizacion_create",
    objectType: "movement",
    objectId: data.id,
    afterData: {
      amount,
      type: input.type,
    },
  });
}

export async function reverseMovement(input: {
  movementId: string;
  actorProfileId: string;
  note?: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const { data: original, error: originalError } = await supabase
    .from("movements")
    .select(
      "id, type, client_profile_id, local_id, can_count, value_per_can, amount, logistic_status, detail",
    )
    .eq("id", input.movementId)
    .is("deleted_at", null)
    .maybeSingle();

  if (originalError || !original) {
    throw new Error("No se encontró el movimiento a reversar.");
  }

  const detail =
    typeof original.detail === "object" && original.detail !== null
      ? original.detail
      : {};

  const { data: inserted, error: insertError } = await supabase
    .from("movements")
    .insert({
      type: "ajuste",
      client_profile_id: original.client_profile_id,
      local_id: original.local_id,
      created_by_profile_id: input.actorProfileId,
      movement_ref_id: original.id,
      can_count: 0,
      value_per_can: 0,
      amount: -Number(original.amount),
      balance_origin: "ajuste",
      movement_classification: "reversa",
      logistic_status: "retirado",
      is_system_adjustment: true,
      detail: {
        note: input.note?.trim() || "Reversa manual",
        source: "admin_reverse",
        original_type: original.type,
        original_note: "note" in detail ? String(detail.note) : null,
      },
    })
    .select("id")
    .single();

  if (insertError || !inserted) {
    throw new Error("No se pudo registrar la reversa.");
  }

  await addAuditLog({
    actorProfileId: input.actorProfileId,
    action: "movimiento_reverse",
    objectType: "movement",
    objectId: inserted.id,
    afterData: {
      ref: input.movementId,
    },
  });
}

export async function mergeClientProfiles(input: {
  primaryProfileId: string;
  secondaryProfileId: string;
  actorProfileId: string;
}) {
  if (!hasSupabaseEnv()) {
    throw new Error("Supabase no está configurado.");
  }

  if (input.primaryProfileId === input.secondaryProfileId) {
    throw new Error("Los perfiles a fusionar deben ser distintos.");
  }

  const supabase = getSupabaseServerClient();
  if (!supabase) {
    throw new Error("No fue posible conectar con Supabase.");
  }

  const { error: moveMovementError } = await supabase
    .from("movements")
    .update({
      client_profile_id: input.primaryProfileId,
    })
    .eq("client_profile_id", input.secondaryProfileId);

  if (moveMovementError) {
    throw new Error("No se pudieron mover los movimientos al perfil principal.");
  }

  const { data: secondaryRelations } = await supabase
    .from("cliente_locales")
    .select("local_id")
    .eq("cliente_profile_id", input.secondaryProfileId);

  for (const relation of secondaryRelations ?? []) {
    await supabase.from("cliente_locales").upsert(
      {
        cliente_profile_id: input.primaryProfileId,
        local_id: relation.local_id,
        created_by_profile_id: input.actorProfileId,
      },
      {
        onConflict: "cliente_profile_id,local_id",
      },
    );
  }

  await supabase
    .from("cliente_locales")
    .delete()
    .eq("cliente_profile_id", input.secondaryProfileId);

  await supabase
    .from("alerts")
    .update({
      profile_id: input.primaryProfileId,
    })
    .eq("profile_id", input.secondaryProfileId);

  const { error: secondaryUpdateError } = await supabase
    .from("profiles")
    .update({
      active: false,
      metadata: {
        merged_into: input.primaryProfileId,
      },
    })
    .eq("id", input.secondaryProfileId);

  if (secondaryUpdateError) {
    throw new Error("No se pudo marcar el perfil secundario como fusionado.");
  }

  await addAuditLog({
    actorProfileId: input.actorProfileId,
    action: "cliente_merge",
    objectType: "profile",
    objectId: input.primaryProfileId,
    afterData: {
      secondary: input.secondaryProfileId,
    },
  });
}
