"use server";

import { revalidatePath } from "next/cache";
import { isRedirectError } from "next/dist/client/components/redirect-error";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import {
  createClientForLocal,
  createAdjustmentMovement,
  createGastoMovement,
  createIngresoMovement,
  createIncentiveMovement,
  createHistoricalRegularization,
  createPublicProfileRegistration,
  getUserByCredentials,
  importMovementsCsv,
  mergeClientProfiles,
  reverseMovement,
  setMovementRetirado,
  uploadEvidenceFile,
  updateLocalProfile,
  getUserById,
} from "@/lib/data";
import {
  IMPERSONATED_USER_COOKIE,
  IMPERSONATOR_COOKIE,
  SESSION_COOKIE,
} from "@/lib/session";

function parsePositiveInteger(value: FormDataEntryValue | null) {
  const parsed = Number.parseInt(String(value ?? "").trim(), 10);
  return Number.isFinite(parsed) ? parsed : 0;
}

function redirectWithPanelMessage(
  tab: string,
  type: "success" | "error",
  message: string,
) {
  const params = new URLSearchParams();
  params.set(type, message);
  if (tab) {
    params.set("tab", tab);
  }
  redirect(`/panel/almacen?${params.toString()}`);
}

function buildPreviewRedirect(
  tab: "ingreso" | "gasto",
  values: Record<string, string>,
) {
  const params = new URLSearchParams();
  params.set("tab", tab);
  params.set("preview", "1");

  Object.entries(values).forEach(([key, value]) => {
    if (value) {
      params.set(key, value);
    }
  });

  redirect(`/panel/almacen?${params.toString()}`);
}

export async function loginAction(formData: FormData) {
  const email = String(formData.get("identifier") ?? "").trim();
  const password = String(formData.get("password") ?? "").trim();

  const user = await getUserByCredentials(email, password);

  if (!user) {
    redirect("/login?error=1");
  }

  const cookieStore = await cookies();
  cookieStore.set(SESSION_COOKIE, user.id, {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
  });
  cookieStore.delete(IMPERSONATED_USER_COOKIE);

  redirect(`/panel/${user.role}`);
}

export async function logoutAction() {
  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE);
  cookieStore.delete(IMPERSONATOR_COOKIE);
  cookieStore.delete(IMPERSONATED_USER_COOKIE);
  redirect("/login");
}

async function getActingAdmin() {
  const cookieStore = await cookies();
  const impersonatorId = cookieStore.get(IMPERSONATOR_COOKIE)?.value;
  const sessionId = cookieStore.get(SESSION_COOKIE)?.value;
  const actingUser = impersonatorId
    ? await getUserById(impersonatorId)
    : sessionId
      ? await getUserById(sessionId)
      : null;

  return actingUser?.role === "admin" ? actingUser : null;
}

export async function impersonateUserAction(formData: FormData) {
  const admin = await getActingAdmin();

  if (!admin) {
    redirect("/login");
  }

  const targetUserId = String(formData.get("targetUserId") ?? "");
  const targetUser = await getUserById(targetUserId);

  if (!targetUser) {
    redirect("/panel/admin?error=Usuario+no+encontrado");
  }

  const cookieStore = await cookies();
  cookieStore.set(IMPERSONATOR_COOKIE, admin.id, {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
  });
  cookieStore.set(SESSION_COOKIE, targetUser.id, {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
  });
  cookieStore.set(IMPERSONATED_USER_COOKIE, JSON.stringify(targetUser), {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
  });

  redirect(`/panel/${targetUser.role}`);
}

export async function stopImpersonationAction() {
  const cookieStore = await cookies();
  const impersonatorId = cookieStore.get(IMPERSONATOR_COOKIE)?.value;

  if (impersonatorId) {
    cookieStore.set(SESSION_COOKIE, impersonatorId, {
      httpOnly: true,
      sameSite: "lax",
      path: "/",
    });
  }

  cookieStore.delete(IMPERSONATOR_COOKIE);
  cookieStore.delete(IMPERSONATED_USER_COOKIE);
  redirect("/panel/admin");
}

export async function previewIngresoAction(formData: FormData) {
  try {
    const evidenceFile = formData.get("evidence");
    const evidenceUrl =
      evidenceFile instanceof File && evidenceFile.size > 0
        ? await uploadEvidenceFile(evidenceFile)
        : String(formData.get("evidenceUrl") ?? "");

    buildPreviewRedirect("ingreso", {
      clientProfileId: String(formData.get("clientProfileId") ?? ""),
      canCount: String(formData.get("canCount") ?? ""),
      note: String(formData.get("note") ?? ""),
      evidenceUrl,
    });
  } catch (error) {
    if (isRedirectError(error)) {
      throw error;
    }

    const message =
      error instanceof Error ? error.message : "No se pudo preparar el registro.";
    redirectWithPanelMessage("ingreso", "error", message);
  }
}

