import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import { getUserById } from "@/lib/data";
import { AppUser, UserRole } from "@/lib/types";

const SESSION_COOKIE = "mlv_demo_user";
const IMPERSONATOR_COOKIE = "mlv_admin_impersonator";
const IMPERSONATED_USER_COOKIE = "mlv_impersonated_user";

function decodeImpersonatedUser(value: string | undefined) {
  if (!value) {
    return "";
  }

  try {
    return Buffer.from(value, "base64url").toString("utf8");
  } catch {
    return "";
  }
}

function parseImpersonatedUser(value: string | undefined): AppUser | null {
  if (!value) {
    return null;
  }

  try {
    const parsed = JSON.parse(decodeImpersonatedUser(value)) as AppUser;
    if (
      parsed &&
      typeof parsed.id === "string" &&
      typeof parsed.role === "string" &&
      typeof parsed.fullName === "string" &&
      typeof parsed.rut === "string" &&
      typeof parsed.email === "string"
    ) {
      return parsed;
    }
  } catch {}

  return null;
}

export async function getSessionUser(): Promise<AppUser | null> {
  const cookieStore = await cookies();
  const impersonatedUser = parseImpersonatedUser(
    cookieStore.get(IMPERSONATED_USER_COOKIE)?.value,
  );

  if (impersonatedUser) {
    return impersonatedUser;
  }

  const userId = cookieStore.get(SESSION_COOKIE)?.value;

  if (!userId) {
    return null;
  }

  return getUserById(userId);
}

export async function getImpersonatorUser(): Promise<AppUser | null> {
  const cookieStore = await cookies();
  const userId = cookieStore.get(IMPERSONATOR_COOKIE)?.value;

  if (!userId) {
    return null;
  }

  const user = await getUserById(userId);
  return user?.role === "admin" ? user : null;
}

export async function requireSessionContext(role?: UserRole): Promise<{
  user: AppUser;
  impersonator: AppUser | null;
}> {
  const user = await getSessionUser();

  if (!user) {
    redirect("/login");
  }

  if (role && user.role !== role) {
    redirect(`/panel/${user.role}`);
  }

  const impersonator = await getImpersonatorUser();
  return { user, impersonator };
}

export async function requireSessionUser(role?: UserRole): Promise<AppUser> {
  const { user } = await requireSessionContext(role);
  return user;
}

export { IMPERSONATED_USER_COOKIE, IMPERSONATOR_COOKIE, SESSION_COOKIE };
