import { cookies } from "next/headers";
import { redirect } from "next/navigation";

import { getUserById } from "@/lib/data";
import { AppUser, UserRole } from "@/lib/types";

const SESSION_COOKIE = "mlv_demo_user";

export async function getSessionUser(): Promise<AppUser | null> {
  const cookieStore = await cookies();
  const userId = cookieStore.get(SESSION_COOKIE)?.value;

  if (!userId) {
    return null;
  }

  return getUserById(userId);
}

export async function requireSessionUser(role?: UserRole): Promise<AppUser> {
  const user = await getSessionUser();

  if (!user) {
    redirect("/login");
  }

  if (role && user.role !== role) {
    redirect(`/panel/${user.role}`);
  }

  return user;
}

export { SESSION_COOKIE };