export async function previewGastoAction(formData: FormData) {
  try {
    const evidenceFile = formData.get("evidence");
    const evidenceUrl =
      evidenceFile instanceof File && evidenceFile.size > 0
        ? await uploadEvidenceFile(evidenceFile)
        : String(formData.get("evidenceUrl") ?? "");

    buildPreviewRedirect("gasto", {
      clientProfileId: String(formData.get("clientProfileId") ?? ""),
      amount: String(formData.get("amount") ?? ""),
      note: String(formData.get("note") ?? ""),
      evidenceUrl,
    });
  } catch (error) {
    if (isRedirectError(error)) {
      throw error;
    }

    const message =
      error instanceof Error ? error.message : "No se pudo preparar el gasto.";
    redirectWithPanelMessage("gasto", "error", message);
  }
}

export async function createClientAction(formData: FormData) {
  const fullName = String(formData.get("fullName") ?? "");
  const rut = String(formData.get("rut") ?? "");
  const email = String(formData.get("email") ?? "");
  const phone = String(formData.get("phone") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");

  try {
    await createClientForLocal({
      fullName,
      rut,
      email,
      phone,
      localCode,
      createdByProfileId,
    });

    revalidatePath("/panel/almacen");
    revalidatePath("/panel/admin");
    redirectWithPanelMessage("cliente", "success", "Cliente creado o asociado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo crear el cliente.";
    redirectWithPanelMessage("cliente", "error", message);
  }
}

export async function createIngresoAction(formData: FormData) {
  const clientProfileId = String(formData.get("clientProfileId") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");
  const canCount = parsePositiveInteger(formData.get("canCount"));
  const note = String(formData.get("note") ?? "");
  const evidenceUrl = String(formData.get("evidenceUrl") ?? "");

  try {
    await createIngresoMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      canCount,
      note,
      evidenceUrl,
    });

    revalidatePath("/panel/almacen");
    revalidatePath("/panel/admin");
    revalidatePath("/panel/cliente");
    revalidatePath("/panel/gestor");
    redirectWithPanelMessage("ingreso", "success", "Ingreso registrado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar el ingreso.";
    redirectWithPanelMessage("ingreso", "error", message);
  }
}

export async function createGastoAction(formData: FormData) {
  const clientProfileId = String(formData.get("clientProfileId") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");
  const amount = parsePositiveInteger(formData.get("amount"));
  const note = String(formData.get("note") ?? "");
  const evidenceUrl = String(formData.get("evidenceUrl") ?? "");

  try {
    await createGastoMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      amount,
      note,
      evidenceUrl,
    });

    revalidatePath("/panel/almacen");
    revalidatePath("/panel/admin");
    revalidatePath("/panel/cliente");
    redirectWithPanelMessage("gasto", "success", "Gasto registrado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar el gasto.";
    redirectWithPanelMessage("gasto", "error", message);
  }
}

export async function setRetiradoAction(formData: FormData) {
  const movementId = String(formData.get("movementId") ?? "");
  const actorProfileId = String(formData.get("actorProfileId") ?? "");
  const retirado = String(formData.get("retirado") ?? "") === "1";
  const backTo = String(formData.get("backTo") ?? "gestor");

  await setMovementRetirado({
    movementId,
    actorProfileId,
    retirado,
  });

  revalidatePath("/panel/gestor");
  revalidatePath("/panel/almacen");
  revalidatePath("/panel/admin");

  redirect(`/panel/${backTo}`);
}

export async function createIncentiveAction(formData: FormData) {
  const clientProfileId = String(formData.get("clientProfileId") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");
  const amount = parsePositiveInteger(formData.get("amount"));
  const note = String(formData.get("note") ?? "");

  try {
    await createIncentiveMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      amount,
      note,
    });

    revalidatePath("/panel/admin");
    revalidatePath("/panel/cliente");
    redirect("/panel/admin?tab=incentivos&success=Incentivo+registrado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar el incentivo.";
    redirect(`/panel/admin?tab=incentivos&error=${encodeURIComponent(message)}`);
  }
}

export async function reverseMovementAction(formData: FormData) {
  const movementId = String(formData.get("movementId") ?? "");
  const actorProfileId = String(formData.get("actorProfileId") ?? "");
  const note = String(formData.get("note") ?? "");

  try {
    await reverseMovement({
      movementId,
      actorProfileId,
      note,
    });

    revalidatePath("/panel/admin");
    revalidatePath("/panel/cliente");
    redirect("/panel/admin?tab=movimientos&success=Reversa+registrada.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo reversar el movimiento.";
    redirect(`/panel/admin?tab=movimientos&error=${encodeURIComponent(message)}`);
  }
}

export async function updateLocalProfileAction(formData: FormData) {
  const localCode = String(formData.get("localCode") ?? "");
  const actorProfileId = String(formData.get("actorProfileId") ?? "");
  const name = String(formData.get("name") ?? "");
  const comuna = String(formData.get("comuna") ?? "");
  const address = String(formData.get("address") ?? "");
  const phone = String(formData.get("phone") ?? "");
  const days = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];

  const hours = days.map((day, index) => ({
    day,
    open: String(formData.get(`day_${index}_open`) ?? "") === "1",
    from: String(formData.get(`day_${index}_from`) ?? "08:30"),
    to: String(formData.get(`day_${index}_to`) ?? "20:30"),
  }));

  try {
    await updateLocalProfile({
      localCode,
      actorProfileId,
      name,
      comuna,
      address,
      phone,
      hours,
    });

    revalidatePath("/panel/almacen");
    redirect("/panel/almacen?tab=perfil&success=Perfil+actualizado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo actualizar el perfil.";
    redirect(`/panel/almacen?tab=perfil&error=${encodeURIComponent(message)}`);
  }
}

export async function createAdjustmentAction(formData: FormData) {
  const clientProfileId = String(formData.get("clientProfileId") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");
  const amount = parsePositiveInteger(formData.get("amount"));
  const direction = String(formData.get("direction") ?? "") as "abonar" | "descontar";
  const note = String(formData.get("note") ?? "");

  try {
    await createAdjustmentMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      amount,
      direction,
      note,
    });

    revalidatePath("/panel/admin");
    redirect("/panel/admin?tab=ajustes&success=Ajuste+registrado.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar el ajuste.";
    redirect(`/panel/admin?tab=ajustes&error=${encodeURIComponent(message)}`);
  }
}

export async function createRegularizationAction(formData: FormData) {
  const clientProfileId = String(formData.get("clientProfileId") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const createdByProfileId = String(formData.get("createdByProfileId") ?? "");
  const amount = parsePositiveInteger(formData.get("amount"));
  const canCount = parsePositiveInteger(formData.get("canCount"));
  const valuePerCan = parsePositiveInteger(formData.get("valuePerCan"));
  const note = String(formData.get("note") ?? "");
  const type = String(formData.get("type") ?? "") as
    | "latas_preexistentes"
    | "saldo_preexistente"
    | "ajuste_excepcional";

  try {
    await createHistoricalRegularization({
      clientProfileId,
      localCode,
      createdByProfileId,
      amount,
      canCount,
      valuePerCan,
      note,
      type,
    });

    revalidatePath("/panel/admin");
    redirect("/panel/admin?tab=regularizacion&success=Regularización+registrada.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar la regularización.";
    redirect(`/panel/admin?tab=regularizacion&error=${encodeURIComponent(message)}`);
  }
}

export async function mergeClientsAction(formData: FormData) {
  const primaryProfileId = String(formData.get("primaryProfileId") ?? "");
  const secondaryProfileId = String(formData.get("secondaryProfileId") ?? "");
  const actorProfileId = String(formData.get("actorProfileId") ?? "");

  try {
    await mergeClientProfiles({
      primaryProfileId,
      secondaryProfileId,
      actorProfileId,
    });

    revalidatePath("/panel/admin");
    redirect("/panel/admin?tab=duplicados&success=Fusión+realizada.");
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo fusionar el cliente.";
    redirect(`/panel/admin?tab=duplicados&error=${encodeURIComponent(message)}`);
  }
}

export async function publicRegistrationAction(formData: FormData) {
  const role = String(formData.get("role") ?? "") as
    | "cliente"
    | "almacen"
    | "gestor";
  const fullName = String(formData.get("fullName") ?? "");
  const rut = String(formData.get("rut") ?? "");
  const email = String(formData.get("email") ?? "");
  const phone = String(formData.get("phone") ?? "");
  const localCode = String(formData.get("localCode") ?? "");
  const localName = String(formData.get("localName") ?? "");
  const comuna = String(formData.get("comuna") ?? "");
  const address = String(formData.get("address") ?? "");

  try {
    await createPublicProfileRegistration({
      role,
      fullName,
      rut,
      email,
      phone,
      localCode,
      localName,
      comuna,
      address,
    });

    redirect(`/registro?role=${role}&success=Registro+enviado`);
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo registrar el usuario.";
    redirect(`/registro?role=${role}&error=${encodeURIComponent(message)}`);
  }
}

export async function importCsvAction(formData: FormData) {
  const actorProfileId = String(formData.get("actorProfileId") ?? "");
  const file = formData.get("csvFile");

  if (!(file instanceof File) || file.size === 0) {
    redirect("/panel/admin?tab=importar&error=Debes+subir+un+CSV+válido.");
  }

  try {
    const csvText = await file.text();
    const results = await importMovementsCsv({
      csvText,
      actorProfileId,
    });

    revalidatePath("/panel/admin");
    redirect(
      `/panel/admin?tab=importar&success=${encodeURIComponent(
        `Importación completada: ${results.length} fila(s).`,
      )}`,
    );
  } catch (error) {
    const message =
      error instanceof Error ? error.message : "No se pudo importar el CSV.";
    redirect(`/panel/admin?tab=importar&error=${encodeURIComponent(message)}`);
  }
}
