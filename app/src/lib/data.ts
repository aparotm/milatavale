import { demoMovements, demoUsers } from "@/lib/demo-data";
import { hasSupabaseEnv } from "@/lib/env";
import { getSupabaseServerClient } from "@/lib/supabase";
import { AppUser, LedgerMovement, UserRole } from "@/lib/types";

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
    .limit(1)
    .maybeSingle();

  if (error || !data) {
    return null;
  }

  // Etapa actual: password demo fija para validar flujo antes de auth real.
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
