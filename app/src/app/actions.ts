"use server";

import { revalidatePath } from "next/cache";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import {
  createClientForLocal,
  createGastoMovement,
  createIngresoMovement,
  getUserByCredentials,
} from "@/lib/data";
import { SESSION_COOKIE } from "@/lib/session";

function parsePositiveInteger(value: FormDataEntryValue | null) {
  const parsed = Number.parseInt(String(value ?? "").trim(), 10);
  return Number.isFinite(parsed) ? parsed : 0;
}

function redirectWithPanelMessage(
  search: string,
  type: "success" | "error",
  message: string,
) {
  const params = new URLSearchParams();
  params.set(type, message);
  if (search) {
    params.set("tab", search);
  }
  redirect(`/panel/almacen?${params.toString()}`);
}

export async function loginAction(formData: FormData) {
  const email = String(formData.get("email") ?? "").trim();
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

  try {
    await createIngresoMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      canCount,
      note,
    });

    revalidatePath("/panel/almacen");
    revalidatePath("/panel/admin");
    revalidatePath("/panel/cliente");
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

  try {
    await createGastoMovement({
      clientProfileId,
      localCode,
      createdByProfileId,
      amount,
      note,
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
