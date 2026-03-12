"use server";

import { revalidatePath } from "next/cache";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import {
  createClientForLocal,
  createGastoMovement,
  createIngresoMovement,
  createIncentiveMovement,
  getUserByCredentials,
  reverseMovement,
  setMovementRetirado,
  updateLocalProfile,
} from "@/lib/data";
import { SESSION_COOKIE } from "@/lib/session";

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

  redirect(`/panel/${user.role}`);
}

export async function logoutAction() {
  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE);
  redirect("/login");
}

export async function previewIngresoAction(formData: FormData) {
  buildPreviewRedirect("ingreso", {
    clientProfileId: String(formData.get("clientProfileId") ?? ""),
    canCount: String(formData.get("canCount") ?? ""),
    note: String(formData.get("note") ?? ""),
    evidenceUrl: String(formData.get("evidenceUrl") ?? ""),
  });
}

export async function previewGastoAction(formData: FormData) {
  buildPreviewRedirect("gasto", {
    clientProfileId: String(formData.get("clientProfileId") ?? ""),
    amount: String(formData.get("amount") ?? ""),
    note: String(formData.get("note") ?? ""),
    evidenceUrl: String(formData.get("evidenceUrl") ?? ""),
  });
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
